<?php

declare(strict_types=1);

// The gradebook now lives in the reports page.
require_once __DIR__ . '/../includes/config.php';

$query = isset($_GET['course_id']) ? '&course_id=' . (int) $_GET['course_id'] : '';
redirect('instructor/reports.php?report=gradebook' . $query);
exit;
