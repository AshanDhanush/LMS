<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$row = db()->query('SELECT COUNT(*) AS total FROM users')->fetch();
echo 'Connected. Users in table: ' . $row['total'];