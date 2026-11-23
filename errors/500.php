<?php
declare(strict_types=1);

/**
 * 500 Internal Server Error
 */
http_response_code(500);

$errorCode = 500;
$errorTitle = 'Internal Server Error';
$errorSubtitle = 'Something unexpected happened on our end';
$errorDescription = 'Our servers encountered an unexpected issue while processing your request. Our technical team has been notified. Please try refreshing the page or try again in a few moments.';
$errorType = 'danger';
$badgeLabel = 'Server Error';

require __DIR__ . '/error_layout.php';
