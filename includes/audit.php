<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit log: who changed or deleted what.
|
|   auditLog($pdo, $adminUser, 'user.delete', 'user', 12, 'Deleted user Jane', ['email' => '...']);
|
| Never throws. If the audit_logs table does not exist yet (migration 001 not run),
| the page keeps working and nothing is recorded.
|--------------------------------------------------------------------------
*/

function auditLog(PDO $pdo, ?array $actor, string $action, string $entityType, ?int $entityId, string $summary, array $details = []): void
{
    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_id, actor_name, actor_role, action, entity_type, entity_id, summary, details, ip_address)
             VALUES (:actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id, :summary, :details, :ip)'
        )->execute([
            ':actor_id'    => $actor['id'] ?? null,
            ':actor_name'  => mb_substr((string) ($actor['name'] ?? 'System'), 0, 100),
            ':actor_role'  => (string) ($actor['role'] ?? ''),
            ':action'      => mb_substr($action, 0, 60),
            ':entity_type' => mb_substr($entityType, 0, 30),
            ':entity_id'   => $entityId,
            ':summary'     => mb_substr($summary, 0, 255),
            ':details'     => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip'          => isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ]);
    } catch (Throwable $error) {
        error_log('Audit log failed: ' . $error->getMessage());
    }
}

/** True when migration 001 has been run (the "pending" course status exists). */
function courseReviewReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $type = (string) ($pdo->query("SHOW COLUMNS FROM courses LIKE 'status'")->fetch()['Type'] ?? '');
            $ready = str_contains($type, "'pending'");
        } catch (Throwable $error) {
            $ready = false;
        }
    }
    return $ready;
}

function auditTableReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM audit_logs LIMIT 1');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}
