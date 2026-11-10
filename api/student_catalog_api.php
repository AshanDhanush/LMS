<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student course catalog
|
|   GET  api/student_catalog_api.php?q=&category=&sort=&rating=&lessons=&page=
|        -> published courses, categories and paging as JSON
|   POST api/student_catalog_api.php  (action=enroll, course_id, csrf_token)
|        -> enrols the signed-in student in a published course
|
| Only signed-in students may use it. Same-site requests only.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';

const CATALOG_PER_PAGE = 9;

function catalog_json(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function catalog_validate_origin(): void
{
    if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
        catalog_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!is_string($originHost) || strcasecmp($originHost, (string) $requestHost) !== 0) {
            catalog_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
        }
    }
}

/** Does courses.level exist? (optional column, see database/add_course_level.sql) */
function catalog_has_level(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'level'"
    );
    return (int) $stmt->fetchColumn() > 0;
}

// Never let a PHP warning or exception turn the response into HTML.
ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    error_log('Catalog API: ' . $error->getMessage());
    catalog_json(500, ['status' => 'error', 'message' => 'The catalog could not be loaded. Please try again.']);
});

catalog_validate_origin();

$user = currentUser();
if ($user === null) {
    catalog_json(401, ['status' => 'error', 'message' => 'Please sign in.']);
}
if (($user['role'] ?? '') !== 'student') {
    catalog_json(403, ['status' => 'error', 'message' => 'Only students can use the course catalog.']);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$userId = (int) $user['id'];

/* ---------------------------------------------------------------- Enrol */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SESSION['student_catalog_csrf'] ?? '';
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($posted) || !hash_equals($token, $posted)) {
        catalog_json(403, ['status' => 'error', 'message' => 'Your session could not be verified. Reload the page and try again.']);
    }

    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($_POST['action'] ?? '') !== 'enroll' || $courseId === false) {
        catalog_json(422, ['status' => 'error', 'message' => 'A valid course is required.']);
    }

    $stmt = $pdo->prepare("SELECT id, instructor_id, title FROM courses WHERE id = :id AND status = 'published' LIMIT 1");
    $stmt->execute([':id' => $courseId]);
    $enrolCourse = $stmt->fetch();
    if (!$enrolCourse) {
        catalog_json(404, ['status' => 'error', 'message' => 'That course is not available.']);
    }

    // The unique key (user_id, course_id) makes this safe to repeat.
    $stmt = $pdo->prepare('INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (:user, :course)');
    $stmt->execute([':user' => $userId, ':course' => $courseId]);

    // First time only: tell the instructor a new student joined (never blocks enrolling).
    if ($stmt->rowCount() === 1) {
        notify(
            $pdo, (int) $enrolCourse['instructor_id'], 'enrollment',
            (string) $user['name'] . ' enrolled in ' . (string) $enrolCourse['title'],
            null, 'instructor/students/students/' . $courseId
        );
    }

    catalog_json(200, [
        'status'   => 'ok',
        'message'  => 'You are enrolled.',
        'redirect' => url('course/' . $courseId),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    catalog_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

/* ----------------------------------------------------------------- List */
$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 100, 'UTF-8');
$categoryId = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$minRating = (float) ($_GET['rating'] ?? 0);
$minRating = in_array($minRating, [4.0, 4.5], true) ? $minRating : 0.0;
$lessonBand = (string) ($_GET['lessons'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'recommended');
$level = (string) ($_GET['level'] ?? '');

$hasLevel = catalog_has_level($pdo);

$where = ["c.status = 'published'"];
$params = [];

if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(c.title LIKE :q1 OR c.description LIKE :q2 OR u.name LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $like;
}
if ($categoryId !== false) {
    $where[] = 'c.category_id = :cat';
    $params[':cat'] = $categoryId;
}
if ($hasLevel && in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
    $where[] = 'c.level = :level';
    $params[':level'] = $level;
}

$having = [];
if ($minRating > 0) {
    $having[] = 'rating_avg >= ' . $minRating;
}
$bands = ['1-5' => [1, 5], '6-10' => [6, 10], '11+' => [11, 100000]];
if (isset($bands[$lessonBand])) {
    $having[] = 'lessons_total BETWEEN ' . $bands[$lessonBand][0] . ' AND ' . $bands[$lessonBand][1];
}

$orderBy = [
    'newest'  => 'c.created_at DESC, c.id DESC',
    'rating'  => 'rating_avg DESC, rating_count DESC, c.title ASC',
    'popular' => 'students DESC, c.title ASC',
    'title'   => 'c.title ASC',
    // Recommended: well rated and well attended first.
    'recommended' => 'rating_avg DESC, students DESC, c.created_at DESC',
][$sort] ?? 'c.created_at DESC';

$levelSql = $hasLevel ? 'c.level' : 'NULL';

$base = "FROM courses c
         JOIN users u ON u.id = c.instructor_id
         LEFT JOIN categories cat ON cat.id = c.category_id
         WHERE " . implode(' AND ', $where);

$select = "SELECT c.id, c.title, c.thumbnail, c.created_at, cat.name AS category, u.name AS instructor,
                  $levelSql AS level,
                  (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons_total,
                  (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
                  COALESCE((SELECT AVG(e.rating) FROM enrollments e WHERE e.course_id = c.id AND e.rating IS NOT NULL), 0) AS rating_avg,
                  (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.rating IS NOT NULL) AS rating_count,
                  EXISTS(SELECT 1 FROM enrollments e WHERE e.course_id = c.id AND e.user_id = :me) AS enrolled
           $base";

// Wrap so HAVING-style filters on computed columns work on every MySQL/MariaDB.
$filter = $having ? ' WHERE ' . implode(' AND ', $having) : '';

$params[':me'] = $userId;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ($select) t" . $filter);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$pages = max(1, (int) ceil($total / CATALOG_PER_PAGE));
$page = min($page, $pages);
$offset = ($page - 1) * CATALOG_PER_PAGE;

$orderBy = preg_replace('/\bc\.(created_at|id|title)\b/', 't.$1', $orderBy);
$stmt = $pdo->prepare(
    "SELECT * FROM ($select) t" . $filter . ' ORDER BY ' . $orderBy
    . ' LIMIT ' . CATALOG_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);

$courses = [];
foreach ($stmt->fetchAll() as $row) {
    $thumb = $row['thumbnail'];
    $courses[] = [
        'id'            => (int) $row['id'],
        'title'         => (string) $row['title'],
        'category'      => $row['category'] !== null ? (string) $row['category'] : null,
        'instructor'    => (string) $row['instructor'],
        'level'         => $row['level'] !== null ? (string) $row['level'] : null,
        'lessons'       => (int) $row['lessons_total'],
        'students'      => (int) $row['students'],
        'rating'        => $row['rating_count'] > 0 ? round((float) $row['rating_avg'], 1) : null,
        'rating_count'  => (int) $row['rating_count'],
        'enrolled'      => (bool) (int) $row['enrolled'],
        'thumbnail_url' => is_string($thumb) && str_starts_with($thumb, 'uploads/thumbnails/') ? url($thumb) : null,
        'url'           => url('course/' . (int) $row['id']),
    ];
}

$cats = $pdo->query(
    "SELECT cat.id, cat.name, COUNT(c.id) AS courses
     FROM categories cat
     JOIN courses c ON c.category_id = cat.id AND c.status = 'published'
     GROUP BY cat.id, cat.name ORDER BY cat.name"
)->fetchAll();

$allCount = (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'published'")->fetchColumn();

catalog_json(200, [
    'status'     => 'ok',
    'courses'    => $courses,
    'categories' => array_map(static fn (array $r): array => [
        'id' => (int) $r['id'], 'name' => (string) $r['name'], 'courses' => (int) $r['courses'],
    ], $cats),
    'has_level'  => $hasLevel,
    'all_count'  => $allCount,
    'total'      => $total,
    'page'       => $page,
    'pages'      => $pages,
    'per_page'   => CATALOG_PER_PAGE,
]);
