
<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';

$user = currentUser();

if (!$user || ($user['role'] ?? '') !== 'instructor') {
    redirect(url('index.php'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

$courseIdInput = $_GET['course_id'] ?? null;
if ($courseIdInput === null && count($_GET) === 1) {
    $bareCourseId = array_key_first($_GET);
    if ($_GET[$bareCourseId] === '') {
        $courseIdInput = $bareCourseId;
    }
}

$courseId = filter_var(
    $courseIdInput,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$courseId) {
    http_response_code(400);
    exit('Invalid course ID.');
}

$stmt = db()->prepare(
    'SELECT id, title FROM courses
     WHERE id = :course_id AND instructor_id = :instructor_id'
);
$stmt->execute([
    'course_id' => $courseId,
    'instructor_id' => (int) $user['id'],
]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not own it.');
}

$stmt = db()->prepare(
    'SELECT COALESCE(MAX(position), 0) + 1
     FROM lessons WHERE course_id = :course_id'
);
$stmt->execute(['course_id' => $courseId]);
$nextPosition = (int) $stmt->fetchColumn();

function addLessonEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Lesson | Learning Hub</title>
    <link rel="stylesheet" href="<?= addLessonEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= addLessonEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">
<main class="ins-main ins-lesson-main ins-lesson-main--form">
    <header class="ins-lesson-hero ins-lesson-hero--form">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= addLessonEscape(
                url('instructor/courses/course_lesson.php?' . (int) $courseId)
            ) ?>">&larr; Back to lessons</a>
            <h1>Add lesson</h1>
            <p>Course: <strong><?= addLessonEscape($course['title']) ?></strong></p>
        </div>
    </header>

    <section class="ins-card ins-lesson-form-card" aria-label="New lesson details">
        <form id="lessonForm" method="post"
              action="<?= addLessonEscape(url('api/api_lesson.php')) ?>"
              enctype="multipart/form-data"
              class="ins-lesson-form">

            <input type="hidden" name="action" value="create">
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
            <input type="hidden" name="csrf_token"
                   value="<?= addLessonEscape($_SESSION['instructor_course_form_csrf']) ?>">

            <div class="ins-lesson-field">
                <label for="lessonTitle">Lesson title</label>
                <input id="lessonTitle" name="title" type="text"
                       maxlength="190" required
                       placeholder="e.g. Introduction to PHP">
            </div>

            <div class="ins-lesson-field">
                <label for="lessonType">Lesson type</label>
                <select id="lessonType" name="type" required>
                    <option value="text">Text lesson</option>
                    <option value="video">Video lesson</option>
                    <option value="pdf">PDF lesson</option>
                </select>
            </div>

            <div id="lessonContentGroup" class="ins-lesson-field">
                <label id="lessonContentLabel" for="lessonContent">Lesson content</label>
                <textarea id="lessonContent" name="content" rows="8"
                          placeholder="Write your lesson content here"></textarea>
            </div>

            <div id="lessonPdfGroup" class="ins-lesson-field" hidden>
                <label for="lessonPdf">Upload PDF (maximum 10 MB)</label>
                <input id="lessonPdf" name="lesson_pdf" type="file"
                       accept=".pdf,application/pdf">
            </div>

            <div class="ins-lesson-field">
                <label for="lessonPosition">Lesson position</label>
                <input id="lessonPosition" name="position" type="number"
                       min="1" max="100000" value="<?= $nextPosition ?>"
                       required>
            </div>

            <p class="ins-lesson-message" id="lessonFormMessage" role="status" aria-live="polite"></p>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= addLessonEscape(
                    url('instructor/courses/course_lesson.php?' . (int) $courseId)
                ) ?>">Cancel</a>
                <button class="ins-btn ins-btn--primary" id="lessonSubmitButton" type="submit">Create lesson</button>
            </div>

        </form>
    </section>
</main>

<script>
window.lessonApiUrl = <?= json_encode(url('api/api_lesson.php')) ?>;
</script>
<script src="<?= addLessonEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>
</body>
</html>
