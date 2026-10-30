<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Delete helpers for a database WITHOUT foreign keys
|
| With foreign keys, MySQL deleted the child rows by itself (ON DELETE CASCADE).
| Without them the application has to do it. Every delete in the project goes
| through these functions, so the rules live in one place:
|
|   dataCleanupDeleteCourse     course  -> everything inside it
|   dataCleanupDeleteModule     module  -> its lessons and their progress/comments
|   dataCleanupDeleteLesson     lesson  -> progress, comments, "last lesson" markers
|   dataCleanupDeleteAssignment assignment -> submissions
|   dataCleanupDeleteQuiz       quiz    -> attempts
|   dataCleanupDeleteCohort     cohort  -> members and announcements
|   dataCleanupDeleteUser       user    -> everything they own or did
|
| Each one runs inside a transaction: either everything is removed or nothing.
| The functions only change database rows. Uploaded files are listed by
| dataCleanupCourseFiles() / dataCleanupUserFiles() so the caller can remove
| them after the delete succeeded.
|
| This is a library file. Requesting it in a browser returns 404.
|--------------------------------------------------------------------------
*/

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Run $work in a transaction (or inside the caller's, if one is already open). */
function dataCleanupTransaction(PDO $pdo, callable $work): mixed
{
    if ($pdo->inTransaction()) {
        return $work();
    }

    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();

        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Does this table exist? The cohort tables only exist after the cohorts SQL
 * was run, and a delete must not fail because an optional table is missing.
 */
function dataCleanupTableExists(PDO $pdo, string $table): bool
{
    static $known = [];

    if (!array_key_exists($table, $known)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name'
        );
        $stmt->execute(['name' => $table]);
        $known[$table] = (int) $stmt->fetchColumn() > 0;
    }

    return $known[$table];
}

/** Does this column exist? (some columns were added by optional SQL migrations) */
function dataCleanupColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $known = [];

    $key = $table . '.' . $column;
    if (!array_key_exists($key, $known)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col'
        );
        $stmt->execute(['tbl' => $table, 'col' => $column]);
        $known[$key] = (int) $stmt->fetchColumn() > 0;
    }

    return $known[$key];
}

/** Run one statement with a single :id style parameter. */
function dataCleanupRun(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/* ---------- Lessons and modules ---------- */

/** Rows that belong to a lesson: progress, comments (and replies), and "last lesson" markers. */
function dataCleanupLessonChildren(PDO $pdo, int $lessonId): void
{
    dataCleanupRun($pdo, 'DELETE FROM comments WHERE lesson_id = :id', ['id' => $lessonId]);
    dataCleanupRun($pdo, 'DELETE FROM lesson_progress WHERE lesson_id = :id', ['id' => $lessonId]);
    dataCleanupRun($pdo, 'UPDATE enrollments SET last_lesson_id = NULL WHERE last_lesson_id = :id', ['id' => $lessonId]);
}

/** The same, for every lesson of one module. */
function dataCleanupModuleLessonChildren(PDO $pdo, int $moduleId, int $courseId): void
{
    $params = ['module' => $moduleId, 'course' => $courseId];

    dataCleanupRun($pdo,
        'DELETE cm FROM comments cm JOIN lessons l ON l.id = cm.lesson_id
         WHERE l.module_id = :module AND l.course_id = :course', $params);
    dataCleanupRun($pdo,
        'DELETE lp FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
         WHERE l.module_id = :module AND l.course_id = :course', $params);
    dataCleanupRun($pdo,
        'UPDATE enrollments e JOIN lessons l ON l.id = e.last_lesson_id
         SET e.last_lesson_id = NULL
         WHERE l.module_id = :module AND l.course_id = :course', $params);
}

function dataCleanupDeleteLesson(PDO $pdo, int $lessonId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $lessonId): bool {
        dataCleanupLessonChildren($pdo, $lessonId);

        return dataCleanupRun($pdo, 'DELETE FROM lessons WHERE id = :id', ['id' => $lessonId]) > 0;
    });
}

/** Delete a module and all its lessons. */
function dataCleanupDeleteModule(PDO $pdo, int $moduleId, int $courseId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $moduleId, $courseId): bool {
        dataCleanupModuleLessonChildren($pdo, $moduleId, $courseId);
        dataCleanupRun($pdo, 'DELETE FROM lessons WHERE module_id = :module AND course_id = :course',
            ['module' => $moduleId, 'course' => $courseId]);

        return dataCleanupRun($pdo, 'DELETE FROM modules WHERE id = :module AND course_id = :course',
            ['module' => $moduleId, 'course' => $courseId]) > 0;
    });
}

/* ---------- Assessments and cohorts ---------- */

function dataCleanupDeleteAssignment(PDO $pdo, int $assignmentId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $assignmentId): bool {
        dataCleanupRun($pdo, 'DELETE FROM submissions WHERE assignment_id = :id', ['id' => $assignmentId]);

        return dataCleanupRun($pdo, 'DELETE FROM assignments WHERE id = :id', ['id' => $assignmentId]) > 0;
    });
}

function dataCleanupDeleteQuiz(PDO $pdo, int $quizId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $quizId): bool {
        dataCleanupRun($pdo, 'DELETE FROM quiz_attempts WHERE quiz_id = :id', ['id' => $quizId]);

        return dataCleanupRun($pdo, 'DELETE FROM quizzes WHERE id = :id', ['id' => $quizId]) > 0;
    });
}

function dataCleanupDeleteCohort(PDO $pdo, int $cohortId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $cohortId): bool {
        dataCleanupRun($pdo, 'DELETE FROM cohort_members WHERE cohort_id = :id', ['id' => $cohortId]);

        // Announcements written for this cohort (needs the cohort_id column from the cohorts SQL).
        if (dataCleanupColumnExists($pdo, 'announcements', 'cohort_id')) {
            dataCleanupRun($pdo, 'DELETE FROM announcements WHERE cohort_id = :id', ['id' => $cohortId]);
        }

        return dataCleanupRun($pdo, 'DELETE FROM cohorts WHERE id = :id', ['id' => $cohortId]) > 0;
    });
}

/* ---------- Course ---------- */

/**
 * Uploaded files that belong to a course (thumbnail, lesson files, assignment
 * files and student submissions). Call this BEFORE deleting the course.
 *
 * @return string[]
 */
function dataCleanupCourseFiles(PDO $pdo, int $courseId): array
{
    $queries = [
        'SELECT thumbnail FROM courses WHERE id = :id',
        'SELECT file_path FROM lessons WHERE course_id = :id',
        'SELECT pdf_path FROM lessons WHERE course_id = :id',
        'SELECT powerpoint_path FROM lessons WHERE course_id = :id',
        'SELECT file_path FROM assignments WHERE course_id = :id',
        'SELECT s.file_path FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = :id',
    ];

    $files = [];
    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $courseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (is_string($path) && $path !== '') {
                $files[$path] = true;
            }
        }
    }

    return array_keys($files);
}

/** Delete a course and everything inside it. */
function dataCleanupDeleteCourse(PDO $pdo, int $courseId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $courseId): bool {
        $p = ['c' => $courseId];

        dataCleanupRun($pdo, 'DELETE qa FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id WHERE q.course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE FROM quizzes WHERE course_id = :c', $p);

        dataCleanupRun($pdo, 'DELETE s FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE FROM assignments WHERE course_id = :c', $p);

        dataCleanupRun($pdo, 'DELETE FROM announcements WHERE course_id = :c', $p);
        if (dataCleanupTableExists($pdo, 'cohort_members') && dataCleanupTableExists($pdo, 'cohorts')) {
            dataCleanupRun($pdo, 'DELETE m FROM cohort_members m JOIN cohorts h ON h.id = m.cohort_id WHERE h.course_id = :c', $p);
        }
        if (dataCleanupTableExists($pdo, 'cohorts')) {
            dataCleanupRun($pdo, 'DELETE FROM cohorts WHERE course_id = :c', $p);
        }

        dataCleanupRun($pdo, 'DELETE cm FROM comments cm JOIN lessons l ON l.id = cm.lesson_id WHERE l.course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE lp FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE FROM enrollments WHERE course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE FROM lessons WHERE course_id = :c', $p);
        dataCleanupRun($pdo, 'DELETE FROM modules WHERE course_id = :c', $p);

        return dataCleanupRun($pdo, 'DELETE FROM courses WHERE id = :c', $p) > 0;
    });
}

/* ---------- User ---------- */

/**
 * Uploaded files that belong to a user: their photo, their submissions, and
 * the files of every course they teach. Call this BEFORE deleting the user.
 *
 * @return string[]
 */
function dataCleanupUserFiles(PDO $pdo, int $userId): array
{
    $files = [];

    foreach (['SELECT photo FROM users WHERE id = :id', 'SELECT file_path FROM submissions WHERE student_id = :id'] as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (is_string($path) && $path !== '') {
                $files[$path] = true;
            }
        }
    }

    $courses = $pdo->prepare('SELECT id FROM courses WHERE instructor_id = :id');
    $courses->execute(['id' => $userId]);
    foreach ($courses->fetchAll(PDO::FETCH_COLUMN) as $courseId) {
        foreach (dataCleanupCourseFiles($pdo, (int) $courseId) as $path) {
            $files[$path] = true;
        }
    }

    return array_keys($files);
}

/** Delete a user, the courses they teach, and everything they did on the platform. */
function dataCleanupDeleteUser(PDO $pdo, int $userId): bool
{
    return (bool) dataCleanupTransaction($pdo, static function () use ($pdo, $userId): bool {
        $p = ['u' => $userId];

        // Courses they teach (an instructor), each with everything inside it.
        $courses = $pdo->prepare('SELECT id FROM courses WHERE instructor_id = :u');
        $courses->execute($p);
        foreach ($courses->fetchAll(PDO::FETCH_COLUMN) as $courseId) {
            dataCleanupDeleteCourse($pdo, (int) $courseId);
        }

        // What they did as a learner.
        dataCleanupRun($pdo, 'DELETE FROM quiz_attempts WHERE user_id = :u', $p);
        dataCleanupRun($pdo, 'DELETE FROM submissions WHERE student_id = :u', $p);
        dataCleanupRun($pdo, 'DELETE FROM lesson_progress WHERE user_id = :u', $p);
        if (dataCleanupTableExists($pdo, 'cohort_members')) {
            dataCleanupRun($pdo, 'DELETE FROM cohort_members WHERE user_id = :u', $p);
        }
        dataCleanupRun($pdo, 'DELETE FROM enrollments WHERE user_id = :u', $p);

        // Their comments, and the replies other people wrote under them.
        if (dataCleanupColumnExists($pdo, 'comments', 'parent_id')) {
            dataCleanupRun($pdo, 'DELETE r FROM comments r JOIN comments parent ON parent.id = r.parent_id WHERE parent.user_id = :u', $p);
        }
        dataCleanupRun($pdo, 'DELETE FROM comments WHERE user_id = :u', $p);

        // Announcements they wrote, badges and sign-in tokens.
        dataCleanupRun($pdo, 'DELETE FROM announcements WHERE author_id = :u', $p);
        dataCleanupRun($pdo, 'DELETE FROM user_badges WHERE user_id = :u', $p);
        dataCleanupRun($pdo, 'DELETE FROM user_tokens WHERE user_id = :u', $p);

        return dataCleanupRun($pdo, 'DELETE FROM users WHERE id = :u', $p) > 0;
    });
}
