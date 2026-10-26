<?php

$instructorHeaderEscape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$instructorHeaderName = (string) ($user['name'] ?? '');
$instructorHeaderNameParts = preg_split('/\s+/', trim($instructorHeaderName)) ?: [];
$instructorHeaderInitials = mb_strtoupper(
    mb_substr($instructorHeaderNameParts[0] ?? '', 0, 1, 'UTF-8')
        . (count($instructorHeaderNameParts) > 1
            ? mb_substr((string) end($instructorHeaderNameParts), 0, 1, 'UTF-8')
            : ''),
    'UTF-8'
);
?>
<header class="ins-topbar">
    <button class="ins-menu-toggle" type="button" aria-label="Open navigation" aria-controls="instructor-sidebar" aria-expanded="false" data-instructor-sidebar-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="ins-topbar__title"><?= $instructorHeaderEscape((string) ($instructorHeaderTitle ?? '')) ?></span>
    <span class="ins-topbar__spacer"></span>
    <a class="ins-user" href="<?= $instructorHeaderEscape(url('profile')) ?>" aria-label="View profile for <?= $instructorHeaderEscape($instructorHeaderName) ?>">
        <?php if (!empty($user['photo'])): ?>
            <img class="ins-user__avatar" src="<?= $instructorHeaderEscape(url((string) $user['photo'])) ?>" alt="" aria-hidden="true">
        <?php else: ?>
            <span class="ins-user__avatar" aria-hidden="true"><?= $instructorHeaderEscape($instructorHeaderInitials ?: '?') ?></span>
        <?php endif; ?>
        <span class="ins-user__text">
            <span class="ins-user__name"><?= $instructorHeaderEscape($instructorHeaderName) ?></span>
            <span class="ins-user__role">Instructor</span>
        </span>
    </a>
    <button class="ins-btn" type="button" id="signOut"
            data-logout-url="<?= $instructorHeaderEscape(url('api/auth_api.php?action=logout')) ?>"
            data-login-url="<?= $instructorHeaderEscape(url('index.php')) ?>">Sign out</button>
</header>
