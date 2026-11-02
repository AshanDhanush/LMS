<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$quizId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($quizId === false) {
    redirect('my_courses.php');
    exit;
}
if (array_key_exists('id', $_GET)
    && parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === parse_url(url('quiz'), PHP_URL_PATH)) {
    redirect('quiz/' . $quizId, 301);
}
$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_quiz_api.php')) . '"'
    . ' data-quiz-id="' . $quizId . '"'
    . ' data-student="' . student_e((string) ($student['name'] ?? '')) . '"'
    . ' data-csrf="' . student_e(sc_csrf_token()) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'my-courses', 'Quiz', $attributes, ['assests/css/student_course.css', 'assests/css/student_quiz.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="#" data-back>‹ Back to course</a> / <span aria-current="page">Quiz</span>
                </nav>

                <p class="alert alert--info" data-status role="status">Loading quiz…</p>

                <div data-content hidden>
                    <header class="quiz-head">
                        <p class="quiz-head__course" data-course-title></p>
                        <p class="text-muted" data-course-sub></p>
                    </header>

                    <!-- 1. Intro -->
                    <section class="card quiz-card" data-view="intro" aria-labelledby="quizTitle">
                        <div class="quiz-card__tag"><span class="quiz-card__icon" aria-hidden="true">✎</span><span class="chip chip--primary">Course quiz</span></div>
                        <h1 id="quizTitle" data-title></h1>
                        <p class="text-muted" data-description></p>

                        <div class="quiz-stats" data-stats></div>

                        <h2 class="quiz-subhead">Before you begin</h2>
                        <ol class="quiz-rules" data-rules></ol>

                        <div class="alert alert--info quiz-reward" data-reward></div>
                        <p class="alert alert--warning" data-blocked hidden></p>
                        <div class="quiz-best" data-best hidden></div>

                        <button class="btn btn-primary" type="button" data-start>Start quiz</button>
                        <p class="form-message" data-intro-message role="status"></p>

                        <div data-history hidden>
                            <h2 class="quiz-subhead">Your attempts</h2>
                            <ol class="quiz-history" data-history-list></ol>
                        </div>
                    </section>

                    <!-- 2. Question -->
                    <section class="quiz-flow" data-view="question" hidden aria-label="Quiz questions">
                        <h1 class="quiz-flow__title" data-flow-title></h1>
                        <div class="card quiz-card quiz-card--narrow">
                            <div class="quiz-progress__top">
                                <span data-q-position></span>
                                <span class="fw-600" data-q-answered></span>
                            </div>
                            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-q-progress aria-label="Questions answered"><div class="progress__bar"></div></div>
                            <p class="text-muted quiz-attempt" data-q-attempt></p>

                            <fieldset class="quiz-question" data-q-body></fieldset>

                            <div class="quiz-note" data-q-note role="status"></div>

                            <div class="quiz-actions">
                                <button class="btn btn-outline" type="button" data-prev>‹ Previous</button>
                                <button class="btn btn-outline" type="button" data-next>Next ›</button>
                            </div>
                            <button class="btn btn-primary btn-block" type="button" data-submit>Submit quiz</button>
                            <p class="form-message" data-q-message role="alert"></p>
                        </div>
                    </section>

                    <!-- 3. Result -->
                    <section class="quiz-result" data-view="result" hidden aria-labelledby="resultTitle">
                        <h1 class="quiz-flow__title" data-result-quiz></h1>
                        <div class="card quiz-card">
                            <div class="quiz-result__top">
                                <div class="quiz-ring" data-ring></div>
                                <div>
                                    <p class="quiz-result__attempt text-muted" data-result-attempt></p>
                                    <h2 id="resultTitle" tabindex="-1" data-result-title></h2>
                                    <p class="text-muted" data-result-text></p>
                                </div>
                            </div>
                            <div class="alert" data-result-banner role="status"></div>
                            <div class="quiz-result__chips" data-result-chips></div>
                            <p class="text-muted" data-result-reward></p>
                            <div class="quiz-actions quiz-actions--left">
                                <button class="btn btn-primary" type="button" data-retry>Retry quiz</button>
                                <a class="btn btn-outline" href="#" data-back>Back to course</a>
                            </div>
                        </div>

                        <h2 class="quiz-subhead">Your answer review</h2>
                        <p class="text-muted" data-review-summary></p>
                        <div data-review></div>
                    </section>
                </div>
<?php studentShellClose(['assests/student_quiz.js']);
