<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Course capacity (seat limit) and the one function that enrols a student.
|
|   courseCapacityReady($pdo)           true once database/migrations/002_course_capacity.sql was run
|   courseEnrol($pdo, $course, $user)   'enrolled' | 'already' | 'full' | 'unavailable'
|   courseSetCapacity($pdo, $course, ?int)  error text, or null when saved
|
| Every way of enrolling a student (the catalog, an instructor's cohort, the admin) goes through
| courseEnrol(), so the limit cannot be bypassed. The course row is locked while the seats are
| counted, so two students clicking at the same moment cannot both take the last seat.
|
| This is a library file. Requesting it in a browser returns 404.
|--------------------------------------------------------------------------
*/

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const COURSE_CAPACITY_MAX = 100000;

/** Does courses.capacity exist? (NULL in that column means "no limit") */
function courseCapacityReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = $pdo->query("SHOW COLUMNS FROM courses LIKE 'capacity'")->fetch() !== false;
        } catch (Throwable $error) {
            $ready = false;
        }
    }

    return $ready;
}

/**
 * Enrol one student, respecting the seat limit.
 *
 * @param bool $requirePublished students can only join published courses; admins and instructors
 *                               may pass false
 * @return string 'enrolled' (new), 'already' (was enrolled), 'full' (no seat left),
 *                'unavailable' (no such course, or not published)
 */
function courseEnrol(PDO $pdo, int $courseId, int $userId, bool $requirePublished = true): string
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $columns = courseCapacityReady($pdo) ? 'status, capacity' : "status, NULL AS capacity";
        $course = $pdo->prepare("SELECT $columns FROM courses WHERE id = :id FOR UPDATE");
        $course->execute(['id' => $courseId]);
        $row = $course->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($requirePublished && $row['status'] !== 'published')) {
            $result = 'unavailable';
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :user AND course_id = :course LIMIT 1');
            $exists->execute(['user' => $userId, 'course' => $courseId]);

            if ($exists->fetchColumn() !== false) {
                $result = 'already';
            } else {
                $taken = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = :course');
                $taken->execute(['course' => $courseId]);

                if ($row['capacity'] !== null && (int) $taken->fetchColumn() >= (int) $row['capacity']) {
                    $result = 'full';
                } else {
                    $pdo->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (:user, :course)')
                        ->execute(['user' => $userId, 'course' => $courseId]);
                    $result = 'enrolled';
                }
            }
        }

        if ($ownTransaction) {
            $pdo->commit();
        }

        return $result;
    } catch (Throwable $error) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Set or clear the seat limit. $capacity null = no limit.
 * Returns an error text, or null when it was saved.
 */
function courseSetCapacity(PDO $pdo, int $courseId, ?int $capacity): ?string
{
    if (!courseCapacityReady($pdo)) {
        return 'Course capacity is not available yet. Run database/migrations/002_course_capacity.sql first.';
    }
    if ($capacity !== null && ($capacity < 1 || $capacity > COURSE_CAPACITY_MAX)) {
        return 'Enter a capacity between 1 and ' . number_format(COURSE_CAPACITY_MAX) . ', or leave it empty for no limit.';
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM courses WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $courseId]);
        if ($lock->fetchColumn() === false) {
            $pdo->rollBack();
            return 'That course could not be found.';
        }

        $taken = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = :course');
        $taken->execute(['course' => $courseId]);
        $enrolled = (int) $taken->fetchColumn();

        if ($capacity !== null && $capacity < $enrolled) {
            $pdo->rollBack();
            return 'This course already has ' . $enrolled . ' student(s). Remove some first, or choose a capacity of at least ' . $enrolled . '.';
        }

        $pdo->prepare('UPDATE courses SET capacity = :capacity WHERE id = :id')
            ->execute(['capacity' => $capacity, 'id' => $courseId]);
        $pdo->commit();

        return null;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
