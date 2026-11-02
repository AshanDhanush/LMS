<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared helpers for the student course pages and their APIs
| (course detail: api/student_course_api.php, lesson: api/student_lesson_api.php)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';

function sc_json(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Same-site requests only. */
function sc_check_origin(): void
{
    $deny = static fn () => sc_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
        $deny();
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!is_string($originHost) || strcasecmp($originHost, (string) $requestHost) !== 0) {
            $deny();
        }
    }
}

/** Start an API request: JSON-only errors, same-site, signed-in student. Returns [PDO, user]. */
function sc_api_start(): array
{
    ini_set('display_errors', '0');
    set_exception_handler(static function (Throwable $error): void {
        error_log('Student course API: ' . $error->getMessage());
        sc_json(500, ['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
    });
    sc_check_origin();
    $user = currentUser();
    if ($user === null) {
        sc_json(401, ['status' => 'error', 'message' => 'Please sign in again.']);
    }
    if (($user['role'] ?? '') !== 'student') {
        sc_json(403, ['status' => 'error', 'message' => 'These pages are for student accounts.']);
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return [db(), $user];
}

/** One CSRF token shared by the student pages (also used by the catalog's Enrol button). */
function sc_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['student_catalog_csrf'])) {
        $_SESSION['student_catalog_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['student_catalog_csrf'];
}

function sc_require_csrf(): void
{
    $token = $_SESSION['student_catalog_csrf'] ?? '';
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($posted) || !hash_equals($token, $posted)) {
        sc_json(403, ['status' => 'error', 'message' => 'Your session could not be verified. Reload the page and try again.']);
    }
}

function sc_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8')
        . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1, 'UTF-8') : '');
    return mb_strtoupper($letters !== '' ? $letters : '?', 'UTF-8');
}

function sc_iso(?string $mysqlDate): ?string
{
    return $mysqlDate ? str_replace(' ', 'T', $mysqlDate) : null;
}

function sc_thumb(?string $path): ?string
{
    return is_string($path) && str_starts_with($path, 'uploads/thumbnails/') ? url($path) : null;
}

/** A stored file path is served only when it lives in uploads/lessons/ and has no "..". */
function sc_lesson_file(?string $path): ?string
{
    if (!is_string($path) || !str_starts_with($path, 'uploads/lessons/') || str_contains($path, '..')) {
        return null;
    }
    return url($path);
}

/** Turn a video link into something safe to show: a YouTube/Vimeo embed, a direct video file, or a plain link. */
function sc_video(?string $link): ?array
{
    if (!is_string($link) || $link === '' || !preg_match('#^https?://#i', $link)) {
        return null;
    }
    $host = strtolower((string) parse_url($link, PHP_URL_HOST));
    $path = (string) parse_url($link, PHP_URL_PATH);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $youtube = null;
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $youtube = trim($path, '/');
    } elseif (str_ends_with($host, 'youtube.com')) {
        $youtube = $query['v'] ?? (preg_match('#^/(?:embed|shorts|live)/([\w-]{11})#', $path, $m) ? $m[1] : null);
    }
    if (is_string($youtube) && preg_match('/^[\w-]{11}$/', $youtube)) {
        return ['kind' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/' . $youtube, 'link' => $link];
    }
    if (str_ends_with($host, 'vimeo.com') && preg_match('#/(\d{5,})#', $path, $m)) {
        return ['kind' => 'iframe', 'url' => 'https://player.vimeo.com/video/' . $m[1], 'link' => $link];
    }
    if (preg_match('/\.(mp4|webm|ogg)$/i', $path)) {
        return ['kind' => 'video', 'url' => $link, 'link' => $link];
    }
    return ['kind' => 'link', 'url' => $link, 'link' => $link];
}

/**
 * Lessons a student can see (a lesson in a draft module is hidden), grouped by module,
 * in teaching order, with the student's completion state.
 *
 * @return array{modules:array<int,array>,flat:array<int,array>,done:int,total:int}
 */
function sc_outline(PDO $pdo, int $courseId, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id, l.title, l.type, l.pdf_path, l.file_path, l.powerpoint_path, l.video_url,
                m.id AS module_id, m.title AS module_title, m.description AS module_description,
                (SELECT lp.completed_at FROM lesson_progress lp WHERE lp.lesson_id = l.id AND lp.user_id = :u LIMIT 1) AS done_at
         FROM lessons l
         LEFT JOIN modules m ON m.id = l.module_id
         WHERE l.course_id = :c AND (l.module_id IS NULL OR m.status = 'published')
         ORDER BY COALESCE(m.module_order, 0) ASC, l.position ASC, l.id ASC"
    );
    $stmt->execute([':u' => $userId, ':c' => $courseId]);

    $modules = [];
    $flat = [];
    $done = 0;
    foreach ($stmt->fetchAll() as $i => $row) {
        $key = (int) ($row['module_id'] ?? 0);
        if (!isset($modules[$key])) {
            $modules[$key] = [
                'id'          => $key ?: null,
                'title'       => $row['module_title'] ?? 'Lessons',
                'description' => $row['module_description'] ?? null,
                'lessons'     => [],
            ];
        }
        $type = $row['video_url'] ? 'video' : ($row['powerpoint_path'] ? 'powerpoint' : ($row['pdf_path'] || $row['file_path'] ? 'pdf' : (string) $row['type']));
        $lesson = [
            'id'    => (int) $row['id'],
            'index' => $i + 1,
            'title' => (string) $row['title'],
            'type'  => $type,
            'done'  => $row['done_at'] !== null,
            'module_id' => $key ?: null,
        ];
        $done += $lesson['done'] ? 1 : 0;
        $modules[$key]['lessons'][] = $lesson;
        $flat[] = $lesson;
    }
    return ['modules' => array_values($modules), 'flat' => $flat, 'done' => $done, 'total' => count($flat)];
}

/** The lesson to resume: the first one not yet done, else the first. */
function sc_next_lesson(array $flat): ?array
{
    foreach ($flat as $lesson) {
        if (!$lesson['done']) {
            return $lesson;
        }
    }
    return $flat[0] ?? null;
}

function sc_percent(int $done, int $total): int
{
    return $total > 0 ? (int) round($done * 100 / $total) : 0;
}

/** The student's enrolment row for a published course, or null. */
function sc_enrollment(PDO $pdo, int $courseId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE course_id = :c AND user_id = :u LIMIT 1');
    $stmt->execute([':c' => $courseId, ':u' => $userId]);
    return $stmt->fetch() ?: null;
}
