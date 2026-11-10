<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

/*
|--------------------------------------------------------------------------
| Mark one student's submission: a whole-number mark from 0 to the
| assignment's maximum, and optional written feedback. The student sees both
| on their assignment page and dashboard straight away. A mark can be
| changed later.
|--------------------------------------------------------------------------
*/

const GRADE_FEEDBACK_MAX = 2000;

$user = assessmentRequireInstructor();
$pdo  = db();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$submissionId = filter_var($isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$submissionId) {
    http_response_code(400);
    exit('Invalid submission ID.');
}

$stmt = $pdo->prepare(
    'SELECT s.id, s.assignment_id, s.student_id, s.file_path, s.submitted_at, s.mark, s.feedback, s.graded_at,
            a.title, a.due_date, a.max_mark, a.course_id, c.title AS course_title,
            u.name AS student_name, u.email AS student_email
     FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     JOIN courses c ON c.id = a.course_id
     JOIN users u ON u.id = s.student_id
     WHERE s.id = :id AND c.instructor_id = :instructor LIMIT 1'
);
$stmt->execute(['id' => $submissionId, 'instructor' => (int) $user['id']]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) {
    http_response_code(404);
    exit('Submission not found or you do not own its course.');
}

$assignmentId = (int) $submission['assignment_id'];
$maxMark = (int) $submission['max_mark'];
$listUrl = url('instructor/assignment-grading/submissions/' . $assignmentId);
$errors = [];
$values = [
    'mark'     => $submission['mark'] !== null ? (string) (int) $submission['mark'] : '',
    'feedback' => (string) ($submission['feedback'] ?? ''),
];

if ($isPost) {
    $values['mark'] = trim((string) ($_POST['mark'] ?? ''));
    $values['feedback'] = trim((string) ($_POST['feedback'] ?? ''));

    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $mark = filter_var($values['mark'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maxMark]]);
    if ($mark === false) {
        $errors[] = 'The mark must be a whole number from 0 to ' . $maxMark . '.';
    }
    if (mb_strlen($values['feedback'], 'UTF-8') > GRADE_FEEDBACK_MAX) {
        $errors[] = 'Feedback must be ' . GRADE_FEEDBACK_MAX . ' characters or fewer.';
    }

    if (!$errors) {
        try {
            $pdo->prepare(
                'UPDATE submissions SET mark = :mark, feedback = :feedback, graded_at = NOW()
                 WHERE id = :id AND assignment_id = :assignment'
            )->execute([
                'mark'       => $mark,
                'feedback'   => $values['feedback'] !== '' ? $values['feedback'] : null,
                'id'         => $submissionId,
                'assignment' => $assignmentId,
            ]);
            assessmentCsrfRotate();

            // Tell the student straight away (never blocks saving the mark).
            notify(
                $pdo, (int) $submission['student_id'], 'grade',
                ($submission['mark'] !== null ? 'Your mark was updated: ' : 'Your assignment was marked: ') . (string) $submission['title'],
                (int) $mark . ' / ' . $maxMark . ($values['feedback'] !== '' ? ' · ' . notificationExcerpt($values['feedback'], 90) : ''),
                'assignment/' . $assignmentId
            );

            // "Save and mark next": go to the next submission that is still waiting.
            if (($_POST['after'] ?? '') === 'next') {
                $next = $pdo->prepare(
                    'SELECT id FROM submissions WHERE assignment_id = :assignment AND mark IS NULL AND id <> :id
                     ORDER BY submitted_at ASC, id ASC LIMIT 1'
                );
                $next->execute(['assignment' => $assignmentId, 'id' => $submissionId]);
                $nextId = $next->fetchColumn();
                if ($nextId !== false) {
                    redirect('instructor/assignment-grading/grade/' . (int) $nextId . '?saved=1');
                    exit;
                }
            }
            redirect('instructor/assignment-grading/submissions/' . $assignmentId . '?graded=1');
            exit;
        } catch (PDOException $exception) {
            error_log('Grading failed: ' . $exception->getMessage());
            $errors[] = 'The mark could not be saved. Please try again.';
        }
    }
}

// Waiting count, so the "mark next" button only appears when there is a next one.
$waiting = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE assignment_id = :assignment AND mark IS NULL AND id <> :id');
$waiting->execute(['assignment' => $assignmentId, 'id' => $submissionId]);
$othersWaiting = (int) $waiting->fetchColumn();

$e = static fn (mixed $v): string => assessmentFormEscape($v);
$isLate = new DateTimeImmutable((string) $submission['submitted_at']) > new DateTimeImmutable((string) $submission['due_date']);
$storedName = basename((string) $submission['file_path']);
$fileName = preg_match('/^[0-9a-f]{16}_(.+)$/', $storedName, $m) ? $m[1] : $storedName;
$fileExt = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION));
$csrfToken = assessmentCsrfToken();
$isMarked = $submission['mark'] !== null;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mark <?= $e($submission['student_name']) ?> | Learning Hub</title>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Mark submission'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-lesson-main" id="main">

    <header class="ins-lesson-hero">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= $e($listUrl) ?>">&larr; Back to submissions</a>
            <h1><?= $e($submission['student_name']) ?></h1>
            <h2><?= $e($submission['title']) ?> · <?= $e($submission['course_title']) ?></h2>
        </div>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <div class="ins-alert ins-course-notice ins-course-notice--success" role="status">Mark saved. Here is the next submission waiting for a mark.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="ins-lesson-message" role="alert">
            <?php foreach ($errors as $error): ?><?= $e($error) ?><br><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="ins-card ins-lesson-form-card">
        <dl class="ins-assess-meta">
            <div><dt>Student</dt><dd><?= $e($submission['student_name']) ?><br><small><?= $e($submission['student_email']) ?></small></dd></div>
            <div><dt>Submitted</dt><dd><?= $e(assessmentFormatDate((string) $submission['submitted_at'])) ?>
                <?php if ($isLate): ?><span class="ins-chip ins-chip--warning">Late</span><?php endif; ?></dd></div>
            <div><dt>Due</dt><dd><?= $e(assessmentFormatDate((string) $submission['due_date'])) ?></dd></div>
            <div><dt>Status</dt><dd><?php if ($isMarked): ?><span class="ins-chip ins-chip--success">Marked <?= $e(assessmentFormatDate((string) $submission['graded_at'])) ?></span>
                <?php else: ?><span class="ins-chip ins-chip--primary">Waiting for a mark</span><?php endif; ?></dd></div>
        </dl>

        <p>
            <a class="ins-btn" href="<?= $e(url('api/instructor_submission_file.php?id=' . $submissionId)) ?>">
                Download <?= $e($fileExt ?: 'file') ?> · <?= $e($fileName) ?>
            </a>
        </p>

        <form method="post" action="" class="ins-lesson-form" novalidate>
            <input type="hidden" name="id" value="<?= (int) $submissionId ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

            <div class="ins-lesson-field">
                <label for="gradeMark">Mark (0 to <?= $maxMark ?>)</label>
                <input type="number" id="gradeMark" name="mark" min="0" max="<?= $maxMark ?>" step="1" required
                       value="<?= $e($values['mark']) ?>" inputmode="numeric">
            </div>

            <div class="ins-lesson-field">
                <label for="gradeFeedback">Feedback for the student (optional)</label>
                <textarea id="gradeFeedback" name="feedback" rows="6" maxlength="<?= GRADE_FEEDBACK_MAX ?>"><?= $e($values['feedback']) ?></textarea>
            </div>

            <p class="ins-card__sub">The student sees this mark and feedback on their assignment page and dashboard as soon as you save. You can change it later.</p>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= $e($listUrl) ?>">Cancel</a>
                <?php if ($othersWaiting > 0): ?>
                    <button type="submit" name="after" value="next" class="ins-btn">Save and mark next (<?= $othersWaiting ?> waiting)</button>
                <?php endif; ?>
                <button type="submit" name="after" value="list" class="ins-btn ins-btn--primary"><?= $isMarked ? 'Update mark' : 'Save mark' ?></button>
            </div>
        </form>
    </section>

</main>

    </div>
</div>

<script src="<?= $e(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
