<?php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME','lms_db');
define('DB_USER', 'root');
define('DB_PASS', 'madumali@123');

define('IS_LOCAL', true);


/* ==========================================================================
   JWT login settings
   ========================================================================== */

// Make the secret long and random, for example:  php -r "echo bin2hex(random_bytes(32));"
// Change it before the site goes live.
define('JWT_SECRET', 'CHANGE_ME_use_64_random_hex_characters_before_going_live');
define('JWT_TTL', 60 * 60 * 24 * 30);          // seconds the login stays valid (30 days)
define('JWT_COOKIE', 'lms_token');              // name of the HttpOnly cookie

/* ==========================================================================
   Email settings (used by "Forgot password")
   ========================================================================== */

// The address people type to reach the site, WITHOUT the folder (BASE_URL adds that).
// Fixed on purpose: email links must never be built from the visitor's request.
define('SITE_URL', 'http://localhost');

define('RESET_TOKEN_MINUTES', 60);              // how long a reset link works
define('RESET_LINK_PATH', 'reset-password.php');// page the email link opens: SITE_URL/BASE_URL/<this>?token=...

// While SMTP_HOST is empty and IS_LOCAL is true, no email is sent: it is written to logs/mail.log
// so you can copy the link and test. Fill these in to send real email (needs PHPMailer in /vendor, see vendor/PHPMailer/).
define('SMTP_HOST', 'smtp.gmail.com');                        // e.g. 'smtp.gmail.com'
define('SMTP_PORT', 587);                       // 587 with 'tls', 465 with 'ssl'
define('SMTP_SECURE', 'tls');                   // 'tls' (port 587), 'ssl' (port 465), or '' for local test tools such as Mailpit
define('SMTP_USER', 'adhanushka57@gmail.com');
define('SMTP_PASS', 'gneafzghfofwbdxl');
define('MAIL_FROM', 'adhanushka57@gmail.com');
define('MAIL_FROM_NAME', 'Learning Hub');

if (!IS_LOCAL && (strlen(JWT_SECRET) < 32 || str_starts_with(JWT_SECRET, 'CHANGE_ME'))) {
    http_response_code(500);
    exit('Server is not configured: set a strong JWT_SECRET in includes/config.php.');
}

/*
 * Site folder in the address bar: '' when the site opens at http://localhost/
 * and '/lms' when it opens at http://localhost/lms/. It is detected automatically.
 * Only if the detection is wrong, add this line ABOVE this block:  define('BASE_URL', '/lms');
 */
if (!defined('BASE_URL')) {
    $projectRoot = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $documentRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = ($documentRoot !== '' && stripos($projectRoot, $documentRoot) === 0)
        ? substr($projectRoot, strlen($documentRoot))
        : '';
    define('BASE_URL', rtrim($base, '/'));
    unset($projectRoot, $documentRoot, $base);
}


/* ==========================================================================
   Helpers for the login
   ========================================================================== */

/** Site address of a path: url('admin/dashboard.php') */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Redirect to a path on this site and stop. */
function redirect(string $path, int $code = 302): void
{
    header('Location: ' . url($path), true, $code);
    exit;
}

/**
 * Where each role lands after login. Returns a path without the site folder:
 * use it as redirect(dashboardPath($role)) or url(dashboardPath($role)).
 */
function dashboardPath(string $role): string
{
    return match ($role) {
        'admin'      => 'admin/dashboard.php',
        'instructor' => 'instructor/dashboard.php',
        default      => 'dashboard.php',           // student (and any other role)
    };
}

function b64urlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64urlDecode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

/** Create a signed token (HS256). $claims example: ['sub' => 12, 'role' => 'student'] */
function jwtCreate(array $claims, int $ttl = JWT_TTL): string
{
    $header  = b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = b64urlEncode(json_encode($claims + ['iat' => time(), 'exp' => time() + $ttl]));
    $sig     = b64urlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

/** Check signature, algorithm and expiry. Returns the claims or null. */
function jwtVerify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;

    $header = json_decode(b64urlDecode($h), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') return null;   // rejects alg "none"

    $expected = b64urlEncode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;

    $payload = json_decode(b64urlDecode($p), true);
    if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

/** Called after a successful login: puts the token in an HttpOnly cookie. */
function setAuthCookie(int $userId, string $role): void
{
    setcookie(JWT_COOKIE, jwtCreate(['sub' => $userId, 'role' => $role]), [
        'expires'  => time() + JWT_TTL,
        'path'     => '/',
        'secure'   => !IS_LOCAL,                   // needs HTTPS on the live server
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * The logged-in user, or null. Role and status come from the DATABASE on every request,
 * so deactivating a user or changing a role takes effect at once.
 * Returns: id, name, email, role
 */
function currentUser(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;

    $token   = $_COOKIE[JWT_COOKIE] ?? '';
    $payload = is_string($token) && $token !== '' ? jwtVerify($token) : null;
    if ($payload && isset($payload['sub'])) {
        $stmt = db()->prepare('SELECT id, name, email, role, status, email_verified_at FROM users WHERE id = ?');
        $stmt->execute([(int) $payload['sub']]);
        $row = $stmt->fetch();
        if ($row && $row['status'] === 'active' && $row['email_verified_at'] !== null) {
            unset($row['status'], $row['email_verified_at']);
            $user = $row;
        }
    }
    return $user;
}


/* ==========================================================================
   Email helpers
   ========================================================================== */

// PHPMailer: found either through Composer (vendor/autoload.php)
// or as a plain copy of the downloaded folder at vendor/PHPMailer/ (no Composer needed).
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
if (!class_exists('\PHPMailer\PHPMailer\PHPMailer', false)) {
    $manualCopy = dirname(__DIR__) . '/vendor/PHPMailer/src';
    if (is_file($manualCopy . '/PHPMailer.php') && is_file($manualCopy . '/SMTP.php') && is_file($manualCopy . '/Exception.php')) {
        require_once $manualCopy . '/Exception.php';
        require_once $manualCopy . '/PHPMailer.php';
        require_once $manualCopy . '/SMTP.php';
    }
}
unset($composerAutoload, $manualCopy);

/** Full address with the domain, for emails: absUrl('reset-password.php?token=...') */
function absUrl(string $path = ''): string
{
    return rtrim(SITE_URL, '/') . url($path);
}

/**
 * Send an HTML email. Returns true on success. Never throws: problems are written to the PHP error log.
 * While SMTP_HOST is empty and IS_LOCAL is true, the email is written to logs/mail.log instead.
 */
function sendMail(string $to, string $subject, string $html): bool
{
    // Plain-text version: keep each link's address, written as "text (https://...)"
    $withLinks = (string) preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
    $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/h\d)[^>]*>/i', "\n", $withLinks)), ENT_QUOTES, 'UTF-8');
    $text = trim((string) preg_replace('/\n{3,}/', "\n\n", $text));

    if (SMTP_HOST === '') {
        if (!IS_LOCAL) {
            error_log('Email not sent: SMTP_HOST is not set in includes/config.php');
            return false;
        }
        $dir = dirname(__DIR__) . '/logs';
        if (is_dir($dir) && is_writable($dir)) {
            file_put_contents(
                $dir . '/mail.log',
                '[' . date('Y-m-d H:i:s') . "] To: $to | Subject: $subject\n$text\n" . str_repeat('-', 60) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return true;
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        error_log('Email not sent: PHPMailer is not installed (copy it to vendor/PHPMailer/ or run: composer require phpmailer/phpmailer)');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = SMTP_USER !== '';
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        if (SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_SECURE === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';          // no encryption: only for local test tools
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        return $mail->send();
    } catch (Throwable $ex) {
        error_log('Email failed: ' . $ex->getMessage());
        return false;
    }
}


/* Database connection (includes/db.php), so one require of config.php is enough on a page */
require_once __DIR__ . '/db.php';