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

if (empty($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

if (!function_exists('escape')) {
    function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Validate GET Parameters
|--------------------------------------------------------------------------
*/

$courseId = filter_input(
    INPUT_GET,
    'course_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$moduleId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$courseId || !$moduleId) {
    http_response_code(400);
    exit('Invalid course or module ID.');
}

$pdo = db();

/*
|--------------------------------------------------------------------------
| Fetch Module & Verify Ownership
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT m.*, c.title AS course_title 
     FROM modules m
     JOIN courses c ON m.course_id = c.id
     WHERE m.id = :module_id
       AND m.course_id = :course_id
       AND c.instructor_id = :instructor_id
     LIMIT 1'
);

$stmt->execute([
    'module_id' => $moduleId,
    'course_id' => $courseId,
    'instructor_id' => (int) $user['id'],
]);

$module = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    http_response_code(404);
    exit('Module not found or you do not have permission to edit it.');
}

$csrfToken = $_SESSION['instructor_course_form_csrf'];
$pageTitle = 'Edit Module: ' . $module['title'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?= escape($pageTitle) ?> | Learning Hub</title>
    
    <link rel="stylesheet" href="<?= escape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= escape(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<main class="ins-main ins-lesson-main ins-lesson-main--form" id="main">
    <header class="ins-lesson-hero ins-lesson-hero--form">
        <div class="ins-lesson-hero__copy">
            <a class="ins-lesson-back"
               href="<?= escape(url('instructor/courses/course_lesson.php?course_id=' . (int) $courseId)) ?>">
                &larr; Back to curriculum
            </a>
            <p class="eyebrow">COURSE CURRICULUM</p>
            <h1>Edit module</h1>
            <p>Update this module's title, description, position, or status.</p>
            <p>Course: <strong><?= escape($module['course_title']) ?></strong></p>
        </div>
    </header>

    <section class="ins-card ins-lesson-form-card ins-module-form-card"
             aria-label="Edit module details">
        <div id="moduleFormMessage" class="ins-lesson-message" role="status"
             aria-live="polite" hidden></div>

        <form id="editModuleForm" method="post"
              action="<?= escape(url('api/api_module.php')) ?>"
              class="ins-lesson-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="module_id" value="<?= (int) $module['id'] ?>">
            <input type="hidden" name="course_id" value="<?= (int) $module['course_id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">

            <div class="ins-lesson-field">
                <label for="moduleTitle">Module title <span aria-hidden="true">*</span></label>
                <input type="text" id="moduleTitle" name="title" maxlength="190"
                       value="<?= escape($module['title']) ?>" required>
            </div>

            <div class="ins-lesson-field">
                <label for="moduleDescription">Description</label>
                <textarea id="moduleDescription" name="description"
                          rows="4"><?= escape($module['description'] ?? '') ?></textarea>
            </div>

            <div class="ins-lesson-field">
                <label for="moduleOrder">Module position</label>
                <input type="number" id="moduleOrder" name="module_order"
                       value="<?= (int) $module['module_order'] ?>"
                       min="1" max="100000" step="1" required>
            </div>

            <div class="ins-lesson-field">
                <label for="moduleStatus">Status</label>
                <select id="moduleStatus" name="status" required>
                    <option value="draft" <?= $module['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= $module['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                </select>
            </div>

            <div class="ins-lesson-form__actions">
                <a class="ins-btn"
                   href="<?= escape(url('instructor/courses/course_lesson.php?course_id=' . (int) $courseId)) ?>">
                    Cancel
                </a>
                <button type="submit" class="ins-btn ins-btn--primary"
                        id="editModuleSubmitButton">Save changes</button>
            </div>
        </form>
    </section>
</main>

<script>
window.moduleApiUrl = <?= json_encode(
    url('api/api_module.php'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;
window.moduleRedirectUrl = <?= json_encode(
    url('instructor/courses/course_lesson.php?course_id=' . (int) $courseId),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;
</script>
<script src="<?= escape(url('assests/instructor_dashboard.js')) ?>" defer></script>
</body>
</html>