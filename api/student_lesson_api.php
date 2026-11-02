<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student lesson viewer
|
|   GET  ?id=LESSON -> lesson, course outline, previous/next, comments
|   POST action=complete   (lesson_id, done=1|0, csrf_token)
|   POST action=comment    (lesson_id, body, csrf_token)
|   POST action=delete_comment (lesson_id, comment_id, csrf_token)
|
| Only a student enrolled in the (published) course can open its lessons.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';

const SC_COMMENT_MAX = 500;

[$pdo, $user] = sc_api_start();
$userId = (int) $user['id'];
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($post) {
    sc_require_csrf();
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$lessonId = filter_var($post ? ($_POST['lesson_id'] ?? null) : ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($lessonId === false) {
    sc_json(422, ['status' => 'error', 'message' => 'A valid lesson is required.']);
}

$stmt = $pdo->prepare(
    "SELECT l.*, c.title AS course_title, u.name AS instructor
     FROM lessons l
     JOIN courses c ON c.id = l.course_id AND c.status = 'published'
     JOIN users u ON u.id = c.instructor_id
     LEFT JOIN modules m ON m.id = l.module_id
     WHERE l.id = :id AND (l.module_id IS NULL OR m.status = 'published') LIMIT 1"
);
$stmt->execute([':id' => $lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) {
    sc_json(404, ['status' => 'error', 'message' => 'That lesson is not available.']);
}
$courseId = (int) $lesson['course_id'];

$enrollment = sc_enrollment($pdo, $courseId, $userId);
if ($enrollment === null) {
    sc_json(403, [
        'status' => 'error', 'code' => 'not_enrolled',
        'message' => 'Enrol in this course to open its lessons.',
        'redirect' => url('course/' . $courseId),
    ]);
}

function sc_comments(PDO $pdo, int $lessonId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.parent_id, cm.user_id, cm.body, cm.created_at, u.name, u.role
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.lesson_id = :l ORDER BY cm.created_at DESC, cm.id DESC LIMIT 200'
    );
    $stmt->execute([':l' => $lessonId]);
    $children = [];
    $top = [];
    foreach ($stmt->fetchAll() as $row) {
        $comment = [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'initials' => sc_initials((string) $row['name']),
            'role'     => (string) $row['role'],
            'body'     => (string) $row['body'],
            'date'     => sc_iso($row['created_at']),
            'mine'     => (int) $row['user_id'] === $userId,
        ];
        if ($row['parent_id'] === null) {
            $top[] = $comment;
        } else {
            $children[(int) $row['parent_id']][] = $comment;
        }
    }
    // Newest comment first; replies underneath, oldest first.
    foreach ($top as &$comment) {
        $comment['replies'] = array_reverse($children[$comment['id']] ?? []);
    }
    unset($comment);
    return $top;
}

/** Keep enrollments.completed_at in step with the lessons the student has finished. */
function sc_sync_completion(PDO $pdo, int $courseId, int $userId): array
{
    $outline = sc_outline($pdo, $courseId, $userId);
    $complete = $outline['total'] > 0 && $outline['done'] >= $outline['total'];
    $pdo->prepare(
        $complete
            ? 'UPDATE enrollments SET completed_at = COALESCE(completed_at, NOW()) WHERE course_id = :c AND user_id = :u'
            : 'UPDATE enrollments SET completed_at = NULL WHERE course_id = :c AND user_id = :u'
    )->execute([':c' => $courseId, ':u' => $userId]);
    return $outline;
}

/* ----------------------------------------------------------------- POST */
if ($post) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'complete') {
        $done = ($_POST['done'] ?? '1') === '1';
        if ($done) {
            $pdo->prepare(
                'INSERT INTO lesson_progress (user_id, lesson_id)
                 SELECT :u, :l FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM lesson_progress WHERE user_id = :u2 AND lesson_id = :l2)'
            )->execute([':u' => $userId, ':l' => $lessonId, ':u2' => $userId, ':l2' => $lessonId]);
        } else {
            $pdo->prepare('DELETE FROM lesson_progress WHERE user_id = :u AND lesson_id = :l')
                ->execute([':u' => $userId, ':l' => $lessonId]);
        }
        $outline = sc_sync_completion($pdo, $courseId, $userId);
        sc_json(200, [
            'status'   => 'ok',
            'done'     => $done,
            'progress' => ['done' => $outline['done'], 'total' => $outline['total'], 'percent' => sc_percent($outline['done'], $outline['total'])],
            'lessons'  => array_map(static fn (array $l): array => ['id' => $l['id'], 'done' => $l['done']], $outline['flat']),
            'finished' => $outline['total'] > 0 && $outline['done'] >= $outline['total'],
        ]);
    }

    if ($action === 'comment') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body, 'UTF-8') > SC_COMMENT_MAX) {
            sc_json(422, ['status' => 'error', 'message' => 'Write a comment of 1 to ' . SC_COMMENT_MAX . ' characters.']);
        }
        $pdo->prepare('INSERT INTO comments (lesson_id, user_id, body) VALUES (:l, :u, :b)')
            ->execute([':l' => $lessonId, ':u' => $userId, ':b' => $body]);
        sc_json(200, ['status' => 'ok', 'comments' => sc_comments($pdo, $lessonId, $userId)]);
    }

    if ($action === 'delete_comment') {
        $commentId = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($commentId === false) {
            sc_json(422, ['status' => 'error', 'message' => 'A valid comment is required.']);
        }
        // Only your own comment, on this lesson; replies to it go with it.
        $stmt = $pdo->prepare('DELETE FROM comments WHERE id = :id AND user_id = :u AND lesson_id = :l');
        $stmt->execute([':id' => $commentId, ':u' => $userId, ':l' => $lessonId]);
        if ($stmt->rowCount() > 0) {
            $pdo->prepare('DELETE FROM comments WHERE parent_id = :id AND lesson_id = :l')
                ->execute([':id' => $commentId, ':l' => $lessonId]);
        }
        sc_json(200, ['status' => 'ok', 'comments' => sc_comments($pdo, $lessonId, $userId)]);
    }

    sc_json(422, ['status' => 'error', 'message' => 'Unknown action.']);
}

/* ------------------------------------------------------------------ GET */
$outline = sc_outline($pdo, $courseId, $userId);
$flat = $outline['flat'];
$position = 0;
foreach ($flat as $i => $item) {
    if ($item['id'] === (int) $lessonId) {
        $position = $i;
    }
}
$current = $flat[$position];

// Remember where the student is, so "Continue" resumes here.
$pdo->prepare('UPDATE enrollments SET last_lesson_id = :l WHERE course_id = :c AND user_id = :u')
    ->execute([':l' => $lessonId, ':c' => $courseId, ':u' => $userId]);

$pdf = sc_lesson_file($lesson['pdf_path'] ?: $lesson['file_path']);
$ppt = sc_lesson_file($lesson['powerpoint_path']);
$neighbour = static fn (?array $l): ?array => $l ? ['id' => $l['id'], 'index' => $l['index'], 'title' => $l['title']] : null;

sc_json(200, [
    'status'  => 'ok',
    'lesson'  => [
        'id'      => (int) $lesson['id'],
        'title'   => (string) $lesson['title'],
        'type'    => $current['type'],
        'content' => (string) ($lesson['content'] ?? ''),
        'video'   => sc_video($lesson['video_url']),
        'pdf_url' => $pdf,
        'ppt_url' => $ppt,
        'index'   => $current['index'],
        'done'    => $current['done'],
    ],
    'course'  => [
        'id'         => $courseId,
        'title'      => (string) $lesson['course_title'],
        'instructor' => (string) $lesson['instructor'],
        'url'        => url('course/' . $courseId),
    ],
    'progress' => ['done' => $outline['done'], 'total' => $outline['total'], 'percent' => sc_percent($outline['done'], $outline['total'])],
    'outline'  => $flat,
    'prev'     => $neighbour($flat[$position - 1] ?? null),
    'next'     => $neighbour($flat[$position + 1] ?? null),
    'comments' => sc_comments($pdo, (int) $lessonId, $userId),
    'links'    => ['lesson' => url('lesson/')],
]);
