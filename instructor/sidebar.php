<?php

$sidebarEscape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$sidebarIcon = static function (string $name): string {
    static $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book' => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.7c2.2.6 3.5 2.2 3.5 5.3"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-7"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
    ];

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '')
        . '</svg>';
};

$sidebarPage = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sidebarActive = match (true) {
    str_ends_with($sidebarPage, '/instructor/dashboard.php') => 'dashboard',
    str_ends_with($sidebarPage, '/instructor/courses/my_courses.php'),
    str_ends_with($sidebarPage, '/instructor/courses/courses_edit_form.php') => 'courses',
    str_ends_with($sidebarPage, '/instructor/courses/coureses_add_form.php') => 'create-course',
    str_ends_with($sidebarPage, '/instructor/assignment and grading/assignments_and_grading.php'),
    str_ends_with($sidebarPage, '/instructor/assignment and grading/assessment_add_form.php'),
    str_ends_with($sidebarPage, '/instructor/assignment and grading/assessment_list.php'),
    str_ends_with($sidebarPage, '/instructor/assignment and grading/quiz_edit_form.php'),
    str_ends_with($sidebarPage, '/instructor/assignment and grading/assignment_edit_form.php') => 'assignments',
    str_ends_with($sidebarPage, '/instructor/reports.php'),
    str_ends_with($sidebarPage, '/instructor/gradebook.php') => 'analytics',
    default => '',
};

$sidebarMenu = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'href' => 'instructor/dashboard.php'],
    ['key' => 'courses', 'label' => 'My courses', 'icon' => 'book', 'href' => 'instructor/courses/my_courses.php'],
    ['key' => 'assignments', 'label' => 'Assignments & grading', 'icon' => 'clipboard', 'href' => 'instructor/assignment and grading/assignments_and_grading.php', 'badge' => true],
    ['key' => 'students', 'label' => 'Students & cohorts', 'icon' => 'users', 'href' => 'instructor/dashboard.php#enroll-title'],
    ['key' => 'analytics', 'label' => 'Analytics & reports', 'icon' => 'chart', 'href' => 'instructor/reports.php'],
    ['key' => 'reviews', 'label' => 'Course reviews', 'icon' => 'star', 'href' => 'instructor/dashboard.php#course-rating'],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'gear', 'href' => 'profile.php#security'],
];

$sidebarPendingTotal = isset($pendingTotal) ? (int) $pendingTotal : 0;
?>
<aside class="ins-sidebar" id="instructor-sidebar" aria-label="Instructor navigation">
    <a class="ins-brand" href="<?= $sidebarEscape(url('instructor/dashboard.php')) ?>">
        <span class="ins-brand__mark" aria-hidden="true">L</span>
        <span class="ins-brand__text">
            <strong>Learning Hub</strong>
            <small>LMS Academy</small>
        </span>
    </a>
    <span class="ins-portal">Instructor portal</span>

    <nav aria-label="Instructor menu">
        <ul class="ins-nav">
            <?php foreach ($sidebarMenu as $item): ?>
                <?php $isCurrent = $item['key'] === $sidebarActive; ?>
                <li>
                    <a class="ins-nav__item<?= $isCurrent ? ' is-current' : '' ?>"
                       href="<?= $sidebarEscape(url($item['href'])) ?>"
                       <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                        <?= $sidebarIcon($item['icon']) ?>
                        <span class="ins-nav__label"><?= $sidebarEscape($item['label']) ?></span>
                        <?php if (!empty($item['badge']) && $sidebarPendingTotal > 0): ?>
                            <span class="ins-nav__badge"><?= $sidebarPendingTotal ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>
