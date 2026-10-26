<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$user = currentUser();

if (!$user || ($user['role'] ?? '') !== 'instructor') {
    redirect(url('index.php'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['assessment_form_csrf'])) {
    $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('assessmentFormEscape')) {
    function assessmentFormEscape(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

const ASSIGNMENT_MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

// Allowed extensions and the MIME types accepted for each one.
const ASSIGNMENT_ALLOWED_FILES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ],
];

$assessmentsPageUrl = url('instructor/assignment and grading/assignments_and_grading.php');

/*
|--------------------------------------------------------------------------
| Validate course ID
|--------------------------------------------------------------------------
*/

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$courseId = filter_var(
    $isPost ? ($_POST['course_id'] ?? null) : ($_GET['course_id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$courseId) {
    http_response_code(400);
    exit('Invalid course ID.');
}

$pdo = db();

/*
|--------------------------------------------------------------------------
| Verify course ownership
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT id, title
     FROM courses
     WHERE id = :course_id
       AND instructor_id = :instructor_id
     LIMIT 1'
);

$stmt->execute([
    'course_id'     => $courseId,
    'instructor_id' => (int) $user['id'],
]);

$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not own it.');
}

/*
|--------------------------------------------------------------------------
| Handle the form
|--------------------------------------------------------------------------
*/

$errors = [];

$values = [
    'type'        => 'assignment',
    'title'       => '',
    'description' => '',
    'due_date'    => '',
    'max_mark'    => '100',
];

if ($isPost) {
    $values['type']        = (string) ($_POST['type'] ?? 'assignment');
    $values['title']       = trim((string) ($_POST['title'] ?? ''));
    $values['description'] = trim((string) ($_POST['description'] ?? ''));
    $values['due_date']    = trim((string) ($_POST['due_date'] ?? ''));
    $values['max_mark']    = trim((string) ($_POST['max_mark'] ?? ''));

    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['assessment_form_csrf'], $postedToken)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if ($values['type'] !== 'assignment') {
        $errors[] = 'Only assignments can be added from this form for now.';
    }

    // Title
    $titleLength = mb_strlen($values['title'], 'UTF-8');
    if ($titleLength < 1 || $titleLength > 190) {
        $errors[] = 'Title is required and must be 190 characters or fewer.';
    }

    // Description (optional)
    if (mb_strlen($values['description'], 'UTF-8') > 5000) {
        $errors[] = 'Description must be 5000 characters or fewer.';
    }

    // Due date
    $dueDate = DateTime::createFromFormat('Y-m-d\TH:i', $values['due_date']);
    if (!$dueDate || $dueDate->format('Y-m-d\TH:i') !== $values['due_date']) {
        $errors[] = 'Please choose a valid due date and time.';
    }

    // Maximum mark
    $maxMark = filter_var(
        $values['max_mark'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 1000]]
    );
    if ($maxMark === false) {
        $errors[] = 'Maximum mark must be a whole number from 1 to 1000.';
    }

    // Optional PDF or Word file
    $upload = $_FILES['assignment_file'] ?? null;
    $hasFile = $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $fileExtension = '';
    $originalName = '';

    if ($hasFile) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The file could not be uploaded. Please try again.';
        } else {
            $originalName = (string) $upload['name'];
            $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!array_key_exists($fileExtension, ASSIGNMENT_ALLOWED_FILES)) {
                $errors[] = 'Only PDF, DOC or DOCX files are allowed.';
            } elseif ((int) $upload['size'] > ASSIGNMENT_MAX_FILE_SIZE) {
                $errors[] = 'The file must be 10 MB or smaller.';
            } elseif (!is_uploaded_file($upload['tmp_name'])) {
                $errors[] = 'The file could not be uploaded. Please try again.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = (string) $finfo->file($upload['tmp_name']);

                if (!in_array($detectedMime, ASSIGNMENT_ALLOWED_FILES[$fileExtension], true)) {
                    $errors[] = 'The file content does not match a PDF or Word document.';
                }
            }
        }
    }

    // Save
    if (!$errors) {
        $storedPath = null;
        $storedName = null;
        $absolutePath = null;

        if ($hasFile) {
            $uploadDir = dirname(__DIR__, 2) . '/uploads/assignments';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'The upload folder could not be created.';
            } else {
                $newFileName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
                $absolutePath = $uploadDir . '/' . $newFileName;

                if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                    $errors[] = 'The file could not be saved. Please try again.';
                } else {
                    $storedPath = 'uploads/assignments/' . $newFileName;
                    $storedName = mb_substr(
                        preg_replace('/[^\w.\- ()]+/u', '_', basename($originalName)),
                        0,
                        255,
                        'UTF-8'
                    );
                }
            }
        }

        if (!$errors) {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO assignments
                        (course_id, title, description, file_path, file_name, due_date, max_mark)
                     VALUES
                        (:course_id, :title, :description, :file_path, :file_name, :due_date, :max_mark)'
                );

                $insert->execute([
                    'course_id'   => $courseId,
                    'title'       => $values['title'],
                    'description' => $values['description'] !== '' ? $values['description'] : null,
                    'file_path'   => $storedPath,
                    'file_name'   => $storedName,
                    'due_date'    => $dueDate->format('Y-m-d H:i:s'),
                    'max_mark'    => $maxMark,
                ]);

                $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));

                redirect('instructor/assignment and grading/assignments_and_grading.php?added=assignment');
                exit;
            } catch (PDOException $exception) {
                // Do not leave an orphan file if the database insert fails.
                if ($absolutePath && is_file($absolutePath)) {
                    unlink($absolutePath);
                }

                error_log('Assignment insert failed: ' . $exception->getMessage());
                $errors[] = 'The assignment could not be saved. Please try again.';
            }
        }
    }
}

$csrfToken = $_SESSION['assessment_form_csrf'];
$pageTitle = 'Add Assignment or Quiz';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= assessmentFormEscape($pageTitle) ?>
        | Learning Hub
    </title>

    <link
        rel="stylesheet"
        href="<?= assessmentFormEscape(url('assests/css/theme.css')) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= assessmentFormEscape(url('assests/css/instructor_dashboard.css')) ?>"
    >
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Add Assignment or Quiz'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-lesson-main" id="main">

    <header class="ins-lesson-hero">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= assessmentFormEscape($assessmentsPageUrl) ?>">
                &larr; Back to Assignments and Quizzes
            </a>

            <h1>Add Assignment or Quiz</h1>
            <h2><?= assessmentFormEscape($course['title']) ?></h2>
            <p>Choose what you want to add to this course.</p>
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

        <form
            method="post"
            action=""
            enctype="multipart/form-data"
            class="ins-lesson-form"
            id="assessmentForm"
            novalidate
        >
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
            <input type="hidden" name="csrf_token" value="<?= assessmentFormEscape($csrfToken) ?>">

            <!-- Type -->
            <div class="ins-lesson-field">
                <label for="assessmentType">What do you want to add?</label>
                <select id="assessmentType" name="type">
                    <option value="assignment"
                        <?= $values['type'] === 'assignment' ? 'selected' : '' ?>>
                        Assignment
                    </option>
                    <option value="quiz"
                        <?= $values['type'] === 'quiz' ? 'selected' : '' ?>>
                        Quiz
                    </option>
                </select>
            </div>

            <!-- Assignment fields -->
            <div
                class="ins-lesson-form"
                data-type-panel="assignment"
                <?= $values['type'] === 'assignment' ? '' : 'hidden' ?>
            >
                <div class="ins-lesson-field">
                    <label for="assignmentTitle">Title</label>
                    <input
                        type="text"
                        id="assignmentTitle"
                        name="title"
                        maxlength="190"
                        required
                        value="<?= assessmentFormEscape($values['title']) ?>"
                    >
                </div>

                <div class="ins-lesson-field">
                    <label for="assignmentDescription">
                        Description (optional)
                    </label>
                    <textarea
                        id="assignmentDescription"
                        name="description"
                        maxlength="5000"
                    ><?= assessmentFormEscape($values['description']) ?></textarea>
                </div>

                <div class="ins-assess-form-row">
                    <div class="ins-lesson-field">
                        <label for="assignmentDue">Due date and time</label>
                        <input
                            type="datetime-local"
                            id="assignmentDue"
                            name="due_date"
                            required
                            value="<?= assessmentFormEscape($values['due_date']) ?>"
                        >
                    </div>

                    <div class="ins-lesson-field">
                        <label for="assignmentMark">Maximum mark</label>
                        <input
                            type="number"
                            id="assignmentMark"
                            name="max_mark"
                            min="1"
                            max="1000"
                            step="1"
                            required
                            value="<?= assessmentFormEscape($values['max_mark']) ?>"
                        >
                    </div>
                </div>

                <div class="ins-lesson-field">
                    <label for="assignmentFile">Assignment file (optional)</label>
                    <input
                        type="file"
                        id="assignmentFile"
                        name="assignment_file"
                        accept=".pdf,.doc,.docx"
                    >
                    <small>PDF, DOC or DOCX, up to 10 MB.</small>
                    <p class="ins-lesson-message" id="assignmentFileMessage" role="status" aria-live="polite"></p>
                </div>
            </div>

            <!-- Quiz placeholder -->
            <div
                class="ins-assess-note"
                data-type-panel="quiz"
                <?= $values['type'] === 'quiz' ? '' : 'hidden' ?>
            >
                The quiz form is not available yet. Choose Assignment to add one now.
            </div>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= assessmentFormEscape($assessmentsPageUrl) ?>">
                    Cancel
                </a>

                <button
                    type="submit"
                    class="ins-btn ins-btn--primary"
                    id="assessmentSubmit"
                    <?= $values['type'] === 'assignment' ? '' : 'hidden' ?>
                >
                    Save assignment
                </button>
            </div>
        </form>

    </section>

</main>

    </div>
</div>

<script src="<?= assessmentFormEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>