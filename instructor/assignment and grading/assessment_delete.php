<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/api/api_assignment_service.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';

$user = assessmentRequireInstructor();

$assessmentsPage = 'instructor/assignment and grading/assignments_and_grading.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($assessmentsPage);
    exit;
}

$type     = (string) ($_POST['type'] ?? '');
$id       = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$back = static fn (string $query): string => 'instructor/assignment and grading/assessment_list.php?course_id='
    . (int) $courseId . '&type=' . ($type === 'quiz' ? 'quiz' : 'assignment') . '&' . $query;

if (!$courseId || !$id || !in_array($type, ['assignment', 'quiz'], true)) {
    redirect($assessmentsPage);
    exit;
}

if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
    redirect($back('error=csrf'));
    exit;
}

$pdo = db();

try {
    if ($type === 'quiz') {
        $delete = $pdo->prepare(
            'DELETE q FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             WHERE q.id = :id AND q.course_id = :course_id AND c.instructor_id = :instructor'
        );
        $delete->execute(['id' => $id, 'course_id' => $courseId, 'instructor' => (int) $user['id']]);
    } else {
        $find = $pdo->prepare(
            'SELECT a.file_path
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             WHERE a.id = :id AND a.course_id = :course_id AND c.instructor_id = :instructor
             LIMIT 1'
        );
        $find->execute(['id' => $id, 'course_id' => $courseId, 'instructor' => (int) $user['id']]);
        $row = $find->fetch(PDO::FETCH_ASSOC);

        $submissionFiles = [];
        if ($row) {
            $files = $pdo->prepare('SELECT file_path FROM submissions WHERE assignment_id = :id');
            $files->execute(['id' => $id]);
            $submissionFiles = $files->fetchAll(PDO::FETCH_COLUMN);
        }

        $delete = $pdo->prepare(
            'DELETE a FROM assignments a
             JOIN courses c ON c.id = a.course_id
             WHERE a.id = :id AND a.course_id = :course_id AND c.instructor_id = :instructor'
        );
        $delete->execute(['id' => $id, 'course_id' => $courseId, 'instructor' => (int) $user['id']]);

        if ($delete->rowCount() > 0 && $row) {
            assessmentDeleteStoredFile($row['file_path'] ?? null);
            foreach ($submissionFiles as $submissionFile) {
                assessmentDeleteStoredFile($submissionFile);
            }
        }
    }

    if ($delete->rowCount() === 0) {
        redirect($back('error=notfound'));
        exit;
    }
} catch (PDOException $exception) {
    error_log('Delete failed: ' . $exception->getMessage());
    redirect($back('error=notfound'));
    exit;
}

assessmentCsrfRotate();
redirect($back('deleted=' . $type));
exit;
