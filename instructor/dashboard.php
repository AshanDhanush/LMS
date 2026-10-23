<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';


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

/** "5 min ago", "3 hours ago" ... from a number of minutes (counted by the database clock) */
function ago(int $minutes): string
{
    if ($minutes < 1)    return 'Just now';
    if ($minutes < 60)   return $minutes . ' min ago';
    if ($minutes < 1440) { $n = intdiv($minutes, 60);   return $n . ($n === 1 ? ' hour ago' : ' hours ago'); }
    if ($minutes < 10080) { $n = intdiv($minutes, 1440); return $n . ($n === 1 ? ' day ago' : ' days ago'); }
    $n = intdiv($minutes, 10080);
    return $n . ($n === 1 ? ' week ago' : ' weeks ago');
}

/** Each course gets one of five tag colours, always the same one */
function tagClass(int $courseId): string
{
    return 'ins-tag--' . ($courseId % 5);
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
        'download' => '<path d="M12 4v11"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}


$newCourseHref = 'instructor/courses/coureses_add_form.php';
$exportHref    = 'instructor/dashboard.php#sub-title';


/* ==========================================================================
   Load the numbers (every query is limited to THIS instructor's courses)
   ========================================================================== */
$instructorId = (int) $user['id'];
$filter = in_array($_GET['filter'] ?? '', ['urgent', 'late'], true) ? $_GET['filter'] : 'all';

$loadError = false;
$courseStats = $studentStats = $ratingStats = $pendingStats = [];
$pending = $courseRows = $recentEnrollments = $discussions = [];
$newDiscussions = 0;

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(status = "published"), 0) AS published,
                COALESCE(SUM(status = "draft"), 0)     AS drafts
         FROM courses WHERE instructor_id = ?'
    );
    $stmt->execute([$instructorId]);
    $courseStats = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT e.user_id) AS students,
                COALESCE(SUM(e.enrolled_at >= NOW() - INTERVAL 30 DAY), 0) AS recent
         FROM enrollments e JOIN courses c ON c.id = e.course_id
         WHERE c.instructor_id = ?'
    );
    $stmt->execute([$instructorId]);
    $studentStats = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT ROUND(AVG(e.rating), 2) AS average, COUNT(e.rating) AS reviews
         FROM enrollments e JOIN courses c ON c.id = e.course_id
         WHERE c.instructor_id = ? AND e.rating IS NOT NULL'
    );
    $stmt->execute([$instructorId]);
    $ratingStats = $stmt->fetch();

    // Submissions that have no mark yet. "Urgent" = waiting more than 48 hours. "Late" = sent after the due date.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(s.submitted_at < NOW() - INTERVAL 48 HOUR), 0) AS urgent,
                COALESCE(SUM(s.submitted_at > a.due_date), 0)               AS late,
                COUNT(DISTINCT c.id)                                         AS courses
         FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN courses c ON c.id = a.course_id
         WHERE c.instructor_id = ? AND s.mark IS NULL'
    );
    $stmt->execute([$instructorId]);
    $pendingStats = $stmt->fetch();

    $where = 'c.instructor_id = ? AND s.mark IS NULL';
    if ($filter === 'urgent') $where .= ' AND s.submitted_at < NOW() - INTERVAL 48 HOUR';
    if ($filter === 'late')   $where .= ' AND s.submitted_at > a.due_date';
    $stmt = $pdo->prepare(
        "SELECT u.name, u.email, a.title AS assignment, c.id AS course_id, c.title AS course,
                s.submitted_at, (s.submitted_at > a.due_date) AS is_late,
                TIMESTAMPDIFF(MINUTE, s.submitted_at, NOW()) AS mins_ago
         FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN courses c ON c.id = a.course_id
         JOIN users u ON u.id = s.student_id
         WHERE $where
         ORDER BY s.submitted_at ASC
         LIMIT 8"
    );
    $stmt->execute([$instructorId]);
    $pending = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT c.id, c.title, c.status,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id)     AS lessons,
                (SELECT COUNT(*) FROM lesson_progress lp
                   JOIN lessons l2 ON l2.id = lp.lesson_id
                   JOIN enrollments e2 ON e2.course_id = l2.course_id AND e2.user_id = lp.user_id
                  WHERE l2.course_id = c.id)                                  AS completed
         FROM courses c
         WHERE c.instructor_id = ?
         ORDER BY enrolled DESC, c.title ASC
         LIMIT 12'
    );
    $stmt->execute([$instructorId]);
    $courseRows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT u.name, c.title AS course, TIMESTAMPDIFF(MINUTE, e.enrolled_at, NOW()) AS mins_ago
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id
         JOIN users u ON u.id = e.user_id
         WHERE c.instructor_id = ?
         ORDER BY e.enrolled_at DESC, e.id DESC
         LIMIT 5'
    );
    $stmt->execute([$instructorId]);
    $recentEnrollments = $stmt->fetchAll();

    // Latest comments from other people (students) on this instructor's lessons
    $stmt = $pdo->prepare(
        'SELECT cm.body, u.name, c.id AS course_id, c.title AS course,
                TIMESTAMPDIFF(MINUTE, cm.created_at, NOW()) AS mins_ago
         FROM comments cm
         JOIN lessons l ON l.id = cm.lesson_id
         JOIN courses c ON c.id = l.course_id
         JOIN users u ON u.id = cm.user_id
         WHERE c.instructor_id = ? AND cm.user_id <> ?
         ORDER BY cm.created_at DESC, cm.id DESC
         LIMIT 3'
    );
    $stmt->execute([$instructorId, $instructorId]);
    $discussions = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM comments cm
         JOIN lessons l ON l.id = cm.lesson_id
         JOIN courses c ON c.id = l.course_id
         WHERE c.instructor_id = ? AND cm.user_id <> ? AND cm.created_at >= NOW() - INTERVAL 1 DAY'
    );
    $stmt->execute([$instructorId, $instructorId]);
    $newDiscussions = (int) $stmt->fetchColumn();
} catch (Throwable $error) {
    error_log('Instructor dashboard failed to load: ' . $error->getMessage());
    $loadError = true;
}

$pendingTotal   = (int) ($pendingStats['total'] ?? 0);
$urgentTotal    = (int) ($pendingStats['urgent'] ?? 0);
$lateTotal      = (int) ($pendingStats['late'] ?? 0);
$filterTotals   = ['all' => $pendingTotal, 'urgent' => $urgentTotal, 'late' => $lateTotal];
$averageRating  = (float) ($ratingStats['average'] ?? 0);
$reviewCount    = (int) ($ratingStats['reviews'] ?? 0);

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard | Learning Hub</title>
    <link rel="stylesheet" href="<?= h(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('assests/css/instructor_dashboard.css')) ?>">
</head>
<body class="ins">
    <a class="ins-skip" href="#main">Skip to content</a>

    <div class="ins-layout">

        <?php require __DIR__ . '/sidebar.php'; ?>

        <div class="ins-body">

            <!-- ========== Top bar ========== -->
            <header class="ins-topbar">
                <button class="ins-menu-toggle" type="button" aria-label="Open navigation" aria-controls="instructor-sidebar" aria-expanded="false" data-instructor-sidebar-toggle>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="ins-topbar__title">Dashboard</span>
                <span class="ins-topbar__spacer"></span>
                <a class="ins-user" href="<?= h(url('profile')) ?>" aria-label="View profile for <?= h($user['name']) ?>">
                    <?php if (!empty($user['photo'])): ?>
                    <img class="ins-user__avatar" src="<?= h(url((string) $user['photo'])) ?>" alt="" aria-hidden="true">
                    <?php else: ?>
                    <span class="ins-user__avatar" aria-hidden="true"><?= h(initials($user['name'])) ?></span>
                    <?php endif; ?>
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

                <!-- ========== Welcome ========== -->
                <section class="ins-hero">
                    <div>
                        <h1>Welcome back, <?= h($firstName) ?></h1>
                        <p>
                            <?php if ($loadError): ?>
                                Here is your teaching overview.
                            <?php elseif ($pendingTotal > 0): ?>
                                You have <strong><?= $pendingTotal ?> assignment<?= $pendingTotal === 1 ? '' : 's' ?></strong> waiting for evaluation
                                across <?= (int) $pendingStats['courses'] ?> course<?= (int) $pendingStats['courses'] === 1 ? '' : 's' ?>.
                            <?php else: ?>
                                You have no assignments waiting for evaluation. Well done.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="ins-hero__actions">
                        <a class="ins-btn" href="<?= h(url($exportHref)) ?>"><?= ico('download') ?> Export gradebook</a>
                        <a class="ins-btn ins-btn--primary" href="<?= h(url($newCourseHref)) ?>"><?= ico('plus') ?> New course</a>
                    </div>
                </section>

                <?php if ($loadError): ?>
                <div class="ins-alert" role="alert">
                    Your dashboard numbers could not be loaded. Check that the database is running and the tables exist, then reload.
                </div>
                <?php else: ?>

                <!-- ========== Key numbers ========== -->
                <section class="ins-stats" id="teaching-stats" aria-label="Key numbers">
                    <article class="ins-stat">
                        <div class="ins-stat__top">
                            <p class="ins-stat__label">Active courses</p>
                            <span class="ins-stat__icon ins-stat__icon--blue"><?= ico('book') ?></span>
                        </div>
                        <p class="ins-stat__value"><?= (int) $courseStats['published'] ?> <span>active</span></p>
                        <p class="ins-stat__foot"><?= (int) $courseStats['drafts'] ?> in draft mode</p>
                    </article>

                    <article class="ins-stat">
                        <div class="ins-stat__top">
                            <p class="ins-stat__label">Total students</p>
                            <span class="ins-stat__icon ins-stat__icon--green"><?= ico('users') ?></span>
                        </div>
                        <p class="ins-stat__value"><?= number_format((int) $studentStats['students']) ?> <span>enrolled</span></p>
                        <p class="ins-stat__foot">+<?= (int) $studentStats['recent'] ?> new enrollments in 30 days</p>
                    </article>

                    <article class="ins-stat" id="course-rating">
                        <div class="ins-stat__top">
                            <p class="ins-stat__label">Average rating</p>
                            <span class="ins-stat__icon ins-stat__icon--purple"><?= ico('star') ?></span>
                        </div>
                        <?php if ($reviewCount > 0): ?>
                        <p class="ins-stat__value"><?= number_format($averageRating, 2) ?> <span>/ 5.0</span></p>
                        <p class="ins-stat__foot">
                            <span class="ins-stars" style="--rating:<?= h((string) $averageRating) ?>" role="img" aria-label="<?= h(number_format($averageRating, 1)) ?> out of 5 stars"></span>
                            <?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>
                        </p>
                        <?php else: ?>
                        <p class="ins-stat__value ins-stat__value--quiet">No ratings yet</p>
                        <p class="ins-stat__foot">Students rate a course after enrolling</p>
                        <?php endif; ?>
                    </article>

                    <article class="ins-stat ins-stat--alert">
                        <div class="ins-stat__top">
                            <p class="ins-stat__label">Submissions to grade</p>
                            <span class="ins-stat__icon ins-stat__icon--red"><?= ico('clipboard') ?></span>
                        </div>
                        <p class="ins-stat__value"><?= $pendingTotal ?> <span>pending</span></p>
                        <p class="ins-stat__foot">
                            <?php if ($urgentTotal > 0): ?>
                            <strong class="ins-danger"><?= $urgentTotal ?> waiting over 48 hours</strong>
                            <?php else: ?>
                            Nothing waiting over 48 hours
                            <?php endif; ?>
                        </p>
                    </article>
                </section>

                <div class="ins-grid">

                    <div class="ins-col">

                        <!-- ========== Submissions to grade ========== -->
                        <section class="ins-card" aria-labelledby="sub-title">
                            <div class="ins-card__head">
                                <h2 id="sub-title">Submissions to grade</h2>
                                <?php if ($pendingTotal > 0): ?><span class="ins-chip ins-chip--danger"><?= $pendingTotal ?> unchecked</span><?php endif; ?>
                                <nav class="ins-tabs" aria-label="Filter submissions">
                                    <a class="ins-tabs__item<?= $filter === 'all' ? ' is-current' : '' ?>" href="?filter=all"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>All</a>
                                    <a class="ins-tabs__item<?= $filter === 'urgent' ? ' is-current' : '' ?>" href="?filter=urgent"<?= $filter === 'urgent' ? ' aria-current="page"' : '' ?>>Urgent (<?= $urgentTotal ?>)</a>
                                    <a class="ins-tabs__item<?= $filter === 'late' ? ' is-current' : '' ?>" href="?filter=late"<?= $filter === 'late' ? ' aria-current="page"' : '' ?>>Late (<?= $lateTotal ?>)</a>
                                </nav>
                            </div>

                            <?php if (!$pending): ?>
                            <p class="ins-empty<?= $pendingTotal === 0 ? ' ins-empty--good' : '' ?>">
                                <?= $pendingTotal === 0 ? 'No submissions are waiting for a mark.' : 'No submissions match this filter.' ?>
                            </p>
                            <?php else: ?>
                            <div class="ins-table-wrap">
                                <table class="ins-table">
                                    <thead>
                                        <tr><th scope="col">Student</th><th scope="col">Assignment &amp; course</th><th scope="col">Submitted</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending as $row): ?>
                                        <tr>
                                            <td>
                                                <span class="ins-person">
                                                    <span class="ins-person__avatar" aria-hidden="true"><?= h(initials($row['name'])) ?></span>
                                                    <span class="ins-person__text">
                                                        <span class="ins-person__name"><?= h($row['name']) ?></span>
                                                        <span class="ins-person__sub"><?= h($row['email']) ?></span>
                                                    </span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="ins-assignment"><?= h($row['assignment']) ?></span>
                                                <span class="ins-tag <?= h(tagClass((int) $row['course_id'])) ?>"><?= h($row['course']) ?></span>
                                            </td>
                                            <td class="ins-table__time">
                                                <strong><?= h(ago((int) $row['mins_ago'])) ?></strong>
                                                <span><?= h(date('M j, g:i A', (int) strtotime($row['submitted_at']))) ?></span>
                                                <?php if ((int) $row['is_late'] === 1): ?><span class="ins-chip ins-chip--warning">Late</span><?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="ins-card__foot">Showing <?= count($pending) ?> of <?= (int) $filterTotals[$filter] ?> waiting submission<?= $filterTotals[$filter] === 1 ? '' : 's' ?>, oldest first</p>
                            <?php endif; ?>
                        </section>

                        <!-- ========== Active courses overview ========== -->
                        <section class="ins-card" aria-labelledby="courses-title">
                            <div class="ins-card__head">
                                <div>
                                    <h2 id="courses-title">Courses overview</h2>
                                    <p class="ins-card__sub">Enrollment and how far students have got</p>
                                </div>
                                <?php if ($courseRows): ?>
                                <div class="ins-filters">
                                    <label class="ins-visually-hidden" for="courseSearch">Filter courses</label>
                                    <input class="ins-input" id="courseSearch" type="search" placeholder="Filter courses…" autocomplete="off">
                                    <label class="ins-visually-hidden" for="courseStatus">Course status</label>
                                    <select class="ins-input" id="courseStatus">
                                        <option value="">All statuses</option>
                                        <option value="published">Published</option>
                                        <option value="draft">Draft</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!$courseRows): ?>
                            <p class="ins-empty">You have no courses yet.</p>
                            <?php else: ?>
                            <div class="ins-table-wrap">
                                <table class="ins-table" id="courseTable">
                                    <thead>
                                        <tr><th scope="col">Course</th><th scope="col">Enrolled</th><th scope="col">Cohort progress</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courseRows as $course):
                                            $lessons   = (int) $course['lessons'];
                                            $enrolled  = (int) $course['enrolled'];
                                            $completed = (int) $course['completed'];
                                            $percent   = ($lessons > 0 && $enrolled > 0) ? min(100, (int) round($completed / ($lessons * $enrolled) * 100)) : null;
                                        ?>
                                        <tr data-title="<?= h(mb_strtolower($course['title'])) ?>" data-status="<?= h($course['status']) ?>">
                                            <td>
                                                <span class="ins-course">
                                                    <span class="ins-course__icon <?= h(tagClass((int) $course['id'])) ?>" aria-hidden="true"><?= ico('book') ?></span>
                                                    <span class="ins-course__text">
                                                        <span class="ins-course__title"><?= h($course['title']) ?></span>
                                                        <span class="ins-course__sub">
                                                            <?= $lessons ?> lesson<?= $lessons === 1 ? '' : 's' ?>
                                                            <?php if ($course['status'] === 'draft'): ?><span class="ins-chip ins-chip--muted">Draft</span><?php endif; ?>
                                                        </span>
                                                    </span>
                                                </span>
                                            </td>
                                            <td class="ins-table__num"><?= number_format($enrolled) ?></td>
                                            <td class="ins-table__progress">
                                                <?php if ($percent !== null): ?>
                                                <span class="ins-progress" role="img" aria-label="<?= $percent ?> percent complete">
                                                    <span class="ins-progress__bar"><span class="ins-progress__fill" style="--w:<?= $percent ?>"></span></span>
                                                    <span class="ins-progress__text"><?= $percent ?>%</span>
                                                </span>
                                                <?php else: ?>
                                                <span class="ins-muted"><?= $enrolled === 0 ? 'No students yet' : 'No lessons yet' ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="ins-empty" id="courseNoMatch" hidden>No courses match your filter.</p>
                            <?php endif; ?>
                        </section>
                    </div>

                    <div class="ins-col">

                        <!-- ========== Recent enrollments ========== -->
                        <section class="ins-card" aria-labelledby="enroll-title">
                            <div class="ins-card__head">
                                <h2 id="enroll-title">Recent enrollments</h2>
                            </div>
                            <?php if (!$recentEnrollments): ?>
                            <p class="ins-empty">No students have enrolled yet.</p>
                            <?php else: ?>
                            <ul class="ins-list">
                                <?php foreach ($recentEnrollments as $row): ?>
                                <li class="ins-list__item">
                                    <span class="ins-person__avatar" aria-hidden="true"><?= h(initials($row['name'])) ?></span>
                                    <span class="ins-list__main">
                                        <span class="ins-list__title"><?= h($row['name']) ?></span>
                                        <span class="ins-list__sub">Enrolled in <?= h($row['course']) ?></span>
                                    </span>
                                    <span class="ins-list__time"><?= h(ago((int) $row['mins_ago'])) ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </section>

                        <!-- ========== Discussions ========== -->
                        <section class="ins-card" aria-labelledby="disc-title">
                            <div class="ins-card__head">
                                <h2 id="disc-title">Discussions</h2>
                                <?php if ($newDiscussions > 0): ?><span class="ins-chip ins-chip--primary"><?= $newDiscussions ?> new</span><?php endif; ?>
                            </div>
                            <?php if (!$discussions): ?>
                            <p class="ins-empty">No student comments yet.</p>
                            <?php else: ?>
                            <ul class="ins-comments">
                                <?php foreach ($discussions as $row): ?>
                                <li class="ins-comment">
                                    <div class="ins-comment__head">
                                        <strong><?= h($row['name']) ?></strong>
                                        <span class="ins-comment__time"><?= h(ago((int) $row['mins_ago'])) ?></span>
                                        <span class="ins-tag <?= h(tagClass((int) $row['course_id'])) ?>"><?= h($row['course']) ?></span>
                                    </div>
                                    <p>&ldquo;<?= h(mb_strimwidth((string) $row['body'], 0, 160, '…', 'UTF-8')) ?>&rdquo;</p>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>

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