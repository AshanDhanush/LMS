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

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('assessmentsPageEscape')) {
    function assessmentsPageEscape(mixed $value): string
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
| Fetch all courses of this instructor with assignment and quiz counts
|--------------------------------------------------------------------------
*/

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        c.id,
        c.title,
        c.status,
        (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id) AS assignment_count,
        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id) AS quiz_count
     FROM courses c
     WHERE c.instructor_id = :instructor_id
     ORDER BY c.title ASC, c.id ASC'
);

$stmt->execute(['instructor_id' => (int) $user['id']]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

$totalCourses = count($courses);
$totalAssignments = 0;
$totalQuizzes = 0;

foreach ($courses as $course) {
    $totalAssignments += (int) $course['assignment_count'];
    $totalQuizzes += (int) $course['quiz_count'];
}

$pageTitle = 'Assignments and Quizzes';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= assessmentsPageEscape($pageTitle) ?>
        | Learning Hub
    </title>

    <link
        rel="stylesheet"
        href="<?= assessmentsPageEscape(url('assests/css/theme.css')) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= assessmentsPageEscape(url('assests/css/instructor_dashboard.css')) ?>"
    >
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Assignments & grading'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-main ins-curriculum" id="main">

    <!-- Header -->
    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Assignments and Quizzes</h1>
            <p>See how many assignments and quizzes each of your courses has.</p>
        </div>
    </header>

    <?php if (($_GET['added'] ?? '') === 'assignment'): ?>
        <div class="ins-alert ins-course-notice ins-course-notice--success" role="status">
            Assignment added successfully.
        </div>
    <?php elseif (($_GET['added'] ?? '') === 'quiz'): ?>
        <div class="ins-alert ins-course-notice ins-course-notice--success" role="status">
            Quiz added successfully.
        </div>
    <?php endif; ?>

    <!-- Totals -->
    <section class="ins-curr-stats ins-assess-stats" aria-label="Totals">

        <article class="ins-curr-stat">
            <span class="label">Courses</span>
            <span class="value"><?= $totalCourses ?></span>
        </article>

        <article class="ins-curr-stat">
            <span class="label">Assignments</span>
            <span class="value"><?= $totalAssignments ?></span>
        </article>

        <article class="ins-curr-stat">
            <span class="label">Quizzes</span>
            <span class="value"><?= $totalQuizzes ?></span>
        </article>

    </section>

    <?php if (!$courses): ?>

        <div class="ins-curr-empty-state">
            <h2>No courses yet</h2>
            <p>You have not created any courses.</p>
        </div>

    <?php else: ?>

        <!-- Search -->
        <div class="ins-assess-toolbar">
            <input
                type="search"
                id="assessSearch"
                class="ins-input ins-assess-search"
                placeholder="Search courses..."
                aria-label="Search courses"
            >
        </div>

        <!-- Course cards -->
        <section class="ins-assess-grid" aria-label="Courses">
            <?php foreach ($courses as $course): ?>
                <article
                    class="ins-assess-card"
                    data-assess-card
                    data-assess-title="<?= assessmentsPageEscape(
                        mb_strtolower((string) $course['title'], 'UTF-8')
                    ) ?>"
                >
                    <header class="ins-assess-card__head">
                        <h2 class="ins-assess-card__title">
                            <?= assessmentsPageEscape($course['title']) ?>
                        </h2>

                        <span class="ins-chip <?= $course['status'] === 'published'
                            ? 'ins-chip--success'
                            : 'ins-chip--muted' ?>">
                            <?= assessmentsPageEscape(ucfirst((string) $course['status'])) ?>
                        </span>
                    </header>

                    <div class="ins-assess-card__stats">

                        <a class="ins-assess-tile ins-assess-tile--assignment" href="<?= assessmentsPageEscape(url(
                            'instructor/assignment and grading/assessment_list.php?course_id='
                            . (int) $course['id'] . '&type=assignment'
                        )) ?>">
                            <span class="ins-assess-tile__value">
                                <?= (int) $course['assignment_count'] ?>
                            </span>
                            <span class="ins-assess-tile__label">
                                Assignment<?= (int) $course['assignment_count'] === 1 ? '' : 's' ?>
                            </span>
                        </a>

                        <a class="ins-assess-tile ins-assess-tile--quiz" href="<?= assessmentsPageEscape(url(
                            'instructor/assignment and grading/assessment_list.php?course_id='
                            . (int) $course['id'] . '&type=quiz'
                        )) ?>">
                            <span class="ins-assess-tile__value">
                                <?= (int) $course['quiz_count'] ?>
                            </span>
                            <span class="ins-assess-tile__label">
                                Quiz<?= (int) $course['quiz_count'] === 1 ? '' : 'zes' ?>
                            </span>
                        </a>

                    </div>

                    <footer class="ins-assess-card__footer">
                        <a
                            class="ins-btn ins-btn--small"
                            href="<?= assessmentsPageEscape(url(
                                'instructor/assignment and grading/assessment_list.php?course_id='
                                . (int) $course['id']
                            )) ?>"
                        >
                            View all
                        </a>
                        <a
                            class="ins-btn ins-btn--primary ins-btn--small"
                            href="<?= assessmentsPageEscape(url(
                                'instructor/assignment and grading/assessment_add_form?course_id='
                                . (int) $course['id']
                            )) ?>"
                        >
                            + Add assignment or quiz
                        </a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>

        <p id="assessNoResults" class="ins-curr-empty-state" hidden>
            No courses match your search.
        </p>

    <?php endif; ?>

</main>

    </div>
</div>

<script src="<?= assessmentsPageEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>