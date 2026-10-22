
<?php

require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function lessonApiResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function lessonApiDeleteFile(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $relativePath = str_replace('\\', '/', $relativePath);

    // Only delete files inside uploads/lessons.
    if (!preg_match('~^uploads/lessons/[a-f0-9]{32}\.pdf$~i', $relativePath)) {
        return;
    }

    $root = realpath(dirname(__DIR__));
    $file = realpath(dirname(__DIR__) . '/' . $relativePath);

    if (
        $root !== false &&
        $file !== false &&
        is_file($file) &&
        str_starts_with($file, $root . DIRECTORY_SEPARATOR)
    ) {
        @unlink($file);
    }
}

function lessonApiSavePdf(array $upload): string
{
    if (
        !isset($upload['error']) ||
        is_array($upload['error']) ||
        $upload['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('Please select a valid PDF file.');
    }

    if (($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('The PDF must not exceed 10 MB.');
    }

    $tmp = $upload['tmp_name'] ?? '';

    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('The uploaded file is invalid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($tmp) !== 'application/pdf') {
        throw new RuntimeException('Only PDF files are allowed.');
    }

    // Basic PDF signature validation.
    $handle = fopen($tmp, 'rb');
    $signature = $handle ? fread($handle, 5) : '';
    if ($handle) {
        fclose($handle);
    }

    if ($signature !== '%PDF-') {
        throw new RuntimeException('The file is not a valid PDF.');
    }

    $directory = dirname(__DIR__) . '/uploads/lessons';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the lesson upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.pdf';
    $destination = $directory . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save the PDF file.');
    }

    return 'uploads/lessons/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lessonApiResponse(405, [
        'status' => 'error',
        'message' => 'Only POST requests are allowed.',
    ]);
}

$user = currentUser();

if (!$user || ($user['role'] ?? '') !== 'instructor') {
    lessonApiResponse(403, [
        'status' => 'error',
        'message' => 'Only instructors can manage lessons.',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
$expectedCsrf = (string) ($_SESSION['instructor_course_form_csrf'] ?? '');

if (
    $expectedCsrf === '' ||
    $csrf === '' ||
    !hash_equals($expectedCsrf, $csrf)
) {
    lessonApiResponse(403, [
        'status' => 'error',
        'message' => 'Your session token is invalid. Refresh the page and try again.',
    ]);
}

$action = (string) ($_POST['action'] ?? '');

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    lessonApiResponse(400, [
        'status' => 'error',
        'message' => 'Invalid action.',
    ]);
}

$pdo = db();
$instructorId = (int) $user['id'];

$lessonId = filter_var(
    $_POST['lesson_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$courseId = filter_var(
    $_POST['course_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($action === 'create' && !$courseId) {
    lessonApiResponse(422, [
        'status' => 'error',
        'message' => 'A valid course is required.',
    ]);
}

if ($action !== 'create' && !$lessonId) {
    lessonApiResponse(422, [
        'status' => 'error',
        'message' => 'A valid lesson is required.',
    ]);
}

try {
    // Verify ownership for new lessons.
    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'SELECT id FROM courses WHERE id = :course_id AND instructor_id = :instructor_id'
        );
        $stmt->execute([
            'course_id' => $courseId,
            'instructor_id' => $instructorId,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            lessonApiResponse(404, [
                'status' => 'error',
                'message' => 'Course not found or you do not own it.',
            ]);
        }
    }

    // Find the lesson and verify its course belongs to this instructor.
    $existing = null;

    if ($action !== 'create') {
        $stmt = $pdo->prepare(
            'SELECT l.id, l.course_id, l.title, l.type, l.content,
                    l.file_path, l.position
             FROM lessons l
             INNER JOIN courses c ON c.id = l.course_id
             WHERE l.id = :lesson_id
               AND c.instructor_id = :instructor_id'
        );
        $stmt->execute([
            'lesson_id' => $lessonId,
            'instructor_id' => $instructorId,
        ]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            lessonApiResponse(404, [
                'status' => 'error',
                'message' => 'Lesson not found or you do not own its course.',
            ]);
        }

        $courseId = (int) $existing['course_id'];
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM lessons WHERE id = :id');
        $stmt->execute(['id' => $lessonId]);

        lessonApiDeleteFile($existing['file_path'] ?? null);

        lessonApiResponse(200, [
            'status' => 'success',
            'message' => 'Lesson deleted successfully.',
            'redirect' => url('instructor/courses/course_lesson.php?' . $courseId),
        ]);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $type = (string) ($_POST['type'] ?? 'text');
    $content = trim((string) ($_POST['content'] ?? ''));
    $position = filter_var(
        $_POST['position'] ?? 1,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100000]]
    );

    if ($title === '' || mb_strlen($title, 'UTF-8') > 190) {
        lessonApiResponse(422, [
            'status' => 'error',
            'message' => 'Enter a title between 1 and 190 characters.',
        ]);
    }

    if (!in_array($type, ['text', 'video', 'pdf'], true)) {
        lessonApiResponse(422, [
            'status' => 'error',
            'message' => 'Select a valid lesson type.',
        ]);
    }

    if (!$position) {
        lessonApiResponse(422, [
            'status' => 'error',
            'message' => 'Lesson position must be between 1 and 100000.',
        ]);
    }

    if ($type === 'video' && $content !== '') {
        $scheme = strtolower((string) parse_url($content, PHP_URL_SCHEME));

        if (
            !filter_var($content, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true)
        ) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Enter a valid HTTP or HTTPS video URL.',
            ]);
        }
    }

    // Text and video use content; PDF uses file_path.
    $filePath = $existing['file_path'] ?? null;
    $oldFilePath = $filePath;

    if ($type === 'pdf') {
        $upload = $_FILES['lesson_pdf'] ?? null;
        $hasNewUpload = is_array($upload)
            && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasNewUpload) {
            $filePath = lessonApiSavePdf($upload);
        } elseif ($action === 'create' || !$filePath) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Upload a PDF for this lesson.',
            ]);
        }

        $content = '';
    } else {
        $filePath = null;

        if ($type === 'text' && $content === '') {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Enter the lesson content.',
            ]);
        }

        if ($type === 'video' && $content === '') {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Enter the video URL.',
            ]);
        }
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO lessons
                (course_id, title, type, content, file_path, position)
             VALUES
                (:course_id, :title, :type, :content, :file_path, :position)'
        );

        $stmt->execute([
            'course_id' => $courseId,
            'title' => $title,
            'type' => $type,
            'content' => $content !== '' ? $content : null,
            'file_path' => $filePath,
            'position' => $position,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE lessons
             SET title = :title,
                 type = :type,
                 content = :content,
                 file_path = :file_path,
                 position = :position
             WHERE id = :id'
        );

        $stmt->execute([
            'title' => $title,
            'type' => $type,
            'content' => $content !== '' ? $content : null,
            'file_path' => $filePath,
            'position' => $position,
            'id' => $lessonId,
        ]);

        // Remove the old PDF only after the database update succeeds.
        if ($oldFilePath && $oldFilePath !== $filePath) {
            lessonApiDeleteFile($oldFilePath);
        }
    }

    lessonApiResponse(200, [
        'status' => 'success',
        'message' => $action === 'create'
            ? 'Lesson created successfully.'
            : 'Lesson updated successfully.',
        'redirect' => url('instructor/courses/course_lesson.php?' . $courseId),
    ]);
} catch (Throwable $e) {
    error_log('Lesson API error: ' . $e->getMessage());

    lessonApiResponse(500, [
        'status' => 'error',
        'message' => 'Something went wrong while saving the lesson. Check the PHP error log.',
    ]);
}
