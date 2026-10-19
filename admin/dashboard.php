<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';


/* ==========================================================================
   Only an admin may see this page
   ========================================================================== */
$user = currentUser();

if ($user === null) {
    redirect('');                                       // not signed in: go to the login page
}
if ($user['role'] !== 'admin') {
    redirect(dashboardPath($user['role']));             // signed in, but not an admin: own dashboard
}


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


/* ==========================================================================
   Load the numbers
   ========================================================================== */
$loadError = false;
$users = $courses = $signupDays = $recentUsers = $popularCourses = [];
$enrollments = 0;
$today = null;

try {
    $pdo = db();

    // "Today" comes from the database clock, so the 7-day chart matches the stored dates
    $today = new DateTimeImmutable((string) $pdo->query('SELECT CURDATE()')->fetchColumn());

    $users = $pdo->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(role = "student"), 0)    AS students,
                COALESCE(SUM(role = "instructor"), 0) AS instructors,
                COALESCE(SUM(role = "admin"), 0)      AS admins,
                COALESCE(SUM(status = "inactive"), 0) AS inactive,
                COALESCE(SUM(email_verified_at IS NULL), 0) AS unconfirmed,
                COALESCE(SUM(locked_until IS NOT NULL AND locked_until > NOW()), 0) AS locked
         FROM users'
    )->fetch();

    $courses = $pdo->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(status = "published"), 0) AS published,
                COALESCE(SUM(status = "draft"), 0)     AS drafts
         FROM courses'
    )->fetch();

    $enrollments = (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();

    // New sign-ups per day for the last 7 days
    $counts = [];
    $rows = $pdo->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS total FROM users
                           WHERE created_at >= :since GROUP BY DATE(created_at)');
    $rows->execute(['since' => $today->modify('-6 days')->format('Y-m-d')]);
    foreach ($rows->fetchAll() as $row) {
        $counts[$row['day']] = (int) $row['total'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $day = $today->modify("-$i days");
        $signupDays[] = [
            'label' => $day->format('D'),
            'date'  => $day->format('j M'),
            'total' => $counts[$day->format('Y-m-d')] ?? 0,
        ];
    }

    $recentUsers = $pdo->query(
        'SELECT name, email, role, status, email_verified_at, created_at
         FROM users ORDER BY created_at DESC, id DESC LIMIT 8'
    )->fetchAll();

    $popularCourses = $pdo->query(
        'SELECT c.title, c.status, u.name AS instructor, COUNT(e.id) AS enrolled
         FROM courses c
         JOIN users u ON u.id = c.instructor_id
         LEFT JOIN enrollments e ON e.course_id = c.id
         GROUP BY c.id, c.title, c.status, u.name
         ORDER BY enrolled DESC, c.title ASC
         LIMIT 5'
    )->fetchAll();
} catch (Throwable $error) {
    error_log('Admin dashboard failed to load: ' . $error->getMessage());
    $loadError = true;
}

$maxSignups  = max(1, ...array_column($signupDays ?: [['total' => 0]], 'total'));
$maxEnrolled = max(1, ...array_map(static fn(array $c): int => (int) $c['enrolled'], $popularCourses ?: [['enrolled' => 0]]));

$attention = $loadError ? [] : array_filter([
    ['label' => 'Locked accounts',     'hint' => 'Too many wrong passwords',     'count' => (int) $users['locked'],      'tone' => 'danger'],
    ['label' => 'Unconfirmed emails',  'hint' => 'Cannot sign in yet',           'count' => (int) $users['unconfirmed'], 'tone' => 'warning'],
    ['label' => 'Inactive accounts',   'hint' => 'Turned off by an admin',       'count' => (int) $users['inactive'],    'tone' => 'warning'],
    ['label' => 'Draft courses',       'hint' => 'Not visible to students yet',  'count' => (int) $courses['drafts'],    'tone' => 'info'],
], static fn(array $item): bool => $item['count'] > 0);

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Learning Hub</title>
    <link rel="stylesheet" href="<?= h(url('assests/theme.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('assests/dashboard.css')) ?>">
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

            <span class="admin-topbar__spacer"></span>

            <span class="admin-notification" role="img" aria-label="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            </span>
            <a class="admin-user" href="<?= h(url('profile')) ?>" aria-label="View profile for <?= h($user['name']) ?>">
                <span class="admin-user__avatar" aria-hidden="true"><?= h(initials($user['name'])) ?></span>
                <span class="admin-user__name"><?= h($user['name']) ?></span>
            </a>
            <form action="<?= h(url('admin/logout.php')) ?>" method="post">
                <button class="admin-btn" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation">
            <nav class="admin-sidebar__nav">
                <a class="admin-sidebar__link admin-sidebar__link--active" href="<?= h(url('admin/dashboard')) ?>" aria-current="page">
                    <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/></svg>
                    <span>Home</span>
                </a>
                <a class="admin-sidebar__link" href="<?= h(url('admin/users')) ?>">
                    <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Users</span>
                </a>
                <form action="<?= h(url('admin/logout.php')) ?>" method="post">
                    <button class="admin-sidebar__link admin-sidebar__logout" type="submit">
                        <svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                        <span>Logout</span>
                    </button>
                </form>
            </nav>
        </aside>

        <main class="admin-main" id="main">
          <div class="admin-container">
            <section class="admin-hero">
                <h1>Welcome back, <?= h($firstName) ?></h1>
                <p><?= $today ? h($today->format('l, j F Y')) . '. ' : '' ?>Here is how Learning Hub is doing.</p>
            </section>

            <?php if ($loadError): ?>
            <div class="admin-alert" role="alert">
                The dashboard numbers could not be loaded. Check that the database is running and the tables exist, then reload.
            </div>
            <?php else: ?>

            <section class="admin-stats" aria-label="Key numbers">
                <article class="admin-stat">
                    <p class="admin-stat__label">Users</p>
                    <p class="admin-stat__value"><?= (int) $users['total'] ?></p>
                    <p class="admin-stat__hint"><?= (int) $users['admins'] ?> admin<?= (int) $users['admins'] === 1 ? '' : 's' ?></p>
                </article>
                <article class="admin-stat">
                    <p class="admin-stat__label">Students</p>
                    <p class="admin-stat__value"><?= (int) $users['students'] ?></p>
                    <p class="admin-stat__hint">Learning on the platform</p>
                </article>
                <article class="admin-stat">
                    <p class="admin-stat__label">Instructors</p>
                    <p class="admin-stat__value"><?= (int) $users['instructors'] ?></p>
                    <p class="admin-stat__hint">Creating courses</p>
                </article>
                <article class="admin-stat">
                    <p class="admin-stat__label">Courses</p>
                    <p class="admin-stat__value"><?= (int) $courses['total'] ?></p>
                    <p class="admin-stat__hint"><?= (int) $courses['published'] ?> published, <?= (int) $courses['drafts'] ?> draft</p>
                </article>
                <article class="admin-stat">
                    <p class="admin-stat__label">Enrollments</p>
                    <p class="admin-stat__value"><?= $enrollments ?></p>
                    <p class="admin-stat__hint">Students in courses</p>
                </article>
            </section>

            <div class="admin-grid">

                <div class="admin-col">
                    <section class="admin-card" aria-labelledby="signups-title">
                        <div class="admin-card__head">
                            <h2 id="signups-title">New sign-ups</h2>
                            <span class="admin-card__note">Last 7 days</span>
                        </div>
                        <?php
                        $chartSummary = implode(', ', array_map(static fn(array $d): string => $d['label'] . ' ' . $d['total'], $signupDays));
                        ?>
                        <div class="admin-chart" role="img" aria-label="New sign-ups per day: <?= h($chartSummary) ?>">
                            <?php foreach ($signupDays as $day): ?>
                            <div class="admin-chart__col" title="<?= h($day['date']) ?>: <?= (int) $day['total'] ?>">
                                <span class="admin-chart__count"><?= (int) $day['total'] ?></span>
                                <span class="admin-chart__bar"><span class="admin-chart__fill" style="--h:<?= (int) round($day['total'] / $maxSignups * 100) ?>"></span></span>
                                <span class="admin-chart__label"><?= h($day['label']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="admin-card" aria-labelledby="recent-title">
                        <div class="admin-card__head">
                            <h2 id="recent-title">Recent users</h2>
                            <span class="admin-card__note">Newest first</span>
                        </div>
                        <?php if (!$recentUsers): ?>
                        <p class="admin-empty">No users yet.</p>
                        <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Joined</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsers as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="admin-person">
                                                <span class="admin-person__avatar" aria-hidden="true"><?= h(initials($row['name'])) ?></span>
                                                <span class="admin-person__text">
                                                    <span class="admin-person__name"><?= h($row['name']) ?></span>
                                                    <span class="admin-person__email"><?= h($row['email']) ?></span>
                                                </span>
                                            </span>
                                        </td>
                                        <td><span class="admin-chip admin-chip--<?= h($row['role']) ?>"><?= h(ucfirst($row['role'])) ?></span></td>
                                        <td>
                                            <?php if ($row['status'] === 'inactive'): ?>
                                                <span class="admin-chip admin-chip--muted">Inactive</span>
                                            <?php elseif ($row['email_verified_at'] === null): ?>
                                                <span class="admin-chip admin-chip--warning">Unconfirmed</span>
                                            <?php else: ?>
                                                <span class="admin-chip admin-chip--success">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="admin-table__date"><?= h(date('j M Y', (int) strtotime($row['created_at']))) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="admin-col">
                    <section class="admin-card" aria-labelledby="attention-title">
                        <div class="admin-card__head">
                            <h2 id="attention-title">Needs attention</h2>
                        </div>
                        <?php if (!$attention): ?>
                        <p class="admin-empty admin-empty--good">Nothing needs attention right now.</p>
                        <?php else: ?>
                        <ul class="admin-list">
                            <?php foreach ($attention as $item): ?>
                            <li class="admin-list__item">
                                <span class="admin-list__main">
                                    <span class="admin-list__title"><?= h($item['label']) ?></span>
                                    <span class="admin-list__sub"><?= h($item['hint']) ?></span>
                                </span>
                                <span class="admin-chip admin-chip--<?= h($item['tone']) ?>"><?= (int) $item['count'] ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </section>

                    <section class="admin-card" aria-labelledby="popular-title">
                        <div class="admin-card__head">
                            <h2 id="popular-title">Popular courses</h2>
                            <span class="admin-card__note">By enrollments</span>
                        </div>
                        <?php if (!$popularCourses): ?>
                        <p class="admin-empty">No courses yet.</p>
                        <?php else: ?>
                        <ul class="admin-list">
                            <?php foreach ($popularCourses as $course): ?>
                            <li class="admin-list__item admin-list__item--stack">
                                <span class="admin-list__row">
                                    <span class="admin-list__main">
                                        <span class="admin-list__title"><?= h($course['title']) ?></span>
                                        <span class="admin-list__sub">
                                            <?= h($course['instructor']) ?><?= $course['status'] === 'draft' ? ' (draft)' : '' ?>
                                        </span>
                                    </span>
                                    <span class="admin-list__number"><?= (int) $course['enrolled'] ?></span>
                                </span>
                                <span class="admin-meter" aria-hidden="true"><span class="admin-meter__fill" style="--w:<?= (int) round($course['enrolled'] / $maxEnrolled * 100) ?>"></span></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </section>
                </div>

            </div>
            <?php endif; ?>

          </div>
        </main>
    </div>

    <footer class="admin-footer">
        <span>© <?php echo date('Y'); ?> Learning Hub</span>
    </footer>

    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>