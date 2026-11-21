<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security helpers (loaded by includes/config.php, so every page has them)
|
|   sendSecurityHeaders()   X-Frame-Options, CSP, nosniff, HSTS ... (runs once, automatically)
|   requestIsSameOrigin()   false when a POST comes from another website
|   plainTextProblem()      validates a name/title: no < > or control characters
|   csvSafeCell()           stops spreadsheet formula injection in CSV downloads
|   likeEscape()            makes % and _ typed by a person match literally in LIKE
|--------------------------------------------------------------------------
*/

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** True on HTTPS, also when the site sits behind a proxy such as Azure's front end. */
function requestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Is this request for a page or API inside the admin/ folder? */
function requestIsAdminArea(): bool
{
    foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME'] as $key) {
        $script = str_replace('\\', '/', (string) ($_SERVER[$key] ?? ''));
        if (preg_match('#/admin/[^/]+\.php$#', $script) === 1 || str_ends_with($script, '/api/api_user_search.php')) {
            return true;
        }
    }

    return false;
}

/**
 * Send the security headers once per request.
 *
 *  - Framing: nobody else's website may put our pages in a frame (clickjacking).
 *    The admin area refuses framing completely; the rest of the site allows only itself,
 *    because lessons show our own PDFs inside a frame.
 *  - Admin pages get a strict Content-Security-Policy: scripts may only come from this site's own
 *    files, so a script injected into a page could not run even if escaping were ever missed.
 */
function sendSecurityHeaders(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    if (requestIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if (requestIsAdminArea()) {
        header('X-Frame-Options: DENY');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'"
        );
    } else {
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    }
}

/* Session cookies (used by the CSRF token and a few forms) get the same protection as the login cookie. */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (requestIsHttps()) {
        ini_set('session.cookie_secure', '1');
    }
}

sendSecurityHeaders();

/**
 * Browsers tell us which website a POST came from (Origin, or Referer as a fallback).
 * A POST from a different host is refused. When neither header is present (some privacy tools
 * remove them) the request is allowed here, because the CSRF token still has to match.
 */
function requestIsSameOrigin(): bool
{
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($source === '' || $source === 'null') {
        $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    }
    if ($source === '') {
        return true;
    }

    $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
    $ownHost    = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

    return $sourceHost !== '' && $sourceHost === $ownHost;
}

/**
 * Check a person-typed name or title. Returns an error sentence, or null when it is fine.
 * Angle brackets and control characters are refused, so markup can never be stored in a name.
 * (Everything is still HTML-escaped when printed: this is a second layer, not the only one.)
 */
function plainTextProblem(string $value, string $label, int $max = 100, bool $required = true): ?string
{
    if ($value === '') {
        return $required ? $label . ' is required.' : null;
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        return $label . ' contains characters that are not allowed.';
    }
    if (mb_strlen($value, 'UTF-8') > $max) {
        return $label . ' must be ' . $max . ' characters or fewer.';
    }
    if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        return $label . ' cannot contain < or > or hidden control characters.';
    }

    return null;
}

/**
 * Make one CSV cell safe to open in Excel or Sheets. A cell that starts with = + - @ (or a tab/return)
 * is read as a formula, so it gets a leading apostrophe. Plain numbers are left alone.
 */
function csvSafeCell(mixed $cell): string
{
    $cell = (string) $cell;
    if ($cell === '' || preg_match('/^-?\d+(\.\d+)?$/', $cell) === 1) {
        return $cell;
    }

    $first = ltrim($cell, " \t\r\n\0")[0] ?? '';
    if (($first !== '' && strpos('=+-@', $first) !== false) || strpbrk($cell[0], "\t\r") !== false) {
        return "'" . $cell;
    }

    return $cell;
}

/** Escape % _ and \ so a typed search term matches literally inside LIKE '%...%'. */
function likeEscape(string $term): string
{
    return addcslashes($term, '%_\\');
}
