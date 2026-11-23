<?php
declare(strict_types=1);

/**
 * Enhanced Universal Error Page Layout for Learning Hub LMS
 * Supports: 400, 401, 403, 404, 500, 503
 * Responsive, dark/light theme compatible, accessible.
 */

// Safely require config.php if not already loaded, but do not crash if DB connection fails
if (!defined('BASE_URL')) {
    $configPath = dirname(__DIR__) . '/includes/config.php';
    if (is_file($configPath)) {
        try {
            require_once $configPath;
        } catch (Throwable) {
            // DB or config failure during 500 error - define safe fallbacks
        }
    }
}

// Fallback url() helper if config failed to load
if (!function_exists('url')) {
    function url(string $path = ''): string {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F\\\\]/', '', $path);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $clean) === 1) {
            $clean = '';
        }
        $sub = '';
        if (!empty($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['SCRIPT_FILENAME'])) {
            $proj = str_replace('\\', '/', dirname(__DIR__));
            $doc = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT']));
            $sub = ($doc !== '' && stripos($proj, $doc) === 0) ? substr($proj, strlen($doc)) : '';
        }
        return rtrim($sub, '/') . '/' . ltrim($clean, '/');
    }
}

// Fallback dashboardPath helper
if (!function_exists('dashboardPath')) {
    function dashboardPath(string $role): string {
        return match ($role) {
            'admin' => 'admin/dashboard',
            'instructor' => 'instructor/dashboard',
            default => 'dashboard',
        };
    }
}

// Escape helper
$e = static fn (?string $val): string => htmlspecialchars((string) ($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Error variables with safe defaults
$code = (int) ($errorCode ?? 404);
http_response_code($code);

$title = (string) ($errorTitle ?? 'Page Not Found');
$subtitle = (string) ($errorSubtitle ?? "We couldn't find the page you're looking for");
$description = (string) ($errorDescription ?? 'The page you requested might have been moved, renamed, archived, or does not exist.');
$type = (string) ($errorType ?? 'primary');
$badge = (string) ($badgeLabel ?? match ($code) {
    400 => 'Bad Request',
    401 => 'Authentication Required',
    403 => 'Access Denied',
    404 => 'Page Not Found',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable',
    default => 'Error',
});

// Custom error message passed from abort() or request
$customDetail = $GLOBALS['errorMessage'] ?? ($errorMessage ?? null);
if (!is_string($customDetail) || trim($customDetail) === '') {
    $customDetail = null;
}

// Safely retrieve current user (handles database outage gracefully)
$currentUser = null;
if (function_exists('currentUser')) {
    try {
        $currentUser = currentUser();
    } catch (Throwable) {
        $currentUser = null;
    }
}

// Determine target dashboard / home URL
$dashboardUrl = '';
$dashboardLabel = 'Return to Homepage';
if ($currentUser !== null && isset($currentUser['role'])) {
    $dashboardUrl = dashboardPath((string) $currentUser['role']);
    $dashboardLabel = match ((string) $currentUser['role']) {
        'admin' => 'Admin Dashboard',
        'instructor' => 'Instructor Dashboard',
        default => 'Student Dashboard',
    };
} elseif ($code === 401) {
    $dashboardUrl = '';
    $dashboardLabel = 'Sign in to Learning Hub';
}

// User initials
$userInitials = 'U';
$userFirstName = '';
if ($currentUser !== null) {
    $nameParts = preg_split('/\s+/', trim((string) ($currentUser['name'] ?? ''))) ?: [];
    $userFirstName = (string) ($nameParts[0] ?? 'User');
    $userInitials = mb_strtoupper(
        mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . (count($nameParts) > 1 ? mb_substr((string) end($nameParts), 0, 1, 'UTF-8') : ''),
        'UTF-8'
    );
}

// SVG icon selector
$getIconSvg = static function (int $c): string {
    return match ($c) {
        400 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        401 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1.5"/></svg>',
        403 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="4.5" y1="4.5" x2="19.5" y2="19.5"/></svg>',
        404 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
        500 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/><path d="m14 6 2 2 4-4"/><line x1="13" y1="18" x2="19" y2="18"/></svg>',
        503 => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title) ?> (<?= $code ?>) | Learning Hub</title>
    <script src="<?= $e(url('assests/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
</head>
<body class="error-page">
    <a class="skip-link" href="#error-main">Skip to main content</a>

    <div class="error-page__backdrop" aria-hidden="true">
        <div class="error-page__glow error-page__glow--<?= $e($type) ?>"></div>
        <div class="error-page__grid-pattern"></div>
    </div>

    <header class="error-page__header">
        <a class="error-page__brand" href="<?= $e(url($dashboardUrl)) ?>" aria-label="Learning Hub home">
            <span class="brand__mark" aria-hidden="true">L</span>
            <span>Learning Hub</span>
        </a>

        <div class="error-page__nav">
            <?php if ($currentUser !== null): ?>
                <a class="error-page__user-chip" href="<?= $e(url('profile.php')) ?>" title="Signed in as <?= $e($currentUser['name']) ?>">
                    <span class="error-page__user-avatar" aria-hidden="true">
                        <?php if (!empty($currentUser['photo'])): ?>
                            <img src="<?= $e(url((string) $currentUser['photo'])) ?>" alt="">
                        <?php else: ?>
                            <?= $e($userInitials) ?>
                        <?php endif; ?>
                    </span>
                    <span><?= $e($userFirstName) ?></span>
                    <span class="chip chip--primary chip--sm" style="font-size: 0.7rem; padding: 0 6px;"><?= $e(ucfirst((string) $currentUser['role'])) ?></span>
                </a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline" href="<?= $e(url('')) ?>">Sign In</a>
            <?php endif; ?>

            <button type="button" class="error-page__theme-btn" id="themeToggleBtn" aria-label="Toggle dark mode" title="Toggle dark mode" onclick="window.toggleTheme ? window.toggleTheme() : null;">
                <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
        </div>
    </header>

    <main class="error-page__main" id="error-main">
        <section class="error-card" aria-labelledby="error-title">
            <div class="error-card__accent-stripe error-card__accent-stripe--<?= $e($type) ?>" aria-hidden="true"></div>
            <div class="error-card__watermark" aria-hidden="true"><?= $code ?></div>

            <div class="error-card__badge-row">
                <span class="chip chip--<?= $e($type) ?> chip--lg error-chip">
                    <span class="error-chip__dot error-chip__dot--<?= $e($type) ?>" aria-hidden="true"></span>
                    <span>HTTP <?= $code ?> &bull; <?= $e($badge) ?></span>
                </span>
            </div>

            <div class="error-card__icon-shell">
                <div class="error-card__icon-ring error-card__icon-ring--<?= $e($type) ?>" aria-hidden="true"></div>
                <div class="error-card__icon-wrap error-card__icon-wrap--<?= $e($type) ?>">
                    <?= $getIconSvg($code) ?>
                </div>
            </div>

            <h1 class="error-card__title" id="error-title"><?= $e($title) ?></h1>
            <p class="error-card__subtitle"><?= $e($subtitle) ?></p>
            <p class="error-card__desc"><?= $e($description) ?></p>

            <?php if ($customDetail !== null): ?>
                <div class="alert alert--<?= $e($type) ?> error-card__alert" role="alert">
                    <div class="alert__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div class="alert__content">
                        <span class="alert__title">Context Information</span>
                        <p class="alert__message"><?= $e($customDetail) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="error-card__actions">
                <a class="btn btn-primary" href="<?= $e(url($dashboardUrl)) ?>">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <?php if ($currentUser !== null): ?>
                            <path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>
                        <?php elseif ($code === 401): ?>
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                        <?php else: ?>
                            <path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>
                        <?php endif; ?>
                    </svg>
                    <span><?= $e($dashboardLabel) ?></span>
                </a>

                <?php if ($code >= 500): ?>
                    <button type="button" class="btn btn-outline" onclick="window.location.reload();">
                        <svg class="btn-icon-reload" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.3"/>
                        </svg>
                        <span>Try Again</span>
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '<?= $e(url($dashboardUrl)) ?>'; }">
                        <svg class="btn-icon-back" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                        </svg>
                        <span>Go Back</span>
                    </button>
                <?php endif; ?>

                <a class="btn btn-ghost" href="<?= $e(url('courses.php')) ?>">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                    </svg>
                    <span>Browse Courses</span>
                </a>
            </div>

            <nav class="error-card__nav" aria-label="Suggested links">
                <div class="error-card__nav-title">Popular Destinations</div>
                <div class="error-card__links">
                    <a class="error-card__link" href="<?= $e(url('courses.php')) ?>">
                        <div class="error-card__link-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        </div>
                        <div class="error-card__link-title">Course Catalog</div>
                        <div class="error-card__link-sub">Browse all topics & subjects</div>
                    </a>

                    <a class="error-card__link" href="<?= $e(url($dashboardUrl)) ?>">
                        <div class="error-card__link-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        </div>
                        <div class="error-card__link-title"><?= $currentUser !== null ? 'My Dashboard' : 'Sign In' ?></div>
                        <div class="error-card__link-sub"><?= $currentUser !== null ? 'Resume your learning' : 'Access your account' ?></div>
                    </a>

                    <?php if ($currentUser !== null): ?>
                        <a class="error-card__link" href="<?= $e(url('profile.php')) ?>">
                            <div class="error-card__link-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <div class="error-card__link-title">Profile & Settings</div>
                            <div class="error-card__link-sub">Manage your account</div>
                        </a>
                    <?php else: ?>
                        <a class="error-card__link" href="<?= $e(url('')) ?>">
                            <div class="error-card__link-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            </div>
                            <div class="error-card__link-title">Account Access</div>
                            <div class="error-card__link-sub">Log in with credentials</div>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        </section>
    </main>

    <footer class="error-page__footer">
        <div class="error-page__status-indicator">
            <span class="error-page__status-dot" aria-hidden="true"></span>
            <span>Platform Status: Operational</span>
        </div>
        <span>&bull;</span>
        <span>&copy; <?= date('Y') ?> Learning Hub. All rights reserved.</span>
        <span>&bull;</span>
        <span>Reference: <code><?= $code ?>-<?= date('YmdHi') ?></code></span>
    </footer>
</body>
</html>
