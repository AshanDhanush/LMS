<?php

require_once dirname(__DIR__) . '/includes/config.php';
require_once __DIR__ . '/api_lesson_service.php';

function moduleApiResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    $acceptsJson = str_contains(
        strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')),
        'application/json'
    );

    if ($acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo (string) ($data['message'] ?? 'Module request failed.');
    }

    exit;
}

$wantsJson = str_contains(
    strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')),
    'application/json'
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moduleApiResponse(405, [
        'status' => 'error',
        'message' => 'Only POST requests are allowed.',
    ]);
}

$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['create', 'update','delete'], true)) {
    moduleApiResponse(400, [
        'status' => 'error',
        'message' => 'Invalid module action.',
    ]);
}

$user = currentUser();
if (!$user || ($user['role'] ?? '') !== 'instructor') {
    moduleApiResponse(403, [
        'status' => 'error',
        'message' => 'Only instructors can manage modules.',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
$expectedCsrf = (string) ($_SESSION['instructor_course_form_csrf'] ?? '');
if ($expectedCsrf === '' || $csrf === '' || !hash_equals($expectedCsrf, $csrf)) {
    moduleApiResponse(403, [
        'status' => 'error',
        'message' => 'Your session token is invalid. Refresh the page and try again.',
    ]);
}

$courseId = filter_var(
    $_POST['course_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$moduleId = filter_var(
    $_POST['module_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$moduleOrder = filter_var(
    $_POST['module_order'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 100000]]
);
$status = (string) ($_POST['status'] ?? 'draft');

if (!$courseId) {
    moduleApiResponse(422, [
        'status' => 'error',
        'message' => 'A valid course is required.',
    ]);
}

if (in_array($action, ['update', 'delete'], true) && !$moduleId) {
    moduleApiResponse(422, [
        'status' => 'error',
        'message' => 'A valid module is required.',
    ]);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id FROM courses
     WHERE id = :course_id AND instructor_id = :instructor_id
     LIMIT 1'
);
$stmt->execute([
    'course_id' => $courseId,
    'instructor_id' => (int) $user['id'],
]);

if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    moduleApiResponse(404, [
        'status' => 'error',
        'message' => 'Course not found or you do not own it.',
    ]);
}

if ($action === 'delete') {
    $lessonFiles = [];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id FROM modules
             WHERE id = :module_id AND course_id = :course_id
             FOR UPDATE'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->rollBack();
            moduleApiResponse(404, [
                'status' => 'error',
                'message' => 'Module not found in this course.',
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT file_path, pdf_path, powerpoint_path
             FROM lessons
             WHERE module_id = :module_id AND course_id = :course_id'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);
        $lessonFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'DELETE FROM lessons
             WHERE module_id = :module_id AND course_id = :course_id'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);

        $stmt = $pdo->prepare(
            'DELETE FROM modules WHERE id = :module_id AND course_id = :course_id'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The module could not be deleted.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Module deletion error: ' . $e->getMessage());
        moduleApiResponse(500, [
            'status' => 'error',
            'message' => 'The module and its lessons could not be deleted. Please try again.',
        ]);
    }

    foreach ($lessonFiles as $lessonFile) {
        foreach (array_unique(array_filter([
            $lessonFile['file_path'] ?? null,
            $lessonFile['pdf_path'] ?? null,
            $lessonFile['powerpoint_path'] ?? null,
        ])) as $filePath) {
            lessonServiceDeleteFile($filePath);
        }
    }

    $deleteRedirect = url('instructor/courses/course_lesson.php?course_id=' . $courseId);
    if (!$wantsJson) {
        redirect('instructor/courses/course_lesson.php?course_id=' . $courseId);
    }

    moduleApiResponse(200, [
        'status' => 'success',
        'message' => 'Module and its lessons deleted successfully.',
        'redirect' => $deleteRedirect,
    ]);
}

if ($title === '' || mb_strlen($title, 'UTF-8') > 190) {
    moduleApiResponse(422, [
        'status' => 'error',
        'message' => 'Enter a module title between 1 and 190 characters.',
    ]);
}

if (!$moduleOrder) {
    moduleApiResponse(422, [
        'status' => 'error',
        'message' => 'Module order must be between 1 and 100000.',
    ]);
}

if (!in_array($status, ['draft', 'published'], true)) {
    moduleApiResponse(422, [
        'status' => 'error',
        'message' => 'Select a valid module status.',
    ]);
}

if ($action === 'create') {
    $stmt = $pdo->prepare(
        'INSERT INTO modules (course_id, title, description, module_order, status)
         VALUES (:course_id, :title, :description, :module_order, :status)'
    );
    $stmt->execute([
        'course_id' => $courseId,
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'module_order' => $moduleOrder,
        'status' => $status,
    ]);
} else {
    $stmt = $pdo->prepare(
        'UPDATE modules
         SET title = :title,
             description = :description,
             module_order = :module_order,
             status = :status
         WHERE id = :module_id AND course_id = :course_id'
    );
    $stmt->execute([
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'module_order' => $moduleOrder,
        'status' => $status,
        'module_id' => $moduleId,
        'course_id' => $courseId,
    ]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare(
            'SELECT id FROM modules WHERE id = :module_id AND course_id = :course_id'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            moduleApiResponse(404, [
                'status' => 'error',
                'message' => 'Module not found in this course.',
            ]);
        }
    }
}

$redirect = url('instructor/courses/course_lesson.php?course_id=' . $courseId);
if ($wantsJson) {
    moduleApiResponse(200, [
        'status' => 'success',
        'message' => $action === 'create'
            ? 'Module created successfully.'
            : 'Module updated successfully.',
        'redirect' => $redirect,
    ]);
}

redirect('instructor/courses/course_lesson.php?course_id=' . $courseId);
