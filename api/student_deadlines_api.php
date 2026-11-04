<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student deadlines (feeds the calendar on deadlines.php)
|
|   GET -> { events: [ ... ] }
|
| One event per assignment due date and one per quiz closing time, for the
| published courses the student is enrolled in. A quiz with no closing time
| has no deadline, so it is not listed. Each event says whether the student
| has already dealt with it:
|
|   done     assignment submitted or marked; quiz attempted
|   overdue  the deadline has passed and nothing was handed in / attempted
|   open     still to do
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';

[$pdo, $user] = sc_api_start();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
$userId = (int) $user['id'];
$now = new DateTimeImmutable();
$events = [];

// ---- Assignments ----
$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.due_date, a.max_mark, c.id AS course_id, c.title AS course_title,
            s.submitted_at, s.mark
     FROM assignments a
     JOIN courses c ON c.id = a.course_id AND c.status = 'published'
     JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
     ORDER BY a.due_date ASC, a.id ASC LIMIT 500"
);
$stmt->execute([$userId, $userId]);
foreach ($stmt->fetchAll() as $row) {
    $due = new DateTimeImmutable((string) $row['due_date']);
    if ($row['mark'] !== null) {
        [$state, $label] = ['done', 'Marked · ' . (int) $row['mark'] . ' / ' . (int) $row['max_mark']];
    } elseif ($row['submitted_at'] !== null) {
        [$state, $label] = ['done', 'Submitted'];
    } elseif ($due < $now) {
        [$state, $label] = ['overdue', 'Overdue'];
    } else {
        [$state, $label] = ['open', 'Not submitted'];
    }
    $events[] = [
        'key'    => 'assignment-' . (int) $row['id'],
        'kind'   => 'assignment',
        'title'  => (string) $row['title'],
        'course' => ['id' => (int) $row['course_id'], 'title' => (string) $row['course_title']],
        'at'     => sc_iso((string) $row['due_date']),
        'state'  => $state,
        'label'  => $label,
        'detail' => (int) $row['max_mark'] . ' marks',
        'url'    => url('assignment/' . (int) $row['id']),
    ];
}

// ---- Quizzes with a closing time ----
$stmt = $pdo->prepare(
    "SELECT q.id, q.title, q.pass_mark, q.max_attempts, q.start_time, q.end_time,
            c.id AS course_id, c.title AS course_title,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?) AS used,
            (SELECT COALESCE(MAX(qa.passed), 0) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?) AS passed
     FROM quizzes q
     JOIN courses c ON c.id = q.course_id AND c.status = 'published'
     JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     WHERE q.end_time IS NOT NULL
     ORDER BY q.end_time ASC, q.id ASC LIMIT 500"
);
$stmt->execute([$userId, $userId, $userId]);
foreach ($stmt->fetchAll() as $row) {
    $end = new DateTimeImmutable((string) $row['end_time']);
    $used = (int) $row['used'];
    $left = max(0, (int) $row['max_attempts'] - $used);
    if ((int) $row['passed'] === 1) {
        [$state, $label] = ['done', 'Passed'];
    } elseif ($used > 0) {
        [$state, $label] = ['done', 'Attempted'];
    } elseif ($end < $now) {
        [$state, $label] = ['overdue', 'Missed'];
    } else {
        [$state, $label] = ['open', 'Not attempted'];
    }
    $opens = $row['start_time'] !== null ? new DateTimeImmutable((string) $row['start_time']) : null;
    $detail = 'Pass mark ' . (int) $row['pass_mark'] . '% · ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left';
    if ($opens !== null && $opens > $now) {
        $detail = 'Opens ' . $opens->format('d M, H:i') . ' · ' . $detail;
    }
    $events[] = [
        'key'    => 'quiz-' . (int) $row['id'],
        'kind'   => 'quiz',
        'title'  => (string) $row['title'],
        'course' => ['id' => (int) $row['course_id'], 'title' => (string) $row['course_title']],
        'at'     => sc_iso((string) $row['end_time']),
        'state'  => $state,
        'label'  => $label,
        'detail' => $detail,
        'url'    => url('quiz/' . (int) $row['id']),
    ];
}

usort($events, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']) ?: strcmp($a['key'], $b['key']));

sc_json(200, ['status' => 'ok', 'events' => $events]);
