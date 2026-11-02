<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$student = studentPageGuard();
$attributes = 'data-dashboard-endpoint="' . student_e(url('api/student_dashboard_api.php')) . '"'
    . ' data-course-url="' . student_e(url('course')) . '"'
    . ' data-explore-url="' . student_e(url('courses.php')) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'my-courses', 'My courses', $attributes);
?>

                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= student_e(url('dashboard.php')) ?>">Student workspace</a> / <span aria-current="page">My courses</span>
                </nav>

                <header class="catalog-head catalog-head--row">
                    <div>
                        <h1>My courses</h1>
                        <p class="text-muted">Pick up where you left off in the courses you have enrolled in.</p>
                    </div>
                    <a class="btn btn-primary" href="<?= student_e(url('courses.php')) ?>">Explore more courses</a>
                </header>

                <p class="alert alert--info" data-status role="status">Loading your courses…</p>

                <div data-content hidden>
                    <div class="catalog-toolbar">
                        <div class="form-group catalog-toolbar__search">
                            <label class="form-label" for="mySearch">Search my courses</label>
                            <div class="search">
                                <input class="form-control" id="mySearch" type="search" maxlength="100"
                                       placeholder="Search by course or instructor…" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-group catalog-toolbar__sort">
                            <label class="form-label" for="mySort">Sort by</label>
                            <select class="form-control" id="mySort">
                                <option value="recent">Default</option>
                                <option value="title">Title A–Z</option>
                                <option value="progress-high">Most progress</option>
                                <option value="progress-low">Least progress</option>
                            </select>
                        </div>
                    </div>

                    <div class="catalog-chips my-tabs" role="group" aria-label="Filter by progress" data-tabs></div>

                    <p class="catalog-count text-muted" data-count aria-live="polite"></p>
                    <div class="catalog-grid" data-grid></div>
                </div>
<?php studentShellClose(['assests/student_my_courses.js']); ?>
