<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';

/*
|--------------------------------------------------------------------------
| Submissions of one assignment: who handed in, who is still waiting for a
| mark, and who has not submitted. Each row links to the grading page.
|--------------------------------------------------------------------------
*/

$user = assessmentRequireInstructor();
$pdo  = db();

$assignmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$assignmentId) {
    http_response_code(400);
    exit('Invalid assignment ID.');
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.due_date, a.max_mark, a.course_id, c.title AS course_title
     FROM assignments a
     JOIN courses c ON c.id = a.course_id
     WHERE a.id = :id AND c.instructor_id = :instructor LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'instructor' => (int) $user['id']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$assignment) {
    http_response_code(404);
    exit('Assignment not found or you do not own it.');
}
$courseId = (int) $assignment['course_id'];
$maxMark  = (int) $assignment['max_mark'];
$due      = new DateTimeImmutable((string) $assignment['due_date']);

// Every enrolled student, plus anyone who submitted and has since left the course.
$stmt = $pdo->prepare(
    'SELECT u.id AS student_id, u.name, u.email,
            s.id AS submission_id, s.submitted_at, s.mark, s.graded_at
     FROM users u
     LEFT JOIN enrollments e ON e.user_id = u.id AND e.course_id = :course
     LEFT JOIN submissions s ON s.assignment_id = :assignment AND s.student_id = u.id
     WHERE u.role = \'student\' AND (e.id IS NOT NULL OR s.id IS NOT NULL)
     ORDER BY (s.id IS NULL) ASC, (s.mark IS NOT NULL) ASC, s.submitted_at ASC, u.name ASC'
);
$stmt->execute(['course' => $courseId, 'assignment' => $assignmentId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = ['all' => count($rows), 'waiting' => 0, 'graded' => 0, 'missing' => 0];
foreach ($rows as &$row) {
    if ($row['submission_id'] === null) {
        $row['state'] = 'missing';
    } elseif ($row['mark'] === null) {
        $row['state'] = 'waiting';
    } else {
        $row['state'] = 'graded';
    }
    $counts[$row['state']]++;
    $row['late'] = $row['submitted_at'] !== null && new DateTimeImmutable((string) $row['submitted_at']) > $due;
}
unset($row);

$filter = (string) ($_GET['filter'] ?? 'all');
if (!isset($counts[$filter])) {
    $filter = 'all';
}
$shown = $filter === 'all' ? $rows : array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === $filter));

$e = static fn (mixed $v): string => assessmentFormEscape($v);
$listUrl   = url('instructor/assignment-grading/list/' . $courseId . '?type=assignment');
$selfUrl   = static fn (string $f): string => url('instructor/assignment-grading/submissions/' . (int) $_GET['id'] . ($f === 'all' ? '' : '?filter=' . $f));
$initials  = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1, 'UTF-8') . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1, 'UTF-8') : ''), 'UTF-8');
};
$tabs = ['all' => 'All students', 'waiting' => 'Waiting for a mark', 'graded' => 'Marked', 'missing' => 'Not submitted'];
$flashGraded = isset($_GET['graded']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submissions | <?= $e($assignment['title']) ?> | Learning Hub</title>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Assignments & grading'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-main ins-curriculum" id="main">

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <a class="ins-lesson-back" href="<?= $e($listUrl) ?>">&larr; Back to assignments</a>
            <h1><?= $e($assignment['title']) ?></h1>
            <p><?= $e($assignment['course_title']) ?> · Due <?= $e(assessmentFormatDate((string) $assignment['due_date'])) ?> · Maximum mark <?= $maxMark ?></p>
        </div>
    </header>

    <?php if ($flashGraded): ?>
        <div class="ins-alert ins-course-notice ins-course-notice--success" role="status">Mark saved. The student can see it now.</div>
    <?php endif; ?>

    <nav class="ins-assess-tabs" aria-label="Filter submissions">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="ins-assess-tab <?= $filter === $key ? 'is-current' : '' ?>" href="<?= $e($selfUrl($key)) ?>"
               <?= $filter === $key ? 'aria-current="page"' : '' ?>><?= $e($label) ?> <span><?= (int) $counts[$key] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$shown): ?>
        <div class="ins-curr-empty-state">
            <h2><?= $counts['all'] === 0 ? 'No students yet' : 'Nothing here' ?></h2>
            <p><?= $counts['all'] === 0 ? 'Nobody is enrolled in this course yet.' : 'No students match this filter.' ?></p>
        </div>
    <?php else: ?>
        <section class="ins-card">
            <div class="ins-table-wrap">
                <table class="ins-table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col">Mark</th>
                            <th scope="col"><span class="ins-visually-hidden">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shown as $row): ?>
                        <tr>
                            <td>
                                <span class="ins-person">
                                    <span class="ins-person__avatar" aria-hidden="true"><?= $e($initials((string) $row['name'])) ?></span>
                                    <span class="ins-person__text">
                                        <span class="ins-person__name"><?= $e($row['name']) ?></span>
                                        <span class="ins-person__sub"><?= $e($row['email']) ?></span>
                                    </span>
                                </span>
                            </td>
                            <td class="ins-table__time">
                                <?php if ($row['submitted_at'] === null): ?>
                                    <span>—</span>
                                <?php else: ?>
                                    <span><?= $e(assessmentFormatDate((string) $row['submitted_at'])) ?></span>
                                    <?php if ($row['late']): ?><span class="ins-chip ins-chip--warning">Late</span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['state'] === 'graded'): ?>
                                    <span class="ins-chip ins-chip--success">Marked</span>
                                <?php elseif ($row['state'] === 'waiting'): ?>
                                    <span class="ins-chip ins-chip--primary">Waiting for a mark</span>
                                <?php else: ?>
                                    <span class="ins-chip ins-chip--muted">Not submitted</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $row['mark'] !== null ? $e((int) $row['mark'] . ' / ' . $maxMark) : '—' ?></td>
                            <td>
                                <?php if ($row['submission_id'] !== null): ?>
                                    <a class="ins-btn ins-btn--small <?= $row['state'] === 'waiting' ? 'ins-btn--primary' : '' ?>"
                                       href="<?= $e(url('instructor/assignment-grading/grade/' . (int) $row['submission_id'])) ?>"
                                       aria-label="<?= $e(($row['state'] === 'waiting' ? 'Mark' : 'Review') . ' ' . $row['name']) ?>"><?= $row['state'] === 'waiting' ? 'Mark' : 'Review' ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

</main>

    </div>
</div>

<script src="<?= $e(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
