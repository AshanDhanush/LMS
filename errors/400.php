<?php
declare(strict_types=1);

/**
 * 400 Bad Request
 */
http_response_code(400);

$errorCode = 400;
$errorTitle = 'Bad Request';
$errorSubtitle = 'Something went wrong with this request';
$errorDescription = 'The server could not understand or process your request due to invalid syntax or parameters. Please check the URL and try again.';
$errorType = 'warning';
$badgeLabel = 'Bad Request';

require __DIR__ . '/error_layout.php';
