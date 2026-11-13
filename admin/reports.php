<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

/* Only an admin may see this page */
$user = currentUser();
if ($user === null) {
    redirect('');
}
if ($user['role'] !== 'admin') {
    redirect(dashboardPath($user['role']));
}

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

/** A valid Y-m-d date, or null. */
function reportDate(mixed $value): ?string
{
    $value = trim((string) $value);
    $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : null;
}

function pct(int $part, int $whole): int
{
    return $whole > 0 ? (int) round($part / $whole * 100) : 0;
}

$reportTypes = [
    'enrollments' => 'Enrollments',
    'courses'     => 'Course performance',
    'students'    => 'Students',
    'emails'      => 'Email usage',
];
$report = (string) ($_GET['report'] ?? 'enrollments');
if (!isset($reportTypes[$report])) {
    $report = 'enrollments';
}
$exporting = ($_GET['export'] ?? '') === 'csv';

$loadError = false;
$stats = $rows = $byCourse = $courseList = $days = [];
$csvHeader = $csvRows = [];
$from = $to = null;
$courseId = null;
$q = '';
$activity = 'all';
$page = 1;
$perPage = 25;
$totalRows = 0;
$today = null;

try {
    $pdo   = db();
    $today = new DateTimeImmutable((string) $pdo->query('SELECT CURDATE()')->fetchColumn());

    /* ---------------- Enrollments ---------------- */
    if ($report === 'enrollments') {
        $to   = reportDate($_GET['to'] ?? '')   ?? $today->format('Y-m-d');
        $from = reportDate($_GET['from'] ?? '') ?? $today->modify('-29 days')->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        $where  = 'e.enrolled_at >= :from AND e.enrolled_at < :to';
        $params = [':from' => $from . ' 00:00:00', ':to' => (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
        if ($courseId) {
            $where .= ' AND e.course_id = :cid';
            $params[':cid'] = $courseId;
        }

        $courseList = $pdo->query('SELECT id, title FROM courses ORDER BY title')->fetchAll();

        $s = $pdo->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT e.user_id) AS students,
                                   COALESCE(SUM(e.completed_at IS NOT NULL), 0) AS completed
                            FROM enrollments e WHERE $where");
        $s->execute($params);
        $stats = $s->fetch();

        $s = $pdo->prepare("SELECT c.title, COUNT(e.id) AS enrolled, COALESCE(SUM(e.completed_at IS NOT NULL), 0) AS completed
                            FROM enrollments e JOIN courses c ON c.id = e.course_id
                            WHERE $where GROUP BY c.id, c.title ORDER BY enrolled DESC, c.title");
        $s->execute($params);
        $byCourse = $s->fetchAll();

        $s = $pdo->prepare("SELECT e.enrolled_at, e.completed_at, u.name, u.email, c.title AS course
                            FROM enrollments e
                            JOIN users u ON u.id = e.user_id
                            JOIN courses c ON c.id = e.course_id
                            WHERE $where ORDER BY e.enrolled_at DESC, e.id DESC");
        $s->execute($params);
        $rows = $s->fetchAll();

        $csvHeader = ['Student', 'Email', 'Course', 'Enrolled on', 'Completed on', 'Status'];
        foreach ($rows as $r) {
            $csvRows[] = [$r['name'], $r['email'], $r['course'], $r['enrolled_at'], $r['completed_at'] ?? '', $r['completed_at'] ? 'Completed' : 'In progress'];
        }
    }

    /* ---------------- Course performance ---------------- */
    if ($report === 'courses') {
        $rows = $pdo->query(
            'SELECT c.id, c.title, c.status, u.name AS instructor,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.completed_at IS NOT NULL) AS completed,
                    (SELECT ROUND(AVG(e.rating), 1) FROM enrollments e WHERE e.course_id = c.id AND e.rating IS NOT NULL) AS avg_rating,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.rating IS NOT NULL) AS reviews,
                    (SELECT COUNT(*) FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE q.course_id = c.id) AS attempts,
                    (SELECT COALESCE(SUM(qa.passed), 0) FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE q.course_id = c.id) AS passed
             FROM courses c JOIN users u ON u.id = c.instructor_id
             ORDER BY enrolled DESC, c.title'
        )->fetchAll();

        $stats = [
            'courses'   => count($rows),
            'enrolled'  => array_sum(array_map(static fn($r) => (int) $r['enrolled'], $rows)),
            'completed' => array_sum(array_map(static fn($r) => (int) $r['completed'], $rows)),
            'attempts'  => array_sum(array_map(static fn($r) => (int) $r['attempts'], $rows)),
            'passed'    => array_sum(array_map(static fn($r) => (int) $r['passed'], $rows)),
        ];

        $csvHeader = ['Course', 'Instructor', 'Status', 'Enrolled', 'Completed', 'Completion %', 'Average rating', 'Reviews', 'Quiz attempts', 'Quiz pass %'];
        foreach ($rows as $r) {
            $csvRows[] = [$r['title'], $r['instructor'], $r['status'], (int) $r['enrolled'], (int) $r['completed'],
                pct((int) $r['completed'], (int) $r['enrolled']), $r['avg_rating'] ?? '', (int) $r['reviews'],
                (int) $r['attempts'], pct((int) $r['passed'], (int) $r['attempts'])];
        }
    }

    /* ---------------- Students ---------------- */
    if ($report === 'students') {
        $q        = trim((string) ($_GET['q'] ?? ''));
        $activity = in_array($_GET['activity'] ?? '', ['active', 'inactive', 'none'], true) ? (string) $_GET['activity'] : 'all';
        $page     = max(1, (int) ($_GET['page'] ?? 1));

        $sql = "SELECT u.name, u.email, u.status, u.points, u.created_at,
                       (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS enrolled,
                       (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.completed_at IS NOT NULL) AS completed,
                       (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = u.id) AS lessons_done,
                       (SELECT MAX(lp.completed_at) FROM lesson_progress lp WHERE lp.user_id = u.id) AS last_activity,
                       EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id = u.id AND lp.completed_at >= NOW() - INTERVAL 30 DAY) AS recent
                FROM users u WHERE u.role = 'student'";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [':q1' => $like, ':q2' => $like];
        }
        $s = $pdo->prepare($sql . ' ORDER BY u.created_at DESC, u.id DESC');
        $s->execute($params);
        foreach ($s->fetchAll() as $r) {
            $r['state'] = (int) $r['enrolled'] === 0 ? 'none' : ((int) $r['recent'] === 1 ? 'active' : 'inactive');
            if ($activity === 'all' || $activity === $r['state']) {
                $rows[] = $r;
            }
        }
        $totalRows = count($rows);
        $stats = [
            'total'    => $totalRows,
            'active'   => count(array_filter($rows, static fn($r) => $r['state'] === 'active')),
            'inactive' => count(array_filter($rows, static fn($r) => $r['state'] === 'inactive')),
            'none'     => count(array_filter($rows, static fn($r) => $r['state'] === 'none')),
        ];

        $stateText = ['active' => 'Active', 'inactive' => 'Inactive 30+ days', 'none' => 'Not enrolled'];
        $csvHeader = ['Student', 'Email', 'Account', 'Courses enrolled', 'Courses completed', 'Lessons completed', 'Points', 'Last lesson activity', 'Learning status', 'Joined'];
        foreach ($rows as $r) {
            $csvRows[] = [$r['name'], $r['email'], $r['status'], (int) $r['enrolled'], (int) $r['completed'], (int) $r['lessons_done'],
                (int) $r['points'], $r['last_activity'] ?? '', $stateText[$r['state']], $r['created_at']];
        }

        $pages = max(1, (int) ceil($totalRows / $perPage));
        $page  = min($page, $pages);
        if (!$exporting) {
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }
    }

    /* ---------------- Email usage ---------------- */
    if ($report === 'emails') {
        $tz  = new DateTimeZone('Asia/Colombo');
        $now = new DateTimeImmutable('now', $tz);
        $counts = [];
        foreach ($pdo->query("SELECT dedupe_key, email_count FROM notifications WHERE type = 'email_quota'")->fetchAll() as $r) {
            $counts[substr((string) $r['dedupe_key'], 12)] = (int) $r['email_count'];
        }
        for ($i = 0; $i < 30; $i++) {
            $d = $now->modify("-$i days");
            $days[] = ['date' => $d, 'sent' => $counts[$d->format('Y-m-d')] ?? 0];
        }
        $sentToday = $days[0]['sent'];
        $stats = [
            'today'     => $sentToday,
            'remaining' => emailsRemainingToday($pdo),
            'limit'     => EMAIL_DAILY_LIMIT,
            'month'     => array_sum(array_column($days, 'sent')),
            'busiest'   => max(array_column($days, 'sent')),
        ];
        $csvHeader = ['Date', 'Emails sent', 'Daily limit', 'Limit used %'];
        foreach ($days as $d) {
            $csvRows[] = [$d['date']->format('Y-m-d'), $d['sent'], EMAIL_DAILY_LIMIT, pct($d['sent'], EMAIL_DAILY_LIMIT)];
        }
    }
} catch (Throwable $error) {
    error_log('Admin reports failed to load: ' . $error->getMessage());
    $loadError = true;
}

/* ---------------- CSV download ---------------- */
if ($exporting && !$loadError) {
    $safe = static function ($cell): string {
        $cell = (string) $cell;
        return ($cell !== '' && strpos("=+-@\t\r", $cell[0]) !== false) ? "'" . $cell : $cell;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report-' . $report . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $csvHeader);
    foreach ($csvRows as $line) {
        fputcsv($out, array_map($safe, $line));
    }
    fclose($out);
    exit;
}

/** Address of this page that keeps the current filters. */
$link = static function (array $extra = []) use ($report, $from, $to, $courseId, $q, $activity): string {
    $query = array_merge(['report' => $report], $report === 'enrollments'
        ? ['from' => $from, 'to' => $to, 'course_id' => $courseId]
        : ($report === 'students' ? ['q' => $q, 'activity' => $activity !== 'all' ? $activity : null] : []), $extra);
    return 'admin/reports?' . http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
};

$adminPageTitle  = 'Reports | Learning Hub';
$adminActivePage = 'reports';
?>
<?php require __DIR__ . '/header.php'; ?>

        <main class="admin-main" id="main">
          <div class="admin-container">
            <section class="admin-hero">
                <h1>Reports</h1>
                <p>Pick a report, narrow it down, and download it as a CSV file.</p>
            </section>

            <nav class="admin-tabs" aria-label="Report types">
                <?php foreach ($reportTypes as $key => $label): ?>
                <a class="admin-tabs__link<?= $key === $report ? ' admin-tabs__link--active' : '' ?>" href="<?= h(url('admin/reports?report=' . $key)) ?>"<?= $key === $report ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($loadError): ?>
            <div class="admin-alert" role="alert">The report could not be loaded. Check that the database is running and the tables exist, then reload.</div>
            <?php else: ?>

            <?php if ($report === 'enrollments'): ?>
            <form class="admin-filter-form admin-filter-form--labeled" method="get" action="<?= h(url('admin/reports')) ?>">
                <input type="hidden" name="report" value="enrollments">
                <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
                <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
                <label>Course
                    <select name="course_id">
                        <option value="">All courses</option>
                        <?php foreach ($courseList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $courseId === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="admin-filter-form__actions">
                    <button class="admin-btn" type="submit">Apply</button>
                    <a class="admin-btn" href="<?= h(url($link(['export' => 'csv']))) ?>">Download CSV</a>
                </div>
            </form>

            <section class="admin-stats" aria-label="Key numbers">
                <article class="admin-stat"><p class="admin-stat__label">Enrollments</p><p class="admin-stat__value"><?= (int) $stats['total'] ?></p><p class="admin-stat__hint"><?= h(date('j M', strtotime($from))) ?> to <?= h(date('j M Y', strtotime($to))) ?></p></article>
                <article class="admin-stat"><p class="admin-stat__label">Students</p><p class="admin-stat__value"><?= (int) $stats['students'] ?></p><p class="admin-stat__hint">Who enrolled</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Completed</p><p class="admin-stat__value"><?= (int) $stats['completed'] ?></p><p class="admin-stat__hint"><?= pct((int) $stats['completed'], (int) $stats['total']) ?>% of these enrollments</p></article>
            </section>

            <div class="admin-grid">
                <div class="admin-col">
                    <section class="admin-card">
                        <div class="admin-card__head"><h2>Enrollment list</h2><span class="admin-card__note">Newest first</span></div>
                        <?php if (!$rows): ?>
                        <p class="admin-empty">No enrollments in this period.</p>
                        <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Student</th><th scope="col">Course</th><th scope="col">Status</th><th scope="col">Enrolled</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($rows, 0, 100) as $r): ?>
                                <tr>
                                    <td><span class="admin-person"><span class="admin-person__avatar" aria-hidden="true"><?= h(initials($r['name'])) ?></span><span class="admin-person__text"><span class="admin-person__name"><?= h($r['name']) ?></span><span class="admin-person__email"><?= h($r['email']) ?></span></span></span></td>
                                    <td><?= h($r['course']) ?></td>
                                    <td><?php if ($r['completed_at']): ?><span class="admin-chip admin-chip--success">Completed</span><?php else: ?><span class="admin-chip admin-chip--info">In progress</span><?php endif; ?></td>
                                    <td class="admin-table__date"><?= h(date('j M Y', (int) strtotime($r['enrolled_at']))) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($rows) > 100): ?><p class="admin-card__note">Showing the newest 100 of <?= count($rows) ?>. The CSV has all of them.</p><?php endif; ?>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="admin-col">
                    <section class="admin-card">
                        <div class="admin-card__head"><h2>By course</h2><span class="admin-card__note">Enrollments</span></div>
                        <?php if (!$byCourse): ?>
                        <p class="admin-empty">Nothing to show.</p>
                        <?php else: $maxC = max(1, (int) $byCourse[0]['enrolled']); ?>
                        <ul class="admin-list">
                            <?php foreach ($byCourse as $c): ?>
                            <li class="admin-list__item admin-list__item--stack">
                                <span class="admin-list__row">
                                    <span class="admin-list__main"><span class="admin-list__title"><?= h($c['title']) ?></span><span class="admin-list__sub"><?= (int) $c['completed'] ?> completed</span></span>
                                    <span class="admin-list__number"><?= (int) $c['enrolled'] ?></span>
                                </span>
                                <span class="admin-meter" aria-hidden="true"><span class="admin-meter__fill" style="--w:<?= pct((int) $c['enrolled'], $maxC) ?>"></span></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($report === 'courses'): ?>
            <div class="admin-actions" style="margin-bottom:16px"><a class="admin-btn" href="<?= h(url($link(['export' => 'csv']))) ?>">Download CSV</a></div>
            <section class="admin-stats" aria-label="Key numbers">
                <article class="admin-stat"><p class="admin-stat__label">Courses</p><p class="admin-stat__value"><?= (int) $stats['courses'] ?></p><p class="admin-stat__hint">All statuses</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Enrollments</p><p class="admin-stat__value"><?= (int) $stats['enrolled'] ?></p><p class="admin-stat__hint">All time</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Completion</p><p class="admin-stat__value"><?= pct((int) $stats['completed'], (int) $stats['enrolled']) ?>%</p><p class="admin-stat__hint"><?= (int) $stats['completed'] ?> finished</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Quiz pass rate</p><p class="admin-stat__value"><?= pct((int) $stats['passed'], (int) $stats['attempts']) ?>%</p><p class="admin-stat__hint"><?= (int) $stats['attempts'] ?> attempts</p></article>
            </section>
            <section class="admin-card">
                <div class="admin-card__head"><h2>Every course</h2><span class="admin-card__note">Most enrolled first</span></div>
                <?php if (!$rows): ?>
                <p class="admin-empty">No courses yet.</p>
                <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th scope="col">Course</th><th scope="col">Enrolled</th><th scope="col">Completion</th><th scope="col">Rating</th><th scope="col">Quiz pass</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><span class="admin-person__name"><?= h($r['title']) ?></span><span class="admin-person__email"><?= h($r['instructor']) ?><?= $r['status'] === 'draft' ? ' (draft)' : '' ?></span></td>
                            <td><?= (int) $r['enrolled'] ?></td>
                            <td><?= pct((int) $r['completed'], (int) $r['enrolled']) ?>% <span class="admin-person__email">(<?= (int) $r['completed'] ?>)</span></td>
                            <td><?= $r['avg_rating'] === null ? '-' : h((string) $r['avg_rating']) . ' / 5' ?> <span class="admin-person__email">(<?= (int) $r['reviews'] ?>)</span></td>
                            <td><?= (int) $r['attempts'] === 0 ? '-' : pct((int) $r['passed'], (int) $r['attempts']) . '%' ?> <span class="admin-person__email">(<?= (int) $r['attempts'] ?>)</span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($report === 'students'): ?>
            <form class="admin-filter-form admin-filter-form--labeled" method="get" action="<?= h(url('admin/reports')) ?>">
                <input type="hidden" name="report" value="students">
                <label>Search<input type="search" name="q" value="<?= h($q) ?>" placeholder="Name or email"></label>
                <label>Learning status
                    <select name="activity">
                        <option value="all"<?= $activity === 'all' ? ' selected' : '' ?>>All students</option>
                        <option value="active"<?= $activity === 'active' ? ' selected' : '' ?>>Active (last 30 days)</option>
                        <option value="inactive"<?= $activity === 'inactive' ? ' selected' : '' ?>>Inactive 30+ days</option>
                        <option value="none"<?= $activity === 'none' ? ' selected' : '' ?>>Not enrolled</option>
                    </select>
                </label>
                <div class="admin-filter-form__actions">
                    <button class="admin-btn" type="submit">Apply</button>
                    <a class="admin-btn" href="<?= h(url($link(['export' => 'csv']))) ?>">Download CSV</a>
                </div>
            </form>
            <section class="admin-stats" aria-label="Key numbers">
                <article class="admin-stat"><p class="admin-stat__label">Students</p><p class="admin-stat__value"><?= (int) $stats['total'] ?></p><p class="admin-stat__hint">Matching the filters</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Active</p><p class="admin-stat__value"><?= (int) $stats['active'] ?></p><p class="admin-stat__hint">Finished a lesson in 30 days</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Inactive</p><p class="admin-stat__value"><?= (int) $stats['inactive'] ?></p><p class="admin-stat__hint">Enrolled, but quiet</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Not enrolled</p><p class="admin-stat__value"><?= (int) $stats['none'] ?></p><p class="admin-stat__hint">No course yet</p></article>
            </section>
            <section class="admin-card">
                <div class="admin-card__head"><h2>Student progress</h2><span class="admin-card__note">Newest first</span></div>
                <?php if (!$rows): ?>
                <p class="admin-empty">No students match.</p>
                <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th scope="col">Student</th><th scope="col">Courses</th><th scope="col">Lessons done</th><th scope="col">Status</th><th scope="col">Last activity</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><span class="admin-person"><span class="admin-person__avatar" aria-hidden="true"><?= h(initials($r['name'])) ?></span><span class="admin-person__text"><span class="admin-person__name"><?= h($r['name']) ?></span><span class="admin-person__email"><?= h($r['email']) ?></span></span></span></td>
                            <td><?= (int) $r['completed'] ?> / <?= (int) $r['enrolled'] ?> <span class="admin-person__email">done</span></td>
                            <td><?= (int) $r['lessons_done'] ?></td>
                            <td>
                                <?php if ($r['state'] === 'active'): ?><span class="admin-chip admin-chip--success">Active</span>
                                <?php elseif ($r['state'] === 'inactive'): ?><span class="admin-chip admin-chip--warning">Inactive</span>
                                <?php else: ?><span class="admin-chip admin-chip--muted">Not enrolled</span><?php endif; ?>
                            </td>
                            <td class="admin-table__date"><?= $r['last_activity'] ? h(date('j M Y', (int) strtotime($r['last_activity']))) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages > 1): ?>
                <div class="admin-pager">
                    <?php if ($page > 1): ?><a class="admin-btn" href="<?= h(url($link(['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
                    <span class="admin-card__note">Page <?= $page ?> of <?= $pages ?></span>
                    <?php if ($page < $pages): ?><a class="admin-btn" href="<?= h(url($link(['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($report === 'emails'): ?>
            <div class="admin-actions" style="margin-bottom:16px"><a class="admin-btn" href="<?= h(url($link(['export' => 'csv']))) ?>">Download CSV</a></div>
            <section class="admin-stats" aria-label="Key numbers">
                <article class="admin-stat"><p class="admin-stat__label">Sent today</p><p class="admin-stat__value"><?= (int) $stats['today'] ?></p><p class="admin-stat__hint">Limit is <?= (int) $stats['limit'] ?> a day</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Left today</p><p class="admin-stat__value"><?= (int) $stats['remaining'] ?></p><p class="admin-stat__hint">Resets at midnight (Colombo)</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Last 30 days</p><p class="admin-stat__value"><?= (int) $stats['month'] ?></p><p class="admin-stat__hint">Emails sent</p></article>
                <article class="admin-stat"><p class="admin-stat__label">Busiest day</p><p class="admin-stat__value"><?= (int) $stats['busiest'] ?></p><p class="admin-stat__hint">In the last 30 days</p></article>
            </section>
            <section class="admin-card">
                <div class="admin-card__head"><h2>Emails per day</h2><span class="admin-card__note">Last 30 days</span></div>
                <ul class="admin-list">
                    <?php foreach ($days as $d): $used = pct($d['sent'], (int) $stats['limit']); ?>
                    <li class="admin-list__item admin-list__item--stack">
                        <span class="admin-list__row">
                            <span class="admin-list__main"><span class="admin-list__title"><?= h($d['date']->format('D, j M Y')) ?></span><span class="admin-list__sub"><?= $used ?>% of the daily limit</span></span>
                            <span class="admin-list__number"><?= (int) $d['sent'] ?></span>
                        </span>
                        <span class="admin-meter" aria-hidden="true"><span class="admin-meter__fill" style="--w:<?= min(100, $used) ?>"></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="admin-card__note">Counts only emails that were sent. Emails blocked by the limit are not recorded.</p>
            </section>
            <?php endif; ?>

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
