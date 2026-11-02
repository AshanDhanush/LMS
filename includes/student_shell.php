<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student page shell: shared sidebar + topbar for student pages.
|
|   $student = studentPageGuard();
|   studentShellOpen($student, 'my-courses', 'Page title', $mainAttributes, $extraCss);
|   ... page content ...
|   studentShellClose(['assests/page.js']);
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/student_course_common.php';

function student_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Signed-in student only; anyone else is sent away. */
function studentPageGuard(): array
{
    $student = currentUser();
    if ($student === null) {
        redirect('');
        exit;
    }
    if ($student['role'] !== 'student') {
        redirect(dashboardPath((string) $student['role']));
        exit;
    }
    return $student;
}

function student_icon(string $name): string
{
    static $paths = [
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book' => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-7"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.7c2.2.6 3.5 2.2 3.5 5.3"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function studentShellOpen(array $student, string $active, string $title, string $mainAttributes = '', array $css = []): void
{
    $name = (string) ($student['name'] ?? '');
    $item = static function (string $key, string $href, string $icon, string $label) use ($active): string {
        $isActive = $key === $active;
        return '<li><a class="nav-item' . ($isActive ? ' active' : '') . '" href="' . student_e($href) . '"'
            . ($isActive ? ' aria-current="page"' : '') . '><span class="nav-icon" aria-hidden="true">' . student_icon($icon)
            . '</span><span class="nav-label">' . $label . '</span></a></li>';
    };
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= student_e($title) ?> | Learning Hub</title>
    <link rel="stylesheet" href="<?= student_e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= student_e(url('assests/css/student_dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= student_e(url('assests/css/student_catalog.css')) ?>">
    <?php foreach ($css as $file): ?>
    <link rel="stylesheet" href="<?= student_e(url($file)) ?>">
    <?php endforeach; ?>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="app" id="app">
        <aside class="sidebar" id="sidebar" aria-label="Main menu">
            <a class="brand sidebar__brand" href="<?= student_e(url('dashboard.php')) ?>">
                <span class="brand__mark" aria-hidden="true">L</span>
                <span class="brand__text">Learning Hub</span>
            </a>
            <nav aria-label="Student">
                <ul class="nav">
                    <?= $item('dashboard', url('dashboard.php'), 'home', 'Dashboard') ?>
                    <?= $item('my-courses', url('my_courses.php'), 'book', 'My courses') ?>
                    <?= $item('explore', url('courses.php'), 'compass', 'Explore courses') ?>
                    <?= $item('deadlines', url('dashboard.php#deadlines'), 'clipboard', 'Deadlines') ?>
                    <?= $item('grades', url('grades.php'), 'chart', 'Grades') ?>
                    <?= $item('profile', url('profile.php'), 'users', 'Profile') ?>
                </ul>
            </nav>
            <div class="sidebar__footer">
                <form method="post" action="<?= student_e(url('api/auth_api.php?action=logout')) ?>">
                    <button class="btn btn-outline btn-block" type="submit">Sign out</button>
                </form>
            </div>
        </aside>
        <div class="sidebar-overlay" id="overlay"></div>

        <div class="app-main">
            <header class="topbar">
                <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= student_icon('menu') ?></button>
                <a class="brand topbar__brand" href="<?= student_e(url('dashboard.php')) ?>">
                    <span class="brand__mark" aria-hidden="true">L</span>
                    <span class="brand__text">Learning Hub</span>
                </a>
                <span class="topbar__pill">Student</span>
                <span class="topbar__spacer"></span>
                <a class="topbar__user" href="<?= student_e(url('profile.php')) ?>" aria-label="View profile for <?= student_e($name) ?>">
                    <span class="topbar__avatar" aria-hidden="true"><?= student_e(sc_initials($name)) ?></span>
                    <span class="topbar__user-name"><?= student_e($name) ?></span>
                </a>
                <form class="topbar__logout" method="post" action="<?= student_e(url('api/auth_api.php?action=logout')) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
                </form>
            </header>

            <main class="content" id="main" <?= $mainAttributes ?>>
<?php
}

function studentShellClose(array $scripts): void
{
    array_unshift($scripts, 'assests/student_shell.js');
    ?>
            </main>
        </div>
    </div>
    <?php foreach ($scripts as $file): ?>
    <script src="<?= student_e(url($file)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
<?php
}
