<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/api/api_assignment_service.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';
require_once dirname(__DIR__, 2) . '/api/data_cleanup.php';

$user = assessmentRequireInstructor();

$assessmentsPage = 'instructor/assignment and grading/assignments_and_grading.php';

// Deleting is only ever done by a form POST, never by opening a link.
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
    $deleted = false;

    if ($type === 'quiz') {
        // Is it a quiz of one of this instructor's courses?
        $find = $pdo->prepare(
            'SELECT q.id
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             WHERE q.id = :id AND q.course_id = :course_id AND c.instructor_id = :instructor
             LIMIT 1'
        );
        $find->execute(['id' => $id, 'course_id' => $courseId, 'instructor' => (int) $user['id']]);

        if ($find->fetch(PDO::FETCH_ASSOC)) {
            // Removes the quiz and its attempts together (no foreign keys do it for us).
            $deleted = dataCleanupDeleteQuiz($pdo, (int) $id);
        }
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

        if ($row) {
            // Find the files first: once the rows are deleted they cannot be found.
            $files = $pdo->prepare('SELECT file_path FROM submissions WHERE assignment_id = :id');
            $files->execute(['id' => $id]);
            $submissionFiles = $files->fetchAll(PDO::FETCH_COLUMN);

            // Removes the assignment and its submissions together.
            $deleted = dataCleanupDeleteAssignment($pdo, (int) $id);

            if ($deleted) {
                assessmentDeleteStoredFile($row['file_path'] ?? null);
                foreach ($submissionFiles as $submissionFile) {
                    assessmentDeleteStoredFile($submissionFile);
                }
            }
        }
    }

    if (!$deleted) {
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
