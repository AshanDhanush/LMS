<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Limitation (email): at most EMAIL_DAILY_LIMIT (100) emails per day.
|
| The counter is stored in the `notifications` table (no new table):
| one hidden row per day (type = 'email_quota') with the column `email_count`.
| The notification bell ignores these rows (see api/notifications_api.php).
|--------------------------------------------------------------------------
*/

if (!defined('EMAIL_DAILY_LIMIT')) {
    define('EMAIL_DAILY_LIMIT', 100);
}

function emailQuotaKey(): string
{
    return 'email_quota:' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo')))->format('Y-m-d');
}

/** notifications.user_id is required, so the counter row belongs to the oldest admin. */
function emailQuotaOwnerId(PDO $pdo): ?int
{
    $id = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Claim one of today's email slots. Returns false when 100 emails were already sent today.
 * The UPDATE is atomic, so two requests at the same moment cannot pass the limit.
 */
function reserveEmailSlot(PDO $pdo): bool
{
    $owner = emailQuotaOwnerId($pdo);
    if ($owner === null) {
        return true; // no admin account yet, nothing to count on
    }
    $key = emailQuotaKey();

    // Today's row is created by the first email of the day (already marked as read).
    $pdo->prepare(
        "INSERT IGNORE INTO notifications (user_id, type, title, dedupe_key, read_at, email_count)
         VALUES (:u, 'email_quota', 'Daily email counter', :k, NOW(), 0)"
    )->execute([':u' => $owner, ':k' => $key]);

    $stmt = $pdo->prepare(
        'UPDATE notifications SET email_count = email_count + 1
         WHERE user_id = :u AND dedupe_key = :k AND email_count < :max'
    );
    $stmt->execute([':u' => $owner, ':k' => $key, ':max' => EMAIL_DAILY_LIMIT]);

    return $stmt->rowCount() === 1;
}

/** Give the slot back when the email could not be sent. */
function releaseEmailSlot(PDO $pdo): void
{
    $owner = emailQuotaOwnerId($pdo);
    if ($owner === null) {
        return;
    }
    $pdo->prepare(
        'UPDATE notifications SET email_count = GREATEST(email_count, 1) - 1
         WHERE user_id = :u AND dedupe_key = :k'
    )->execute([':u' => $owner, ':k' => emailQuotaKey()]);
}

function emailsRemainingToday(PDO $pdo): int
{
    $owner = emailQuotaOwnerId($pdo);
    if ($owner === null) {
        return EMAIL_DAILY_LIMIT;
    }
    $stmt = $pdo->prepare('SELECT email_count FROM notifications WHERE user_id = :u AND dedupe_key = :k');
    $stmt->execute([':u' => $owner, ':k' => emailQuotaKey()]);
    return max(0, EMAIL_DAILY_LIMIT - (int) ($stmt->fetchColumn() ?: 0));
}
