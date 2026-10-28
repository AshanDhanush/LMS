<?php

declare(strict_types=1);

// The gradebook now lives in the reports page.
require_once __DIR__ . '/../includes/config.php';

$courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$path = 'instructor/reports/gradebook' . ($courseId ? '/' . $courseId : '');
redirect($path);
exit;
