<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

$action = $_GET['action'] ?? '';

if ($action === 'login') {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

function send_json(int $statusCode, array $body): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function get_json_input(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        send_json(415, ['status' => 'error', 'message' => 'The sign-in request must use JSON.']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        send_json(400, ['status' => 'error', 'message' => 'The sign-in request is invalid.']);
    }

    return $input;
}

try {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $error) {
    error_log('Auth API startup failed: ' . $error->getMessage());
    send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
}

switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts sign-in requests.']);
        }

        try {
            $input = get_json_input();
            $email = trim(is_string($input['email'] ?? null) ? $input['email'] : '');
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $password === '' || strlen($password) > 4096) {
                send_json(400, ['status' => 'error', 'message' => 'Enter a valid email address and password.']);
            }

            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            session_start();

            $pdo = db();

            $resetExpiredLock = $pdo->prepare(
                'UPDATE users
                 SET failed_logins = 0, locked_until = NULL
                 WHERE email = :email AND locked_until IS NOT NULL AND locked_until <= NOW()'
            );
            $resetExpiredLock->execute(['email' => $email]);

            $findUser = $pdo->prepare(
                'SELECT id, name, email, password_hash, role, status,
                        (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $findUser->execute(['email' => $email]);
            $user = $findUser->fetch();

            if (!$user || $user['status'] !== 'active') {
                send_json(401, ['status' => 'error', 'message' => 'Email or password is incorrect.']);
            }

            if ((int) $user['is_locked'] === 1) {
                send_json(423, ['status' => 'error', 'message' => 'Too many sign-in attempts. Please try again in 15 minutes.']);
            }

            if (!password_verify($password, $user['password_hash'])) {
                $recordFailure = $pdo->prepare(
                    'UPDATE users
                     SET locked_until = IF(failed_logins >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until),
                         failed_logins = LEAST(failed_logins + 1, 5)
                     WHERE id = :id'
                );
                $recordFailure->execute(['id' => $user['id']]);
                send_json(401, ['status' => 'error', 'message' => 'Email or password is incorrect.']);
            }

            if (session_regenerate_id(true) === false) {
                throw new RuntimeException('Unable to regenerate the login session ID.');
            }

            $clearFailures = $pdo->prepare(
                'UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id'
            );
            $clearFailures->execute(['id' => $user['id']]);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            send_json(200, [
                'status' => 'success',
                'message' => 'Login successful.',
                'redirect' => '../dashboard.php',
            ]);
        } catch (Throwable $error) {
            error_log('Auth API login failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    default:
        send_json(404, ['status' => 'error', 'message' => 'Unknown authentication action.']);
}
