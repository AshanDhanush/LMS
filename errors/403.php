<?php
declare(strict_types=1);

/**
 * 403 Forbidden
 */
http_response_code(403);

$errorCode = 403;
$errorTitle = 'Access Denied';
$errorSubtitle = "You don't have permission to access this page";
$errorDescription = 'Your account does not have the necessary privileges to view or perform actions on this resource. If you believe this is an error, please reach out to your instructor or administrator.';
$errorType = 'danger';
$badgeLabel = 'Access Denied';

require __DIR__ . '/error_layout.php';
