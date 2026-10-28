<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/assessment_common.php';
require_once __DIR__ . '/../../api/api_report_service.php';
require_once __DIR__ . '/../../api/api_cohort_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$courses = reportCourses($pdo, $instructorId);

$courseId = filter_var($_REQUEST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$course = null;
foreach ($courses as $candidate) {
    if ($courseId && (int) $candidate['id'] === (int) $courseId) {
        $course = $candidate;
    }
}
if ($course === null && $courses) {
    $course = $courses[0];
}
$courseId = $course ? (int) $course['id'] : 0;

$selfUrl = 'instructor/students/students.php?course_id=' . $courseId;
$errors = [];

/* ---------- Actions (POST) ---------- */
if ($course && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        cohortFlash('error', 'Your session expired. Please try again.');
        redirect($selfUrl);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'enroll') {
            $emails = cohortParseEmails((string) ($_POST['emails'] ?? ''));
            if (!$emails) {
                cohortFlash('error', 'Enter at least one valid student email address.');
            } else {
                $result = cohortEnrollByEmail($pdo, $courseId, $emails);
                cohortFlash($result['enrolled'] > 0 || $result['already'] > 0 ? 'success' : 'error', cohortEnrollSummary($result));
            }
        } elseif ($action === 'remove') {
            $studentId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($studentId && cohortRemoveFromCourse($pdo, $courseId, (int) $studentId)) {
                cohortFlash('success', 'Student removed from the course.');
            } else {
                cohortFlash('error', 'That student is not enrolled in this course.');
            }
        }
        assessmentCsrfRotate();
    } catch (PDOException $exception) {
        error_log('Students action failed: ' . $exception->getMessage());
        cohortFlash('error', 'That could not be saved. Please try again.');
    }

    redirect($selfUrl);
    exit;
}

/* ---------- Data ---------- */
$search = trim((string) ($_GET['q'] ?? ''));
$cohortFilter = filter_var($_GET['cohort_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$students = $cohorts = $cohortNames = [];
if ($course) {
    $students    = reportStudents($pdo, $courseId);
    $cohorts     = cohortsForCourse($pdo, $courseId);
    $cohortNames = cohortNamesByStudent($pdo, $courseId);
}
$totalStudents = count($students);

$students = array_values(array_filter($students, static function (array $s) use ($search, $cohortFilter, $cohortNames): bool {
    if ($search !== '' && mb_stripos($s['name'] . ' ' . $s['email'], $search) === false) {
        return false;
    }
    return !$cohortFilter || isset($cohortNames[(int) $s['id']][$cohortFilter]);
}));

$csrfToken = assessmentCsrfToken();
$pct = static fn ($v): string => $v === null ? '–' : number_format((float) $v, 1) . '%';
$activeTab = 'students';
$pageTitleTag = 'Students & cohorts';
$pageHeading = 'Students & cohorts';

require __DIR__ . '/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Students &amp; cohorts</h1>
            <p>Enrol students, group them into cohorts, and follow their progress.</p>
        </div>
    </header>

    <?php require __DIR__ . '/_messages.php'; ?>

    <?php if (!$courses): ?>
        <div class="ins-curr-empty-state">
            <h2>No courses yet</h2>
            <p>Create a course first, then enrol students in it.</p>
        </div>
    <?php else: ?>

        <form method="get" class="ins-report-picker">
            <label for="studentCourse">Course</label>
            <select id="studentCourse" name="course_id">
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>>
                        <?= $e($c['title']) ?> (<?= (int) $c['students'] ?> students)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ins-btn">Show</button>
        </form>

        <?php require __DIR__ . '/_tabs.php'; ?>

        <section class="ins-report-section">
            <details class="ins-assess-details ins-card ins-people-box">
                <summary>+ Enrol students by email</summary>
                <form method="post" class="ins-lesson-form">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="action" value="enroll">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <div class="ins-lesson-field">
                        <label for="enrollEmails">Student email addresses</label>
                        <textarea id="enrollEmails" name="emails" rows="4" placeholder="one@example.com, two@example.com"></textarea>
                        <small>One per line or separated by commas. The student must already have a student account.</small>
                    </div>
                    <div class="ins-lesson-form__actions">
                        <button type="submit" class="ins-btn ins-btn--primary">Enrol students</button>
                    </div>
                </form>
            </details>

            <form method="get" class="ins-report-picker">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <label for="studentSearch">Search</label>
                <input type="search" id="studentSearch" name="q" value="<?= $e($search) ?>" placeholder="Name or email">
                <label for="studentCohort">Cohort</label>
                <select id="studentCohort" name="cohort_id">
                    <option value="">All students</option>
                    <?php foreach ($cohorts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $cohortFilter === (int) $c['id'] ? 'selected' : '' ?>><?= $e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="ins-btn">Filter</button>
            </form>

            <?php if (!$students): ?>
                <p class="ins-report-empty">
                    <?= $totalStudents === 0 ? 'No students are enrolled in this course yet.' : 'No students match this filter.' ?>
                </p>
            <?php else: ?>
                <p class="ins-report-summary"><strong><?= count($students) ?></strong> of <?= $totalStudents ?> students</p>
                <div class="ins-table-wrap">
                    <table class="ins-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Cohorts</th>
                                <th>Progress</th>
                                <th>Last activity</th>
                                <th>Enrolled</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><strong><?= $e($s['name']) ?></strong><br><small><?= $e($s['email']) ?></small></td>
                                <td>
                                    <?php foreach (($cohortNames[(int) $s['id']] ?? []) as $cid => $cname): ?>
                                        <a class="ins-chip ins-chip--primary" href="<?= $e(url('instructor/students/cohort_view.php?id=' . (int) $cid)) ?>"><?= $e($cname) ?></a>
                                    <?php endforeach; ?>
                                    <?php if (empty($cohortNames[(int) $s['id']])): ?><small>None</small><?php endif; ?>
                                </td>
                                <td>
                                    <div class="ins-report-bar" role="img" aria-label="<?= $e($pct($s['progress'])) ?> complete">
                                        <span style="width: <?= $e((string) min(100, (float) ($s['progress'] ?? 0))) ?>%"></span>
                                    </div>
                                    <small><?= (int) $s['lessons_done'] ?> / <?= (int) $s['lessons_total'] ?> lessons</small>
                                </td>
                                <td><?= $e(assessmentFormatDate($s['last_activity']) ?: 'No activity yet') ?></td>
                                <td><?= $e(cohortFormatDate($s['enrolled_at'])) ?></td>
                                <td class="ins-table__actions">
                                    <a class="ins-btn ins-btn--small" href="<?= $e(url('instructor/students/student_view.php?course_id=' . $courseId . '&id=' . (int) $s['id'])) ?>">View</a>
                                    <form method="post" class="ins-inline-form"
                                          data-confirm="Remove <?= $e($s['name']) ?> from this course? They will also leave its cohorts. Their lesson progress, submissions and quiz attempts are kept.">
                                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    <?php endif; ?>

<?php require __DIR__ . '/_chrome_bottom.php'; ?>
