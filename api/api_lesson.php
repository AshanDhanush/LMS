
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
    if (!preg_match('~^uploads/lessons/[a-f0-9]{32}\.(?:pdf|ppt|pptx)$~i', $relativePath)) {
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

function lessonApiSaveUpload(array $upload): string
{
    if (
        !isset($upload['error']) ||
        is_array($upload['error']) ||
        $upload['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('Please select a valid lesson file.');
    }

    if (($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Lesson files must not exceed 10 MB.');
    }

    $tmp = $upload['tmp_name'] ?? '';

    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('The uploaded file is invalid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmp);
    $handle = fopen($tmp, 'rb');
    $signature = $handle ? fread($handle, 8) : '';
    if ($handle) {
        fclose($handle);
    }

    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'pdf' => [
            'mime_types' => ['application/pdf'],
            'signature' => '%PDF-',
        ],
        'ppt' => [
            'mime_types' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
            'signature' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
        ],
        'pptx' => [
            'mime_types' => [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
            ],
            'signature' => 'PK',
        ],
    ];

    if (
        !isset($allowedTypes[$extension]) ||
        !in_array($mimeType, $allowedTypes[$extension]['mime_types'], true) ||
        !str_starts_with($signature, $allowedTypes[$extension]['signature'])
    ) {
        throw new RuntimeException('Upload a valid PDF, PPT, or PPTX file.');
    }

    $directory = dirname(__DIR__) . '/uploads/lessons';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the lesson upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $directory . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save the lesson file.');
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
            'SELECT l.id, l.course_id, l.module_id, l.title, l.type, l.content,
                l.file_path, l.pdf_path, l.powerpoint_path, l.video_url, l.position
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

        foreach (array_unique(array_filter([
            $existing['file_path'] ?? null,
            $existing['pdf_path'] ?? null,
            $existing['powerpoint_path'] ?? null,
        ])) as $filePath) {
            lessonApiDeleteFile($filePath);
        }

        lessonApiResponse(200, [
            'status' => 'success',
            'message' => 'Lesson deleted successfully.',
            'redirect' => url('instructor/courses/course_lesson.php?' . $courseId),
        ]);
    }

    $moduleId = null;
    if (array_key_exists('module_id', $_POST)) {
        $postedModuleId = trim((string) $_POST['module_id']);
        if ($postedModuleId !== '') {
            $moduleId = filter_var(
                $postedModuleId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$moduleId) {
                lessonApiResponse(422, [
                    'status' => 'error',
                    'message' => 'Select a valid module.',
                ]);
            }
        }
    } elseif ($action === 'update') {
        $moduleId = isset($existing['module_id']) ? (int) $existing['module_id'] : null;
    }

    if ($moduleId !== null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM modules
             WHERE id = :module_id AND course_id = :course_id
             LIMIT 1'
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'course_id' => $courseId,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'The selected module does not belong to this course.',
            ]);
        }
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $type = (string) ($_POST['type'] ?? 'text');
    $content = trim((string) ($_POST['content'] ?? ''));
    $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
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

    if (!in_array($type, ['text', 'video', 'pdf', 'powerpoint'], true)) {
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

    if ($videoUrl !== '') {
        $scheme = strtolower((string) parse_url($videoUrl, PHP_URL_SCHEME));
        if (
            mb_strlen($videoUrl, 'UTF-8') > 1000 ||
            !filter_var($videoUrl, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true)
        ) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Enter a valid HTTP or HTTPS video URL.',
            ]);
        }
    }

    if ($type === 'video' && $videoUrl === '') {
        lessonApiResponse(422, [
            'status' => 'error',
            'message' => 'Enter the video URL.',
        ]);
    }

    if ($type === 'text' && $content === '') {
        lessonApiResponse(422, [
            'status' => 'error',
            'message' => 'Enter the lesson content.',
        ]);
    }

    $oldFilePaths = array_unique(array_filter([
        $existing['file_path'] ?? null,
        $existing['pdf_path'] ?? null,
        $existing['powerpoint_path'] ?? null,
    ]));
    $pdfPath = null;
    $powerpointPath = null;
    $newUploadPaths = [];
    if ($type === 'pdf') {
        $upload = $_FILES['lesson_pdf'] ?? null;
        $hasNewUpload = is_array($upload)
            && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasNewUpload) {
            $pdfPath = lessonApiSaveUpload($upload);
            $newUploadPaths[] = $pdfPath;
        } else {
            $pdfPath = $existing['pdf_path'] ?? $existing['file_path'] ?? null;
        }

        if (!$pdfPath) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Upload a PDF for this lesson.',
            ]);
        }

        $content = '';
    } elseif ($type === 'powerpoint') {
        $upload = $_FILES['lesson_powerpoint'] ?? null;
        $hasNewUpload = is_array($upload)
            && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasNewUpload) {
            $powerpointPath = lessonApiSaveUpload($upload);
            $newUploadPaths[] = $powerpointPath;
        } else {
            $powerpointPath = $existing['powerpoint_path'] ?? null;
        }

        if (!$powerpointPath) {
            lessonApiResponse(422, [
                'status' => 'error',
                'message' => 'Upload a PowerPoint file for this lesson.',
            ]);
        }

        $content = '';
    } else {
        $content = $type === 'text' ? $content : '';
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO lessons
                (course_id, module_id, title, type, content, file_path, pdf_path,
                 powerpoint_path, video_url, position)
             VALUES
                (:course_id, :module_id, :title, :type, :content, :file_path, :pdf_path,
                 :powerpoint_path, :video_url, :position)'
        );

        $stmt->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'title' => $title,
            'type' => $type,
            'content' => $content !== '' ? $content : null,
            'file_path' => $pdfPath,
            'pdf_path' => $pdfPath,
            'powerpoint_path' => $powerpointPath,
            'video_url' => $videoUrl !== '' ? $videoUrl : null,
            'position' => $position,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE lessons
             SET module_id = :module_id,
                 title = :title,
                 type = :type,
                 content = :content,
                 video_url = :video_url,
                 file_path = :file_path,
                 pdf_path = :pdf_path,
                 powerpoint_path = :powerpoint_path,
                 position = :position
             WHERE id = :id'
        );

        $stmt->execute([
            'module_id' => $moduleId,
            'title' => $title,
            'type' => $type,
            'content' => $content !== '' ? $content : null,
            'video_url' => $videoUrl !== '' ? $videoUrl : null,
            'file_path' => $pdfPath,
            'pdf_path' => $pdfPath,
            'powerpoint_path' => $powerpointPath,
            'position' => $position,
            'id' => $lessonId,
        ]);

    }

    foreach ($oldFilePaths as $oldFilePath) {
        if (!in_array($oldFilePath, [$pdfPath, $powerpointPath], true)) {
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
    foreach ($newUploadPaths ?? [] as $newUploadPath) {
        lessonApiDeleteFile($newUploadPath);
    }

    error_log('Lesson API error: ' . $e->getMessage());

    lessonApiResponse(500, [
        'status' => 'error',
        'message' => 'Something went wrong while saving the lesson. Check the PHP error log.',
    ]);
}
