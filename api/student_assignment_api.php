<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student assignment (instructions, PDF download, submit answers)
|
|   GET  ?id=ASSIGNMENT                         -> details, your submission, mark and feedback
|   POST action=submit (assignment_id, file)    -> upload your answers (PDF, DOC or DOCX, 10 MB max)
|
| A student can replace their file until the instructor has marked it.
| A submission after the due date is accepted and shows as late.
| Files are stored in uploads/submissions and are only served through
| api/student_assignment_file.php, which checks who is asking.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';
require_once __DIR__ . '/api_assignment_service.php';

[$pdo, $user] = sc_api_start();
$userId = (int) $user['id'];
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($post) {
    // A file bigger than post_max_size arrives as an empty request.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
        sc_json(413, ['status' => 'error', 'message' => 'That file is larger than this server accepts. Choose a file of 10 MB or less.']);
    }
    sc_require_csrf();
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$assignmentId = filter_var($post ? ($_POST['assignment_id'] ?? null) : ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignmentId === false) {
    sc_json(422, ['status' => 'error', 'message' => 'A valid assignment is required.']);
}

$stmt = $pdo->prepare(
    "SELECT a.*, c.title AS course_title, u.name AS instructor
     FROM assignments a
     JOIN courses c ON c.id = a.course_id AND c.status = 'published'
     JOIN users u ON u.id = c.instructor_id
     WHERE a.id = :id LIMIT 1"
);
$stmt->execute([':id' => $assignmentId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    sc_json(404, ['status' => 'error', 'message' => 'That assignment is not available.']);
}
$courseId = (int) $assignment['course_id'];
if (sc_enrollment($pdo, $courseId, $userId) === null) {
    sc_json(403, [
        'status' => 'error', 'code' => 'not_enrolled',
        'message' => 'Enrol in this course to open its assignments.',
        'redirect' => url('course/' . $courseId),
    ]);
}

/** "9f3a…_my_answers.pdf" -> "my_answers.pdf" (the readable part of a stored name). */
function sa_display_name(string $path): string
{
    $base = basename($path);
    return preg_match('/^[0-9a-f]{16}_(.+)$/', $base, $m) ? $m[1] : $base;
}

function sa_submission(PDO $pdo, int $assignmentId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = :a AND student_id = :s LIMIT 1');
    $stmt->execute([':a' => $assignmentId, ':s' => $userId]);
    return $stmt->fetch() ?: null;
}

function sa_info(PDO $pdo, array $assignment, int $userId): array
{
    $id = (int) $assignment['id'];
    $due = new DateTimeImmutable((string) $assignment['due_date']);
    $overdue = $due < new DateTimeImmutable();
    $row = sa_submission($pdo, $id, $userId);

    $submission = null;
    $graded = false;
    if ($row) {
        $graded = $row['mark'] !== null;
        $name = sa_display_name((string) $row['file_path']);
        $submission = [
            'file_name' => $name,
            'ext'       => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'url'       => url('api/student_assignment_file.php?kind=mine&id=' . $id),
            'submitted' => sc_iso($row['submitted_at']),
            'late'      => new DateTimeImmutable((string) $row['submitted_at']) > $due,
            'graded'    => $graded,
            'mark'      => $graded ? (int) $row['mark'] : null,
            'percent'   => $graded ? sc_percent((int) $row['mark'], (int) $assignment['max_mark']) : null,
            'feedback'  => $row['feedback'] !== null && $row['feedback'] !== '' ? (string) $row['feedback'] : null,
            'graded_at' => sc_iso($row['graded_at']),
        ];
    }

    $file = null;
    if (!empty($assignment['file_path'])) {
        $fileName = (string) ($assignment['file_name'] ?: basename((string) $assignment['file_path']));
        $file = [
            'name' => $fileName,
            'ext'  => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'url'  => url('api/student_assignment_file.php?kind=brief&id=' . $id),
        ];
    }

    return [
        'id'          => $id,
        'title'       => (string) $assignment['title'],
        'description' => (string) ($assignment['description'] ?? ''),
        'due'         => sc_iso((string) $assignment['due_date']),
        'overdue'     => $overdue,
        'max_mark'    => (int) $assignment['max_mark'],
        'file'        => $file,
        'course'      => [
            'id' => (int) $assignment['course_id'], 'title' => (string) $assignment['course_title'],
            'instructor' => (string) $assignment['instructor'], 'url' => url('course/' . (int) $assignment['course_id']),
        ],
        'submission'  => $submission,
        'state'       => $graded ? 'graded' : ($row ? 'submitted' : ($overdue ? 'overdue' : 'open')),
        'can_submit'  => !$graded,
        'limits'      => ['max_mb' => ASSIGNMENT_MAX_FILE_SIZE / 1048576, 'types' => array_keys(ASSIGNMENT_ALLOWED_FILES)],
    ];
}

/* ----------------------------------------------------------------- POST */
if ($post) {
    if ((string) ($_POST['action'] ?? '') !== 'submit') {
        sc_json(422, ['status' => 'error', 'message' => 'Unknown action.']);
    }
    $existing = sa_submission($pdo, (int) $assignmentId, $userId);
    if ($existing && $existing['mark'] !== null) {
        sc_json(409, ['status' => 'error', 'message' => 'This assignment has already been marked, so it can no longer be changed.', 'assignment' => sa_info($pdo, $assignment, $userId)]);
    }

    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        sc_json(422, ['status' => 'error', 'message' => 'Choose a file to upload.']);
    }
    if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        sc_json(413, ['status' => 'error', 'message' => 'That file is larger than this server accepts. Choose a file of 10 MB or less.']);
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
        sc_json(422, ['status' => 'error', 'message' => 'The file could not be uploaded. Please try again.']);
    }

    $originalName = (string) $upload['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!array_key_exists($extension, ASSIGNMENT_ALLOWED_FILES)) {
        sc_json(422, ['status' => 'error', 'message' => 'Only PDF, DOC or DOCX files are allowed.']);
    }
    if ((int) $upload['size'] <= 0) {
        sc_json(422, ['status' => 'error', 'message' => 'That file is empty.']);
    }
    if ((int) $upload['size'] > ASSIGNMENT_MAX_FILE_SIZE) {
        sc_json(422, ['status' => 'error', 'message' => 'The file must be 10 MB or smaller.']);
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
    if (!in_array($mime, ASSIGNMENT_ALLOWED_FILES[$extension], true)) {
        sc_json(422, ['status' => 'error', 'message' => 'The file content does not match a PDF or Word document.']);
    }

    // Save with an unguessable name that still shows the student's own file name.
    $directory = dirname(__DIR__) . '/uploads/submissions';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        sc_json(500, ['status' => 'error', 'message' => 'The upload folder could not be created.']);
    }
    $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)), '_');
    $base = $base !== '' ? substr($base, 0, 60) : 'answers';
    $storedName = bin2hex(random_bytes(8)) . '_' . $base . '.' . $extension;
    if (!move_uploaded_file((string) $upload['tmp_name'], $directory . '/' . $storedName)) {
        sc_json(500, ['status' => 'error', 'message' => 'The file could not be saved. Please try again.']);
    }
    $relative = 'uploads/submissions/' . $storedName;

    $oldPath = null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, file_path, mark FROM submissions WHERE assignment_id = :a AND student_id = :s FOR UPDATE');
        $stmt->execute([':a' => $assignmentId, ':s' => $userId]);
        $row = $stmt->fetch();
        if ($row && $row['mark'] !== null) {
            $pdo->rollBack();
            assessmentDeleteStoredFile($relative);
            sc_json(409, ['status' => 'error', 'message' => 'This assignment has already been marked, so it can no longer be changed.', 'assignment' => sa_info($pdo, $assignment, $userId)]);
        }
        if ($row) {
            $oldPath = (string) $row['file_path'];
            $pdo->prepare('UPDATE submissions SET file_path = :f, submitted_at = NOW() WHERE id = :id')
                ->execute([':f' => $relative, ':id' => $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO submissions (assignment_id, student_id, file_path) VALUES (:a, :s, :f)')
                ->execute([':a' => $assignmentId, ':s' => $userId, ':f' => $relative]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        assessmentDeleteStoredFile($relative);
        throw $error;
    }
    if ($oldPath !== null && $oldPath !== $relative) {
        assessmentDeleteStoredFile($oldPath); // the replaced file
    }

    sc_json(200, [
        'status'     => 'ok',
        'message'    => $oldPath !== null ? 'Your file has been replaced.' : 'Your answers have been submitted.',
        'assignment' => sa_info($pdo, $assignment, $userId),
    ]);
}

/* ------------------------------------------------------------------ GET */
sc_json(200, ['status' => 'ok', 'assignment' => sa_info($pdo, $assignment, $userId)]);
