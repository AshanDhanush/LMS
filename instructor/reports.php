<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/assessment_common.php';
require_once __DIR__ . '/../includes/report_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$reportKeys = [
    'progress'  => 'Progress & grades',
    'gradebook' => 'Gradebook',
    'students'  => 'Student list',
    'quizzes'   => 'Quiz results',
    'awaiting'  => 'Awaiting grading',
];
$report = (string) ($_GET['report'] ?? 'progress');
if (!isset($reportKeys[$report])) {
    $report = 'progress';
}

$courses = reportCourses($pdo, $instructorId);

// Use the chosen course, otherwise the first one.
$courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$course = null;
foreach ($courses as $candidate) {
    if ($courseId && (int) $candidate['id'] === (int) $courseId) {
        $course = $candidate;
    }
}
if ($course === null && $courses && $report !== 'awaiting') {
    $course = $courses[0];
}
$courseId = $course ? (int) $course['id'] : 0;

$reportUrl = static fn (string $key, int $cid): string => url(
    'instructor/reports.php?report=' . $key . ($cid ? '&course_id=' . $cid : '')
);
$exportUrl = static fn (string $key, int $cid): string => url(
    'instructor/export-csv.php?report=' . $key . '&course_id=' . $cid
);
$e = static fn (mixed $v): string => assessmentFormEscape($v);
$pct = static fn ($v): string => $v === null ? '–' : number_format((float) $v, 1) . '%';

$students = $gradebook = $quizResults = $awaiting = [];
$quizFilter = null;

if ($courseId) {
    if (in_array($report, ['progress', 'students'], true)) {
        $students = reportStudents($pdo, $courseId);
    } elseif ($report === 'gradebook') {
        $gradebook = reportGradebook($pdo, $courseId);
    } elseif ($report === 'quizzes') {
        $quizFilter = filter_var($_GET['quiz_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $quizResults = reportQuizResults($pdo, $courseId, $quizFilter);
    }
}
if ($report === 'awaiting') {
    $awaiting = reportAwaitingGrading($pdo, $instructorId, $courseId ?: null);
}

// Summary tiles for the progress view.
$avgProgress = null;
if ($students) {
    $known = array_filter(array_column($students, 'progress'), static fn ($p) => $p !== null);
    $avgProgress = $known ? round(array_sum($known) / count($known), 1) : null;
}

$pageTitle = 'Analytics & reports';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics &amp; reports | Learning Hub</title>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Analytics & reports'; require __DIR__ . '/header.php'; ?>

<main class="ins-main ins-curriculum ins-reports" id="main">

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Analytics &amp; reports</h1>
            <p>See how your students are doing, and download the data as CSV.</p>
        </div>
    </header>

    <?php if (!$courses): ?>
        <div class="ins-curr-empty-state">
            <h2>No courses yet</h2>
            <p>Create a course and enrol students to see reports here.</p>
        </div>
    <?php else: ?>

        <form method="get" class="ins-report-picker">
            <input type="hidden" name="report" value="<?= $e($report) ?>">
            <label for="reportCourse">Course</label>
            <select id="reportCourse" name="course_id">
                <?php if ($report === 'awaiting'): ?>
                    <option value="">All my courses</option>
                <?php endif; ?>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>>
                        <?= $e($c['title']) ?> (<?= (int) $c['students'] ?> students)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ins-btn">Show</button>
        </form>

        <nav class="ins-assess-tabs" aria-label="Reports">
            <?php foreach ($reportKeys as $key => $label): ?>
                <a class="ins-assess-tab <?= $report === $key ? 'is-current' : '' ?>"
                   href="<?= $e($reportUrl($key, $courseId)) ?>"
                   <?= $report === $key ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($report === 'progress'): ?>
            <section class="ins-report-section">
                <div class="ins-report-head">
                    <h2>Student progress and grades</h2>
                    <div class="ins-report-actions">
                        <a class="ins-btn ins-btn--small" href="<?= $e($exportUrl('progress', $courseId)) ?>">Download progress CSV</a>
                    </div>
                </div>

                <?php if (!$students): ?>
                    <p class="ins-report-empty">No students are enrolled in this course yet.</p>
                <?php else: ?>
                    <?php $book = reportGradebook($pdo, $courseId); ?>
                    <p class="ins-report-summary">
                        <strong><?= count($students) ?></strong> students &middot;
                        average progress <strong><?= $e($pct($avgProgress)) ?></strong>
                        &middot; marks and scores are in the
                        <a href="<?= $e($reportUrl('gradebook', $courseId)) ?>">Gradebook</a>
                    </p>
                    <div class="ins-table-wrap">
                        <table class="ins-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th class="ins-table__num">Lessons done</th>
                                    <th>Progress</th>
                                    <th class="ins-table__num">Assignments graded</th>
                                    <th class="ins-table__num">Quizzes attempted</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($book['rows'] as $r): ?>
                                <tr>
                                    <td><strong><?= $e($r['name']) ?></strong><br><small><?= $e($r['email']) ?></small></td>
                                    <td class="ins-table__num"><?= (int) $r['lessons_done'] ?> / <?= (int) $r['lessons_total'] ?></td>
                                    <td>
                                        <div class="ins-report-bar" role="img" aria-label="<?= $e($pct($r['progress'])) ?> complete">
                                            <span style="width: <?= $e((string) min(100, (float) ($r['progress'] ?? 0))) ?>%"></span>
                                        </div>
                                        <small><?= $e($pct($r['progress'])) ?></small>
                                    </td>
                                    <td class="ins-table__num"><?= (int) $r['assignments_graded'] ?> / <?= (int) $r['assignments_total'] ?></td>
                                    <td class="ins-table__num"><?= (int) $r['quizzes_attempted'] ?> / <?= (int) $r['quizzes_total'] ?></td>
                                    <td><?= $r['completed_at'] ? 'Completed' : 'In progress' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($report === 'gradebook'): ?>
            <section class="ins-report-section">
                <div class="ins-report-head">
                    <h2>Gradebook</h2>
                    <div class="ins-report-actions">
                        <a class="ins-btn ins-btn--small ins-btn--primary" href="<?= $e($exportUrl('gradebook', $courseId)) ?>">Download gradebook CSV</a>
                    </div>
                </div>

                <?php if (!$gradebook['rows']): ?>
                    <p class="ins-report-empty">No students are enrolled in this course yet.</p>
                <?php elseif (!$gradebook['assignments'] && !$gradebook['quizzes']): ?>
                    <p class="ins-report-empty">This course has no assignments or quizzes yet.</p>
                <?php else: ?>
                    <div class="ins-table-wrap">
                        <table class="ins-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <?php foreach ($gradebook['assignments'] as $a): ?>
                                        <th class="ins-table__num"><?= $e($a['title']) ?><br><small>out of <?= (int) $a['max_mark'] ?></small></th>
                                    <?php endforeach; ?>
                                    <?php foreach ($gradebook['quizzes'] as $q): ?>
                                        <th class="ins-table__num">Quiz: <?= $e($q['title']) ?><br><small>best attempt</small></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($gradebook['rows'] as $r): ?>
                                <tr>
                                    <td><strong><?= $e($r['name']) ?></strong></td>
                                    <?php foreach ($gradebook['assignments'] as $a): ?>
                                        <?php $mark = $r['marks'][(int) $a['id']] ?? 'missing'; ?>
                                        <td class="ins-table__num">
                                            <?= $mark === 'missing' ? '<small>Not submitted</small>'
                                                : ($mark === null ? '<small>Not graded</small>' : (int) $mark) ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <?php foreach ($gradebook['quizzes'] as $q): ?>
                                        <?php $best = $r['quiz_results'][(int) $q['id']] ?? null; ?>
                                        <td class="ins-table__num">
                                            <?= $best === null ? '<small>Not attempted</small>' : (int) $best['score'] . ' / ' . (int) $best['total'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($report === 'students'): ?>
            <section class="ins-report-section">
                <div class="ins-report-head">
                    <h2>Student list</h2>
                    <div class="ins-report-actions">
                        <a class="ins-btn ins-btn--small ins-btn--primary" href="<?= $e($exportUrl('students', $courseId)) ?>">Download student list CSV</a>
                    </div>
                </div>

                <?php if (!$students): ?>
                    <p class="ins-report-empty">No students are enrolled in this course yet.</p>
                <?php else: ?>
                    <div class="ins-table-wrap">
                        <table class="ins-table">
                            <thead>
                                <tr><th>Name</th><th>Email</th><th>Account</th><th>Enrolled</th><th>Last activity</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $r): ?>
                                <tr>
                                    <td><strong><?= $e($r['name']) ?></strong></td>
                                    <td><?= $e($r['email']) ?></td>
                                    <td><?= $e(ucfirst((string) $r['status'])) ?></td>
                                    <td><?= $e(assessmentFormatDate($r['enrolled_at'])) ?></td>
                                    <td><?= $e(assessmentFormatDate($r['last_activity']) ?: 'No activity yet') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($report === 'quizzes'): ?>
            <section class="ins-report-section">
                <div class="ins-report-head">
                    <h2>Quiz results and attempt history</h2>
                    <div class="ins-report-actions">
                        <a class="ins-btn ins-btn--small ins-btn--primary" href="<?= $e($exportUrl('quiz_attempts', $courseId)) ?>">Download attempts CSV</a>
                    </div>
                </div>

                <?php if (!$quizResults['summary']): ?>
                    <p class="ins-report-empty">This course has no quizzes yet.</p>
                <?php else: ?>
                    <div class="ins-table-wrap">
                        <table class="ins-table">
                            <thead>
                                <tr>
                                    <th>Quiz</th>
                                    <th class="ins-table__num">Students</th>
                                    <th class="ins-table__num">Attempts</th>
                                    <th class="ins-table__num">Average</th>
                                    <th class="ins-table__num">Highest</th>
                                    <th class="ins-table__num">Pass rate</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($quizResults['summary'] as $q): ?>
                                <tr>
                                    <td><strong><?= $e($q['title']) ?></strong><br><small>Pass mark <?= (int) $q['pass_mark'] ?>%</small></td>
                                    <td class="ins-table__num"><?= (int) $q['students'] ?></td>
                                    <td class="ins-table__num"><?= (int) $q['attempts'] ?></td>
                                    <td class="ins-table__num"><?= $e($pct($q['average'] === null ? null : round((float) $q['average'], 1))) ?></td>
                                    <td class="ins-table__num"><?= $e($pct($q['highest'] === null ? null : round((float) $q['highest'], 1))) ?></td>
                                    <td class="ins-table__num"><?= $e($pct(reportPercent((int) $q['passed'], (int) $q['attempts']))) ?></td>
                                    <td class="ins-table__actions">
                                        <a class="ins-btn ins-btn--small" href="<?= $e(url('instructor/reports.php?report=quizzes&course_id=' . $courseId . '&quiz_id=' . (int) $q['id'])) ?>">History</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="ins-report-subhead">
                        Attempt history<?= $quizFilter ? ' (one quiz)' : '' ?>
                        <?php if ($quizFilter): ?>
                            <a class="ins-btn ins-btn--small" href="<?= $e($reportUrl('quizzes', $courseId)) ?>">Show all quizzes</a>
                        <?php endif; ?>
                    </h3>

                    <?php if (!$quizResults['attempts']): ?>
                        <p class="ins-report-empty">No student has attempted a quiz yet.</p>
                    <?php else: ?>
                        <div class="ins-table-wrap">
                            <table class="ins-table">
                                <thead>
                                    <tr>
                                        <th>Student</th><th>Quiz</th>
                                        <th class="ins-table__num">Attempt</th>
                                        <th class="ins-table__num">Score</th>
                                        <th class="ins-table__num">%</th>
                                        <th>Result</th><th>When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($quizResults['attempts'] as $a): ?>
                                    <tr>
                                        <td><strong><?= $e($a['name']) ?></strong><br><small><?= $e($a['email']) ?></small></td>
                                        <td><?= $e($a['quiz']) ?></td>
                                        <td class="ins-table__num">#<?= (int) $a['attempt_no'] ?></td>
                                        <td class="ins-table__num"><?= (int) $a['score'] ?> / <?= (int) $a['total_marks'] ?></td>
                                        <td class="ins-table__num"><?= $e($pct($a['percent'])) ?></td>
                                        <td><span class="ins-chip <?= (int) $a['passed'] === 1 ? 'ins-chip--success' : 'ins-chip--danger' ?>"><?= (int) $a['passed'] === 1 ? 'Passed' : 'Failed' ?></span></td>
                                        <td><?= $e(assessmentFormatDate($a['attempted_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

        <?php else: /* awaiting */ ?>
            <section class="ins-report-section">
                <div class="ins-report-head">
                    <h2>Submissions awaiting grading</h2>
                </div>

                <?php if (!$awaiting): ?>
                    <p class="ins-report-empty">Nothing is waiting. Every submission has a mark.</p>
                <?php else: ?>
                    <p class="ins-report-summary"><strong><?= count($awaiting) ?></strong> waiting, oldest first</p>
                    <div class="ins-table-wrap">
                        <table class="ins-table">
                            <thead>
                                <tr><th>Student</th><th>Assignment</th><th>Course</th><th>Submitted</th><th>Waiting</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($awaiting as $r): ?>
                                <?php $hours = (int) $r['hours_waiting']; ?>
                                <tr>
                                    <td><strong><?= $e($r['name']) ?></strong><br><small><?= $e($r['email']) ?></small></td>
                                    <td><?= $e($r['assignment']) ?></td>
                                    <td><?= $e($r['course']) ?></td>
                                    <td><?= $e(assessmentFormatDate($r['submitted_at'])) ?>
                                        <?= (int) $r['is_late'] === 1 ? '<span class="ins-chip ins-chip--danger">Late</span>' : '' ?></td>
                                    <td><?= $hours >= 48
                                        ? '<span class="ins-chip ins-chip--danger">' . intdiv($hours, 24) . ' days</span>'
                                        : $e($hours < 1 ? 'Under 1 hour' : $hours . ' hours') ?></td>
                                    <td class="ins-table__actions">
                                        <a class="ins-btn ins-btn--small" href="<?= $e(url('instructor/assignment and grading/assessment_list.php?course_id=' . (int) $r['course_id'] . '&type=assignment')) ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    <?php endif; ?>

</main>

    </div>
</div>

<script src="<?= $e(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
