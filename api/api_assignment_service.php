<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Assignment helpers (shared by the add and edit forms)
|--------------------------------------------------------------------------
*/

const ASSIGNMENT_MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

// Allowed extensions and the MIME types accepted for each one.
const ASSIGNMENT_ALLOWED_FILES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ],
];

/**
 * Validate the text fields and the optional file of an assignment form.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed>|null $upload one entry of $_FILES
 * @param string[] $errors
 * @return array{values:array<string,string>,clean:?array,hasFile:bool,extension:string,originalName:string}
 */
function assignmentValidateInput(array $post, ?array $upload, array &$errors): array
{
    $before = count($errors);

    $values = [
        'title'       => trim((string) ($post['title'] ?? '')),
        'description' => trim((string) ($post['description'] ?? '')),
        'due_date'    => trim((string) ($post['due_date'] ?? '')),
        'max_mark'    => trim((string) ($post['max_mark'] ?? '')),
    ];

    $titleLength = mb_strlen($values['title'], 'UTF-8');
    if ($titleLength < 1 || $titleLength > 190) {
        $errors[] = 'Title is required and must be 190 characters or fewer.';
    }

    if (mb_strlen($values['description'], 'UTF-8') > 5000) {
        $errors[] = 'Description must be 5000 characters or fewer.';
    }

    $dueDate = DateTime::createFromFormat('Y-m-d\TH:i', $values['due_date']);
    if (!$dueDate || $dueDate->format('Y-m-d\TH:i') !== $values['due_date']) {
        $errors[] = 'Please choose a valid due date and time.';
    }

    $maxMark = filter_var($values['max_mark'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($maxMark === false) {
        $errors[] = 'Maximum mark must be a whole number from 1 to 1000.';
    }

    $hasFile = $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $extension = '';
    $originalName = '';

    if ($hasFile) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The file could not be uploaded. Please try again.';
        } else {
            $originalName = (string) $upload['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!array_key_exists($extension, ASSIGNMENT_ALLOWED_FILES)) {
                $errors[] = 'Only PDF, DOC or DOCX files are allowed.';
            } elseif ((int) $upload['size'] > ASSIGNMENT_MAX_FILE_SIZE) {
                $errors[] = 'The file must be 10 MB or smaller.';
            } elseif (!is_uploaded_file((string) $upload['tmp_name'])) {
                $errors[] = 'The file could not be uploaded. Please try again.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = (string) $finfo->file((string) $upload['tmp_name']);

                if (!in_array($detectedMime, ASSIGNMENT_ALLOWED_FILES[$extension], true)) {
                    $errors[] = 'The file content does not match a PDF or Word document.';
                }
            }
        }
    }

    $clean = null;
    if (count($errors) === $before) {
        $clean = [
            'title'       => $values['title'],
            'description' => $values['description'] !== '' ? $values['description'] : null,
            'due_date'    => $dueDate->format('Y-m-d H:i:s'),
            'max_mark'    => (int) $maxMark,
        ];
    }

    return [
        'values'       => $values,
        'clean'        => $clean,
        'hasFile'      => (bool) $hasFile,
        'extension'    => $extension,
        'originalName' => $originalName,
    ];
}

/**
 * Move a validated upload into uploads/assignments.
 *
 * @param string[] $errors
 * @return array{path:string,name:string,absolute:string}|null
 */
function assignmentStoreUpload(array $upload, string $extension, string $originalName, array &$errors): ?array
{
    $uploadDir = dirname(__DIR__) . '/uploads/assignments';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $errors[] = 'The upload folder could not be created.';
        return null;
    }

    $newFileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $absolute = $uploadDir . '/' . $newFileName;

    if (!move_uploaded_file((string) $upload['tmp_name'], $absolute)) {
        $errors[] = 'The file could not be saved. Please try again.';
        return null;
    }

    return [
        'path'     => 'uploads/assignments/' . $newFileName,
        'name'     => mb_substr(
            (string) preg_replace('/[^\w.\- ()]+/u', '_', basename($originalName)),
            0,
            255,
            'UTF-8'
        ),
        'absolute' => $absolute,
    ];
}

/**
 * Delete a stored file, but only when it really sits inside the project's
 * uploads folder (never trust a path read from the database).
 */
function assessmentDeleteStoredFile(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $uploadsRoot = realpath(dirname(__DIR__) . '/uploads');
    $absolute = realpath(dirname(__DIR__) . '/' . ltrim($relativePath, '/\\'));

    if ($uploadsRoot && $absolute && is_file($absolute) && str_starts_with($absolute, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        @unlink($absolute);
    }
}
