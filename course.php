<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$courseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($courseId === false) {
    redirect('my_courses.php');
    exit;
}
if (array_key_exists('id', $_GET)
    && parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === parse_url(url('course'), PHP_URL_PATH)) {
    redirect('course/' . $courseId, 301);
}
$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_course_api.php')) . '"'
    . ' data-catalog-endpoint="' . student_e(url('api/student_catalog_api.php')) . '"'
    . ' data-course-id="' . $courseId . '"'
    . ' data-csrf="' . student_e(sc_csrf_token()) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'my-courses', 'Course detail', $attributes, ['assests/css/student_course.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= student_e(url('my_courses.php')) ?>">My courses</a> / <span aria-current="page">Course detail</span>
                </nav>

                <p class="alert alert--info" data-status role="status">Loading course…</p>

                <div data-content hidden>
                    <!-- Header -->
                    <section class="course-hero" aria-labelledby="courseTitle">
                        <div class="course-hero__text">
                            <div class="course-hero__tags" data-tags></div>
                            <h1 id="courseTitle" data-title></h1>
                            <p class="text-muted" data-summary></p>
                            <div class="course-hero__meta">
                                <span class="avatar-chip" data-instructor-avatar aria-hidden="true"></span>
                                <span data-instructor class="fw-600"></span>
                            </div>
                            <div class="course-hero__rating" data-rating-line></div>
                        </div>
                        <div class="course-hero__art" data-art aria-hidden="true"></div>
                    </section>

                    <!-- Progress / enrol -->
                    <section class="card course-progress" data-progress aria-label="Your progress"></section>

                    <!-- Tabs -->
                    <div class="course-tabs" role="tablist" aria-label="Course sections">
                        <button class="course-tab" role="tab" id="tab-overview" aria-controls="panel-overview" data-tab="overview" type="button">Overview</button>
                        <button class="course-tab" role="tab" id="tab-lessons" aria-controls="panel-lessons" data-tab="lessons" type="button">Lessons</button>
                        <button class="course-tab" role="tab" id="tab-quizzes" aria-controls="panel-quizzes" data-tab="quizzes" type="button">Quizzes</button>
                        <button class="course-tab" role="tab" id="tab-assignments" aria-controls="panel-assignments" data-tab="assignments" type="button">Assignments</button>
                        <button class="course-tab" role="tab" id="tab-reviews" aria-controls="panel-reviews" data-tab="reviews" type="button">Reviews</button>
                    </div>

                    <!-- Overview -->
                    <section id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" data-panel="overview" class="course-panel">
                        <div class="course-overview">
                            <div class="course-overview__main">
                                <article class="card"><h2 class="card__title">About this course</h2><div data-about></div></article>
                                <article class="card"><h2 class="card__title">What you’ll learn</h2><ul class="learn-list" data-learn></ul></article>
                            </div>
                            <aside class="course-overview__side">
                                <article class="card"><h2 class="card__title">At a glance</h2><ul class="glance-list" data-glance></ul></article>
                                <article class="card card--soft"><h2 class="card__title" data-teacher-title></h2><p class="text-muted" data-teacher-text></p></article>
                            </aside>
                        </div>
                    </section>

                    <!-- Lessons -->
                    <section id="panel-lessons" role="tabpanel" aria-labelledby="tab-lessons" data-panel="lessons" class="course-panel" hidden>
                        <div class="path-head"><h2>Your learning path</h2><span class="text-muted" data-path-meta></span></div>
                        <div data-path></div>
                        <p class="alert alert--info" data-path-note hidden></p>
                    </section>

                    <!-- Quizzes -->
                    <section id="panel-quizzes" role="tabpanel" aria-labelledby="tab-quizzes" data-panel="quizzes" class="course-panel" hidden>
                        <div class="path-head"><h2>Quizzes</h2><span class="text-muted" data-quizzes-meta></span></div>
                        <div class="card path-module"><ul class="path-list" data-quizzes></ul></div>
                    </section>

                    <!-- Assignments -->
                    <section id="panel-assignments" role="tabpanel" aria-labelledby="tab-assignments" data-panel="assignments" class="course-panel" hidden>
                        <div class="path-head"><h2>Assignments</h2><span class="text-muted" data-assignments-meta></span></div>
                        <div class="card path-module"><ul class="path-list" data-assignments></ul></div>
                    </section>

                    <!-- Reviews -->
                    <section id="panel-reviews" role="tabpanel" aria-labelledby="tab-reviews" data-panel="reviews" class="course-panel" hidden>
                        <h2>Learner reviews</h2>
                        <div class="reviews-top">
                            <article class="card" data-summary-card aria-label="Rating summary"></article>
                            <article class="card" data-review-form-card></article>
                        </div>
                        <h3 class="reviews-subhead">What learners say</h3>
                        <div data-review-list></div>
                        <div class="reviews-foot">
                            <span class="text-muted" data-review-count></span>
                            <button class="btn btn-outline btn-sm" type="button" data-more hidden>Load more reviews</button>
                        </div>
                    </section>
                </div>
<?php studentShellClose(['assests/student_course.js']);
