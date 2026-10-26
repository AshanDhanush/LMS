<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/assignment_service.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';

$user = assessmentRequireInstructor();
$pdo  = db();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$assignmentId = filter_var(
    $isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$assignmentId) {
    http_response_code(400);
    exit('Invalid assignment ID.');
}

$stmt = $pdo->prepare(
    'SELECT a.*, c.title AS course_title
     FROM assignments a
     JOIN courses c ON c.id = a.course_id
     WHERE a.id = :id AND c.instructor_id = :instructor
     LIMIT 1'
);
$stmt->execute(['id' => $assignmentId, 'instructor' => (int) $user['id']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    http_response_code(404);
    exit('Assignment not found or you do not own it.');
}

$courseId = (int) $assignment['course_id'];
$listUrl  = url('instructor/assignment and grading/assessment_list.php?course_id=' . $courseId . '&type=assignment');
$errors   = [];

$values = [
    'title'       => (string) $assignment['title'],
    'description' => (string) ($assignment['description'] ?? ''),
    'due_date'    => (new DateTimeImmutable((string) $assignment['due_date']))->format('Y-m-d\TH:i'),
    'max_mark'    => (string) $assignment['max_mark'],
];
$currentFileName = $assignment['file_name'] ?? null;
$currentFilePath = $assignment['file_path'] ?? null;

if ($isPost) {
    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $removeFile = ($_POST['remove_file'] ?? '') === '1';
    $result = assignmentValidateInput($_POST, $_FILES['assignment_file'] ?? null, $errors);
    $values = $result['values'];

    if (!$errors && $result['clean'] !== null) {
        $stored = null;

        if ($result['hasFile']) {
            $stored = assignmentStoreUpload(
                $_FILES['assignment_file'],
                $result['extension'],
                $result['originalName'],
                $errors
            );
        }

        if (!$errors) {
            // Keep the old file unless it is replaced or removed.
            $newPath = $currentFilePath;
            $newName = $currentFileName;
            if ($stored) {
                $newPath = $stored['path'];
                $newName = $stored['name'];
            } elseif ($removeFile) {
                $newPath = null;
                $newName = null;
            }

            try {
                $update = $pdo->prepare(
                    'UPDATE assignments
                     SET title = :title,
                         description = :description,
                         file_path = :file_path,
                         file_name = :file_name,
                         due_date = :due_date,
                         max_mark = :max_mark
                     WHERE id = :id AND course_id = :course_id'
                );
                $update->execute($result['clean'] + [
                    'file_path' => $newPath,
                    'file_name' => $newName,
                    'id'        => $assignmentId,
                    'course_id' => $courseId,
                ]);

                // Only now that the row is saved, remove the replaced file.
                if ($newPath !== $currentFilePath) {
                    assessmentDeleteStoredFile($currentFilePath);
                }

                assessmentCsrfRotate();
                redirect('instructor/assignment and grading/assessment_list.php?course_id='
                    . $courseId . '&type=assignment&updated=assignment');
                exit;
            } catch (PDOException $exception) {
                if ($stored) {
                    assessmentDeleteStoredFile($stored['path']);
                }
                error_log('Assignment update failed: ' . $exception->getMessage());
                $errors[] = 'The assignment could not be saved. Please try again.';
            }
        }
    }
}

$csrfToken = assessmentCsrfToken();
$pageTitle = 'Edit Assignment';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Assignment | Learning Hub</title>
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Edit Assignment'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-lesson-main" id="main">

    <header class="ins-lesson-hero">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= assessmentFormEscape($listUrl) ?>">&larr; Back to assignments</a>
            <h1>Edit Assignment</h1>
            <h2><?= assessmentFormEscape($assignment['course_title']) ?></h2>
        </div>
    </header>

    <?php if ($errors): ?>
        <div class="ins-lesson-message" role="alert">
            <?php foreach ($errors as $error): ?>
                <?= assessmentFormEscape($error) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="ins-card ins-lesson-form-card">

        <form method="post" action="" enctype="multipart/form-data" class="ins-lesson-form" id="assessmentForm" novalidate>
            <input type="hidden" name="id" value="<?= (int) $assignmentId ?>">
            <input type="hidden" name="csrf_token" value="<?= assessmentFormEscape($csrfToken) ?>">

            <!-- The shared script expects a type field; here it is always "assignment". -->
            <select id="assessmentType" name="type" hidden>
                <option value="assignment" selected>Assignment</option>
            </select>

            <div class="ins-lesson-form" data-type-panel="assignment">
                <div class="ins-lesson-field">
                    <label for="assignmentTitle">Title</label>
                    <input type="text" id="assignmentTitle" name="title" maxlength="190" required
                           value="<?= assessmentFormEscape($values['title']) ?>">
                </div>

                <div class="ins-lesson-field">
                    <label for="assignmentDescription">Description (optional)</label>
                    <textarea id="assignmentDescription" name="description" maxlength="5000"><?= assessmentFormEscape($values['description']) ?></textarea>
                </div>

                <div class="ins-assess-form-row">
                    <div class="ins-lesson-field">
                        <label for="assignmentDue">Due date and time</label>
                        <input type="datetime-local" id="assignmentDue" name="due_date" required
                               value="<?= assessmentFormEscape($values['due_date']) ?>">
                    </div>

                    <div class="ins-lesson-field">
                        <label for="assignmentMark">Maximum mark</label>
                        <input type="number" id="assignmentMark" name="max_mark" min="1" max="1000" step="1" required
                               value="<?= assessmentFormEscape($values['max_mark']) ?>">
                    </div>
                </div>

                <div class="ins-lesson-field">
                    <label for="assignmentFile">Assignment file (optional)</label>

                    <?php if ($currentFileName): ?>
                        <p class="ins-assess-current-file">
                            Current file: <strong><?= assessmentFormEscape($currentFileName) ?></strong>
                        </p>
                        <label class="ins-assess-check">
                            <input type="checkbox" name="remove_file" value="1">
                            Remove the current file
                        </label>
                    <?php endif; ?>

                    <input type="file" id="assignmentFile" name="assignment_file" accept=".pdf,.doc,.docx">
                    <small>
                        <?= $currentFileName ? 'Choose a new file to replace the current one. ' : '' ?>PDF, DOC or DOCX, up to 10 MB.
                    </small>
                    <p class="ins-lesson-message" id="assignmentFileMessage" role="status" aria-live="polite"></p>
                </div>
            </div>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= assessmentFormEscape($listUrl) ?>">Cancel</a>
                <button type="submit" class="ins-btn ins-btn--primary" id="assessmentSubmit">Save changes</button>
            </div>
        </form>

    </section>

</main>

    </div>
</div>

<script src="<?= assessmentFormEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
