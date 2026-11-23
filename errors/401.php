<?php
declare(strict_types=1);

/**
 * 401 Unauthorized
 */
http_response_code(401);

$errorCode = 401;
$errorTitle = 'Authentication Required';
$errorSubtitle = 'Please sign in to access this page';
$errorDescription = 'You need an active session to view this resource. Please sign in with your account credentials to continue.';
$errorType = 'primary';
$badgeLabel = 'Authentication Required';

require __DIR__ . '/error_layout.php';
