<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_grades_api.php')) . '"'
    . ' data-explore-url="' . student_e(url('courses.php')) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'grades', 'Grades', $attributes, ['assests/css/student_grades.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= student_e(url('dashboard.php')) ?>">Student workspace</a> / <span aria-current="page">Grades</span>
                </nav>

                <header class="catalog-head">
                    <h1>Grades</h1>
                    <p class="text-muted">Your assignment marks and quiz results in one place.</p>
                </header>

                <p class="alert alert--info" data-status role="status">Loading your grades…</p>

                <div data-content hidden>
                    <div class="grades-summary" data-summary></div>
                    <p class="text-muted grades-note">Your average is the marks you have earned divided by the marks available in the work that has been marked or attempted. Quizzes count with your best attempt. Work that is still waiting for a mark is not counted yet.</p>

                    <div class="catalog-chips grades-filter" role="group" aria-label="Filter by course" data-filter></div>
                    <div data-courses></div>
                </div>
<?php studentShellClose(['assests/student_grades.js']);
