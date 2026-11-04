<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Download an assignment file
|
|   ?kind=brief&id=ASSIGNMENT   the instructor's PDF/DOC (students enrolled in the course)
|   ?kind=mine&id=ASSIGNMENT    the signed-in student's own submitted file
|
| The files live in /uploads; this script checks who is asking before it
| sends them, and never accepts a path from the browser.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';

function saf_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    saf_fail(405, 'Method not allowed.');
}
if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
    saf_fail(403, 'This request is not allowed.');
}

$user = currentUser();
if ($user === null) {
    saf_fail(401, 'Please sign in.');
}
if (($user['role'] ?? '') !== 'student') {
    saf_fail(403, 'This download is for student accounts.');
}

$kind = (string) ($_GET['kind'] ?? '');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || !in_array($kind, ['brief', 'mine'], true)) {
    saf_fail(422, 'A valid file is required.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT a.id, a.file_path, a.file_name
     FROM assignments a
     JOIN courses c ON c.id = a.course_id AND c.status = 'published'
     JOIN enrollments e ON e.course_id = c.id AND e.user_id = :u
     WHERE a.id = :id LIMIT 1"
);
$stmt->execute([':u' => (int) $user['id'], ':id' => $id]);
$assignment = $stmt->fetch();
if (!$assignment) {
    saf_fail(404, 'File not found.');
}

if ($kind === 'brief') {
    $relative = (string) ($assignment['file_path'] ?? '');
    $downloadName = (string) ($assignment['file_name'] ?: basename($relative));
} else {
    $stmt = $pdo->prepare('SELECT file_path FROM submissions WHERE assignment_id = :a AND student_id = :s LIMIT 1');
    $stmt->execute([':a' => $id, ':s' => (int) $user['id']]);
    $relative = (string) ($stmt->fetchColumn() ?: '');
    $downloadName = preg_match('/^[0-9a-f]{16}_(.+)$/', basename($relative), $m) ? $m[1] : basename($relative);
}

// Only files that really sit inside /uploads are ever sent.
$root = realpath(dirname(__DIR__) . '/uploads');
$absolute = $relative !== '' ? realpath(dirname(__DIR__) . '/' . ltrim($relative, '/\\')) : false;
if (!$root || !$absolute || !is_file($absolute) || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
    saf_fail(404, 'File not found.');
}

$types = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
$safeName = preg_replace('/[\r\n"\\\\]+/', '_', $downloadName) ?: 'file.' . $extension;

header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . addcslashes($safeName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Content-Length: ' . (string) filesize($absolute));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($absolute);
}
exit;
