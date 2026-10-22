
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

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('courseModulesPageEscape')) {
    function courseModulesPageEscape(mixed $value): string
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
| Validate course ID
|--------------------------------------------------------------------------
*/

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

$pdo = db();

/*
|--------------------------------------------------------------------------
| Verify course ownership
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT id, title, status
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

/*
|--------------------------------------------------------------------------
| Fetch lessons belonging to this course
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        l.id,
        l.course_id,
        l.title,
        l.type,
        l.position,
        COUNT(DISTINCT lp.id) AS completion_count
     FROM lessons l
     LEFT JOIN lesson_progress lp
        ON lp.lesson_id = l.id
     WHERE l.course_id = :course_id
     GROUP BY
        l.id,
        l.course_id,
        l.title,
        l.type,
        l.position
     ORDER BY l.position ASC, l.id ASC'
);

$stmt->execute(['course_id' => $courseId]);

$allLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Calculate statistics
|--------------------------------------------------------------------------
*/

$totalLessons = count($allLessons);

$videoLessons = 0;
$pdfLessons = 0;
$textLessons = 0;

foreach ($allLessons as $lesson) {
    switch ($lesson['type']) {
        case 'video':
            $videoLessons++;
            break;

        case 'pdf':
            $pdfLessons++;
            break;

        case 'text':
            $textLessons++;
            break;
    }
}

$csrfToken = $_SESSION['instructor_course_form_csrf'];
$pageTitle = 'Manage Course Curriculum';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= courseModulesPageEscape($pageTitle) ?>
        | Learning Hub
    </title>

    <link
        rel="stylesheet"
        href="<?= courseModulesPageEscape(url('assests/css/theme.css')) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= courseModulesPageEscape(url('assests/css/instructor_dashboard.css')) ?>"
    >

    
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<main class="ins-main ins-curriculum" id="main">

    <!-- Header -->
    <header class="ins-curr-header">

        <div class="ins-curr-header__left">

            <nav class="ins-breadcrumbs" aria-label="Breadcrumb">
                <a href="<?= courseModulesPageEscape(
                    url('instructor/courses/my_courses.php')
                ) ?>">My Courses</a>

                &rsaquo;

                <span>
                    <?= courseModulesPageEscape($course['title']) ?>
                </span>
            </nav>

            <h1>Manage Course Lessons</h1>

            <p>
                Add, edit, and manage the lessons in this course.
            </p>

        </div>

        <div class="ins-curr-header__actions">

            <a
                class="ins-btn ins-btn--outline-primary"
                href="<?= courseModulesPageEscape(
                    url('instructor/lessons/lesson_add_form.php?' . (int) $courseId)
                ) ?>"
            >
                + Add Lesson
            </a>
            <a
                class="ins-btn ins-btn--outline-primary"
                href="<?= courseModulesPageEscape(
                    url('instructor/modules/module_add_form.php?' . (int) $courseId)
                ) ?>"
            >
                + Add Module
            </a>


        </div>

    </header>

    <!-- Statistics -->
    <section class="ins-curr-stats" aria-label="Curriculum statistics">

        <article class="ins-curr-stat">
            <span class="label">Total Lessons</span>
            <span class="value">
                <?= $totalLessons ?>
                <small>Lessons</small>
            </span>
        </article>

        <article class="ins-curr-stat">
            <span class="label">Video Lessons</span>
            <span class="value">
                <?= $videoLessons ?>
                <small>Videos</small>
            </span>
        </article>

        <article class="ins-curr-stat">
            <span class="label">PDF Lessons</span>
            <span class="value">
                <?= $pdfLessons ?>
                <small>PDFs</small>
            </span>
        </article>

        <article class="ins-curr-stat">
            <span class="label">Text Lessons</span>
            <span class="value">
                <?= $textLessons ?>
                <small>Text</small>
            </span>
        </article>

    </section>

    <!-- Main curriculum layout -->
    <div class="ins-curr-layout">

        <!-- Course lessons -->
        <section class="ins-curr-content" aria-label="Course lessons">

            <div class="ins-curr-filters">

                <input
                    type="search"
                    id="curriculumSearch"
                    class="ins-input"
                    placeholder="Search lessons..."
                    aria-label="Search lessons"
                >

                <span>
                    <?= $totalLessons ?>
                    lesson<?= $totalLessons === 1 ? '' : 's' ?>
                </span>

            </div>

            <p class="ins-lesson-message" id="lessonPageMessage" role="status" aria-live="polite"></p>

            <article class="ins-module curriculum-module" data-lesson-card>

                        <!-- Module heading -->
                        <header class="ins-module__header">

                            <div class="ins-module__title">
                                <h2>Course Lessons</h2>

                            </div>

                            <div class="ins-module__meta">
                                <?= $totalLessons ?>
                                lesson<?= $totalLessons === 1 ? '' : 's' ?>
                            </div>

                        </header>

                        <!-- Lesson actions -->
                        <div class="ins-curr-module-actions">

                            <a
                                class="ins-btn ins-btn--primary ins-btn--small"
                                href="<?= courseModulesPageEscape(
                                    url(
                                        'instructor/lessons/lesson_add_form.php?'
                                        . (int) $courseId
                                    )
                                ) ?>"
                            >
                                + Add Lesson
                            </a>

                        </div>

                        <!-- Lessons belonging to this course -->
                        <div class="ins-module__lessons">

                            <?php if (!$allLessons): ?>

                                <p class="ins-curr-empty">
                                    This course does not have any lessons yet.
                                </p>

                            <?php else: ?>

                                <?php foreach ($allLessons as $lesson): ?>

                                    <div class="ins-lesson-row" data-lesson-row>

                                        <div
                                            class="ins-lesson-icon"
                                            aria-hidden="true"
                                        >
                                            <?php
                                            echo match ($lesson['type']) {
                                                'video' => '▶',
                                                'pdf' => 'PDF',
                                                default => 'TXT',
                                            };
                                            ?>
                                        </div>

                                        <div class="ins-lesson-details">

                                            <span
                                                class="ins-lesson-title"
                                                data-lesson-title
                                            >
                                                <?= (int) $lesson['position'] ?>.
                                                <?= courseModulesPageEscape(
                                                    $lesson['title']
                                                ) ?>
                                            </span>

                                            <div class="ins-lesson-meta">

                                                <span class="ins-tag">
                                                    <?= courseModulesPageEscape(
                                                        strtoupper((string) $lesson['type'])
                                                    ) ?>
                                                </span>

                                                <span>
                                                    <?= number_format(
                                                        (int) $lesson['completion_count']
                                                    ) ?>
                                                    completed
                                                </span>

                                            </div>

                                        </div>

                                        <div class="ins-lesson-actions">

                                            <a
                                                class="ins-btn-icon"
                                                href="<?= courseModulesPageEscape(
                                                    url(
                                                        'instructor/lessons/lesson_edit_form.php?'
                                                        . (int) $lesson['id']
                                                    )
                                                ) ?>"
                                            >
                                                Edit
                                            </a>

                                            <form
                                                method="post"
                                                action="<?= courseModulesPageEscape(
                                                    url('api/api_lesson.php')
                                                ) ?>"
                                                class="lessonDeleteForm"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="course_id"
                                                    value="<?= (int) $courseId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="lesson_id"
                                                    value="<?= (int) $lesson['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= courseModulesPageEscape($csrfToken) ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="ins-btn-icon ins-btn-icon--danger"
                                                >
                                                    Delete
                                                </button>

                                            </form>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    </article>

            <p
                id="curriculumNoResults"
                class="ins-curr-empty-state"
                hidden
            >
                No lessons match your search.
            </p>

        </section>

        <!-- Sidebar -->
        <aside class="ins-curr-sidebar">

            <section class="ins-widget">

                <h3>Curriculum Overview</h3>

                <ul class="ins-checklist">

                    <li class="<?= $totalLessons > 0 ? 'checked' : 'pending' ?>">
                        <?= $totalLessons > 0 ? '✓' : '○' ?>
                        At least one lesson added
                    </li>

                    <li class="<?= $course['status'] === 'published' ? 'checked' : 'pending' ?>">
                        <?= $course['status'] === 'published' ? '✓' : '○' ?>
                        Course published
                    </li>

                </ul>

                <p class="ins-text-sm">
                    These checks summarize your current curriculum.
                </p>

            </section>

            <section class="ins-widget">

                <h3>Course Information</h3>

                <p>
                    <strong>Course</strong><br>
                    <?= courseModulesPageEscape($course['title']) ?>
                </p>

                <p>
                    <strong>Course status</strong><br>
                    <?= courseModulesPageEscape(
                        ucfirst((string) $course['status'])
                    ) ?>
                </p>

                <p>
                    <strong>Lesson types</strong><br>
                    Text: <?= $textLessons ?><br>
                    Video: <?= $videoLessons ?><br>
                    PDF: <?= $pdfLessons ?>
                </p>

                <a
                    class="ins-btn ins-btn--full"
                    href="<?= courseModulesPageEscape(
                        url('instructor/courses/my_courses.php')
                    ) ?>"
                >
                    Back to My Courses
                </a>

            </section>

        </aside>

    </div>

</main>

<script>
window.lessonApiUrl = <?= json_encode(url('api/api_lesson.php')) ?>;

(() => {
    'use strict';

    const searchInput = document.getElementById('curriculumSearch');
    const lessonCards = document.querySelectorAll('[data-lesson-card]');
    const noResults = document.getElementById('curriculumNoResults');

    if (!searchInput) {
        return;
    }

    function filterCurriculum() {
        const query = searchInput.value.trim().toLowerCase();
        let visibleLessons = 0;

        lessonCards.forEach((lessonCard) => {
            const lessonRows = lessonCard.querySelectorAll('[data-lesson-row]');
            lessonRows.forEach((lessonRow) => {
                const lessonTitle = lessonRow.querySelector('[data-lesson-title]');
                const lessonTitleText = lessonTitle
                    ? lessonTitle.textContent.toLowerCase()
                    : '';

                const lessonMatches = !query || lessonTitleText.includes(query);

                lessonRow.hidden = !lessonMatches;

                if (lessonMatches) {
                    visibleLessons++;
                }
            });
        });

        if (noResults) {
            noResults.hidden = visibleLessons > 0 || query === '';
        }
    }

    searchInput.addEventListener('input', filterCurriculum);
})();
</script>
<script src="<?= courseModulesPageEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
