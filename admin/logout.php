<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

setcookie(JWT_COOKIE, '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => !IS_LOCAL,
    'httponly' => true,
    'samesite' => 'Lax',
]);

redirect('');
