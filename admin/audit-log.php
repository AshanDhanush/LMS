<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';

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

function auditDate(mixed $value): ?string
{
    $value = trim((string) $value);
    $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : null;
}

const AUDIT_PER_PAGE = 30;
const AUDIT_ENTITIES = ['user' => 'Users', 'course' => 'Courses', 'category' => 'Categories'];

$loadError = false;
$missing   = false;
$rows      = [];
$total     = 0;
$entity    = in_array($_GET['entity'] ?? '', array_keys(AUDIT_ENTITIES), true) ? (string) $_GET['entity'] : '';
$actor     = mb_substr(trim((string) ($_GET['actor'] ?? '')), 0, 100);
$from      = auditDate($_GET['from'] ?? '');
$to        = auditDate($_GET['to'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$exporting = ($_GET['export'] ?? '') === 'csv';

try {
    $pdo = db();
    if (!auditTableReady($pdo)) {
        $missing = true;
    } else {
        $where  = [];
        $params = [];
        if ($entity !== '') {
            $where[] = 'entity_type = :entity';
            $params[':entity'] = $entity;
        }
        if ($actor !== '') {
            $where[] = 'actor_name LIKE :actor';
            $params[':actor'] = '%' . addcslashes($actor, '%_\\') . '%';
        }
        if ($from !== null) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'created_at < :to';
            $params[':to'] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $sql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / AUDIT_PER_PAGE));
        $page  = min($page, $pages);

        $query = 'SELECT id, actor_name, actor_role, action, entity_type, entity_id, summary, details, ip_address, created_at
                  FROM audit_logs' . $sql . ' ORDER BY id DESC';
        if (!$exporting) {
            $query .= ' LIMIT ' . AUDIT_PER_PAGE . ' OFFSET ' . (($page - 1) * AUDIT_PER_PAGE);
        } else {
            $query .= ' LIMIT 5000';
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $error) {
    error_log('Admin audit log failed to load: ' . $error->getMessage());
    $loadError = true;
}

/* ---------------- CSV download ---------------- */
if ($exporting && !$loadError && !$missing) {
    $safe = static function ($cell): string {
        $cell = (string) $cell;
        return ($cell !== '' && strpos("=+-@\t\r", $cell[0]) !== false) ? "'" . $cell : $cell;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['When', 'Who', 'Role', 'Action', 'Item', 'Item ID', 'What happened', 'Details', 'IP address']);
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, [$r['created_at'], $r['actor_name'], $r['actor_role'], $r['action'], $r['entity_type'], $r['entity_id'] ?? '', $r['summary'], $r['details'] ?? '', $r['ip_address'] ?? '']));
    }
    fclose($out);
    exit;
}

$link = static function (array $extra = []) use ($entity, $actor, $from, $to): string {
    $query = array_merge(['entity' => $entity, 'actor' => $actor, 'from' => $from, 'to' => $to], $extra);
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    return 'admin/audit-log' . ($query ? '?' . http_build_query($query) : '');
};

/** Chip colour for an action such as "user.delete". */
$tone = static function (string $action): string {
    $verb = substr(strrchr('.' . $action, '.'), 1);
    return match (true) {
        str_contains($verb, 'delete'), str_contains($verb, 'reject') => 'danger',
        str_contains($verb, 'approve'), str_contains($verb, 'create'), str_contains($verb, 'import'), str_contains($verb, 'publish') => 'success',
        default => 'info',
    };
};

$adminPageTitle  = 'Audit log | Learning Hub Admin';
$adminActivePage = 'audit';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero">
                    <h1>Audit log</h1>
                    <p>A record of who changed or deleted what. Entries cannot be edited from the site.</p>
                </section>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The audit log could not be loaded. Please try again shortly.</p>
                <?php elseif ($missing): ?>
                    <p class="admin-alert" role="alert">The audit log table does not exist yet. Run <strong>database/migrations/001_admin_tools.sql</strong> once in phpMyAdmin (SQL tab), then reload this page.</p>
                <?php else: ?>

                <form class="admin-filter-form admin-filter-form--labeled" method="get" action="<?= h(url('admin/audit-log')) ?>">
                    <label>Item
                        <select name="entity">
                            <option value="">Everything</option>
                            <?php foreach (AUDIT_ENTITIES as $key => $label): ?>
                                <option value="<?= h($key) ?>"<?= $entity === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Who<input type="search" name="actor" value="<?= h($actor) ?>" placeholder="Admin name"></label>
                    <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
                    <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
                    <div class="admin-filter-form__actions">
                        <button class="admin-btn" type="submit">Apply</button>
                        <a class="admin-btn" href="<?= h(url($link(['export' => 'csv']))) ?>">Download CSV</a>
                    </div>
                </form>

                <section class="admin-card" aria-labelledby="audit-title">
                    <div class="admin-card__head">
                        <h2 id="audit-title">Activity</h2>
                        <span class="admin-card__note"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>, newest first</span>
                    </div>
                    <?php if (!$rows): ?>
                        <p class="admin-empty">Nothing has been recorded<?= ($entity !== '' || $actor !== '' || $from || $to) ? ' for these filters' : ' yet' ?>.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Who</th><th scope="col">What happened</th><th scope="col">Action</th><th scope="col">When</th></tr></thead>
                                <tbody>
                                <?php foreach ($rows as $r): $details = $r['details'] ? json_decode((string) $r['details'], true) : null; ?>
                                    <tr>
                                        <td>
                                            <span class="admin-person">
                                                <span class="admin-person__avatar" aria-hidden="true"><?= h(initials($r['actor_name'])) ?></span>
                                                <span class="admin-person__text">
                                                    <span class="admin-person__name"><?= h($r['actor_name']) ?></span>
                                                    <span class="admin-person__email"><?= h(ucfirst($r['actor_role'])) ?><?= $r['ip_address'] ? ' · ' . h($r['ip_address']) : '' ?></span>
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <?= h($r['summary']) ?>
                                            <?php if (is_array($details) && $details): ?>
                                                <details>
                                                    <summary class="admin-person__email">Details</summary>
                                                    <ul class="admin-list">
                                                    <?php foreach ($details as $key => $value): ?>
                                                        <li class="admin-list__item"><span class="admin-list__sub"><?= h(str_replace('_', ' ', (string) $key)) ?>: <?= h(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></span></li>
                                                    <?php endforeach; ?>
                                                    </ul>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="admin-chip admin-chip--<?= h($tone($r['action'])) ?>"><?= h($r['action']) ?></span></td>
                                        <td class="admin-table__date"><?= h(date('j M Y, H:i', (int) strtotime($r['created_at']))) ?></td>
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
            </div>
        </main>
    </div>

    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>
