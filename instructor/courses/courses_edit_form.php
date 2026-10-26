
<?php

require_once __DIR__ . '/../../includes/config.php';

$user = currentUser();

if ($user === null) {
    redirect('index.php');
}

if (($user['role'] ?? '') !== 'instructor') {
    redirect(dashboardPath((string) $user['role']));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function editCourseEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Generate CSRF token.
if (!isset($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['instructor_course_form_csrf'];

// Get course ID from the URL.
$courseId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($courseId === null) {
    $queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if (preg_match('/^[1-9][0-9]*$/D', $queryString) === 1) {
        $courseId = filter_var(
            $queryString,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
    }
}

if (!$courseId) {
    http_response_code(400);
    exit('Invalid course ID.');
}

$course = null;
$categories = [];
$categoryLoadError = false;

try {
    // Load only a course owned by the logged-in instructor.
    $statement = db()->prepare(
        'SELECT id, title, category_id, description, thumbnail, status
         FROM courses
         WHERE id = :course_id AND instructor_id = :instructor_id
         LIMIT 1'
    );

    $statement->execute([
        'course_id' => $courseId,
        'instructor_id' => $user['id'],
    ]);

    $course = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        http_response_code(404);
        exit('Course not found, or you do not have permission to edit it.');
    }

    $categories = db()->query(
        'SELECT id, name FROM categories ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $error) {
    error_log('Edit course load failed: ' . $error->getMessage());

    if ($course === null) {
        http_response_code(500);
        exit('Unable to load this course. Please try again later.');
    }

    $categoryLoadError = true;
}

$firstName = explode(' ', trim((string) $user['name']))[0] ?? 'Instructor';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Course | Learning Hub</title>

    <link rel="stylesheet"
          href="<?= editCourseEscape(url('assests/css/theme.css')) ?>">

    <link rel="stylesheet"
          href="<?= editCourseEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require __DIR__ . '/../sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Edit course'; require __DIR__ . '/../header.php'; ?>

        <main class="ins-main ins-course-create ins-course-edit" id="main">

            <section class="ins-hero">
                <div>
                    <h1>Edit course</h1>
                    <p>
                        Welcome, <?= editCourseEscape($firstName) ?>.
                        Update your course information and save your changes.
                    </p>
                </div>

                <div class="ins-hero__actions">
                    <a class="ins-btn"
                       href="<?= editCourseEscape(url('instructor/courses/my_courses.php')) ?>">
                        Back to my courses
                    </a>
                </div>
            </section>

            <?php if (isset($_GET['updated'])): ?>
                <p class="ins-alert ins-course-notice ins-course-notice--success"
                   role="status">
                    Your course was updated successfully.
                </p>
            <?php endif; ?>

            <?php if ($categoryLoadError): ?>
                <p class="ins-alert ins-course-notice" role="alert">
                    Categories could not be loaded. You can still edit the
                    other course details.
                </p>
            <?php endif; ?>

            <p class="ins-alert ins-course-notice"
               id="courseFormMessage"
               role="alert"
               hidden></p>

            <section class="ins-card ins-course-form-card ins-course-edit__card"
                     aria-labelledby="course-form-title">

                <div class="ins-card__head">
                    <div>
                        <h2 id="course-form-title">Course details</h2>
                        <p class="ins-card__sub">
                            Update the fields below and save your changes.
                        </p>
                    </div>
                </div>

                <form class="ins-course-form"
                      id="editCourseForm"
                      action="<?= editCourseEscape(url('api/api_courses.php')) ?>"
                      method="post"
                      enctype="multipart/form-data">

                    <input type="hidden" name="action" value="update">

                    <input type="hidden"
                           name="course_id"
                           value="<?= (int) $course['id'] ?>">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= editCourseEscape($csrfToken) ?>">

                    <div class="ins-course-form__field">
                        <label for="course-title">
                            Course title <span aria-hidden="true">*</span>
                        </label>

                        <input class="form-control"
                               id="course-title"
                               name="title"
                               type="text"
                               maxlength="190"
                               value="<?= editCourseEscape((string) $course['title']) ?>"
                               required>

                        <small>Use a clear title that describes the course.</small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-category">Category</label>

                        <select class="form-control"
                                id="course-category"
                                name="category_id">

                            <option value="">No category</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= (string) ($course['category_id'] ?? '') === (string) $category['id']
                                        ? 'selected'
                                        : '' ?>>

                                    <?= editCourseEscape((string) $category['name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-description">Description</label>

                        <textarea class="form-control"
                                  id="course-description"
                                  name="description"
                                  rows="7"
                                  maxlength="65535"><?= editCourseEscape((string) ($course['description'] ?? '')) ?></textarea>

                        <small>Explain what learners will study.</small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-thumbnail">Course image</label>

                        <?php if (!empty($course['thumbnail'])): ?>
                            <div class="ins-course-current-image">
                                <p class="ins-course-current-image__label">Current course image</p>
                                <img
                                    class="ins-course-current-image__preview"
                                    src="<?= editCourseEscape(url((string) $course['thumbnail'])) ?>"
                                    alt="Current course thumbnail"
                                    loading="lazy"
                                    width="480"
                                    height="320">
                            </div>
                        <?php endif; ?>

                        <input class="form-control"
                               id="course-thumbnail"
                               name="thumbnail"
                               type="file"
                               accept="image/jpeg,image/png,image/webp">

                        <small>
                            Optional. JPEG, PNG, or WebP, up to 5 MB.
                            Leave empty to keep the current image.
                        </small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-status">Course status</label>

                        <select class="form-control"
                                id="course-status"
                                name="status"
                                required>

                            <option value="draft"
                                <?= $course['status'] === 'draft' ? 'selected' : '' ?>>
                                Save as draft
                            </option>

                            <option value="published"
                                <?= $course['status'] === 'published' ? 'selected' : '' ?>>
                                Publish course
                            </option>

                        </select>

                        <small>
                            Draft courses remain unpublished. Published courses
                            become available according to your catalog rules.
                        </small>
                    </div>

                    <div class="ins-course-form__actions">
                        <a class="ins-btn"
                           href="<?= editCourseEscape(url('instructor/courses/my_courses.php')) ?>">
                            Cancel
                        </a>

                        <button class="ins-btn ins-btn--primary"
                                id="editCourseButton"
                                type="submit">
                            Save changes
                        </button>
                    </div>

                </form>
            </section>
        </main>

        <footer class="ins-footer">
            <span>© <?= date('Y') ?> Learning Hub</span>
        </footer>

    </div>
</div>

<script>
    window.courseApiUrl = <?= json_encode(
        url('api/api_courses.php'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>

<script src="<?= editCourseEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
