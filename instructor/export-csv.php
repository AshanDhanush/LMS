<?php

declare(strict_types=1);

/*
| CSV downloads for the instructor reports.
|   export-csv/{gradebook|students|progress|quiz_attempts|reviews}/{course_id}
|   export-csv/cohort/{cohort_id}
*/

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/assessment_common.php';
require_once __DIR__ . '/../api/api_report_service.php';
require_once __DIR__ . '/../api/api_cohort_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();

$report   = (string) ($_GET['report'] ?? '');
$cohort   = null;

// Cohort member list: identified by the cohort, not the course.
if ($report === 'cohort') {
    $cohortId = filter_var($_GET['cohort_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $cohort = $cohortId ? cohortOwned($pdo, (int) $cohortId, (int) $user['id']) : null;

    if (!$cohort) {
        http_response_code(404);
        exit('Cohort not found or you do not own it.');
    }
    $_GET['course_id'] = (string) $cohort['course_id'];
}

$courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!in_array($report, ['gradebook', 'students', 'progress', 'quiz_attempts', 'cohort', 'reviews'], true) || !$courseId) {
    http_response_code(400);
    exit('Invalid report.');
}

$course = assessmentOwnedCourse($pdo, (int) $courseId, (int) $user['id']);
if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not own it.');
}

$yesNo   = static fn ($v): string => (int) $v === 1 ? 'Yes' : 'No';
$percent = static fn ($v): string => $v === null ? '' : number_format((float) $v, 1, '.', '');

$header = [];
$lines  = [];

switch ($report) {
    case 'cohort':
        $header = ['Cohort', 'Name', 'Email', 'Lessons completed', 'Total lessons', 'Progress %', 'Last activity', 'Enrolled on'];
        foreach (cohortMembers($pdo, (int) $cohort['id'], (int) $courseId) as $r) {
            $lines[] = [
                $cohort['name'], $r['name'], $r['email'], (int) $r['lessons_done'], (int) $r['lessons_total'],
                $percent($r['progress']), $r['last_activity'], $r['enrolled_at'],
            ];
        }
        break;

    case 'reviews':
        $header = ['Course', 'Student', 'Rating (1-5)', 'Review', 'Reviewed on', 'Your reply', 'Replied on'];
        $stmt = $pdo->prepare(
            'SELECT u.name, e.rating, e.review, e.reviewed_at, e.enrolled_at, e.instructor_reply, e.replied_at
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :c AND e.rating IS NOT NULL
             ORDER BY COALESCE(e.reviewed_at, e.enrolled_at) DESC, e.id DESC'
        );
        $stmt->execute(['c' => (int) $courseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = [
                $course['title'], $r['name'], (int) $r['rating'], $r['review'],
                $r['reviewed_at'] ?: $r['enrolled_at'], $r['instructor_reply'], $r['replied_at'],
            ];
        }
        break;

    case 'students':
        $header = ['Name', 'Email', 'Account status', 'Enrolled on', 'Last activity', 'Completed on'];
        foreach (reportStudents($pdo, (int) $courseId) as $r) {
            $lines[] = [$r['name'], $r['email'], $r['status'], $r['enrolled_at'], $r['last_activity'], $r['completed_at']];
        }
        break;

    case 'progress':
        $header = [
            'Name', 'Email', 'Lessons completed', 'Total lessons', 'Progress %',
            'Assignments graded', 'Total assignments', 'Quizzes attempted', 'Total quizzes',
            'Enrolled on', 'Last activity', 'Course completed on',
        ];
        foreach (reportGradebook($pdo, (int) $courseId)['rows'] as $r) {
            $lines[] = [
                $r['name'], $r['email'], (int) $r['lessons_done'], (int) $r['lessons_total'], $percent($r['progress']),
                (int) $r['assignments_graded'], (int) $r['assignments_total'],
                (int) $r['quizzes_attempted'], (int) $r['quizzes_total'],
                $r['enrolled_at'], $r['last_activity'], $r['completed_at'],
            ];
        }
        break;

    case 'gradebook':
        $book = reportGradebook($pdo, (int) $courseId);
        $header = ['Name', 'Email'];
        foreach ($book['assignments'] as $a) {
            $header[] = $a['title'] . ' (out of ' . (int) $a['max_mark'] . ')';
        }
        foreach ($book['quizzes'] as $q) {
            $header[] = 'Quiz: ' . $q['title'] . ' (best attempt)';
        }

        foreach ($book['rows'] as $r) {
            $line = [$r['name'], $r['email']];
            foreach ($book['assignments'] as $a) {
                $mark = $r['marks'][(int) $a['id']] ?? 'missing';
                $line[] = $mark === 'missing' ? 'Not submitted' : ($mark === null ? 'Not graded' : $mark);
            }
            foreach ($book['quizzes'] as $q) {
                $best = $r['quiz_results'][(int) $q['id']] ?? null;
                $line[] = $best === null ? 'Not attempted' : $best['score'] . ' / ' . $best['total'];
            }
            $lines[] = $line;
        }
        break;

    case 'quiz_attempts':
        $header = ['Quiz', 'Student', 'Email', 'Attempt no.', 'Score', 'Total marks', 'Percent', 'Passed', 'Attempted at'];
        foreach (reportQuizResults($pdo, (int) $courseId)['attempts'] as $r) {
            $lines[] = [
                $r['quiz'], $r['name'], $r['email'], (int) $r['attempt_no'], (int) $r['score'],
                (int) $r['total_marks'], $percent($r['percent']), $yesNo($r['passed']), $r['attempted_at'],
            ];
        }
        break;
}

$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($course['title'])), '-');
if ($cohort) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slug . '-' . $cohort['name'])), '-');
}
$fileName = ($slug !== '' ? $slug : 'course') . '-' . $report . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // byte order mark so Excel reads UTF-8 names correctly

fputcsv($out, array_map('reportCsvCell', $header));
foreach ($lines as $line) {
    fputcsv($out, array_map('reportCsvCell', $line));
}
fclose($out);
exit;
