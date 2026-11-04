<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$assignmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignmentId === false) {
    redirect('my_courses.php');
    exit;
}
if (array_key_exists('id', $_GET)
    && parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === parse_url(url('assignment'), PHP_URL_PATH)) {
    redirect('assignment/' . $assignmentId, 301);
}
$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_assignment_api.php')) . '"'
    . ' data-assignment-id="' . $assignmentId . '"'
    . ' data-csrf="' . student_e(sc_csrf_token()) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'my-courses', 'Assignment', $attributes, ['assests/css/student_course.css', 'assests/css/student_assignment.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="#" data-back>‹ Back to course</a> / <span aria-current="page">Assignment</span>
                </nav>

                <p class="alert alert--info" data-status role="status">Loading assignment…</p>

                <div data-content hidden>
                    <header class="quiz-head">
                        <p class="quiz-head__course" data-course-title></p>
                        <p class="text-muted" data-course-sub></p>
                    </header>

                    <div class="assignment-layout">
                        <section class="card assignment-card" aria-labelledby="assignmentTitle">
                            <div class="assignment-card__tags"><span class="chip chip--primary">Assignment</span><span class="chip" data-state></span></div>
                            <h1 id="assignmentTitle" data-title></h1>

                            <dl class="assignment-facts">
                                <div><dt>Due</dt><dd data-due></dd><dd class="text-muted" data-due-note></dd></div>
                                <div><dt>Maximum mark</dt><dd data-max></dd></div>
                            </dl>

                            <h2 class="quiz-subhead">Instructions</h2>
                            <div class="assignment-text" data-description></div>

                            <div class="assignment-file" data-brief hidden>
                                <span class="assignment-file__icon" data-brief-ext aria-hidden="true"></span>
                                <span class="assignment-file__text"><strong data-brief-name></strong><small class="text-muted">Assignment file</small></span>
                                <a class="btn btn-outline btn-sm" data-brief-link href="#">Download</a>
                            </div>
                        </section>

                        <aside class="card assignment-card" aria-labelledby="submissionTitle">
                            <h2 id="submissionTitle" tabindex="-1">Your submission</h2>
                            <div data-submission></div>
                        </aside>
                    </div>
                </div>
<?php studentShellClose(['assests/student_assignment.js']);
