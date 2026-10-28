<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/assessment_common.php';
require_once __DIR__ . '/../../api/api_report_service.php';
require_once __DIR__ . '/../../api/api_cohort_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$cohortId = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cohort = $cohortId ? cohortOwned($pdo, (int) $cohortId, $instructorId) : null;
if (!$cohort) {
    http_response_code(404);
    exit('Cohort not found or you do not own it.');
}

$courseId = (int) $cohort['course_id'];
$selfUrl  = 'instructor/students/cohort_view.php?id=' . (int) $cohortId;
$errors   = [];
$form     = [
    'name'        => (string) $cohort['name'],
    'description' => (string) ($cohort['description'] ?? ''),
    'start_date'  => (string) ($cohort['start_date'] ?? ''),
    'end_date'    => (string) ($cohort['end_date'] ?? ''),
];
$announceForm = ['title' => '', 'message' => ''];
$openPanel = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        cohortFlash('error', 'Your session expired. Please try again.');
        redirect($selfUrl);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'update':
                $openPanel = 'edit';
                $result = cohortValidate($_POST, $errors);
                $form = $result['values'];

                if (!$errors && $result['clean'] !== null) {
                    $dup = $pdo->prepare('SELECT 1 FROM cohorts WHERE course_id = :c AND name = :n AND id <> :id LIMIT 1');
                    $dup->execute(['c' => $courseId, 'n' => $result['clean']['name'], 'id' => $cohortId]);

                    if ($dup->fetchColumn()) {
                        $errors[] = 'This course already has a cohort with that name.';
                        break;
                    }

                    $pdo->prepare(
                        'UPDATE cohorts SET name = :name, description = :description,
                                start_date = :start_date, end_date = :end_date
                         WHERE id = :id'
                    )->execute($result['clean'] + ['id' => $cohortId]);

                    assessmentCsrfRotate();
                    cohortFlash('success', 'Cohort details saved.');
                    redirect($selfUrl);
                    exit;
                }
                break;

            case 'add_selected':
                $ids = is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [];
                $added = cohortAddMembers($pdo, (int) $cohortId, $courseId, $ids);
                assessmentCsrfRotate();
                cohortFlash($added > 0 ? 'success' : 'error', $added > 0
                    ? $added . ' student(s) added to the cohort.'
                    : 'Choose at least one student to add.');
                redirect($selfUrl);
                exit;

            case 'add_emails':
                $emails = cohortParseEmails((string) ($_POST['emails'] ?? ''));
                if (!$emails) {
                    cohortFlash('error', 'Enter at least one valid student email address.');
                } else {
                    // Enrol in the course first (a cohort only holds enrolled students), then add to the cohort.
                    $result = cohortEnrollByEmail($pdo, $courseId, $emails);
                    $added = cohortAddMembers($pdo, (int) $cohortId, $courseId, $result['ids']);
                    cohortFlash($added > 0 ? 'success' : 'error',
                        $added . ' student(s) added to the cohort. ' . cohortEnrollSummary($result));
                }
                assessmentCsrfRotate();
                redirect($selfUrl);
                exit;

            case 'remove_member':
                $studentId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($studentId) {
                    $pdo->prepare('DELETE FROM cohort_members WHERE cohort_id = :c AND user_id = :u')
                        ->execute(['c' => $cohortId, 'u' => $studentId]);
                    cohortFlash('success', 'Student removed from the cohort. They are still enrolled in the course.');
                }
                assessmentCsrfRotate();
                redirect($selfUrl);
                exit;

            case 'announce':
                $openPanel = 'announce';
                $result = cohortValidateAnnouncement($_POST, $errors);
                $announceForm = $result['values'];

                if (!$errors && $result['clean'] !== null) {
                    $pdo->prepare(
                        'INSERT INTO announcements (course_id, cohort_id, author_id, title, message)
                         VALUES (:course, :cohort, :author, :title, :message)'
                    )->execute([
                        'course'  => $courseId,
                        'cohort'  => $cohortId,
                        'author'  => $instructorId,
                        'title'   => $result['clean']['title'],
                        'message' => $result['clean']['message'],
                    ]);

                    assessmentCsrfRotate();
                    cohortFlash('success', 'Announcement sent to this cohort.');
                    redirect($selfUrl);
                    exit;
                }
                break;
        }
    } catch (PDOException $exception) {
        error_log('Cohort page action failed: ' . $exception->getMessage());
        $errors[] = 'That could not be saved. Have you run the cohorts SQL in phpMyAdmin?';
    }
}

$members = cohortMembers($pdo, (int) $cohortId, $courseId);
$memberIds = array_flip(array_map(static fn (array $m): int => (int) $m['id'], $members));
$candidates = array_values(array_filter(
    reportStudents($pdo, $courseId),
    static fn (array $s): bool => !isset($memberIds[(int) $s['id']])
));
$announcements = cohortAnnouncements($pdo, (int) $cohortId);

$csrfToken = assessmentCsrfToken();
$pct = static fn ($v): string => $v === null ? '–' : number_format((float) $v, 1) . '%';
$cohortsUrl = url('instructor/students/cohorts.php?course_id=' . $courseId);
$pageTitleTag = $cohort['name'] . ' (cohort)';
$pageHeading = 'Students & cohorts';

require __DIR__ . '/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <a class="ins-lesson-back" href="<?= $e($cohortsUrl) ?>">&larr; Back to cohorts</a>
            <h1><?= $e($cohort['name']) ?></h1>
            <p>
                <?= $e($cohort['course_title']) ?>
                <?php if ($cohort['start_date'] || $cohort['end_date']): ?>
                    &middot; <?= $e(cohortFormatDate($cohort['start_date']) ?: '…') ?> to <?= $e(cohortFormatDate($cohort['end_date']) ?: '…') ?>
                <?php endif; ?>
            </p>
        </div>
        <a class="ins-btn" href="<?= $e(url('instructor/export-csv/cohort/' . (int) $cohortId)) ?>">Download members CSV</a>
    </header>

    <?php require __DIR__ . '/_messages.php'; ?>

    <?php if (!empty($cohort['description'])): ?>
        <p class="ins-assess-item__desc"><?= nl2br($e($cohort['description'])) ?></p>
    <?php endif; ?>

    <section class="ins-report-section">

        <!-- Members -->
        <div class="ins-report-head">
            <h2>Members (<?= count($members) ?>)</h2>
        </div>

        <?php if (!$members): ?>
            <p class="ins-report-empty">No students in this cohort yet. Add some below.</p>
        <?php else: ?>
            <div class="ins-table-wrap">
                <table class="ins-table">
                    <thead>
                        <tr><th>Student</th><th>Progress</th><th>Last activity</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><strong><?= $e($m['name']) ?></strong><br><small><?= $e($m['email']) ?></small></td>
                            <td>
                                <div class="ins-report-bar" role="img" aria-label="<?= $e($pct($m['progress'])) ?> complete">
                                    <span style="width: <?= $e((string) min(100, (float) ($m['progress'] ?? 0))) ?>%"></span>
                                </div>
                                <small><?= (int) $m['lessons_done'] ?> / <?= (int) $m['lessons_total'] ?> lessons</small>
                            </td>
                            <td><?= $e(assessmentFormatDate($m['last_activity']) ?: 'No activity yet') ?></td>
                            <td class="ins-table__actions">
                                <a class="ins-btn ins-btn--small" href="<?= $e(url('instructor/students/student_view.php?course_id=' . $courseId . '&id=' . (int) $m['id'])) ?>">View</a>
                                <form method="post" class="ins-inline-form"
                                      data-confirm="Remove <?= $e($m['name']) ?> from this cohort? They stay enrolled in the course.">
                                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Add members -->
        <details class="ins-assess-details ins-card ins-people-box">
            <summary>+ Add students to this cohort</summary>

            <?php if ($candidates): ?>
                <form method="post" class="ins-lesson-form">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_selected">
                    <p class="ins-lesson-field"><strong>Students already enrolled in the course</strong></p>
                    <div class="ins-people-pick">
                        <?php foreach ($candidates as $s): ?>
                            <label class="ins-assess-check">
                                <input type="checkbox" name="user_ids[]" value="<?= (int) $s['id'] ?>">
                                <?= $e($s['name']) ?> <small>(<?= $e($s['email']) ?>)</small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="ins-lesson-form__actions">
                        <button type="submit" class="ins-btn ins-btn--primary">Add selected</button>
                    </div>
                </form>
            <?php else: ?>
                <p>Every enrolled student is already in this cohort.</p>
            <?php endif; ?>

            <form method="post" class="ins-lesson-form">
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_emails">
                <div class="ins-lesson-field">
                    <label for="cohortEmails">Or add by email (also enrols them in the course)</label>
                    <textarea id="cohortEmails" name="emails" rows="3" placeholder="one@example.com, two@example.com"></textarea>
                </div>
                <div class="ins-lesson-form__actions">
                    <button type="submit" class="ins-btn">Enrol and add</button>
                </div>
            </form>
        </details>

        <!-- Announce -->
        <details class="ins-assess-details ins-card ins-people-box" <?= $openPanel === 'announce' ? 'open' : '' ?>>
            <summary>Send an announcement to this cohort</summary>
            <form method="post" class="ins-lesson-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="announce">
                <div class="ins-lesson-field">
                    <label for="announceTitle">Title</label>
                    <input type="text" id="announceTitle" name="title" maxlength="190" required value="<?= $e($announceForm['title']) ?>">
                </div>
                <div class="ins-lesson-field">
                    <label for="announceMessage">Message</label>
                    <textarea id="announceMessage" name="message" rows="5" maxlength="5000" required><?= $e($announceForm['message']) ?></textarea>
                </div>
                <div class="ins-lesson-form__actions">
                    <button type="submit" class="ins-btn ins-btn--primary">Send to <?= count($members) ?> student(s)</button>
                </div>
            </form>
        </details>

        <div class="ins-report-head"><h2>Announcements sent</h2></div>
        <?php if (!$announcements): ?>
            <p class="ins-report-empty">No announcements for this cohort yet.</p>
        <?php else: ?>
            <div class="ins-assess-items">
                <?php foreach ($announcements as $a): ?>
                    <article class="ins-assess-item">
                        <header class="ins-assess-item__head">
                            <h3><?= $e($a['title']) ?></h3>
                            <small><?= $e(assessmentFormatDate($a['created_at'])) ?> &middot; <?= $e($a['author']) ?></small>
                        </header>
                        <p class="ins-assess-item__desc"><?= nl2br($e($a['message'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Edit details -->
        <details class="ins-assess-details ins-card ins-people-box" <?= $openPanel === 'edit' ? 'open' : '' ?>>
            <summary>Edit cohort details</summary>
            <form method="post" class="ins-lesson-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <div class="ins-lesson-field">
                    <label for="editName">Cohort name</label>
                    <input type="text" id="editName" name="name" maxlength="120" required value="<?= $e($form['name']) ?>">
                </div>
                <div class="ins-lesson-field">
                    <label for="editDescription">Description (optional)</label>
                    <textarea id="editDescription" name="description" maxlength="2000"><?= $e($form['description']) ?></textarea>
                </div>
                <div class="ins-assess-form-row">
                    <div class="ins-lesson-field">
                        <label for="editStart">Start date</label>
                        <input type="date" id="editStart" name="start_date" value="<?= $e($form['start_date']) ?>">
                    </div>
                    <div class="ins-lesson-field">
                        <label for="editEnd">End date</label>
                        <input type="date" id="editEnd" name="end_date" value="<?= $e($form['end_date']) ?>">
                    </div>
                </div>
                <div class="ins-lesson-form__actions">
                    <button type="submit" class="ins-btn ins-btn--primary">Save details</button>
                </div>
            </form>
        </details>
    </section>

<?php require __DIR__ . '/_chrome_bottom.php'; ?>
