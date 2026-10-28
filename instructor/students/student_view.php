<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/assessment_common.php';
require_once __DIR__ . '/../../api/api_report_service.php';
require_once __DIR__ . '/../../api/api_cohort_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$courseId  = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$studentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$courseId || !$studentId) {
    http_response_code(400);
    exit('Invalid request.');
}

$course = assessmentOwnedCourse($pdo, (int) $courseId, $instructorId);
if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not own it.');
}

// The student's row in the gradebook (it only contains students enrolled in this course).
$book = reportGradebook($pdo, (int) $courseId);
$row = null;
foreach ($book['rows'] as $candidate) {
    if ((int) $candidate['id'] === (int) $studentId) {
        $row = $candidate;
    }
}
if (!$row) {
    http_response_code(404);
    exit('That student is not enrolled in this course.');
}

$attempts = array_values(array_filter(
    reportQuizResults($pdo, (int) $courseId)['attempts'],
    static fn (array $a): bool => (int) $a['user_id'] === (int) $studentId
));
$cohortNames = cohortNamesByStudent($pdo, (int) $courseId)[(int) $studentId] ?? [];

$pct = static fn ($v): string => $v === null ? '–' : number_format((float) $v, 1) . '%';
$backUrl = url('instructor/students/students.php?course_id=' . (int) $courseId);
$pageTitleTag = $row['name'];
$pageHeading = 'Students & cohorts';

require __DIR__ . '/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <a class="ins-lesson-back" href="<?= $e($backUrl) ?>">&larr; Back to students</a>
            <h1><?= $e($row['name']) ?></h1>
            <p><?= $e($row['email']) ?> &middot; <?= $e($course['title']) ?></p>
        </div>
    </header>

    <section class="ins-report-section">

        <dl class="ins-assess-meta ins-card ins-people-box">
            <div><dt>Account</dt><dd><?= $e(ucfirst((string) $row['status'])) ?></dd></div>
            <div><dt>Enrolled</dt><dd><?= $e(cohortFormatDate($row['enrolled_at'])) ?></dd></div>
            <div><dt>Last activity</dt><dd><?= $e(assessmentFormatDate($row['last_activity']) ?: 'No activity yet') ?></dd></div>
            <div><dt>Course</dt><dd><?= $row['completed_at'] ? 'Completed ' . $e(cohortFormatDate($row['completed_at'])) : 'In progress' ?></dd></div>
            <div>
                <dt>Lessons</dt>
                <dd><?= (int) $row['lessons_done'] ?> / <?= (int) $row['lessons_total'] ?> (<?= $e($pct($row['progress'])) ?>)</dd>
            </div>
            <div>
                <dt>Cohorts</dt>
                <dd>
                    <?php foreach ($cohortNames as $cid => $cname): ?>
                        <a class="ins-chip ins-chip--primary" href="<?= $e(url('instructor/students/cohort_view.php?id=' . (int) $cid)) ?>"><?= $e($cname) ?></a>
                    <?php endforeach; ?>
                    <?= $cohortNames ? '' : 'None' ?>
                </dd>
            </div>
        </dl>

        <div class="ins-report-bar" role="img" aria-label="<?= $e($pct($row['progress'])) ?> of lessons complete">
            <span style="width: <?= $e((string) min(100, (float) ($row['progress'] ?? 0))) ?>%"></span>
        </div>

        <div class="ins-report-head"><h2>Assignments</h2></div>
        <?php if (!$book['assignments']): ?>
            <p class="ins-report-empty">This course has no assignments.</p>
        <?php else: ?>
            <div class="ins-table-wrap">
                <table class="ins-table">
                    <thead><tr><th>Assignment</th><th class="ins-table__num">Mark</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($book['assignments'] as $a): ?>
                        <?php $mark = $row['marks'][(int) $a['id']] ?? 'missing'; ?>
                        <tr>
                            <td><?= $e($a['title']) ?></td>
                            <td class="ins-table__num"><?= is_int($mark) ? $mark . ' / ' . (int) $a['max_mark'] : '–' ?></td>
                            <td><?= $mark === 'missing' ? 'Not submitted' : ($mark === null ? 'Waiting for a mark' : 'Graded') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="ins-report-head"><h2>Quiz attempts</h2></div>
        <?php if (!$attempts): ?>
            <p class="ins-report-empty">This student has not attempted a quiz yet.</p>
        <?php else: ?>
            <div class="ins-table-wrap">
                <table class="ins-table">
                    <thead><tr><th>Quiz</th><th class="ins-table__num">Attempt</th><th class="ins-table__num">Score</th><th>Result</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($attempts as $a): ?>
                        <tr>
                            <td><?= $e($a['quiz']) ?></td>
                            <td class="ins-table__num">#<?= (int) $a['attempt_no'] ?></td>
                            <td class="ins-table__num"><?= (int) $a['score'] ?> / <?= (int) $a['total_marks'] ?></td>
                            <td><span class="ins-chip <?= (int) $a['passed'] === 1 ? 'ins-chip--success' : 'ins-chip--danger' ?>"><?= (int) $a['passed'] === 1 ? 'Passed' : 'Failed' ?></span></td>
                            <td><?= $e(assessmentFormatDate($a['attempted_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php require __DIR__ . '/_chrome_bottom.php'; ?>
