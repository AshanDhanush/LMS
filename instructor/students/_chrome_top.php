<?php
/* Shared top of the students and cohorts pages. Needs $pageTitleTag and $pageHeading. */
$e = static fn (mixed $v): string => assessmentFormEscape($v);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitleTag) ?> | Learning Hub</title>
    <link rel="stylesheet" href="<?= $e(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= $e(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = $pageHeading; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-main ins-curriculum" id="main">
