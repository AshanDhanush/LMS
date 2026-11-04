<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student gradebook: every assignment mark and quiz result, by course.
|
|   GET -> { courses: [ { id, title, url, summary, items: [...] } ], summary }
|
| The same rules as the instructor gradebook:
|   - an assignment counts once the instructor has marked it (mark / max mark)
|   - a quiz counts with the student's BEST attempt (highest percentage)
|   - the average is marks earned / marks available across that marked work,
|     so a big assignment weighs more than a small quiz question
| Work that has not been marked or attempted yet is listed but not counted.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';
require_once __DIR__ . '/api_quiz_service.php';

[$pdo, $user] = sc_api_start();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
$userId = (int) $user['id'];

/** @return array{earned:int,possible:int,percent:?int} */
function sg_total(int $earned, int $possible): array
{
    return ['earned' => $earned, 'possible' => $possible, 'percent' => $possible > 0 ? (int) round($earned * 100 / $possible) : null];
}

$stmt = $pdo->prepare(
    "SELECT c.id, c.title
     FROM enrollments e JOIN courses c ON c.id = e.course_id AND c.status = 'published'
     WHERE e.user_id = :u ORDER BY c.title ASC, c.id ASC"
);
$stmt->execute([':u' => $userId]);
$courses = [];
foreach ($stmt->fetchAll() as $row) {
    $courses[(int) $row['id']] = [
        'id' => (int) $row['id'], 'title' => (string) $row['title'], 'url' => url('course/' . (int) $row['id']),
        'items' => [], 'earned' => 0, 'possible' => 0, 'marked' => 0,
        'assignments' => 0, 'assignments_marked' => 0, 'quizzes' => 0, 'quizzes_passed' => 0, 'quizzes_attempted' => 0,
    ];
}

if ($courses) {
    $ids = array_keys($courses);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $now = new DateTimeImmutable();

    // ---- Assignments ----
    $stmt = $pdo->prepare(
        "SELECT a.id, a.course_id, a.title, a.due_date, a.max_mark,
                s.submitted_at, s.mark, s.feedback, s.graded_at
         FROM assignments a
         LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
         WHERE a.course_id IN ($in) ORDER BY a.due_date ASC, a.id ASC"
    );
    $stmt->execute(array_merge([$userId], $ids));
    foreach ($stmt->fetchAll() as $row) {
        $cid = (int) $row['course_id'];
        $due = new DateTimeImmutable((string) $row['due_date']);
        $max = (int) $row['max_mark'];
        $marked = $row['mark'] !== null;
        $state = $marked ? 'graded' : ($row['submitted_at'] !== null ? 'submitted' : ($due < $now ? 'overdue' : 'open'));
        $courses[$cid]['assignments']++;
        if ($marked) {
            $courses[$cid]['assignments_marked']++;
            $courses[$cid]['marked']++;
            $courses[$cid]['earned'] += (int) $row['mark'];
            $courses[$cid]['possible'] += $max;
        }
        $courses[$cid]['items'][] = [
            'kind' => 'assignment', 'id' => (int) $row['id'], 'title' => (string) $row['title'],
            'url' => url('assignment/' . (int) $row['id']),
            'due' => sc_iso((string) $row['due_date']), 'state' => $state,
            'score' => $marked ? (int) $row['mark'] : null, 'total' => $max,
            'percent' => $marked ? sc_percent((int) $row['mark'], $max) : null,
            'date' => sc_iso($marked ? $row['graded_at'] : $row['submitted_at']),
            'feedback' => $marked && $row['feedback'] !== null && $row['feedback'] !== '' ? (string) $row['feedback'] : null,
        ];
    }

    // ---- Quizzes: best attempt per quiz ----
    $stmt = $pdo->prepare(
        "SELECT q.id, q.course_id, q.title, q.pass_mark, q.max_attempts, q.start_time, q.end_time
         FROM quizzes q WHERE q.course_id IN ($in) ORDER BY q.id ASC"
    );
    $stmt->execute($ids);
    $quizzes = $stmt->fetchAll();

    $best = [];
    $attempts = [];
    if ($quizzes) {
        $quizIds = array_map(static fn (array $q): int => (int) $q['id'], $quizzes);
        $qin = implode(',', array_fill(0, count($quizIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, quiz_id, score, total_marks, passed, attempted_at FROM quiz_attempts
             WHERE user_id = ? AND quiz_id IN ($qin) ORDER BY id ASC"
        );
        $stmt->execute(array_merge([$userId], $quizIds));
        foreach ($stmt->fetchAll() as $a) {
            $qid = (int) $a['quiz_id'];
            $attempts[$qid] = ($attempts[$qid] ?? 0) + 1;
            $percent = sc_percent((int) $a['score'], (int) $a['total_marks']);
            // Highest percentage wins; a later attempt wins a tie (same rule as the instructor gradebook).
            if (!isset($best[$qid]) || $percent >= $best[$qid]['percent']) {
                $best[$qid] = [
                    'score' => (int) $a['score'], 'total' => (int) $a['total_marks'], 'percent' => $percent,
                    'passed' => (bool) (int) $a['passed'], 'date' => sc_iso($a['attempted_at']),
                ];
            }
        }
    }
    foreach ($quizzes as $q) {
        $cid = (int) $q['course_id'];
        $qid = (int) $q['id'];
        $b = $best[$qid] ?? null;
        $courses[$cid]['quizzes']++;
        if ($b !== null) {
            $courses[$cid]['quizzes_attempted']++;
            if ($b['passed']) {
                $courses[$cid]['quizzes_passed']++;
            }
            if ($b['total'] > 0) {
                $courses[$cid]['marked']++;
                $courses[$cid]['earned'] += $b['score'];
                $courses[$cid]['possible'] += $b['total'];
            }
        }
        $courses[$cid]['items'][] = [
            'kind' => 'quiz', 'id' => $qid, 'title' => (string) $q['title'], 'url' => url('quiz/' . $qid),
            'state' => $b === null ? 'not_attempted' : ($b['passed'] ? 'passed' : 'failed'),
            'availability' => quizAvailability($q['start_time'], $q['end_time']),
            'score' => $b['score'] ?? null, 'total' => $b['total'] ?? null, 'percent' => $b['percent'] ?? null,
            'date' => $b['date'] ?? null, 'pass_mark' => (int) $q['pass_mark'],
            'attempts' => $attempts[$qid] ?? 0, 'max_attempts' => (int) $q['max_attempts'],
        ];
    }
}

// ---- Totals ----
$all = ['earned' => 0, 'possible' => 0, 'assignments' => 0, 'assignments_marked' => 0, 'quizzes' => 0, 'quizzes_passed' => 0, 'quizzes_attempted' => 0];
$out = [];
foreach ($courses as $c) {
    foreach (array_keys($all) as $key) {
        $all[$key] += $c[$key];
    }
    $out[] = [
        'id' => $c['id'], 'title' => $c['title'], 'url' => $c['url'], 'items' => $c['items'],
        'summary' => sg_total($c['earned'], $c['possible']) + [
            'marked' => $c['marked'], 'total_items' => count($c['items']),
            'assignments' => $c['assignments'], 'assignments_marked' => $c['assignments_marked'],
            'quizzes' => $c['quizzes'], 'quizzes_passed' => $c['quizzes_passed'], 'quizzes_attempted' => $c['quizzes_attempted'],
        ],
    ];
}
$points = $pdo->prepare('SELECT points FROM users WHERE id = :u');
$points->execute([':u' => $userId]);

sc_json(200, [
    'status'  => 'ok',
    'summary' => sg_total($all['earned'], $all['possible']) + $all + ['points' => (int) $points->fetchColumn()],
    'courses' => $out,
]);
