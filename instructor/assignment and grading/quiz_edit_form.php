<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/api/api_quiz_service.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';

$user = assessmentRequireInstructor();
$pdo  = db();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$quizId = filter_var(
    $isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$quizId) {
    http_response_code(400);
    exit('Invalid quiz ID.');
}

// Load the quiz, and make sure it belongs to a course of this instructor.
$stmt = $pdo->prepare(
    'SELECT q.*, c.title AS course_title,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count
     FROM quizzes q
     JOIN courses c ON c.id = q.course_id
     WHERE q.id = :id AND c.instructor_id = :instructor
     LIMIT 1'
);
$stmt->execute(['id' => $quizId, 'instructor' => (int) $user['id']]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    http_response_code(404);
    exit('Quiz not found or you do not own it.');
}

$courseId = (int) $quiz['course_id'];
$listUrl  = url('instructor/assignment and grading/assessment_list.php?course_id=' . $courseId . '&type=quiz');
$errors   = [];

// What the form shows first: the saved quiz.
$savedQuestions = json_decode((string) $quiz['questions'], true);
$quizPostedQuestions = is_array($savedQuestions) ? $savedQuestions : [];
$quizValues = [
    'title'        => (string) $quiz['title'],
    'description'  => (string) ($quiz['description'] ?? ''),
    'pass_mark'    => (string) $quiz['pass_mark'],
    'max_attempts' => (string) $quiz['max_attempts'],
    'start_time'   => quizInputDateTime($quiz['start_time'] ?? null),
    'end_time'     => quizInputDateTime($quiz['end_time'] ?? null),
    'method'       => 'manual',
];

if ($isPost) {
    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $result = quizValidateInput($_POST, $_FILES, $errors);
    $quizValues = $result['values'];
    $quizPostedQuestions = $result['posted'];

    if (!$errors && $result['clean'] !== null) {
        try {
            $update = $pdo->prepare(
                'UPDATE quizzes
                 SET title = :title,
                     description = :description,
                     pass_mark = :pass_mark,
                     max_attempts = :max_attempts,
                     start_time = :start_time,
                     end_time = :end_time,
                     question_count = :question_count,
                     total_marks = :total_marks,
                     source = :source,
                     questions = :questions
                 WHERE id = :id AND course_id = :course_id'
            );
            $update->execute($result['clean'] + ['id' => $quizId, 'course_id' => $courseId]);

            assessmentCsrfRotate();
            redirect('instructor/assignment and grading/assessment_list.php?course_id='
                . $courseId . '&type=quiz&updated=quiz');
            exit;
        } catch (PDOException $exception) {
            error_log('Quiz update failed: ' . $exception->getMessage());
            $errors[] = 'The quiz could not be saved. Please try again.';
        }
    }
}

$csrfToken = assessmentCsrfToken();
$pageTitle = 'Edit Quiz';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Quiz | Learning Hub</title>
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Edit Quiz'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-lesson-main" id="main">

    <header class="ins-lesson-hero">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= assessmentFormEscape($listUrl) ?>">&larr; Back to quizzes</a>
            <h1>Edit Quiz</h1>
            <h2><?= assessmentFormEscape($quiz['course_title']) ?></h2>
            <p>Change the details or questions. To replace every question, choose "Upload a CSV file".</p>
        </div>
    </header>

    <?php if ((int) $quiz['attempt_count'] > 0): ?>
        <div class="ins-lesson-message" role="status">
            Students have already made <?= (int) $quiz['attempt_count'] ?> attempt(s) on this quiz.
            Changing questions or correct answers will not change their saved scores.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="ins-lesson-message" role="alert">
            <?php foreach ($errors as $error): ?>
                <?= assessmentFormEscape($error) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="ins-card ins-lesson-form-card">

        <form method="post" action="" enctype="multipart/form-data" class="ins-lesson-form" id="assessmentForm" novalidate>
            <input type="hidden" name="id" value="<?= (int) $quizId ?>">
            <input type="hidden" name="csrf_token" value="<?= assessmentFormEscape($csrfToken) ?>">

            <!-- The shared quiz script expects a type field; here it is always "quiz". -->
            <select id="assessmentType" name="type" hidden>
                <option value="quiz" selected>Quiz</option>
            </select>

            <div class="ins-lesson-form" data-type-panel="quiz">
                <?php require __DIR__ . '/_quiz_fields.php'; ?>
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
