<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student course detail (Overview / Lessons / Reviews)
|
|   GET  ?id=COURSE                 -> course, outline, progress, reviews
|   GET  ?id=COURSE&part=reviews&page=N -> just the reviews (for "Load more")
|   POST action=review (course_id, rating 1-5, review, csrf_token)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';
require_once __DIR__ . '/api_quiz_service.php';
require_once __DIR__ . '/../includes/notifications.php';

const SC_REVIEWS_PER_PAGE = 5;
const SC_REVIEW_MAX = 500;

[$pdo, $user] = sc_api_start();
$userId = (int) $user['id'];

function sc_course_row(PDO $pdo, int $courseId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, c.description, c.thumbnail, c.instructor_id, cat.name AS category, u.name AS instructor
         FROM courses c
         JOIN users u ON u.id = c.instructor_id
         LEFT JOIN categories cat ON cat.id = c.category_id
         WHERE c.id = :id AND c.status = 'published' LIMIT 1"
    );
    $stmt->execute([':id' => $courseId]);
    return $stmt->fetch() ?: null;
}

function sc_reviews(PDO $pdo, int $courseId, int $userId, int $page): array
{
    $stmt = $pdo->prepare(
        'SELECT rating, COUNT(*) AS n FROM enrollments WHERE course_id = :c AND rating IS NOT NULL GROUP BY rating'
    );
    $stmt->execute([':c' => $courseId]);
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $count = 0;
    $sum = 0;
    foreach ($stmt->fetchAll() as $row) {
        $r = (int) $row['rating'];
        if (isset($dist[$r])) {
            $dist[$r] = (int) $row['n'];
            $count += (int) $row['n'];
            $sum += $r * (int) $row['n'];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT e.user_id, e.rating, e.review, e.reviewed_at, e.instructor_reply, e.replied_at, u.name
         FROM enrollments e JOIN users u ON u.id = e.user_id
         WHERE e.course_id = :c AND e.rating IS NOT NULL AND e.review IS NOT NULL AND e.review <> ''
         ORDER BY e.reviewed_at DESC, e.id DESC
         LIMIT " . (SC_REVIEWS_PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * SC_REVIEWS_PER_PAGE)
    );
    $stmt->execute([':c' => $courseId]);
    $rows = $stmt->fetchAll();
    $more = count($rows) > SC_REVIEWS_PER_PAGE;
    $list = [];
    foreach (array_slice($rows, 0, SC_REVIEWS_PER_PAGE) as $row) {
        $list[] = [
            'name'       => (string) $row['name'],
            'initials'   => sc_initials((string) $row['name']),
            'rating'     => (int) $row['rating'],
            'review'     => (string) $row['review'],
            'date'       => sc_iso($row['reviewed_at']),
            'mine'       => (int) $row['user_id'] === $userId,
            'reply'      => $row['instructor_reply'] !== null ? (string) $row['instructor_reply'] : null,
            'reply_date' => sc_iso($row['replied_at']),
        ];
    }
    return [
        'average' => $count ? round($sum / $count, 1) : null,
        'count'   => $count,
        'dist'    => $dist,
        'list'    => $list,
        'page'    => $page,
        'more'    => $more,
    ];
}

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sc_require_csrf();
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($_POST['action'] ?? '') !== 'review' || $courseId === false) {
        sc_json(422, ['status' => 'error', 'message' => 'A valid course is required.']);
    }
    $reviewCourse = sc_course_row($pdo, $courseId);
    $reviewEnrollment = $reviewCourse !== null ? sc_enrollment($pdo, $courseId, $userId) : null;
    if ($reviewCourse === null || $reviewEnrollment === null) {
        sc_json(403, ['status' => 'error', 'message' => 'Only enrolled students can review a course.']);
    }
    $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    $text = trim((string) ($_POST['review'] ?? ''));
    if ($rating === false) {
        sc_json(422, ['status' => 'error', 'message' => 'Choose a rating from 1 to 5 stars.']);
    }
    if (mb_strlen($text, 'UTF-8') > SC_REVIEW_MAX) {
        sc_json(422, ['status' => 'error', 'message' => 'Keep your review to ' . SC_REVIEW_MAX . ' characters or fewer.']);
    }
    $stmt = $pdo->prepare(
        'UPDATE enrollments SET rating = :r, review = :t, reviewed_at = NOW() WHERE course_id = :c AND user_id = :u'
    );
    $stmt->execute([':r' => $rating, ':t' => $text !== '' ? $text : null, ':c' => $courseId, ':u' => $userId]);
    // First review only (not every edit): tell the instructor (never blocks saving).
    if ($reviewEnrollment['rating'] === null) {
        notify(
            $pdo, (int) $reviewCourse['instructor_id'], 'review',
            (string) $user['name'] . ' rated ' . (string) $reviewCourse['title'] . ' ' . $rating . '/5',
            $text !== '' ? notificationExcerpt($text, 120) : null, 'instructor/reviews/reviews'
        );
    }
    sc_json(200, ['status' => 'ok', 'message' => 'Thanks, your review is saved.', 'reviews' => sc_reviews($pdo, $courseId, $userId, 1)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

/* ------------------------------------------------------------------- GET */
$courseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($courseId === false) {
    sc_json(422, ['status' => 'error', 'message' => 'A valid course is required.']);
}
$course = sc_course_row($pdo, $courseId);
if ($course === null) {
    sc_json(404, ['status' => 'error', 'message' => 'That course is not available.']);
}

if (($_GET['part'] ?? '') === 'reviews') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    sc_json(200, ['status' => 'ok', 'reviews' => sc_reviews($pdo, $courseId, $userId, $page)]);
}

$enrollment = sc_enrollment($pdo, $courseId, $userId);
$outline = sc_outline($pdo, $courseId, $userId);
$next = sc_next_lesson($outline['flat']);

$students = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = :c');
$students->execute([':c' => $courseId]);

$stmt = $pdo->prepare(
    "SELECT q.id, q.title, q.questions, q.pass_mark, q.max_attempts, q.start_time, q.end_time,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = :u1) AS used,
            (SELECT MAX(qa.score / NULLIF(qa.total_marks, 0)) * 100 FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = :u2) AS best,
            (SELECT COALESCE(MAX(qa.passed), 0) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = :u3) AS passed
     FROM quizzes q WHERE q.course_id = :c ORDER BY q.id ASC"
);
$stmt->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId, ':c' => $courseId]);
$quizzes = [];
foreach ($stmt->fetchAll() as $row) {
    $decoded = json_decode((string) $row['questions'], true);
    $quizzes[] = [
        'id'           => (int) $row['id'],
        'title'        => (string) $row['title'],
        'questions'    => is_array($decoded) ? count($decoded) : 0,
        'pass_mark'    => (int) $row['pass_mark'],
        'max_attempts' => (int) $row['max_attempts'],
        'used'         => (int) $row['used'],
        'best'         => $row['best'] !== null ? (int) round((float) $row['best']) : null,
        'passed'       => (bool) (int) $row['passed'],
        'availability' => quizAvailability($row['start_time'], $row['end_time']),
    ];
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.due_date, a.max_mark, s.submitted_at, s.mark, s.graded_at
     FROM assignments a
     LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = :u
     WHERE a.course_id = :c ORDER BY a.due_date ASC, a.id ASC'
);
$stmt->execute([':u' => $userId, ':c' => $courseId]);
$assignments = [];
$nowTime = new DateTimeImmutable();
foreach ($stmt->fetchAll() as $row) {
    $due = new DateTimeImmutable((string) $row['due_date']);
    $state = $row['mark'] !== null ? 'graded' : ($row['submitted_at'] !== null ? 'submitted' : ($due < $nowTime ? 'overdue' : 'open'));
    $assignments[] = [
        'id'        => (int) $row['id'],
        'title'     => (string) $row['title'],
        'due'       => sc_iso((string) $row['due_date']),
        'max_mark'  => (int) $row['max_mark'],
        'mark'      => $row['mark'] !== null ? (int) $row['mark'] : null,
        'submitted' => sc_iso($row['submitted_at']),
        'state'     => $state,
    ];
}

$reviews = sc_reviews($pdo, $courseId, $userId, 1);
$mine = null;
if ($enrollment !== null && $enrollment['rating'] !== null) {
    $mine = ['rating' => (int) $enrollment['rating'], 'review' => (string) ($enrollment['review'] ?? '')];
}

sc_json(200, [
    'status'   => 'ok',
    'course'   => [
        'id'            => (int) $course['id'],
        'title'         => (string) $course['title'],
        'description'   => (string) ($course['description'] ?? ''),
        'category'      => $course['category'] !== null ? (string) $course['category'] : null,
        'instructor'    => (string) $course['instructor'],
        'initials'      => sc_initials((string) $course['instructor']),
        'thumbnail_url' => sc_thumb($course['thumbnail']),
        'students'      => (int) $students->fetchColumn(),
    ],
    'enrolled' => $enrollment !== null,
    'completed' => $enrollment !== null && $enrollment['completed_at'] !== null,
    'progress' => [
        'done'    => $outline['done'],
        'total'   => $outline['total'],
        'percent' => sc_percent($outline['done'], $outline['total']),
        'next'    => $next,
    ],
    'modules'  => $outline['modules'],
    'quizzes'  => $quizzes,
    'assignments' => $assignments,
    'reviews'  => $reviews,
    'mine'     => $mine,
    'links'    => [
        'lesson'      => url('lesson/'),
        'quiz'        => url('quiz/'),
        'assignment'  => url('assignment/'),
        'certificate' => url('certificate.php'),
    ],
]);
