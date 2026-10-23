
<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';

$user = currentUser();

if (!$user || ($user['role'] ?? '') !== 'instructor') {
    redirect(url('login.php'));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);

if (!$courseId || $courseId < 1) {
    http_response_code(400);
    exit('Invalid course ID.');
}

$stmt = db()->prepare(
    'SELECT id, title FROM courses WHERE id = :course_id AND instructor_id = :instructor_id LIMIT 1'
);
$stmt->execute([
    'course_id' => $courseId,
    'instructor_id' => $user['id'],
]);

$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not have permission to edit it.');
}

$moduleStmt = db()->prepare(
    'SELECT COALESCE(MAX(module_order), 0) + 1
     FROM modules
     WHERE course_id = :course_id'
);
$moduleStmt->execute(['course_id' => $courseId]);
$nextOrder = (int) $moduleStmt->fetchColumn();

if (empty($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['instructor_course_form_csrf'];

function addModuleEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Module | Learning Hub</title>
    <link rel="stylesheet" href="<?= addModuleEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= addModuleEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">
    <main class="ins-main ins-lesson-main">
        <header class="ins-lesson-hero">
        <a class="ins-lesson-back"
               href="<?= addModuleEscape(url('instructor/courses/course_lesson.php?course_id=' . $courseId)) ?>">
                &larr; Back to curriculum
            </a>

            <p class="eyebrow">COURSE CURRICULUM</p>
            <h1>Add a new module</h1>
            <p class="page-description">
                Organize your course into clear learning sections.
            </p>
        </header>

        <section class="ins-card">
            <div class="course-summary">
                <span class="course-icon" aria-hidden="true">&#128218;</span>
                <div>
                    <span class="field-label">COURSE</span>
                    <h2><?= addModuleEscape((string) $course['title']) ?></h2>
                </div>
            </div>

            <div id="formMessage" class="form-message" role="status"
                 aria-live="polite" hidden></div>

            <form
                id="addModuleForm"
                action="<?= addModuleEscape(url('api/api_module.php')) ?>"
                method="POST"
            >
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="csrf_token"
                       value="<?= addModuleEscape($csrfToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="title">Module title <span>*</span></label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-control"
                        maxlength="190"
                        placeholder="e.g. Introduction to Java"
                        required
                        autocomplete="off"
                    >
                    <small>Choose a short, clear title for this section.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-control"
                        rows="4"
                        placeholder="What will students learn in this module?"
                    ></textarea>
                    <small>Optional. Explain what this module covers.</small>
                </div>

                <div class="form-row form-row--2">
                    <div class="form-group">
                        <label class="form-label" for="module_order">Module order <span>*</span></label>
                        <input
                            type="number"
                            id="module_order"
                            name="module_order"
                            class="form-control"
                            min="1"
                            step="1"
                            value="<?= $nextOrder ?>"
                            required
                        >
                        <small>Position in the course curriculum.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="draft" selected>Draft</option>
                            <option value="published">Published</option>
                        </select>
                        <small>You can publish it when ready.</small>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="ins-btn"
                       href="<?= addModuleEscape(url('instructor/courses/course_lesson.php?course_id=' . $courseId)) ?>">
                        Cancel
                    </a>
                    <button class="ins-btn ins-btn--primary" id="submitButton" type="submit">
                        <span id="buttonText">Create module</span>
                    </button>
                </div>
            </form>
        </section>

        <p class="page-footnote">
            You can add lessons to this module after creating it.
        </p>
    </main>
</body>
</html>
