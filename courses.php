<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

/* Only a signed-in student sees the catalog. Other roles go to their own dashboard. */
$student = currentUser();
if ($student === null) {
    redirect('');
    exit;
}
if ($student['role'] !== 'student') {
    redirect(dashboardPath((string) $student['role']));
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['student_catalog_csrf'])) {
    $_SESSION['student_catalog_csrf'] = bin2hex(random_bytes(32));
}

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$studentName = (string) ($student['name'] ?? '');
$parts = preg_split('/\s+/', trim($studentName)) ?: [];
$initials = mb_strtoupper(
    mb_substr($parts[0] ?? '', 0, 1, 'UTF-8')
    . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1, 'UTF-8') : ''),
    'UTF-8'
);
$navIcon = static function (string $name): string {
    static $paths = [
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book' => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-7"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.7c2.2.6 3.5 2.2 3.5 5.3"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course catalog | Learning Hub</title>
    <script src="<?= $e(url('assests/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/student_dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/student_catalog.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="app" id="app">
        <aside class="sidebar" id="sidebar" aria-label="Main menu">
            <a class="brand sidebar__brand" href="<?= $e(url('dashboard.php')) ?>">
                <span class="brand__mark" aria-hidden="true">L</span>
                <span class="brand__text">Learning Hub</span>
            </a>
            <nav aria-label="Student">
                <ul class="nav">
                    <li><a class="nav-item" href="<?= $e(url('dashboard.php')) ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('home') ?></span><span class="nav-label">Dashboard</span></a></li>
                    <li><a class="nav-item" href="<?= $e(url('my_courses.php')) ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('book') ?></span><span class="nav-label">My courses</span></a></li>
                    <li><a class="nav-item active" href="<?= $e(url('courses.php')) ?>" aria-current="page">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('compass') ?></span><span class="nav-label">Explore courses</span></a></li>
                    <li><a class="nav-item" href="<?= $e(url('dashboard.php#deadlines')) ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('clipboard') ?></span><span class="nav-label">Deadlines</span></a></li>
                    <li><a class="nav-item" href="<?= $e(url('grades.php')) ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('chart') ?></span><span class="nav-label">Grades</span></a></li>
                    <li><a class="nav-item" href="<?= $e(url('profile.php')) ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('users') ?></span><span class="nav-label">Profile</span></a></li>
                </ul>
            </nav>
            <div class="sidebar__footer">
                <form method="post" action="<?= $e(url('api/auth_api.php?action=logout')) ?>">
                    <button class="btn btn-outline btn-block" type="submit">Sign out</button>
                </form>
            </div>
        </aside>
        <div class="sidebar-overlay" id="overlay"></div>

        <div class="app-main">
            <header class="topbar">
                <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= $navIcon('menu') ?></button>
                <a class="brand topbar__brand" href="<?= $e(url('dashboard.php')) ?>">
                    <span class="brand__mark" aria-hidden="true">L</span>
                    <span class="brand__text">Learning Hub</span>
                </a>
                <span class="topbar__pill">Student</span>
                <span class="topbar__spacer"></span>
                <a class="topbar__user" href="<?= $e(url('profile.php')) ?>" aria-label="View profile for <?= $e($studentName) ?>">
                    <span class="topbar__avatar" aria-hidden="true"><?= $e($initials ?: '?') ?></span>
                    <span class="topbar__user-name"><?= $e($studentName) ?></span>
                </a>
                <form class="topbar__logout" method="post" action="<?= $e(url('api/auth_api.php?action=logout')) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
                </form>
            </header>

            <main class="content" id="main"
                  data-catalog-endpoint="<?= $e(url('api/student_catalog_api.php')) ?>"
                  data-csrf="<?= $e((string) $_SESSION['student_catalog_csrf']) ?>"
                  data-login-url="<?= $e(url('')) ?>">

                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="<?= $e(url('dashboard.php')) ?>">Student workspace</a> / <span aria-current="page">Course catalog</span>
                </nav>

                <header class="catalog-head">
                    <h1>Course catalog</h1>
                    <p class="text-muted">Find your next skill. Learn from people who love to teach.</p>
                </header>

                <div class="catalog-toolbar">
                    <div class="form-group catalog-toolbar__search">
                        <label class="form-label" for="catalogSearch">Search courses</label>
                        <div class="search">
                            <input class="form-control" id="catalogSearch" type="search" maxlength="100"
                                   placeholder="Search by course or instructor…" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group catalog-toolbar__sort">
                        <label class="form-label" for="catalogSort">Sort by</label>
                        <select class="form-control" id="catalogSort">
                            <option value="recommended">Recommended</option>
                            <option value="newest">Newest</option>
                            <option value="rating">Highest rated</option>
                            <option value="popular">Most popular</option>
                            <option value="title">Title A–Z</option>
                        </select>
                    </div>
                </div>

                <div class="catalog-categories">
                    <span class="form-label" id="catLabel">Browse by category</span>
                    <div class="catalog-chips" role="group" aria-labelledby="catLabel" data-categories></div>
                </div>

                <button class="btn btn-outline catalog-filters-toggle" type="button" id="filtersToggle"
                        aria-expanded="false" aria-controls="filtersPanel">Filters</button>

                <p class="alert alert--info" data-status role="status">Loading courses…</p>

                <div class="catalog-layout" data-content hidden>
                    <aside class="card catalog-filters" id="filtersPanel" aria-label="Filters">
                        <h2 class="card__title">Filters</h2>
                        <fieldset class="catalog-filter" data-level-group hidden>
                            <legend>Level</legend>
                            <label><input type="radio" name="level" value="" checked> Any</label>
                            <label><input type="radio" name="level" value="beginner"> Beginner</label>
                            <label><input type="radio" name="level" value="intermediate"> Intermediate</label>
                            <label><input type="radio" name="level" value="advanced"> Advanced</label>
                        </fieldset>
                        <fieldset class="catalog-filter">
                            <legend>Length</legend>
                            <label><input type="radio" name="lessons" value="" checked> Any</label>
                            <label><input type="radio" name="lessons" value="1-5"> 1–5 lessons</label>
                            <label><input type="radio" name="lessons" value="6-10"> 6–10 lessons</label>
                            <label><input type="radio" name="lessons" value="11+"> 11+ lessons</label>
                        </fieldset>
                        <fieldset class="catalog-filter">
                            <legend>Rating</legend>
                            <label><input type="radio" name="rating" value="" checked> Any</label>
                            <label><input type="radio" name="rating" value="4.5"> 4.5 &amp; up</label>
                            <label><input type="radio" name="rating" value="4"> 4.0 &amp; up</label>
                        </fieldset>
                        <button class="btn btn-ghost btn-sm" type="button" id="resetFilters">Reset filters</button>
                    </aside>

                    <section aria-label="Courses">
                        <p class="catalog-count text-muted" data-count aria-live="polite"></p>
                        <div class="catalog-grid" data-grid></div>
                        <nav aria-label="Pagination"><ul class="pagination" data-pagination></ul></nav>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <script src="<?= $e(url('assests/student_catalog.js')) ?>" defer></script>
</body>
</html>
