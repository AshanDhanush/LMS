<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

$action = $_GET['action'] ?? '';

if (in_array($action, ['login', 'register', 'check_reset_email', 'send_reset_otp', 'verify_reset_otp', 'reset_password'], true)) {
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
        send_json(400, ['status' => 'error', 'message' => 'The request is invalid.']);
    }

    return $input;
}

function valid_reset_email(array $input): string
{
    $email = trim(is_string($input['email'] ?? null) ? $input['email'] : '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        send_json(400, ['status' => 'error', 'message' => 'Enter a valid email address.']);
    }

    return $email;
}

function valid_reset_otp(array $input): string
{
    $otp = is_string($input['otp'] ?? null) ? $input['otp'] : '';
    if (preg_match('/\A[0-9]{6}\z/', $otp) !== 1) {
        send_json(400, ['status' => 'error', 'message' => 'Enter the six-digit code from your email.']);
    }

    return $otp;
}

function findResetToken(PDO $pdo, string $email, string $otp, bool $lock = false): ?array
{
    $sql = 'SELECT t.id, t.user_id
            FROM user_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE u.email = :email
              AND u.status = \'active\'
              AND t.type = \'reset\'
              AND t.token_hash = :token_hash
              AND t.used_at IS NULL
              AND t.expires_at > NOW()
            ORDER BY t.created_at DESC
            LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $findToken = $pdo->prepare($sql);
    $findToken->execute([
        'email' => $email,
        'token_hash' => hash('sha256', $otp),
    ]);
    $token = $findToken->fetch();

    return $token ?: null;
}

try {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    // config.php holds the JWT settings (JWT_SECRET, JWT_TTL, JWT_COOKIE) and the JWT functions
    // (jwtCreate, jwtVerify, setAuthCookie, currentUser). It also loads db.php.
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';

    ini_set('display_errors', '0');   // config.php shows errors on localhost; an API must only send JSON
} catch (Throwable $error) {
    error_log('Auth API startup failed: ' . $error->getMessage());
    send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
}

switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts sign-up requests.']);
        }

        try {
            $input = get_json_input();
            $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
            $email = trim(is_string($input['email'] ?? null) ? $input['email'] : '');
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            $passwordConfirmation = is_string($input['password_confirmation'] ?? null)
                ? $input['password_confirmation']
                : '';

            if (preg_match('/\A.{1,100}\z/us', $name) !== 1) {
                send_json(400, ['status' => 'error', 'message' => 'Enter your name (up to 100 characters).']);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                send_json(400, ['status' => 'error', 'message' => 'Enter a valid email address.']);
            }

            if (strlen($password) < 8 || strlen($password) > 72) {
                send_json(400, ['status' => 'error', 'message' => 'Your password must be between 8 and 72 characters.']);
            }

            if ($password !== $passwordConfirmation) {
                send_json(400, ['status' => 'error', 'message' => 'The passwords do not match.']);
            }

            $insertUser = db()->prepare(
                'INSERT INTO users (name, email, password_hash, role, status, email_verified_at)
                 VALUES (:name, :email, :password_hash, :role, :status, CURRENT_TIMESTAMP)'
            );
            $insertUser->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'student',
                'status' => 'active',
            ]);

            send_json(201, [
                'status' => 'success',
                'message' => 'Your account has been created. You can now sign in.',
                'redirect' => 'index.php',
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                send_json(409, ['status' => 'error', 'message' => 'An account with this email address already exists.']);
            }

            error_log('Auth API registration failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        } catch (Throwable $error) {
            error_log('Auth API registration failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

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

            $pdo = db();

            $resetExpiredLock = $pdo->prepare(
                'UPDATE users
                 SET failed_logins = 0, locked_until = NULL
                 WHERE email = :email AND locked_until IS NOT NULL AND locked_until <= NOW()'
            );
            $resetExpiredLock->execute(['email' => $email]);

            $findUser = $pdo->prepare(
                'SELECT id, name, email, password_hash, role, status, email_verified_at,
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

            $clearFailures = $pdo->prepare(
                'UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id'
            );
            $clearFailures->execute(['id' => $user['id']]);

            // currentUser() in config.php only accepts confirmed emails, so stop here with a clear message
            if ($user['email_verified_at'] === null) {
                send_json(403, ['status' => 'error', 'message' => 'Your email address is not confirmed yet.']);
            }

            // JWT login: creates the token and sends it as an HttpOnly cookie with this response.
            // The browser keeps it and sends it back on every request; no PHP session is used.
            setAuthCookie((int) $user['id'], (string) $user['role']);

            send_json(200, [
                'status' => 'success',
                'message' => 'Login successful.',
                'redirect' => url(dashboardPath((string) $user['role'])),   // admin, instructor or student dashboard
            ]);
        } catch (Throwable $error) {
            error_log('Auth API login failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    case 'check_reset_email':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts password recovery requests.']);
        }

        try {
            $email = valid_reset_email(get_json_input());
            $findUser = db()->prepare('SELECT id FROM users WHERE email = :email AND status = \'active\' LIMIT 1');
            $findUser->execute(['email' => $email]);

            if (!$findUser->fetch()) {
                send_json(404, ['status' => 'error', 'message' => 'No active account was found with this email address.']);
            }

            send_json(200, ['status' => 'success', 'message' => 'Email confirmed. You can now request a verification code.']);
        } catch (Throwable $error) {
            error_log('Auth API password recovery email check failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    case 'send_reset_otp':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts password recovery requests.']);
        }

        try {
            $email = valid_reset_email(get_json_input());
            $pdo = db();
            $findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email AND status = \'active\' LIMIT 1');
            $findUser->execute(['email' => $email]);
            $user = $findUser->fetch();

            if (!$user) {
                send_json(404, ['status' => 'error', 'message' => 'No active account was found with this email address.']);
            }

            $countRecent = $pdo->prepare(
                'SELECT COUNT(*) FROM user_tokens
                 WHERE user_id = :user_id AND type = \'reset\'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $countRecent->execute(['user_id' => $user['id']]);
            if ((int) $countRecent->fetchColumn() >= 3) {
                send_json(429, ['status' => 'error', 'message' => 'Too many verification codes were requested. Please try again later.']);
            }

            $otp = (string) random_int(100000, 999999);

            $pdo->beginTransaction();
            $invalidateTokens = $pdo->prepare(
                'UPDATE user_tokens SET used_at = NOW()
                 WHERE user_id = :user_id AND type = \'reset\' AND used_at IS NULL'
            );
            $invalidateTokens->execute(['user_id' => $user['id']]);

            $insertToken = $pdo->prepare(
                'INSERT INTO user_tokens (user_id, type, token_hash, expires_at)
                 VALUES (:user_id, \'reset\', :token_hash, DATE_ADD(NOW(), INTERVAL ' . (int) RESET_TOKEN_MINUTES . ' MINUTE))'
            );
            $insertToken->execute([
                'user_id' => $user['id'],
                'token_hash' => hash('sha256', $otp),
            ]);
            $tokenId = (int) $pdo->lastInsertId();
            $pdo->commit();

            $html = '<p>Use this code to reset your Learning Hub password:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>This code expires in ' . (int) RESET_TOKEN_MINUTES . ' minutes. If you did not request a password reset, you can ignore this email.</p>';

            if (!sendMail($email, 'Your Learning Hub password reset code', $html)) {
                $invalidateToken = $pdo->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = :id');
                $invalidateToken->execute(['id' => $tokenId]);
                send_json(502, ['status' => 'error', 'message' => 'We could not send the verification email. Please try again later.']);
            }

            send_json(200, ['status' => 'success', 'message' => 'A verification code has been sent to your email address.']);
        } catch (Throwable $error) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Auth API password recovery OTP send failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    case 'verify_reset_otp':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts password recovery requests.']);
        }

        try {
            $input = get_json_input();
            $email = valid_reset_email($input);
            $otp = valid_reset_otp($input);
            if (!findResetToken(db(), $email, $otp)) {
                send_json(400, ['status' => 'error', 'message' => 'That code is invalid or expired. Request a new code and try again.']);
            }

            send_json(200, ['status' => 'success', 'message' => 'Code verified. Choose a new password.']);
        } catch (Throwable $error) {
            error_log('Auth API password recovery OTP verification failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    case 'reset_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            send_json(405, ['status' => 'error', 'message' => 'This endpoint only accepts password recovery requests.']);
        }

        try {
            $input = get_json_input();
            $email = valid_reset_email($input);
            $otp = valid_reset_otp($input);
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            $passwordConfirmation = is_string($input['password_confirmation'] ?? null)
                ? $input['password_confirmation']
                : '';

            if (strlen($password) < 8 || strlen($password) > 72) {
                send_json(400, ['status' => 'error', 'message' => 'Your password must be between 8 and 72 characters.']);
            }
            if ($password !== $passwordConfirmation) {
                send_json(400, ['status' => 'error', 'message' => 'The passwords do not match.']);
            }

            $pdo = db();
            $pdo->beginTransaction();
            $token = findResetToken($pdo, $email, $otp, true);
            if (!$token) {
                $pdo->rollBack();
                send_json(400, ['status' => 'error', 'message' => 'That code is invalid or expired. Request a new code and try again.']);
            }

            $updatePassword = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
            $updatePassword->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => $token['user_id'],
            ]);

            $useToken = $pdo->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
            $useToken->execute(['id' => $token['id']]);
            $invalidateOtherTokens = $pdo->prepare(
                'UPDATE user_tokens SET used_at = NOW()
                 WHERE user_id = :user_id AND type = \'reset\' AND used_at IS NULL'
            );
            $invalidateOtherTokens->execute(['user_id' => $token['user_id']]);
            $pdo->commit();

            send_json(200, ['status' => 'success', 'message' => 'Your password has been updated. You can now sign in.']);
        } catch (Throwable $error) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Auth API password reset failed: ' . $error->getMessage());
            send_json(500, ['status' => 'error', 'message' => 'Something went wrong on our side. Please try again.']);
        }

    default:
        send_json(404, ['status' => 'error', 'message' => 'Unknown authentication action.']);
}