<?php
declare(strict_types=1);

/**
 * 404 Not Found
 */
http_response_code(404);

$errorCode = 404;
$errorTitle = 'Page Not Found';
$errorSubtitle = "We couldn't find the page you're looking for";
$errorDescription = 'The page you requested might have been moved, renamed, archived, or does not exist. Double-check the URL or use the navigation options below.';
$errorType = 'primary';
$badgeLabel = 'Page Not Found';

require __DIR__ . '/error_layout.php';
