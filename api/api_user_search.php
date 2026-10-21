<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();

if ($user === null || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['users' => []]);
    exit;
}

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$q = mb_substr($q, 0, 100, 'UTF-8');

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, name, email, role
         FROM users
         WHERE name LIKE :name
            OR email LIKE :email
         ORDER BY name ASC
         LIMIT 8'
    );

    $term = '%' . $q . '%';

    $stmt->execute([
        'name' => $term,
        'email' => $term,
    ]);

    echo json_encode(
        ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $error) {
    error_log('User search failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['users' => []]);
}