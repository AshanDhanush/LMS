
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

$lessonIdInput = $_GET['lesson_id'] ?? null;
if ($lessonIdInput === null && count($_GET) === 1) {
    $bareLessonId = array_key_first($_GET);
    if ($_GET[$bareLessonId] === '') {
        $lessonIdInput = $bareLessonId;
    }
}

$lessonId = filter_var(
    $lessonIdInput,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$lessonId) {
    http_response_code(400);
    exit('Invalid lesson ID.');
}

$stmt = db()->prepare(
    'SELECT l.id, l.course_id, l.title, l.type, l.content,
            l.file_path, l.position, c.title AS course_title
     FROM lessons l
     INNER JOIN courses c ON c.id = l.course_id
     WHERE l.id = :lesson_id AND c.instructor_id = :instructor_id'
);
$stmt->execute([
    'lesson_id' => $lessonId,
    'instructor_id' => (int) $user['id'],
]);

$lesson = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    http_response_code(404);
    exit('Lesson not found or you do not own its course.');
}

function editLessonEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Lesson | Learning Hub</title>
    <link rel="stylesheet" href="<?= editLessonEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= editLessonEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">
<main class="ins-main ins-lesson-main ins-lesson-main--form">
    <header class="ins-lesson-hero ins-lesson-hero--form">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back" href="<?= editLessonEscape(
                url('instructor/courses/course_lesson.php?' . (int) $lesson['course_id'])
            ) ?>">&larr; Back to lessons</a>
            <h1>Edit lesson</h1>
            <p>Course: <strong><?= editLessonEscape($lesson['course_title']) ?></strong></p>
        </div>
    </header>

    <section class="ins-card ins-lesson-form-card" aria-label="Edit lesson details">
        <form id="lessonForm" method="post"
              action="<?= editLessonEscape(url('api/api_lesson.php')) ?>"
              enctype="multipart/form-data"
              class="ins-lesson-form">

            <input type="hidden" name="action" value="update">
            <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
            <input type="hidden" name="course_id" value="<?= (int) $lesson['course_id'] ?>">
            <input type="hidden" name="csrf_token"
                   value="<?= editLessonEscape($_SESSION['instructor_course_form_csrf']) ?>">

            <div class="ins-lesson-field">
                <label for="lessonTitle">Lesson title</label>
                <input id="lessonTitle" name="title" type="text" maxlength="190"
                       required
                       value="<?= editLessonEscape($lesson['title']) ?>">
            </div>

            <div class="ins-lesson-field">
                <label for="lessonType">Lesson type</label>
                <select id="lessonType" name="type" required>
                    <option value="text" <?= $lesson['type'] === 'text' ? 'selected' : '' ?>>
                        Text lesson
                    </option>
                    <option value="video" <?= $lesson['type'] === 'video' ? 'selected' : '' ?>>
                        Video lesson
                    </option>
                    <option value="pdf" <?= $lesson['type'] === 'pdf' ? 'selected' : '' ?>>
                        PDF lesson
                    </option>
                </select>
            </div>

            <div id="lessonContentGroup" class="ins-lesson-field">
                <label id="lessonContentLabel" for="lessonContent">Lesson content</label>
                <textarea id="lessonContent" name="content" rows="8"><?= editLessonEscape(
                              $lesson['content'] ?? ''
                          ) ?></textarea>
            </div>

            <div id="lessonPdfGroup" class="ins-lesson-field" hidden>
                <label for="lessonPdf">Replace PDF (optional, maximum 10 MB)</label>

                <?php if ($lesson['file_path']): ?>
                    <p class="ins-lesson-file">Current PDF: <?= editLessonEscape(basename($lesson['file_path'])) ?></p>
                <?php endif; ?>

                <input id="lessonPdf" name="lesson_pdf" type="file"
                       accept=".pdf,application/pdf">
                <small>Leave the upload empty to keep the existing PDF when editing a PDF lesson.</small>
            </div>

            <div class="ins-lesson-field">
                <label for="lessonPosition">Lesson position</label>
                <input id="lessonPosition" name="position" type="number"
                       min="1" max="100000" required
                       value="<?= (int) $lesson['position'] ?>">
            </div>

            <p class="ins-lesson-message" id="lessonFormMessage" role="status" aria-live="polite"></p>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn" href="<?= editLessonEscape(
                    url('instructor/courses/course_lesson.php?' . (int) $lesson['course_id'])
                ) ?>">Cancel</a>
                <button class="ins-btn ins-btn--primary" id="lessonSubmitButton" type="submit">Save changes</button>
            </div>

        </form>
    </section>
</main>

<script>
window.lessonApiUrl = <?= json_encode(url('api/api_lesson.php')) ?>;
</script>
<script src="<?= editLessonEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>
</body>
</html>
