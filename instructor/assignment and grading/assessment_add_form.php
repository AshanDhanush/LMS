<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_service.php';
require_once dirname(__DIR__, 2) . '/includes/assignment_service.php';

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
    $values['type'] = (string) ($_POST['type'] ?? 'assignment');

    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['assessment_form_csrf'], $postedToken)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if (!in_array($values['type'], ['assignment', 'quiz'], true)) {
        $errors[] = 'Please choose Assignment or Quiz.';
    }

    if ($values['type'] === 'quiz') {
        // ---------------- Quiz ----------------
        $quiz = quizValidateInput($_POST, $_FILES, $errors);
        $quizValues = $quiz['values'];
        $quizPostedQuestions = $quiz['posted'];

        if (!$errors && $quiz['clean'] !== null) {
            try {
                $insertQuiz = $pdo->prepare(
                    'INSERT INTO quizzes
                        (course_id, title, description, pass_mark, max_attempts,
                         start_time, end_time, question_count, total_marks, source, questions)
                     VALUES
                        (:course_id, :title, :description, :pass_mark, :max_attempts,
                         :start_time, :end_time, :question_count, :total_marks, :source, :questions)'
                );
                $insertQuiz->execute(['course_id' => $courseId] + $quiz['clean']);

                $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));

                redirect('instructor/assignment and grading/assessment_list.php?course_id='
                    . $courseId . '&type=quiz&added=quiz');
                exit;
            } catch (PDOException | JsonException $exception) {
                error_log('Quiz insert failed: ' . $exception->getMessage());
                $errors[] = 'The quiz could not be saved. Please try again.';
            }
        }
    } elseif ($values['type'] === 'assignment') {
        // ---------------- Assignment ----------------
        $assignment = assignmentValidateInput($_POST, $_FILES['assignment_file'] ?? null, $errors);
        $values = ['type' => 'assignment'] + $assignment['values'];

        if (!$errors && $assignment['clean'] !== null) {
            $stored = null;

            if ($assignment['hasFile']) {
                $stored = assignmentStoreUpload(
                    $_FILES['assignment_file'],
                    $assignment['extension'],
                    $assignment['originalName'],
                    $errors
                );
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
                        'file_path'   => $stored['path'] ?? null,
                        'file_name'   => $stored['name'] ?? null,
                    ] + $assignment['clean']);

                    $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));

                    redirect('instructor/assignment and grading/assessment_list.php?course_id='
                        . $courseId . '&type=assignment&added=assignment');
                    exit;
                } catch (PDOException $exception) {
                    // Do not leave an orphan file if the database insert fails.
                    if ($stored) {
                        assessmentDeleteStoredFile($stored['path']);
                    }

                    error_log('Assignment insert failed: ' . $exception->getMessage());
                    $errors[] = 'The assignment could not be saved. Please try again.';
                }
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

            <!-- Quiz fields -->
            <div
                class="ins-lesson-form"
                data-type-panel="quiz"
                <?= $values['type'] === 'quiz' ? '' : 'hidden' ?>
            >
                <?php require __DIR__ . '/_quiz_fields.php'; ?>
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