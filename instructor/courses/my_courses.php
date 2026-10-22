<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';


/* ==========================================================================
   Only an instructor may see this page
   ========================================================================== */
$user = currentUser();

if ($user === null) {
    redirect('index.php');                              // not signed in: go to the login page
}
if ($user['role'] !== 'instructor') {
    redirect(dashboardPath($user['role']));             // another role: send them to their own dashboard
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['instructor_course_form_csrf'])) {
    $_SESSION['instructor_course_form_csrf'] = bin2hex(random_bytes(32));
}
$courseFormCsrfToken = (string) $_SESSION['instructor_course_form_csrf'];


/* ==========================================================================
   Functions used only on this page
   ========================================================================== */

/** Escape text for HTML. Use on EVERY value you print. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

/** Each course gets one of five tag colours, always the same one */
function tagClass(int $courseId): string
{
    return 'ins-tag--' . ($courseId % 5);
}

/**
 * Address of a course thumbnail, or null to show the placeholder.
 * Thumbnails are saved in the uploads folder, for example "thumbnails/abc123.jpg".
 */
function thumbUrl(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_contains($path, '..') || str_starts_with($path, '//')) {
        return null;                                    // empty, a full web address or a risky path
    }
    $path = ltrim($path, '/');
    return url(str_starts_with($path, 'uploads/') ? $path : 'uploads/' . $path);
}

/** Small inline icons (no icon library needed) */
function ico(string $name): string
{
    static $paths = [
        'home'     => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book'     => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11"/>',
        'clipboard'=> '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.7c2.2.6 3.5 2.2 3.5 5.3"/>',
        'chart'    => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M13 16V8M18 16v-7"/>',
        'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}


/* ==========================================================================
   Menu and buttons (the same menu as the dashboard).
   Put the address of each page here when you build it.
   ========================================================================== */
$menu = [
    ['label' => 'Dashboard',             'icon' => 'home',      'href' => 'instructor/dashboard.php'],
    ['label' => 'My courses',            'icon' => 'book',      'href' => 'instructor/courses/my_courses.php', 'current' => true],
    ['label' => 'Assignments & grading', 'icon' => 'clipboard', 'href' => 'instructor/dashboard.php#sub-title', 'badge' => true],
    ['label' => 'Students & cohorts',    'icon' => 'users',     'href' => 'instructor/dashboard.php#enroll-title'],
    ['label' => 'Analytics & reports',   'icon' => 'chart',     'href' => 'instructor/dashboard.php#teaching-stats'],
    ['label' => 'Course reviews',        'icon' => 'star',      'href' => 'instructor/dashboard.php#course-rating'],
    ['label' => 'Settings',              'icon' => 'gear',      'href' => 'profile.php#security'],
];
$newCourseHref = 'instructor/courses/coureses_add_form.php';    // address of the "Create new course" page
$editCourseHref = 'instructor/courses/courses_edit_form';       // address of the edit page (the course id is added to it)


/* ==========================================================================
   Load this instructor's courses (the query only returns THEIR courses)
   ========================================================================== */
$instructorId = (int) $user['id'];
$courses = [];
$pendingTotal = 0;
$loadError = false;

try {
    $pdo = db();

   
$stmt = $pdo->prepare(
    'SELECT c.id, c.title, c.thumbnail, c.status, c.created_at,
            (SELECT COUNT(*)
             FROM enrollments e
             WHERE e.course_id = c.id) AS enrolled,

            (SELECT COUNT(*)
             FROM modules m
             WHERE m.course_id = c.id) AS modules,

            (SELECT COUNT(*)
             FROM lessons l
             WHERE l.course_id = c.id) AS lessons

     FROM courses c
     WHERE c.instructor_id = ?
     ORDER BY c.created_at DESC, c.id DESC'
);

    $stmt->execute([$instructorId]);
    $courses = $stmt->fetchAll();

    // Submissions without a mark, for the badge in the menu
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN courses c ON c.id = a.course_id
         WHERE c.instructor_id = ? AND s.mark IS NULL'
    );
    $stmt->execute([$instructorId]);
    $pendingTotal = (int) $stmt->fetchColumn();
} catch (Throwable $error) {
    error_log('Instructor courses failed to load: ' . $error->getMessage());
    $loadError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses | Learning Hub</title>
    <link rel="stylesheet" href="<?= h(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">
    <a class="ins-skip" href="#main">Skip to content</a>

    <div class="ins-layout">

        <!-- ========== Sidebar ========== -->
        <aside class="ins-sidebar" id="instructor-sidebar" aria-label="Instructor navigation">
            <a class="ins-brand" href="<?= h(url('instructor/dashboard.php')) ?>">
                <span class="ins-brand__mark" aria-hidden="true">L</span>
                <span class="ins-brand__text"><strong>Learning Hub</strong><small>LMS Academy</small></span>
            </a>
            <span class="ins-portal">Instructor portal</span>

            <nav aria-label="Instructor menu">
                <ul class="ins-nav">
                    <?php foreach ($menu as $item): ?>
                    <li>
                        <?php if (!empty($item['current'])): ?>
                        <a class="ins-nav__item is-current" href="<?= h(url($item['href'])) ?>" aria-current="page">
                        <?php else: ?>
                        <a class="ins-nav__item" href="<?= h(url($item['href'])) ?>">
                        <?php endif; ?>
                            <?= ico($item['icon']) ?>
                            <span class="ins-nav__label"><?= h($item['label']) ?></span>
                            <?php if (!empty($item['badge']) && $pendingTotal > 0): ?>
                            <span class="ins-nav__badge"><?= $pendingTotal ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </aside>

        <div class="ins-body">

            <!-- ========== Top bar ========== -->
            <header class="ins-topbar">
                <button class="ins-menu-toggle" type="button" aria-label="Open navigation" aria-controls="instructor-sidebar" aria-expanded="false" data-instructor-sidebar-toggle>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="ins-topbar__title">My courses</span>
                <span class="ins-topbar__spacer"></span>
                <a class="ins-user" href="<?= h(url('profile.php')) ?>" aria-label="Open your profile, <?= h($user['name']) ?>">
                    <span class="ins-user__avatar" aria-hidden="true"><?= h(initials($user['name'])) ?></span>
                    <span class="ins-user__text">
                        <span class="ins-user__name"><?= h($user['name']) ?></span>
                        <span class="ins-user__role">Instructor</span>
                    </span>
                </a>
                <button class="ins-btn" type="button" id="signOut"
                        data-logout-url="<?= h(url('api/auth_api.php?action=logout')) ?>"
                        data-login-url="<?= h(url('index.php')) ?>">Sign out</button>
            </header>

            <main class="ins-main" id="main">

                <!-- ========== Welcome and actions ========== -->
                <section class="ins-hero">
                    <div>
                        <h1>My courses</h1>
                        <p>Manage your courses, edit their details and see how many students are enrolled.</p>
                    </div>
                    <div class="ins-hero__actions">
                        <a class="ins-btn ins-btn--primary" href="<?= h(url($newCourseHref)) ?>"><?= ico('plus') ?> Create new course</a>
                    </div>
                </section>

                <?php if ($loadError): ?>
                <div class="ins-alert" role="alert">
                    Your courses could not be loaded. Check that the database is running and the tables exist, then reload.
                </div>
                <?php else: ?>

                <!-- ========== Course list ========== -->
                <section class="ins-card" aria-labelledby="courses-title">
                    <div class="ins-card__head">
                        <h2 id="courses-title">All courses (<?= count($courses) ?>)</h2>

                        <?php if ($courses): ?>
                        <div class="ins-filters">
                            <label class="ins-visually-hidden" for="courseSearch">Search courses</label>
                            <input class="ins-input" id="courseSearch" type="search" placeholder="Search by title…" autocomplete="off">
                            <label class="ins-visually-hidden" for="courseStatus">Course status</label>
                            <select class="ins-input" id="courseStatus">
                                <option value="">All statuses</option>
                                <option value="published">Published</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$courses): ?>
                    <p class="ins-empty">You have not created any courses yet.</p>
                    <?php else: ?>

                    <!-- Course Cards Grid -->
                    <p class="ins-alert ins-course-notice" id="courseDeleteMessage" role="alert" hidden></p>
                    <div class="ins-course-grid" id="courseGrid">
                        <?php foreach ($courses as $course):
                           $lessons = (int) $course['lessons'];
                           $modules = (int) $course['modules'];
                           $thumb   = thumbUrl($course['thumbnail']);
                        ?>
                        <div class="ins-grid-card" data-title="<?= h(mb_strtolower($course['title'])) ?>" data-status="<?= h($course['status']) ?>">
                            <a class="ins-grid-card__content"
                               href="<?= h(url('instructor/courses/course_lesson.php?' . (int) $course['id'])) ?>">

                            <div class="ins-grid-card__header <?= h(tagClass((int) $course['id'])) ?>">
                                <?php if ($thumb !== null): ?>
                                    <img class="ins-grid-card__thumb" src="<?= h($thumb) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="ins-grid-card__thumb--empty" aria-hidden="true"><?= ico('book') ?></span>
                                <?php endif; ?>

                                <div class="ins-grid-card__badge">
                                    <?php if ($course['status'] === 'published'): ?>
                                        <span class="ins-chip ins-chip--success">Published</span>
                                    <?php else: ?>
                                        <span class="ins-chip ins-chip--muted">Draft</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ins-grid-card__body">
                                <h3 class="ins-grid-card__title"><?= h($course['title']) ?></h3>
                                <div class="ins-grid-card__meta">
                                   <?= $modules ?> module<?= $modules === 1 ? '' : 's' ?>
                                   &bull;
                                   <?= $lessons ?> lesson<?= $lessons === 1 ? '' : 's' ?>
                                   &bull;
                                   <?= h(date('M j, Y', (int) strtotime($course['created_at']))) ?>
                                </div>
                                <div class="ins-grid-card__stats">
                                    <?= ico('users') ?>
                                    <span><?= number_format((int) $course['enrolled']) ?> Students enrolled</span>
                                </div>
                            </div>

                            </a>

                            <div class="ins-grid-card__footer">
                                <a class="ins-btn" href="<?= h(url($editCourseHref . '?' . (int) $course['id'])) ?>">
                                    Edit
                                </a>
                                <button class="ins-btn ins-btn--danger" type="button"
                                        data-course-delete
                                        data-course-id="<?= (int) $course['id'] ?>"
                                        data-course-title="<?= h($course['title']) ?>"
                                        data-csrf-token="<?= h($courseFormCsrfToken) ?>"
                                        data-delete-url="<?= h(url('api/api_courses.php')) ?>">
                                    Delete
                                </button>
                            </div>

                        </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="ins-empty" id="courseNoMatch" hidden>No courses match your filter.</p>
                    <?php endif; ?>
                </section>

                <?php endif; ?>
            </main>

            <footer class="ins-footer">
                <span>© <?php echo date('Y'); ?> Learning Hub</span>
            </footer>
        </div>
    </div>

    <script src="<?= h(url('assests/instructor_dashboard.js')) ?>" defer></script>
</body>
</html>