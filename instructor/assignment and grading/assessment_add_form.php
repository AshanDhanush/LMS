<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_service.php';

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

$quizValues = [
    'title'        => '',
    'description'  => '',
    'pass_mark'    => '50',
    'max_attempts' => '3',
    'start_time'   => '',
    'end_time'     => '',
    'method'       => 'manual',
];
$quizPostedQuestions = [];

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

    if (!in_array($values['type'], ['assignment', 'quiz'], true)) {
        $errors[] = 'Please choose Assignment or Quiz.';
    }

    if ($values['type'] === 'quiz') {
        // ---------------- Quiz ----------------
        $quizValues['title']        = trim((string) ($_POST['quiz_title'] ?? ''));
        $quizValues['description']  = trim((string) ($_POST['quiz_description'] ?? ''));
        $quizValues['pass_mark']    = trim((string) ($_POST['quiz_pass_mark'] ?? ''));
        $quizValues['max_attempts'] = trim((string) ($_POST['quiz_max_attempts'] ?? ''));
        $quizValues['start_time']   = trim((string) ($_POST['quiz_start_time'] ?? ''));
        $quizValues['end_time']     = trim((string) ($_POST['quiz_end_time'] ?? ''));
        $quizValues['method']       = (string) ($_POST['quiz_method'] ?? 'manual');

        if (is_array($_POST['quiz_questions'] ?? null)) {
            $quizPostedQuestions = array_values($_POST['quiz_questions']);
        }

        $quizTitleLength = mb_strlen($quizValues['title'], 'UTF-8');
        if ($quizTitleLength < 1 || $quizTitleLength > 190) {
            $errors[] = 'Quiz title is required and must be 190 characters or fewer.';
        }

        if (mb_strlen($quizValues['description'], 'UTF-8') > 5000) {
            $errors[] = 'Description must be 5000 characters or fewer.';
        }

        $passMark = filter_var(
            $quizValues['pass_mark'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );
        if ($passMark === false) {
            $errors[] = 'Pass mark must be a whole number from 1 to 100 (percent).';
        }

        $maxAttempts = filter_var(
            $quizValues['max_attempts'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 20]]
        );
        if ($maxAttempts === false) {
            $errors[] = 'Maximum attempts must be a whole number from 1 to 20.';
        }

        // Start and end time are both optional. Empty = no limit on that side.
        $quizStart = null;
        $quizEnd   = null;

        foreach (['start_time' => 'Start time', 'end_time' => 'End time'] as $field => $label) {
            if ($quizValues[$field] === '') {
                continue;
            }

            $parsed = DateTime::createFromFormat('Y-m-d\TH:i', $quizValues[$field]);
            if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $quizValues[$field]) {
                $errors[] = "$label is not a valid date and time.";
                continue;
            }

            if ($field === 'start_time') {
                $quizStart = $parsed;
            } else {
                $quizEnd = $parsed;
            }
        }

        if ($quizStart && $quizEnd && $quizEnd <= $quizStart) {
            $errors[] = 'End time must be after the start time.';
        }

        if (!in_array($quizValues['method'], ['manual', 'csv'], true)) {
            $errors[] = 'Please choose how to add the questions.';
        }

        $quizQuestions = [];

        if ($quizValues['method'] === 'csv') {
            $csv = $_FILES['quiz_csv'] ?? null;
            $csvError = $csv['error'] ?? UPLOAD_ERR_NO_FILE;

            if (!$csv || $csvError === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Please choose a CSV file with the questions.';
            } elseif ($csvError !== UPLOAD_ERR_OK) {
                $errors[] = 'The CSV file could not be uploaded. Please try again.';
            } elseif (strtolower(pathinfo((string) $csv['name'], PATHINFO_EXTENSION)) !== 'csv') {
                $errors[] = 'The questions file must be a .csv file.';
            } elseif ((int) $csv['size'] > QUIZ_CSV_MAX_SIZE) {
                $errors[] = 'The CSV file must be 1 MB or smaller.';
            } elseif (!is_uploaded_file($csv['tmp_name'])) {
                $errors[] = 'The CSV file could not be uploaded. Please try again.';
            } else {
                $quizQuestions = quizBuildFromCsv($csv['tmp_name'], $errors);
            }
        } elseif ($quizValues['method'] === 'manual') {
            $quizQuestions = quizBuildFromManual($_POST['quiz_questions'] ?? null, $errors);
        }

        if (!$errors) {
            try {
                $insertQuiz = $pdo->prepare(
                    'INSERT INTO quizzes
                        (course_id, title, description, pass_mark, max_attempts,
                         start_time, end_time, question_count, total_marks, source, questions)
                     VALUES
                        (:course_id, :title, :description, :pass_mark, :max_attempts,
                         :start_time, :end_time, :question_count, :total_marks, :source, :questions)'
                );

                $insertQuiz->execute([
                    'course_id'      => $courseId,
                    'title'          => $quizValues['title'],
                    'description'    => $quizValues['description'] !== '' ? $quizValues['description'] : null,
                    'pass_mark'      => $passMark,
                    'max_attempts'   => $maxAttempts,
                    'start_time'     => $quizStart ? $quizStart->format('Y-m-d H:i:s') : null,
                    'end_time'       => $quizEnd ? $quizEnd->format('Y-m-d H:i:s') : null,
                    'question_count' => count($quizQuestions),
                    'total_marks'    => quizTotalMarks($quizQuestions),
                    'source'         => $quizValues['method'],
                    'questions'      => json_encode(
                        $quizQuestions,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                ]);

                $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));

                redirect('instructor/assignment and grading/assignments_and_grading.php?added=quiz');
                exit;
            } catch (PDOException | JsonException $exception) {
                error_log('Quiz insert failed: ' . $exception->getMessage());
                $errors[] = 'The quiz could not be saved. Please try again.';
            }
        }
    }

    if ($values['type'] === 'assignment') {

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

    } // end of assignment handling
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

            <!-- Quiz fields -->
            <div
                class="ins-lesson-form"
                data-type-panel="quiz"
                <?= $values['type'] === 'quiz' ? '' : 'hidden' ?>
            >
                <div class="ins-lesson-field">
                    <label for="quizTitle">Quiz title</label>
                    <input
                        type="text"
                        id="quizTitle"
                        name="quiz_title"
                        maxlength="190"
                        required
                        value="<?= assessmentFormEscape($quizValues['title']) ?>"
                    >
                </div>

                <div class="ins-lesson-field">
                    <label for="quizDescription">Description (optional)</label>
                    <textarea
                        id="quizDescription"
                        name="quiz_description"
                        maxlength="5000"
                    ><?= assessmentFormEscape($quizValues['description']) ?></textarea>
                </div>

                <div class="ins-quiz-row">
                    <div class="ins-lesson-field">
                        <label for="quizPassMark">Pass mark (%)</label>
                        <input
                            type="number"
                            id="quizPassMark"
                            name="quiz_pass_mark"
                            min="1"
                            max="100"
                            step="1"
                            required
                            value="<?= assessmentFormEscape($quizValues['pass_mark']) ?>"
                        >
                    </div>

                    <div class="ins-lesson-field">
                        <label for="quizMaxAttempts">Maximum attempts</label>
                        <input
                            type="number"
                            id="quizMaxAttempts"
                            name="quiz_max_attempts"
                            min="1"
                            max="20"
                            step="1"
                            required
                            value="<?= assessmentFormEscape($quizValues['max_attempts']) ?>"
                        >
                    </div>
                </div>

                <div class="ins-quiz-row">
                    <div class="ins-lesson-field">
                        <label for="quizStartTime">Start time (optional)</label>
                        <input
                            type="datetime-local"
                            id="quizStartTime"
                            name="quiz_start_time"
                            value="<?= assessmentFormEscape($quizValues['start_time']) ?>"
                        >
                    </div>

                    <div class="ins-lesson-field">
                        <label for="quizEndTime">End time (optional)</label>
                        <input
                            type="datetime-local"
                            id="quizEndTime"
                            name="quiz_end_time"
                            value="<?= assessmentFormEscape($quizValues['end_time']) ?>"
                        >
                    </div>
                </div>
                <p class="ins-quiz-hint">Students can only take the quiz between these times. Leave a field empty for no limit.</p>

                <fieldset class="ins-quiz-method">
                    <legend>How do you want to add the questions?</legend>

                    <label class="ins-quiz-method__option">
                        <input
                            type="radio"
                            name="quiz_method"
                            value="manual"
                            <?= $quizValues['method'] === 'manual' ? 'checked' : '' ?>
                        >
                        <span>Add questions manually</span>
                    </label>

                    <label class="ins-quiz-method__option">
                        <input
                            type="radio"
                            name="quiz_method"
                            value="csv"
                            <?= $quizValues['method'] === 'csv' ? 'checked' : '' ?>
                        >
                        <span>Upload a CSV file</span>
                    </label>
                </fieldset>

                <!-- Method 1: CSV upload -->
                <div class="ins-lesson-form" data-quiz-method-panel="csv" hidden>
                    <div class="ins-lesson-field">
                        <label for="quizCsv">Questions file (.csv)</label>
                        <input type="file" id="quizCsv" name="quiz_csv" accept=".csv,text/csv">
                        <small>
                            Up to <?= (int) QUIZ_MAX_QUESTIONS ?> questions, 1 MB.
                            <a href="<?= assessmentFormEscape(url('assests/templates/quiz_template.csv')) ?>" download>Download the template</a>
                        </small>
                        <p class="ins-lesson-message" id="quizCsvMessage" role="status" aria-live="polite"></p>
                    </div>

                    <div class="ins-assess-note">
                        <strong>CSV columns:</strong>
                        <code>type, question, marks, option1, option2, option3, option4, option5, correct, model_answer</code><br>
                        <code>type</code> is <code>mcq</code> or <code>essay</code>.
                        MCQ rows need option1 to option4 (option5 is optional) and
                        <code>correct</code> as the option number (1 to 5) or letter (A to E).
                        Essay rows leave the options and <code>correct</code> empty;
                        <code>model_answer</code> is optional.
                    </div>
                </div>

                <!-- Method 2: manual entry -->
                <div class="ins-lesson-form" data-quiz-method-panel="manual" hidden>
                    <div id="quizQuestions" class="ins-quiz-questions"></div>

                    <p class="ins-assess-note" id="quizEmptyNote">
                        No questions yet. Add a multiple choice or an essay question.
                    </p>

                    <div class="ins-quiz-add">
                        <button type="button" class="ins-btn" id="quizAddMcq">+ Add MCQ question</button>
                        <button type="button" class="ins-btn" id="quizAddEssay">+ Add essay question</button>
                    </div>

                    <p class="ins-quiz-total" id="quizTotals" aria-live="polite"></p>
                </div>

                <script type="application/json" id="quizInitialQuestions"><?=
                    json_encode(
                        $quizPostedQuestions,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                    ) ?: '[]'
                ?></script>
            </div>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= assessmentFormEscape($assessmentsPageUrl) ?>">
                    Cancel
                </a>

                <button
                    type="submit"
                    class="ins-btn ins-btn--primary"
                    id="assessmentSubmit"
                >
                    <?= $values['type'] === 'quiz' ? 'Save quiz' : 'Save assignment' ?>
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