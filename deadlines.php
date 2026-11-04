<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/student_shell.php';

$student = studentPageGuard();

$attributes = 'data-endpoint="' . student_e(url('api/student_deadlines_api.php')) . '"'
    . ' data-login-url="' . student_e(url('')) . '"';

studentShellOpen($student, 'deadlines', 'Deadlines', $attributes, ['assests/css/student_deadlines.css']);
?>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= student_e(url('dashboard.php')) ?>">Student workspace</a> / <span aria-current="page">Deadlines</span>
                </nav>

                <header class="catalog-head">
                    <h1>Deadlines</h1>
                    <p class="text-muted">Assignment due dates and quiz closing times for your courses.</p>
                </header>

                <p class="alert alert--info" data-status role="status">Loading your deadlines…</p>

                <div data-content hidden>
                    <div class="dl-summary" data-summary></div>

                    <div class="dl-toolbar">
                        <div class="catalog-chips" role="group" aria-label="Show" data-kinds>
                            <button class="filter-chip catalog-chip" type="button" data-kind="all" aria-pressed="true">All</button>
                            <button class="filter-chip catalog-chip" type="button" data-kind="assignment" aria-pressed="false">Assignments</button>
                            <button class="filter-chip catalog-chip" type="button" data-kind="quiz" aria-pressed="false">Quizzes</button>
                        </div>
                        <label class="dl-check"><input type="checkbox" data-hide-done> Hide completed</label>
                    </div>

                    <div class="dl-layout">
                        <section class="card dl-calendar" aria-labelledby="calTitle">
                            <div class="dl-calendar__head">
                                <h2 id="calTitle" data-month aria-live="polite"></h2>
                                <div class="dl-calendar__nav">
                                    <button class="btn btn-outline btn-sm" type="button" data-prev aria-label="Previous month">‹</button>
                                    <button class="btn btn-outline btn-sm" type="button" data-today>Today</button>
                                    <button class="btn btn-outline btn-sm" type="button" data-next aria-label="Next month">›</button>
                                </div>
                            </div>
                            <div class="dl-calendar__scroll">
                                <table class="dl-grid" data-grid aria-labelledby="calTitle">
                                    <thead><tr data-weekdays></tr></thead>
                                    <tbody data-days></tbody>
                                </table>
                            </div>
                            <ul class="dl-legend" aria-label="Key">
                                <li><span class="dl-key dl-key--assignment" aria-hidden="true"></span> Assignment due</li>
                                <li><span class="dl-key dl-key--quiz" aria-hidden="true"></span> Quiz closes</li>
                                <li><span class="dl-key dl-key--overdue" aria-hidden="true"></span> Overdue</li>
                                <li><span class="dl-key dl-key--done" aria-hidden="true"></span> Completed</li>
                            </ul>
                        </section>

                        <aside class="dl-side">
                            <section class="card" aria-labelledby="dayTitle">
                                <h2 class="card__title" id="dayTitle" data-day-title></h2>
                                <div data-day-list></div>
                            </section>
                            <section class="card" aria-labelledby="soonTitle">
                                <h2 class="card__title" id="soonTitle">Coming up</h2>
                                <div data-soon-list></div>
                            </section>
                        </aside>
                    </div>
                </div>
<?php studentShellClose(['assests/student_deadlines.js']);
