<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notifications for the bell (admin, instructor and student)
|
|   GET  [?remind=1]  -> { unread, items: [ ... latest 12 ... ] }
|   POST action=read      (id)  -> marks one notification read
|   POST action=read_all        -> marks all of them read
|
| Everyone only ever sees and changes their own notifications. A POST must
| carry the header "X-Requested-With: fetch" (a page on another site cannot
| add that header), and must come from this site.
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';

const NOTIFICATIONS_PAGE_SIZE = 12;

function notifications_json(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(static function (Throwable $error): void {
    error_log('Notifications API: ' . $error->getMessage());
    notifications_json(500, ['status' => 'error', 'message' => 'Notifications could not be loaded.']);
});

if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
    notifications_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $requestHost = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!is_string($originHost) || strcasecmp($originHost, (string) $requestHost) !== 0) {
        notifications_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }
}

$user = currentUser();
if ($user === null) {
    notifications_json(401, ['status' => 'error', 'message' => 'Please sign in again.']);
}
$userId = (int) $user['id'];
$pdo = db();

function notifications_unread(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL');
    $stmt->execute(['u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'fetch') {
        notifications_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            notifications_json(422, ['status' => 'error', 'message' => 'A valid notification is required.']);
        }
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :u AND read_at IS NULL')
            ->execute(['id' => $id, 'u' => $userId]);
    } elseif ($action === 'read_all') {
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL')
            ->execute(['u' => $userId]);
    } else {
        notifications_json(422, ['status' => 'error', 'message' => 'Unknown action.']);
    }
    notifications_json(200, ['status' => 'ok', 'unread' => notifications_unread($pdo, $userId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    notifications_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

/* ------------------------------------------------------------------- GET */
if (($user['role'] ?? '') === 'student' && ($_GET['remind'] ?? '') === '1') {
    notificationsDueSoon($pdo, $userId);
}

// Keep the table small: old notifications that were already read are removed.
$pdo->prepare('DELETE FROM notifications WHERE user_id = :u AND read_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)')
    ->execute(['u' => $userId]);

$stmt = $pdo->prepare(
    'SELECT id, type, title, body, link, read_at, created_at FROM notifications
     WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT ' . NOTIFICATIONS_PAGE_SIZE
);
$stmt->execute(['u' => $userId]);

$items = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $items[] = [
        'id'      => (int) $row['id'],
        'type'    => (string) $row['type'],
        'title'   => (string) $row['title'],
        'body'    => $row['body'] !== null ? (string) $row['body'] : null,
        'url'     => $row['link'] !== null ? url((string) $row['link']) : null,
        'created' => str_replace(' ', 'T', (string) $row['created_at']),
        'read'    => $row['read_at'] !== null,
    ];
}

notifications_json(200, ['status' => 'ok', 'unread' => notifications_unread($pdo, $userId), 'items' => $items]);
