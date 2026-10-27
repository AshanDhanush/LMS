<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Report queries for the instructor dashboard.
|
| The report pages and the CSV export both call these functions, so the
| numbers on screen and in the downloaded file are always the same.
| Every function is limited to courses that belong to the instructor.
|--------------------------------------------------------------------------
*/

/** Courses of this instructor (for the course picker). */
function reportCourses(PDO $pdo, int $instructorId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.title,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students
         FROM courses c
         WHERE c.instructor_id = :instructor
         ORDER BY c.title ASC'
    );
    $stmt->execute(['instructor' => $instructorId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Percentage with one decimal, or null when there is nothing to divide by. */
function reportPercent(float|int $part, float|int $whole): ?float
{
    return $whole > 0 ? round($part / $whole * 100, 1) : null;
}

/**
 * Every enrolled student with lesson progress.
 *
 * @return array<int,array<string,mixed>>
 */
function reportStudents(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.status,
                e.enrolled_at, e.completed_at,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = e.course_id) AS lessons_total,
                (SELECT COUNT(*) FROM lesson_progress lp
                   JOIN lessons l2 ON l2.id = lp.lesson_id
                  WHERE l2.course_id = e.course_id AND lp.user_id = e.user_id)   AS lessons_done,
                (SELECT MAX(lp2.completed_at) FROM lesson_progress lp2
                   JOIN lessons l3 ON l3.id = lp2.lesson_id
                  WHERE l3.course_id = e.course_id AND lp2.user_id = e.user_id)  AS last_activity
         FROM enrollments e
         JOIN users u ON u.id = e.user_id
         WHERE e.course_id = :course
         ORDER BY u.name ASC, u.id ASC'
    );
    $stmt->execute(['course' => $courseId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['progress'] = reportPercent((int) $row['lessons_done'], (int) $row['lessons_total']);
    }
    unset($row);

    return $rows;
}

/**
 * Gradebook: one row per student, one column per assignment and quiz.
 * It only shows the results the student got, with no weighting or totals.
 *
 * - marks[assignment id]: the mark of the latest submission
 *   (int = the mark, null = submitted but not graded yet, 'missing' = not submitted).
 * - quiz_results[quiz id]: the student's best attempt as
 *   ['score' => int, 'total' => int, 'percent' => float], or null when not attempted.
 *
 * @return array{assignments:array,quizzes:array,rows:array}
 */
function reportGradebook(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, max_mark FROM assignments WHERE course_id = :c ORDER BY due_date ASC, id ASC'
    );
    $stmt->execute(['c' => $courseId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT id, title, pass_mark FROM quizzes WHERE course_id = :c ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute(['c' => $courseId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $students = reportStudents($pdo, $courseId);

    // Latest submission per student and assignment.
    $submissions = [];
    $stmt = $pdo->prepare(
        'SELECT s.assignment_id, s.student_id, s.mark
         FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id
         WHERE a.course_id = :c
         ORDER BY s.id ASC'
    );
    $stmt->execute(['c' => $courseId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $submissions[(int) $s['student_id']][(int) $s['assignment_id']] =
            $s['mark'] === null ? null : (int) $s['mark'];
    }

    // Best attempt (highest percentage) per student and quiz.
    $best = [];
    $stmt = $pdo->prepare(
        'SELECT qa.quiz_id, qa.user_id, qa.score, qa.total_marks
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         WHERE q.course_id = :c'
    );
    $stmt->execute(['c' => $courseId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $percent = reportPercent((int) $a['score'], (int) $a['total_marks']) ?? 0.0;
        $userId = (int) $a['user_id'];
        $quizId = (int) $a['quiz_id'];

        if (!isset($best[$userId][$quizId]) || $percent > $best[$userId][$quizId]['percent']) {
            $best[$userId][$quizId] = [
                'score'   => (int) $a['score'],
                'total'   => (int) $a['total_marks'],
                'percent' => $percent,
            ];
        }
    }

    $rows = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];

        $marks = [];
        $graded = 0;
        $submitted = 0;
        foreach ($assignments as $assignment) {
            $assignmentId = (int) $assignment['id'];

            if (!array_key_exists($assignmentId, $submissions[$studentId] ?? [])) {
                $marks[$assignmentId] = 'missing';
                continue;
            }

            $submitted++;
            $marks[$assignmentId] = $submissions[$studentId][$assignmentId];
            if ($marks[$assignmentId] !== null) {
                $graded++;
            }
        }

        $quizResults = [];
        $attempted = 0;
        foreach ($quizzes as $quiz) {
            $quizResults[(int) $quiz['id']] = $best[$studentId][(int) $quiz['id']] ?? null;
            if ($quizResults[(int) $quiz['id']] !== null) {
                $attempted++;
            }
        }

        $rows[] = $student + [
            'marks'                => $marks,
            'quiz_results'         => $quizResults,
            'assignments_total'    => count($assignments),
            'assignments_submitted' => $submitted,
            'assignments_graded'   => $graded,
            'quizzes_total'        => count($quizzes),
            'quizzes_attempted'    => $attempted,
        ];
    }

    return ['assignments' => $assignments, 'quizzes' => $quizzes, 'rows' => $rows];
}

/**
 * Quiz results: a summary line per quiz and the full attempt history.
 *
 * @return array{summary:array,attempts:array}
 */
function reportQuizResults(PDO $pdo, int $courseId, ?int $quizId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT q.id, q.title, q.pass_mark, q.max_attempts,
                COUNT(qa.id)                                   AS attempts,
                COUNT(DISTINCT qa.user_id)                     AS students,
                COALESCE(SUM(qa.passed), 0)                    AS passed,
                AVG(qa.score / NULLIF(qa.total_marks, 0)) * 100 AS average,
                MAX(qa.score / NULLIF(qa.total_marks, 0)) * 100 AS highest
         FROM quizzes q
         LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id
         WHERE q.course_id = :c
         GROUP BY q.id, q.title, q.pass_mark, q.max_attempts
         ORDER BY q.created_at ASC, q.id ASC'
    );
    $stmt->execute(['c' => $courseId]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = 'SELECT qa.id, qa.quiz_id, q.title AS quiz, u.name, u.email,
                   qa.score, qa.total_marks, qa.passed, qa.attempted_at
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            JOIN users u ON u.id = qa.user_id
            WHERE q.course_id = :c';
    $params = ['c' => $courseId];
    if ($quizId !== null) {
        $sql .= ' AND qa.quiz_id = :q';
        $params['q'] = $quizId;
    }
    $sql .= ' ORDER BY qa.attempted_at DESC, qa.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attempt number per student and quiz (1 = first try), counted by time.
    $order = $pdo->prepare(
        'SELECT qa.id, qa.user_id, qa.quiz_id
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         WHERE q.course_id = :c
         ORDER BY qa.attempted_at ASC, qa.id ASC'
    );
    $order->execute(['c' => $courseId]);
    $counter = [];
    $number = [];
    foreach ($order->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['user_id'] . '-' . $row['quiz_id'];
        $counter[$key] = ($counter[$key] ?? 0) + 1;
        $number[(int) $row['id']] = $counter[$key];
    }

    foreach ($attempts as &$attempt) {
        $attempt['attempt_no'] = $number[(int) $attempt['id']] ?? 1;
        $attempt['percent'] = reportPercent((int) $attempt['score'], (int) $attempt['total_marks']);
    }
    unset($attempt);

    return ['summary' => $summary, 'attempts' => $attempts];
}

/**
 * Submissions that still have no mark, oldest first.
 *
 * @return array<int,array<string,mixed>>
 */
function reportAwaitingGrading(PDO $pdo, int $instructorId, ?int $courseId = null): array
{
    $sql = 'SELECT s.id, u.name, u.email, a.id AS assignment_id, a.title AS assignment,
                   c.id AS course_id, c.title AS course, a.due_date, s.submitted_at,
                   (s.submitted_at > a.due_date) AS is_late,
                   TIMESTAMPDIFF(HOUR, s.submitted_at, NOW()) AS hours_waiting
            FROM submissions s
            JOIN assignments a ON a.id = s.assignment_id
            JOIN courses c ON c.id = a.course_id
            JOIN users u ON u.id = s.student_id
            WHERE c.instructor_id = :instructor AND s.mark IS NULL';
    $params = ['instructor' => $instructorId];
    if ($courseId !== null) {
        $sql .= ' AND c.id = :course';
        $params['course'] = $courseId;
    }
    $sql .= ' ORDER BY s.submitted_at ASC, s.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Make a value safe for a CSV cell. Spreadsheet programs run text that starts
 * with = + - @ as a formula, so such text gets a leading apostrophe.
 */
function reportCsvCell(mixed $value): string|int|float
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return $value;
    }

    $text = (string) $value;

    return ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false && !is_numeric($text))
        ? "'" . $text
        : $text;
}
