<?php 

require_once __DIR__ . '/../includes/config.php';

$user = currentUser();

if ($user === null) {
    redirect('');
}

if (($user['role'] ?? '') !== 'instructor') {
    redirect(dashboardPath((string) $user['role']));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function courseFormEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (!isset($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['instructor_course_form_csrf'];

$categories = [];
$categoryLoadError = false;

try {
    $categories = db()->query(
        'SELECT id, name FROM categories ORDER BY name ASC'
    )->fetchAll();
} catch (PDOException $error) {
    error_log('Instructor course categories failed to load: ' . $error->getMessage());
    $categoryLoadError = true;
}

$firstName = explode(' ', trim((string) $user['name']))[0] ?? 'Instructor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create a Course | Learning Hub</title>

    <link rel="stylesheet"
          href="<?= courseFormEscape(url('assests/css/theme.css')) ?>">

    <link rel="stylesheet"
          href="<?= courseFormEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <aside class="ins-sidebar">
        <a class="ins-brand" href="<?= courseFormEscape(url('instructor/dashboard')) ?>">
            <span class="ins-brand__mark" aria-hidden="true">L</span>

            <span class="ins-brand__text">
                <strong>Learning Hub</strong>
                <small>LMS Academy</small>
            </span>
        </a>

        <span class="ins-portal">Instructor portal</span>

        <nav aria-label="Instructor menu">
            <ul class="ins-nav">
                <li>
                    <a class="ins-nav__item"
                       href="<?= courseFormEscape(url('instructor/dashboard')) ?>">
                        <span>Dashboard</span>
                    </a>
                </li>

                <li>
                    <a class="ins-nav__item is-current"
                       href="<?= courseFormEscape(url('instructor/coureses_add_form.php')) ?>"
                       aria-current="page">
                        <span>Create a course</span>
                    </a>
                </li>

                <li>
                    <a class="ins-nav__item"
                       href="<?= courseFormEscape(url('profile')) ?>">
                        <span>Profile &amp; settings</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="ins-body">

        <header class="ins-topbar">
            <span class="ins-topbar__title">Create a course</span>
            <span class="ins-topbar__spacer"></span>

            <a class="ins-user"
               href="<?= courseFormEscape(url('profile')) ?>"
               aria-label="View profile for <?= courseFormEscape((string) $user['name']) ?>">

                <?php if (!empty($user['photo'])): ?>
                    <img
                        class="ins-user__avatar"
                        src="<?= courseFormEscape(url((string) $user['photo'])) ?>"
                        alt=""
                        aria-hidden="true">
                <?php else: ?>
                    <span class="ins-user__avatar" aria-hidden="true">
                        <?= courseFormEscape(
                            mb_strtoupper(
                                mb_substr((string) $user['name'], 0, 1, 'UTF-8'),
                                'UTF-8'
                            )
                        ) ?>
                    </span>
                <?php endif; ?>

                <span class="ins-user__text">
                    <span class="ins-user__name">
                        <?= courseFormEscape((string) $user['name']) ?>
                    </span>
                    <span class="ins-user__role">Instructor</span>
                </span>
            </a>

            <button
                class="ins-btn"
                type="button"
                id="signOut"
                data-logout-url="<?= courseFormEscape(url('api/auth_api.php?action=logout')) ?>"
                data-login-url="<?= courseFormEscape(url('index.php')) ?>">
                Sign out
            </button>
        </header>

        <main class="ins-main ins-course-create" id="main">

            <section class="ins-hero">
                <div>
                    <h1>Create a course</h1>
                    <p>
                        Welcome, <?= courseFormEscape($firstName) ?>.
                        Add the details for your new course, then save it as a
                        draft or publish it.
                    </p>
                </div>

                <div class="ins-hero__actions">
                    <a class="ins-btn"
                       href="<?= courseFormEscape(url('instructor/dashboard.php#courses-title')) ?>">
                        Back to dashboard
                    </a>
                </div>
            </section>

            <?php if (isset($_GET['created'])): ?>
                <p class="ins-alert ins-course-notice ins-course-notice--success"
                   role="status">
                    Your course was created successfully.
                </p>
            <?php endif; ?>

            <?php if ($categoryLoadError): ?>
                <p class="ins-alert ins-course-notice" role="alert">
                    Course categories could not be loaded.
                    Please reload before creating a course.
                </p>
            <?php endif; ?>

            <p
                class="ins-alert ins-course-notice"
                id="courseFormMessage"
                role="alert"
                hidden>
            </p>

            <section class="ins-card ins-course-form-card"
                     aria-labelledby="course-form-title">

                <div class="ins-card__head">
                    <div>
                        <h2 id="course-form-title">Course details</h2>
                        <p class="ins-card__sub">
                            Fields marked required must be completed.
                        </p>
                    </div>
                </div>

                <form
                    class="ins-course-form"
                    id="createCourseForm"
                    action="<?= courseFormEscape(url('api/api_courses.php')) ?>"
                    method="post"
                    enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= courseFormEscape($csrfToken) ?>">

                    <div class="ins-course-form__field">
                        <label for="course-title">
                            Course title <span aria-hidden="true">*</span>
                        </label>

                        <input
                            class="form-control"
                            id="course-title"
                            name="title"
                            type="text"
                            maxlength="190"
                            required>

                        <small>
                            Use a clear title that describes what learners will study.
                        </small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-category">Category</label>

                        <select
                            class="form-control"
                            id="course-category"
                            name="category_id">

                            <option value="">No category</option>

                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>">
                                    <?= courseFormEscape((string) $category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-description">Description</label>

                        <textarea
                            class="form-control"
                            id="course-description"
                            name="description"
                            rows="7"
                            maxlength="65535"></textarea>

                        <small>
                            Tell learners what the course covers and what they
                            can expect to learn.
                        </small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-thumbnail">Course image</label>

                        <input
                            class="form-control"
                            id="course-thumbnail"
                            name="thumbnail"
                            type="file"
                            accept="image/jpeg,image/png,image/webp">

                        <small>
                            Optional. JPEG, PNG, or WebP, up to 5 MB.
                        </small>
                    </div>

                    <div class="ins-course-form__field">
                        <label for="course-status">Course status</label>

                        <select
                            class="form-control"
                            id="course-status"
                            name="status"
                            required>

                            <option value="draft">Save as draft</option>
                            <option value="published">Publish course</option>
                        </select>

                        <small>
                            Draft courses remain unpublished. Published courses
                            become available according to your catalog rules.
                        </small>
                    </div>

                    <div class="ins-course-form__actions">
                        <a
                            class="ins-btn"
                            href="<?= courseFormEscape(url('instructor/dashboard.php#courses-title')) ?>">
                            Cancel
                        </a>

                        <button
                            class="ins-btn ins-btn--primary"
                            id="createCourseButton"
                            type="submit"
                            <?= $categoryLoadError ? 'disabled' : '' ?>>
                            Create course
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

<script src="<?= courseFormEscape(url('assests/instructor.js')) ?>" defer></script>

</body>
</html>
