<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$lessonId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($lessonId === false) {
    redirect('my_courses.php');
    exit;
}
if (array_key_exists('id', $_GET)
    && parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === parse_url(url('lesson'), PHP_URL_PATH)) {
    redirect('lesson/' . $lessonId, 301);
}
$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_lesson_api.php')) . '"'
    . ' data-lesson-id="' . $lessonId . '"'
    . ' data-csrf="' . student_e(sc_csrf_token()) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'my-courses', 'Lesson', $attributes, ['assests/css/student_course.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= student_e(url('my_courses.php')) ?>">My courses</a> /
                    <a href="#" data-back>Back to course</a>
                </nav>

                <p class="alert alert--info" data-status role="status">Loading lesson…</p>

                <div data-content hidden>
                    <header class="lesson-head">
                        <h1 class="lesson-head__course" data-course-title></h1>
                        <p class="text-muted" data-course-sub></p>
                    </header>

                    <div class="lesson-layout">
                        <div class="lesson-main">
                            <div class="lesson-media" data-media></div>

                            <section class="lesson-body" aria-labelledby="lessonTitle">
                                <div class="lesson-body__meta">
                                    <span class="text-muted fw-600" data-position></span>
                                    <span class="chip" data-state></span>
                                </div>
                                <h2 id="lessonTitle" data-title></h2>
                                <div class="lesson-text" data-text></div>
                                <div class="lesson-files" data-files></div>

                                <div class="lesson-complete">
                                    <button class="btn btn-primary" type="button" data-complete>Mark as complete</button>
                                    <span class="text-muted" data-complete-hint>Finished learning? Save your progress.</span>
                                </div>
                                <p class="alert alert--success" data-finished hidden></p>
                                <p class="form-message" data-lesson-message role="status"></p>

                                <nav class="lesson-nav" aria-label="Lesson navigation">
                                    <a class="btn btn-outline" data-prev href="#">‹ Previous</a>
                                    <a class="btn btn-outline" data-next href="#">Next ›</a>
                                </nav>
                            </section>

                            <section class="lesson-comments" aria-labelledby="commentsTitle">
                                <div class="lesson-comments__head">
                                    <h2 id="commentsTitle">Comments</h2>
                                    <span class="text-muted" data-comment-count></span>
                                </div>
                                <p class="text-muted">Share an insight or ask a question.</p>
                                <div class="card comment-form">
                                    <label class="form-label" for="commentText">Your comment</label>
                                    <textarea class="form-control" id="commentText" rows="3" maxlength="500" placeholder="Ask a question or share what you learned…"></textarea>
                                    <div class="comment-form__row">
                                        <small class="text-muted" data-comment-counter>Be thoughtful and respectful. 0 / 500</small>
                                        <button class="btn btn-primary btn-sm" type="button" data-post>Post comment</button>
                                    </div>
                                    <p class="form-message" data-comment-message role="status"></p>
                                </div>
                                <div data-comments></div>
                            </section>
                        </div>

                        <aside class="card lesson-side" aria-label="Course lessons">
                            <div class="lesson-side__head">
                                <h2 class="card__title">Course lessons</h2>
                                <span class="text-muted" data-side-count></span>
                            </div>
                            <div data-side-progress></div>
                            <p class="text-muted" data-side-note></p>
                            <ol class="side-list" data-side-list></ol>
                        </aside>
                    </div>
                </div>
<?php studentShellClose(['assests/student_lesson.js']);
