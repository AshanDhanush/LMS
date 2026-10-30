<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

/* Only a signed-in student sees this page. Other roles go to their own dashboard. */
$student = currentUser();
if ($student === null) {
    redirect('');
    exit;
}
if ($student['role'] !== 'student') {
    redirect(dashboardPath((string) $student['role']));
    exit;
}

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$studentName = (string) ($student['name'] ?? '');
$studentNameParts = preg_split('/\s+/', trim($studentName)) ?: [];
$firstName = (string) ($studentNameParts[0] ?? '');
$studentInitials = mb_strtoupper(
    mb_substr($studentNameParts[0] ?? '', 0, 1, 'UTF-8')
    . (count($studentNameParts) > 1 ? mb_substr((string) end($studentNameParts), 0, 1, 'UTF-8') : ''),
    'UTF-8'
);
$navIcon = static function (string $name): string {
    static $paths = [
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book' => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-7"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.7c2.2.6 3.5 2.2 3.5 5.3"/>',
    ];

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '')
        . '</svg>';
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Learning Hub</title>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/student_dashboard.css')) ?>">
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
                    <li><a class="nav-item active" href="<?= $e(url('dashboard.php')) ?>" aria-current="page">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('home') ?></span><span class="nav-label">Dashboard</span></a></li>
                    <li><a class="nav-item" href="#my-courses">
                        <span class="nav-icon" aria-hidden="true"><?= $navIcon('book') ?></span><span class="nav-label">My courses</span></a></li>
                    <li><a class="nav-item" href="#deadlines">
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
                    <span class="topbar__avatar" aria-hidden="true"><?= $e($studentInitials ?: '?') ?></span>
                    <span class="topbar__user-name"><?= $e($studentName ?: $firstName) ?></span>
                </a>
                <form class="topbar__logout" method="post" action="<?= $e(url('api/auth_api.php?action=logout')) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
                </form>
            </header>

            <main class="content" id="main"
                  data-dashboard-endpoint="<?= $e(url('api/student_dashboard_api.php')) ?>"
                  data-login-url="<?= $e(url('')) ?>">

                <p class="alert alert--info" data-status role="status">Loading your dashboard…</p>
                <noscript><p class="alert alert--warning">Your dashboard needs JavaScript to load. Please turn it on and reload.</p></noscript>

                <div data-content hidden>

                    <!-- Greeting with points and badge count -->
                    <header class="dash-greeting">
                        <div>
                            <h1 data-greeting>Welcome back, <?= $e($firstName) ?></h1>
                            <p class="text-muted">Here is where you are with your learning.</p>
                        </div>
                        <div class="dash-greeting__chips">
                            <span class="chip chip--points chip--lg" data-points></span>
                            <span class="chip chip--primary chip--lg" data-badge-count></span>
                        </div>
                    </header>

                    <!-- Continue learning -->
                    <section aria-labelledby="continue-title" data-continue></section>

                    <!-- Stat cards -->
                    <section class="dash-section" aria-label="Your numbers">
                        <div class="stat-grid" data-stats></div>
                    </section>

                    <!-- My courses -->
                    <section class="dash-section" id="my-courses" aria-labelledby="courses-title">
                        <div class="dash-section__head"><h2 id="courses-title">My courses</h2></div>
                        <div class="grid grid--auto" data-courses></div>
                    </section>

                    <!-- Deadlines and grades -->
                    <div class="grid grid--2 dash-section">
                        <section class="card" id="deadlines" aria-labelledby="deadlines-title">
                            <div class="card__header"><h2 class="card__title" id="deadlines-title">Upcoming deadlines</h2></div>
                            <div data-deadlines></div>
                        </section>

                        <section class="card" aria-labelledby="grades-title">
                            <div class="card__header">
                                <h2 class="card__title" id="grades-title">Recent grades</h2>
                                <a class="btn btn-ghost btn-sm" href="<?= $e(url('grades.php')) ?>">All grades</a>
                            </div>
                            <div data-grades></div>
                        </section>
                    </div>

                    <!-- Badges -->
                    <section class="dash-section" aria-labelledby="badges-title">
                        <div class="dash-section__head">
                            <h2 id="badges-title">Badges earned</h2>
                            <a class="btn btn-ghost btn-sm" href="<?= $e(url('profile.php')) ?>">See all</a>
                        </div>
                        <div data-badges></div>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <script src="<?= $e(url('assests/student_dashboard.js')) ?>" defer></script>
</body>
</html>
