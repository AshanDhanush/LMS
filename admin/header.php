<?php
if (isset($user) && is_array($user)) {
    $headerUser = $user;
} elseif (isset($admin) && is_array($admin)) {
    $headerUser = $admin;
} else {
    http_response_code(404);
    exit;
}

$headerName = (string) $headerUser['name'];
$headerInitials = function_exists('initials')
    ? initials($headerName)
    : userProfileInitials($headerName);
$headerSearchValue = isset($userSearch) ? (string) $userSearch : '';
$adminActivePage = $adminActivePage ?? '';
$adminPageTitle = $adminPageTitle ?? 'Learning Hub Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($adminPageTitle) ?></title>
    <link rel="stylesheet" href="<?= h(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('assests/css/dashboard.css')) ?>">
</head>
<body class="admin">
    <a class="admin-skip" href="#main">Skip to content</a>
    <header class="admin-topbar">
        <div class="admin-topbar__inner">
            <button class="admin-menu-toggle" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false" data-sidebar-toggle>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <a class="admin-brand" href="<?= h(url('admin/dashboard')) ?>">
                <span class="admin-brand__mark" aria-hidden="true">L</span>
                <span>Learning Hub</span>
            </a>
            <span class="admin-chip admin-chip--primary">Admin</span>
            <form class="admin-user-search" action="<?= h(url('admin/users')) ?>" method="get" role="search" data-user-search-form data-user-search-endpoint="<?= h(url('api/api_user_search.php')) ?>" data-user-profile-url="<?= h(url('admin/user-profile.php')) ?>">
                <label class="admin-sr-only" for="admin-user-search">Search users by name or email</label>
                <input id="admin-user-search" type="search" name="q" value="<?= h($headerSearchValue) ?>" placeholder="Find a user..." autocomplete="off" aria-autocomplete="list" aria-controls="admin-user-search-suggestions" aria-expanded="false" data-user-search-input>
                <button type="submit" aria-label="Search users" title="Search users">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                </button>
                <button type="button" aria-label="Toggle user suggestions" aria-expanded="false" data-user-search-toggle>▼</button>
                <ul id="admin-user-search-suggestions" class="user-search-suggestions" role="listbox" hidden data-user-search-suggestions></ul>
            </form>
            <span class="admin-notification" role="img" aria-label="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            </span>
            <a class="admin-user" href="<?= h(url('profile')) ?>" aria-label="View profile for <?= h($headerName) ?>">
                <?php if (!empty($headerUser['photo'])): ?>
                    <img class="admin-user__avatar" src="<?= h(url((string) $headerUser['photo'])) ?>" alt="" aria-hidden="true">
                <?php else: ?>
                    <span class="admin-user__avatar" aria-hidden="true"><?= h($headerInitials) ?></span>
                <?php endif; ?>
                <span class="admin-user__name"><?= h($headerName) ?></span>
            </a>
            <form action="<?= h(url('api/auth_api.php?action=logout')) ?>" method="post">
                <button class="admin-btn" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation">
            <nav class="admin-sidebar__nav">
                <a class="admin-sidebar__link<?= $adminActivePage === 'dashboard' ? ' admin-sidebar__link--active' : '' ?>" href="<?= h(url('admin/dashboard')) ?>"<?= $adminActivePage === 'dashboard' ? ' aria-current="page"' : '' ?>>
                    <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/></svg>
                    <span>Home</span>
                </a>
                <a class="admin-sidebar__link<?= $adminActivePage === 'users' ? ' admin-sidebar__link--active' : '' ?>" href="<?= h(url('admin/users')) ?>"<?= $adminActivePage === 'users' ? ' aria-current="page"' : '' ?>>
                    <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Users</span>
                </a>
                <a class="admin-sidebar__link<?= $adminActivePage === 'courses' ? ' admin-sidebar__link--active' : '' ?>" href="<?= h(url('admin/courses')) ?>"<?= $adminActivePage === 'courses' ? ' aria-current="page"' : '' ?>>
                    <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/><path d="M9 7h6"/></svg>
                    <span>Courses</span>
                </a>
                <form action="<?= h(url('api/auth_api.php?action=logout')) ?>" method="post">
                    <button class="admin-sidebar__link admin-sidebar__logout" type="submit">
                        <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                        <span>Logout</span>
                    </button>
                </form>
            </nav>
        </aside>
