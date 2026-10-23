
<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user = currentUser();

if (!$user || ($user['role'] ?? '') !== 'instructor') {
    redirect(url('index.php'));
    exit;
}

// Generate CSRF token.
if (empty($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

// Validate course ID.
$courseId = filter_var(
    $_GET['course_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$courseId) {
    http_response_code(400);
    exit('Invalid course ID.');
}

// Verify course ownership.
$stmt = db()->prepare(
    'SELECT id, title
     FROM courses
     WHERE id = :course_id
       AND instructor_id = :instructor_id
     LIMIT 1'
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

// Load modules belonging to this course.
$moduleStmt = db()->prepare(
    'SELECT id, title
     FROM modules
     WHERE course_id = :course_id
     ORDER BY module_order ASC, id ASC'
);

$moduleStmt->execute(['course_id' => $courseId]);

$modules = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

// Validate optional module selection.
$selectedModuleId = null;

if (isset($_GET['module_id']) && $_GET['module_id'] !== '') {
    $validatedModuleId = filter_var(
        $_GET['module_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($validatedModuleId === false) {
        http_response_code(400);
        exit('Invalid module ID.');
    }

    foreach ($modules as $module) {
        if ((int) $module['id'] === $validatedModuleId) {
            $selectedModuleId = $validatedModuleId;
            break;
        }
    }

    if ($selectedModuleId === null) {
        http_response_code(400);
        exit('The selected module does not belong to this course.');
    }
}

// Calculate the next lesson position.
if ($selectedModuleId !== null) {
    $positionStmt = db()->prepare(
        'SELECT COALESCE(MAX(position), 0) + 1
         FROM lessons
         WHERE course_id = :course_id
           AND module_id = :module_id'
    );

    $positionStmt->execute([
        'course_id' => $courseId,
        'module_id' => $selectedModuleId,
    ]);
} else {
    $positionStmt = db()->prepare(
        'SELECT COALESCE(MAX(position), 0) + 1
         FROM lessons
         WHERE course_id = :course_id
           AND module_id IS NULL'
    );

    $positionStmt->execute(['course_id' => $courseId]);
}

$nextPosition = (int) $positionStmt->fetchColumn();

// Escape output.
function addLessonEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$backUrl = url(
    'instructor/course_modules.php?course_id=' . (int) $courseId
);

$apiUrl = url('api/api_lesson.php');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Add Lesson | Learning Hub</title>

    <link
        rel="stylesheet"
        href="<?= addLessonEscape(url('assests/css/theme.css')) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= addLessonEscape(url('assests/css/instructor_dashboard.css')) ?>"
    >
</head>

<body class="ins">

<main class="ins-main ins-lesson-main ins-lesson-main--form">

    <header class="ins-lesson-hero ins-lesson-hero--form">
        <div class="ins-lesson-hero__copy">

            <a class="ins-lesson-back"
               href="<?= addLessonEscape($backUrl) ?>">
                &larr; Back to course curriculum
            </a>

            <h1>Add lesson</h1>

            <p>
                Course:
                <strong><?= addLessonEscape($course['title']) ?></strong>
            </p>

        </div>
    </header>

    <section class="ins-card ins-lesson-form-card"
             aria-label="New lesson details">

        <form
            id="lessonForm"
            method="post"
            action="<?= addLessonEscape($apiUrl) ?>"
            enctype="multipart/form-data"
            class="ins-lesson-form"
        >

            <input type="hidden" name="action" value="create">

            <input type="hidden"
                   name="course_id"
                   value="<?= (int) $courseId ?>">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= addLessonEscape(
                    $_SESSION['instructor_course_form_csrf']
                ) ?>"
            >

            <!-- Lesson title -->
            <div class="ins-lesson-field">
                <label for="lessonTitle">Lesson title</label>

                <input
                    id="lessonTitle"
                    name="title"
                    type="text"
                    maxlength="190"
                    required
                    placeholder="e.g. Introduction to HTML"
                >
            </div>

            <!-- Module -->
            <div class="ins-lesson-field">
                <label for="lessonModule">Module</label>

                <select id="lessonModule" name="module_id">
                    <option value="">Unassigned</option>

                    <?php foreach ($modules as $module): ?>
                        <option
                            value="<?= (int) $module['id'] ?>"
                            <?= $selectedModuleId === (int) $module['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= addLessonEscape($module['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (empty($modules)): ?>
                    <small>
                        No modules exist yet. You can create one first
                        or leave this lesson unassigned.
                    </small>
                <?php endif; ?>
            </div>

            <!-- Lesson type -->
            <div class="ins-lesson-field">
                <label for="lessonType">Lesson type</label>

                <select id="lessonType" name="type" required>
                    <option value="text">Text lesson</option>
                    <option value="pdf">PDF lesson</option>
                    <option value="powerpoint">PowerPoint lesson</option>
                    <option value="video">Video lesson</option>
                </select>
            </div>

            <!-- Text content -->
            <div id="lessonTextGroup" class="ins-lesson-field">
                <label for="lessonContent">Lesson content</label>

                <textarea
                    id="lessonContent"
                    name="content"
                    rows="8"
                    placeholder="Write the lesson content here"
                ></textarea>

                <small>
                    Enter the text that students will read.
                </small>
            </div>

            <!-- PDF upload -->
            <div id="lessonPdfGroup"
                 class="ins-lesson-field"
                 hidden>

                <label for="lessonPdf">PDF file (maximum 10 MB)</label>

                <input
                    id="lessonPdf"
                    name="lesson_pdf"
                    type="file"
                    accept=".pdf,application/pdf"
                >

                <small>Allowed format: PDF.</small>
            </div>

            <!-- PowerPoint upload -->
            <div id="lessonPowerPointGroup"
                 class="ins-lesson-field"
                 hidden>

                <label for="lessonPowerPoint">
                    PowerPoint file (maximum 10 MB)
                </label>

                <input
                    id="lessonPowerPoint"
                    name="lesson_powerpoint"
                    type="file"
                    accept=".ppt,.pptx,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation"
                >

                <small>Allowed formats: PPT and PPTX.</small>
            </div>

            <!-- Optional video URL for any lesson type -->
            <div id="lessonVideoGroup" class="ins-lesson-field">
                <label for="lessonVideoUrl">
                    Video URL (optional)
                </label>

                <input
                    id="lessonVideoUrl"
                    name="video_url"
                    type="url"
                    maxlength="1000"
                    placeholder="https://www.youtube.com/watch?v=..."
                >

                <small>
                    You can leave this empty. Add a video link if you want
                    students to watch a related video.
                </small>
            </div>

            <!-- Position -->
            <div class="ins-lesson-field">
                <label for="lessonPosition">Lesson position</label>

                <input
                    id="lessonPosition"
                    name="position"
                    type="number"
                    min="1"
                    max="100000"
                    value="<?= $nextPosition ?>"
                    required
                >

                <small>
                    Determines the order of this lesson in its module.
                </small>
            </div>

            <p id="lessonFormMessage"
               class="ins-lesson-message"
               role="status"
               aria-live="polite"></p>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn"
                   href="<?= addLessonEscape($backUrl) ?>">
                    Cancel
                </a>

                <button
                    class="ins-btn ins-btn--primary"
                    id="lessonSubmitButton"
                    type="submit"
                >
                    Create lesson
                </button>
            </div>

        </form>
    </section>
</main>

<script>
window.lessonApiUrl = <?= json_encode(
    $apiUrl,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;
</script>

<script
    src="<?= addLessonEscape(url('assests/instructor_dashboard.js')) ?>"
    defer
></script>

</body>
</html>
