<?php
declare(strict_types=1);

/**
 * 503 Service Unavailable
 */
http_response_code(503);

$errorCode = 503;
$errorTitle = 'Service Temporarily Unavailable';
$errorSubtitle = 'System maintenance or high server traffic';
$errorDescription = 'The Learning Hub platform is temporarily unable to handle this request due to scheduled maintenance or capacity limits. Please try again shortly.';
$errorType = 'warning';
$badgeLabel = 'Service Unavailable';

require __DIR__ . '/error_layout.php';
