<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student dashboard data   GET api/student_dashboard_api.php
|
| Returns everything the student dashboard shows as JSON: points and badges,
| the "Continue learning" card, the four stat cards, My Courses with progress,
| upcoming deadlines, recent grades and earned badges.
|
| Only a signed-in student gets data, and only about themselves.
| The progress rules are plain functions (student_dashboard_api_*) so they can
| be tested without a database; the endpoint itself runs at the bottom.
|--------------------------------------------------------------------------
*/

/** Answer with JSON and stop. */
function student_dashboard_api_json(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Same-site requests only (the same check profile_api.php makes). */
function student_dashboard_api_validate_origin(): void
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        student_dashboard_api_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($originHost) || strcasecmp($originHost, (string) parse_url('//' . $requestHost, PHP_URL_HOST)) !== 0) {
            student_dashboard_api_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
        }
    }
}

/** The badges the platform knows (same keys as api/profile_api.php). */
function student_dashboard_api_badge_catalog(): array
{
    return [
        'first_lesson'     => ['name' => 'First Steps',       'icon' => '📘', 'hint' => 'First lesson completed'],
        'quick_learner'    => ['name' => 'Quick Learner',     'icon' => '⚡', 'hint' => 'Five lessons completed'],
        'quiz_master'      => ['name' => 'Quiz Master',       'icon' => '🧠', 'hint' => 'Three quizzes passed'],
        'course_completer' => ['name' => 'Course Completer', 'icon' => '🎓', 'hint' => 'Course completed'],
        'rising_star'      => ['name' => 'Rising Star',       'icon' => '🌟', 'hint' => '100 points earned'],
    ];
}

/** Percentage rounded to one decimal, or null when there is nothing to divide by. */
function student_dashboard_api_percent(float|int $part, float|int $whole): ?float
{
    return $whole > 0 ? round($part / $whole * 100, 1) : null;
}

/**
 * Work out progress for every enrolled course and pick the "Continue learning" lesson.
 *
 * @param array<int,array<string,mixed>> $courses enrolled courses (id, title, last_lesson_id, completed_at, enrolled_at, ...)
 * @param array<int,array<int,array{id:int,title:string}>> $lessonsByCourse visible lessons, already in teaching order
 * @param array<int,string> $doneAt lesson id => completed_at
 * @return array{courses:array<int,array<string,mixed>>,continue:?array<string,mixed>}
 */
function student_dashboard_api_progress(array $courses, array $lessonsByCourse, array $doneAt): array
{
    $result = [];

    foreach ($courses as $course) {
        $courseId = (int) $course['id'];
        $lessons = $lessonsByCourse[$courseId] ?? [];
        $total = count($lessons);

        $done = 0;
        $next = null;
        $lastActivity = null;
        foreach ($lessons as $lesson) {
            if (isset($doneAt[$lesson['id']])) {
                $done++;
                if ($lastActivity === null || $doneAt[$lesson['id']] > $lastActivity) {
                    $lastActivity = $doneAt[$lesson['id']];
                }
            } elseif ($next === null) {
                $next = $lesson;
            }
        }

        $completed = $course['completed_at'] !== null || ($total > 0 && $done >= $total);

        // The lesson to resume: the one the student last opened if it is not finished, else the next unfinished one.
        $resume = $next;
        foreach ($lessons as $lesson) {
            if ((int) $lesson['id'] === (int) ($course['last_lesson_id'] ?? 0) && !isset($doneAt[$lesson['id']])) {
                $resume = $lesson;
            }
        }

        $result[] = [
            'id'            => $courseId,
            'title'         => (string) $course['title'],
            'category'      => $course['category'] ?? null,
            'instructor'    => (string) ($course['instructor'] ?? ''),
            'thumbnail'     => $course['thumbnail'] ?? null,
            'lessons_total' => $total,
            'lessons_done'  => $done,
            'progress'      => $total > 0 ? (int) min(100, round($done / $total * 100)) : 0,
            'completed'     => $completed,
            'next_lesson'   => $resume ?? ($lessons[0] ?? null),
            'activity'      => $lastActivity ?? (string) ($course['enrolled_at'] ?? ''),
        ];
    }

    // Unfinished courses first, the most recently used at the top.
    usort($result, static function (array $a, array $b): int {
        return [$a['completed'], $b['activity'], $a['id']] <=> [$b['completed'], $a['activity'], $b['id']];
    });

    $continue = null;
    foreach ($result as $course) {
        if (!$course['completed'] && $course['next_lesson'] !== null) {
            $continue = $course;
            break;
        }
    }

    return ['courses' => $result, 'continue' => $continue];
}

/** Everything the dashboard needs for one student. */
function student_dashboard_api_load(PDO $pdo, int $userId): array
{
    // ---- The student ----
    $stmt = $pdo->prepare("SELECT name, points FROM users WHERE id = :id AND role = 'student' AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        student_dashboard_api_json(403, ['status' => 'error', 'message' => 'This dashboard is for student accounts.']);
    }

    // ---- Enrolled (published) courses ----
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, c.thumbnail, cat.name AS category, u.name AS instructor,
                e.last_lesson_id, e.completed_at, e.enrolled_at
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id AND c.status = 'published'
         JOIN users u ON u.id = c.instructor_id
         LEFT JOIN categories cat ON cat.id = c.category_id
         WHERE e.user_id = :user"
    );
    $stmt->execute(['user' => $userId]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Lessons students can see (a lesson in a draft module is hidden), in teaching order ----
    $stmt = $pdo->prepare(
        "SELECT l.id, l.course_id, l.title
         FROM lessons l
         JOIN enrollments e ON e.course_id = l.course_id AND e.user_id = :user
         JOIN courses c ON c.id = l.course_id AND c.status = 'published'
         LEFT JOIN modules m ON m.id = l.module_id
         WHERE l.module_id IS NULL OR m.status = 'published'
         ORDER BY l.course_id ASC, COALESCE(m.module_order, 0) ASC, l.position ASC, l.id ASC"
    );
    $stmt->execute(['user' => $userId]);
    $lessonsByCourse = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lessonsByCourse[(int) $row['course_id']][] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }

    $stmt = $pdo->prepare('SELECT lesson_id, completed_at FROM lesson_progress WHERE user_id = :user');
    $stmt->execute(['user' => $userId]);
    $doneAt = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $doneAt[(int) $row['lesson_id']] = (string) $row['completed_at'];
    }

    $progress = student_dashboard_api_progress($courses, $lessonsByCourse, $doneAt);

    // ---- Average quiz score: the best attempt of each quiz, averaged ----
    $stmt = $pdo->prepare(
        "SELECT MAX(qa.score / NULLIF(qa.total_marks, 0)) * 100 AS best
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         JOIN courses c ON c.id = q.course_id AND c.status = 'published'
         WHERE qa.user_id = :user
         GROUP BY qa.quiz_id"
    );
    $stmt->execute(['user' => $userId]);
    $bests = array_filter(
        array_map(static fn ($v) => $v === null ? null : (float) $v, $stmt->fetchAll(PDO::FETCH_COLUMN)),
        static fn ($v) => $v !== null
    );
    $averageQuiz = $bests ? round(array_sum($bests) / count($bests), 1) : null;

    // ---- Assignments not handed in yet (due within the last 30 days or later) ----
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.due_date, c.title AS course,
                TIMESTAMPDIFF(SECOND, NOW(), a.due_date) AS seconds_left
         FROM assignments a
         JOIN courses c ON c.id = a.course_id AND c.status = 'published'
         JOIN enrollments e ON e.course_id = c.id AND e.user_id = :user
         LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = :student
         WHERE s.id IS NULL AND a.due_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY a.due_date ASC, a.id ASC"
    );
    $stmt->execute(['user' => $userId, 'student' => $userId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Recent grades: marked assignments and quiz attempts, newest first ----
    $stmt = $pdo->prepare(
        'SELECT a.title, c.title AS course, s.mark AS score, a.max_mark AS total,
                COALESCE(s.graded_at, s.submitted_at) AS at_time
         FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN courses c ON c.id = a.course_id
         WHERE s.student_id = :user AND s.mark IS NOT NULL
         ORDER BY COALESCE(s.graded_at, s.submitted_at) DESC, s.id DESC
         LIMIT 5'
    );
    $stmt->execute(['user' => $userId]);
    $grades = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grades[] = ['kind' => 'assignment', 'passed' => null] + $row;
    }

    $stmt = $pdo->prepare(
        'SELECT q.title, c.title AS course, qa.score, qa.total_marks AS total, qa.passed,
                qa.attempted_at AS at_time
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         JOIN courses c ON c.id = q.course_id
         WHERE qa.user_id = :user
         ORDER BY qa.attempted_at DESC, qa.id DESC
         LIMIT 5'
    );
    $stmt->execute(['user' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grades[] = ['kind' => 'quiz'] + $row;
    }
    usort($grades, static fn (array $a, array $b): int => strcmp((string) $b['at_time'], (string) $a['at_time']));
    $grades = array_slice($grades, 0, 5);

    // ---- Badges ----
    $stmt = $pdo->prepare('SELECT badge_key FROM user_badges WHERE user_id = :user ORDER BY earned_at DESC, id DESC');
    $stmt->execute(['user' => $userId]);
    $earnedKeys = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return [
        'student'  => $student,
        'progress' => $progress,
        'average'  => $averageQuiz,
        'pending'  => $pending,
        'grades'   => $grades,
        'badges'   => $earnedKeys,
    ];
}

/** Shape the loaded data into the JSON the page expects (with links and clean types). */
function student_dashboard_api_payload(array $data): array
{
    $lessonUrl = static fn (?int $id): ?string => $id ? url('lesson.php?id=' . $id) : null;

    $courses = [];
    foreach ($data['progress']['courses'] as $course) {
        $thumb = $course['thumbnail'];
        $courses[] = [
            'id'            => $course['id'],
            'title'         => $course['title'],
            'category'      => $course['category'],
            'instructor'    => $course['instructor'],
            'thumbnail_url' => is_string($thumb) && str_starts_with($thumb, 'uploads/thumbnails/') ? url($thumb) : null,
            'lessons_total' => $course['lessons_total'],
            'lessons_done'  => $course['lessons_done'],
            'progress'      => $course['progress'],
            'completed'     => $course['completed'],
            'url'           => $lessonUrl($course['next_lesson']['id'] ?? null),
        ];
    }

    $continue = null;
    if ($data['progress']['continue'] !== null) {
        $c = $data['progress']['continue'];
        $continue = [
            'lesson_title'  => $c['next_lesson']['title'],
            'course_title'  => $c['title'],
            'progress'      => $c['progress'],
            'lessons_done'  => $c['lessons_done'],
            'lessons_total' => $c['lessons_total'],
            'started'       => $c['lessons_done'] > 0,
            'url'           => $lessonUrl($c['next_lesson']['id']),
        ];
    }

    $deadlines = [];
    foreach (array_slice($data['pending'], 0, 6) as $row) {
        $deadlines[] = [
            'title'        => (string) $row['title'],
            'course'       => (string) $row['course'],
            'due_at'       => (new DateTimeImmutable((string) $row['due_date']))->format('c'),
            'seconds_left' => (int) $row['seconds_left'],
            'url'          => url('assignment.php?id=' . (int) $row['id']),
        ];
    }

    $grades = [];
    foreach ($data['grades'] as $row) {
        $grades[] = [
            'kind'    => (string) $row['kind'],
            'title'   => (string) $row['title'],
            'course'  => (string) $row['course'],
            'score'   => (int) $row['score'],
            'total'   => (int) $row['total'],
            'percent' => student_dashboard_api_percent((int) $row['score'], (int) $row['total']),
            'passed'  => $row['passed'] === null ? null : (bool) (int) $row['passed'],
            'at'      => (new DateTimeImmutable((string) $row['at_time']))->format('c'),
        ];
    }

    $catalog = student_dashboard_api_badge_catalog();
    $badges = [];
    foreach ($data['badges'] as $key) {
        $known = $catalog[$key] ?? null;
        $badges[] = [
            'key'  => $key,
            'name' => $known['name'] ?? ucwords(str_replace('_', ' ', $key)),
            'icon' => $known['icon'] ?? '🏅',
            'hint' => $known['hint'] ?? 'Badge earned',
        ];
    }

    return [
        'status'   => 'ok',
        'student'  => [
            'name'        => (string) $data['student']['name'],
            'points'      => (int) $data['student']['points'],
            'badge_count' => count($badges),
        ],
        'continue' => $continue,
        'stats'    => [
            'enrolled'            => count($courses),
            'completed'           => count(array_filter($courses, static fn (array $c): bool => $c['completed'])),
            'average_quiz_score'  => $data['average'],
            'pending_assignments' => count($data['pending']),
        ],
        'courses'   => $courses,
        'deadlines' => $deadlines,
        'grades'    => $grades,
        'badges'    => $badges,
        'links'     => ['grades' => url('grades.php'), 'profile' => url('profile.php')],
    ];
}

/* ==========================================================================
   The endpoint: only runs when this file is requested directly
   ========================================================================== */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    require_once __DIR__ . '/../includes/config.php';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        student_dashboard_api_json(405, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }

    student_dashboard_api_validate_origin();

    $user = currentUser();
    if ($user === null) {
        student_dashboard_api_json(401, ['status' => 'error', 'message' => 'Please sign in again to see your dashboard.']);
    }

    try {
        student_dashboard_api_json(200, student_dashboard_api_payload(student_dashboard_api_load(db(), (int) $user['id'])));
    } catch (Throwable $error) {
        error_log('Student dashboard API failed: ' . $error->getMessage());
        student_dashboard_api_json(500, ['status' => 'error', 'message' => 'Your dashboard could not be loaded. Please try again.']);
    }
}
