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

$selfUrl = 'instructor/students/cohorts.php?course_id=' . $courseId;
$errors = [];
$form = ['name' => '', 'description' => '', 'start_date' => '', 'end_date' => ''];

if ($course && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        cohortFlash('error', 'Your session expired. Please try again.');
        redirect($selfUrl);
        exit;
    }

    try {
        if ($action === 'create') {
            $result = cohortValidate($_POST, $errors);
            $form = $result['values'];

            if (!$errors && $result['clean'] !== null) {
                $dup = $pdo->prepare('SELECT 1 FROM cohorts WHERE course_id = :c AND name = :n LIMIT 1');
                $dup->execute(['c' => $courseId, 'n' => $result['clean']['name']]);

                if ($dup->fetchColumn()) {
                    $errors[] = 'This course already has a cohort with that name.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO cohorts (course_id, name, description, start_date, end_date)
                         VALUES (:course_id, :name, :description, :start_date, :end_date)'
                    );
                    $insert->execute(['course_id' => $courseId] + $result['clean']);
                    $newId = (int) $pdo->lastInsertId();

                    assessmentCsrfRotate();
                    cohortFlash('success', 'Cohort created. Now add students to it.');
                    redirect('instructor/students/cohort_view.php?id=' . $newId);
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $cohortId = filter_var($_POST['cohort_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $owned = $cohortId ? cohortOwned($pdo, (int) $cohortId, $instructorId) : null;

            if ($owned && (int) $owned['course_id'] === $courseId) {
                $pdo->prepare('DELETE FROM cohorts WHERE id = :id')->execute(['id' => (int) $cohortId]);
                assessmentCsrfRotate();
                cohortFlash('success', 'Cohort deleted. The students stay enrolled in the course.');
            } else {
                cohortFlash('error', 'That cohort could not be found.');
            }
            redirect($selfUrl);
            exit;
        }
    } catch (PDOException $exception) {
        error_log('Cohort action failed: ' . $exception->getMessage());
        $errors[] = 'That could not be saved. Have you run the cohorts SQL in phpMyAdmin?';
    }
}

$cohorts = $course ? cohortsForCourse($pdo, $courseId) : [];
$csrfToken = assessmentCsrfToken();
$activeTab = 'cohorts';
$pageTitleTag = 'Cohorts';
$pageHeading = 'Students & cohorts';

require __DIR__ . '/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Students &amp; cohorts</h1>
            <p>A cohort is a named group of students in a course, such as "Batch Jan 2026".</p>
        </div>
    </header>

    <?php require __DIR__ . '/_messages.php'; ?>

    <?php if (!$courses): ?>
        <div class="ins-curr-empty-state">
            <h2>No courses yet</h2>
            <p>Create a course first, then add cohorts to it.</p>
        </div>
    <?php else: ?>

        <form method="get" class="ins-report-picker">
            <label for="cohortCourse">Course</label>
            <select id="cohortCourse" name="course_id">
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>><?= $e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ins-btn">Show</button>
        </form>

        <?php require __DIR__ . '/_tabs.php'; ?>

        <section class="ins-report-section">

            <details class="ins-assess-details ins-card ins-people-box" <?= $errors ? 'open' : '' ?>>
                <summary>+ Create a cohort</summary>
                <form method="post" class="ins-lesson-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">

                    <div class="ins-lesson-field">
                        <label for="cohortName">Cohort name</label>
                        <input type="text" id="cohortName" name="name" maxlength="120" required value="<?= $e($form['name']) ?>" placeholder="Batch Jan 2026">
                    </div>
                    <div class="ins-lesson-field">
                        <label for="cohortDescription">Description (optional)</label>
                        <textarea id="cohortDescription" name="description" maxlength="2000"><?= $e($form['description']) ?></textarea>
                    </div>
                    <div class="ins-assess-form-row">
                        <div class="ins-lesson-field">
                            <label for="cohortStart">Start date (optional)</label>
                            <input type="date" id="cohortStart" name="start_date" value="<?= $e($form['start_date']) ?>">
                        </div>
                        <div class="ins-lesson-field">
                            <label for="cohortEnd">End date (optional)</label>
                            <input type="date" id="cohortEnd" name="end_date" value="<?= $e($form['end_date']) ?>">
                        </div>
                    </div>
                    <div class="ins-lesson-form__actions">
                        <button type="submit" class="ins-btn ins-btn--primary">Create cohort</button>
                    </div>
                </form>
            </details>

            <?php if (!$cohorts): ?>
                <p class="ins-report-empty">This course has no cohorts yet. Use "Create a cohort" to add one.</p>
            <?php endif; ?>

            <div class="ins-assess-items">
                <?php foreach ($cohorts as $c): ?>
                    <article class="ins-assess-item">
                        <header class="ins-assess-item__head">
                            <h2><?= $e($c['name']) ?></h2>
                            <span class="ins-chip ins-chip--muted"><?= (int) $c['member_count'] ?> student(s)</span>
                        </header>

                        <?php if (!empty($c['description'])): ?>
                            <p class="ins-assess-item__desc"><?= nl2br($e($c['description'])) ?></p>
                        <?php endif; ?>

                        <dl class="ins-assess-meta">
                            <div><dt>Starts</dt><dd><?= $e(cohortFormatDate($c['start_date']) ?: 'Not set') ?></dd></div>
                            <div><dt>Ends</dt><dd><?= $e(cohortFormatDate($c['end_date']) ?: 'Not set') ?></dd></div>
                        </dl>

                        <footer class="ins-assess-item__actions">
                            <a class="ins-btn ins-btn--small ins-btn--primary" href="<?= $e(url('instructor/students/cohort_view.php?id=' . (int) $c['id'])) ?>">Open</a>
                            <form method="post"
                                  data-confirm="Delete the cohort &quot;<?= $e($c['name']) ?>&quot;? Its announcements are deleted too. The students stay enrolled in the course.">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                <input type="hidden" name="cohort_id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Delete</button>
                            </form>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    <?php endif; ?>

<?php require __DIR__ . '/_chrome_bottom.php'; ?>
