<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Download a student's submitted file (instructors only)
|
|   ?id=SUBMISSION
|
| Only the instructor who owns the course can download it. The file lives in
| /uploads; no path is ever taken from the browser.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';

function isf_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    isf_fail(405, 'Method not allowed.');
}
if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
    isf_fail(403, 'This request is not allowed.');
}

$user = currentUser();
if ($user === null) {
    isf_fail(401, 'Please sign in.');
}
if (($user['role'] ?? '') !== 'instructor') {
    isf_fail(403, 'This download is for instructors.');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    isf_fail(422, 'A valid submission is required.');
}

$stmt = db()->prepare(
    'SELECT s.file_path
     FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     JOIN courses c ON c.id = a.course_id
     WHERE s.id = :id AND c.instructor_id = :instructor LIMIT 1'
);
$stmt->execute([':id' => $id, ':instructor' => (int) $user['id']]);
$relative = (string) ($stmt->fetchColumn() ?: '');

$root = realpath(dirname(__DIR__) . '/uploads');
$absolute = $relative !== '' ? realpath(dirname(__DIR__) . '/' . ltrim($relative, '/\\')) : false;
if (!$root || !$absolute || !is_file($absolute) || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
    isf_fail(404, 'File not found.');
}

$types = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
$name = preg_match('/^[0-9a-f]{16}_(.+)$/', basename($relative), $m) ? $m[1] : basename($relative);
$safeName = preg_replace('/[\r\n"\\\\]+/', '_', $name) ?: 'submission.' . $extension;

header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . addcslashes($safeName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Content-Length: ' . (string) filesize($absolute));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($absolute);
}
exit;
