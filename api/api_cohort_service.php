<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Students and cohorts (instructor side)
|
| A cohort is a named group of students inside ONE course, for example
| "Batch Jan 2026". A student can only be in a cohort of a course they are
| enrolled in. Needs api/api_report_service.php for the progress numbers.
|--------------------------------------------------------------------------
*/

/* ---------- Flash message (shown once after a redirect) ---------- */

function cohortFlash(string $type, string $text): void
{
    $_SESSION['cohort_flash'] = ['type' => $type, 'text' => $text];
}

/** @return array{type:string,text:string}|null */
function cohortTakeFlash(): ?array
{
    $flash = $_SESSION['cohort_flash'] ?? null;
    unset($_SESSION['cohort_flash']);

    return is_array($flash) ? $flash : null;
}

function cohortFormatDate(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('d M Y') : '';
}

/* ---------- Cohorts ---------- */

/**
 * Validate the cohort form (create and edit).
 *
 * @param string[] $errors
 * @return array{values:array<string,string>,clean:?array}
 */
function cohortValidate(array $post, array &$errors): array
{
    $before = count($errors);

    $values = [
        'name'        => trim((string) ($post['name'] ?? '')),
        'description' => trim((string) ($post['description'] ?? '')),
        'start_date'  => trim((string) ($post['start_date'] ?? '')),
        'end_date'    => trim((string) ($post['end_date'] ?? '')),
    ];

    $length = mb_strlen($values['name'], 'UTF-8');
    if ($length < 1 || $length > 120) {
        $errors[] = 'Cohort name is required and must be 120 characters or fewer.';
    }

    if (mb_strlen($values['description'], 'UTF-8') > 2000) {
        $errors[] = 'Description must be 2000 characters or fewer.';
    }

    $dates = ['start_date' => null, 'end_date' => null];
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $field => $label) {
        if ($values[$field] === '') {
            continue;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $values[$field]);
        if (!$parsed || $parsed->format('Y-m-d') !== $values[$field]) {
            $errors[] = "$label is not a valid date.";
            continue;
        }
        $dates[$field] = $parsed;
    }
    if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) {
        $errors[] = 'End date cannot be before the start date.';
    }

    $clean = null;
    if (count($errors) === $before) {
        $clean = [
            'name'        => $values['name'],
            'description' => $values['description'] !== '' ? $values['description'] : null,
            'start_date'  => $values['start_date'] !== '' ? $values['start_date'] : null,
            'end_date'    => $values['end_date'] !== '' ? $values['end_date'] : null,
        ];
    }

    return ['values' => $values, 'clean' => $clean];
}

/** Cohorts of a course with their member counts. */
function cohortsForCourse(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.description, c.start_date, c.end_date,
                (SELECT COUNT(*) FROM cohort_members m WHERE m.cohort_id = c.id) AS member_count
         FROM cohorts c
         WHERE c.course_id = :course
         ORDER BY c.start_date IS NULL, c.start_date DESC, c.name ASC'
    );
    $stmt->execute(['course' => $courseId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A cohort of one of this instructor's courses, or null. */
function cohortOwned(PDO $pdo, int $cohortId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, co.title AS course_title
         FROM cohorts c
         JOIN courses co ON co.id = c.course_id
         WHERE c.id = :id AND co.instructor_id = :instructor
         LIMIT 1'
    );
    $stmt->execute(['id' => $cohortId, 'instructor' => $instructorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Map of student id => [cohort names] for one course (for the name chips). */
function cohortNamesByStudent(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.user_id, c.id, c.name
         FROM cohort_members m
         JOIN cohorts c ON c.id = m.cohort_id
         WHERE c.course_id = :course
         ORDER BY c.name ASC'
    );
    $stmt->execute(['course' => $courseId]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['user_id']][(int) $row['id']] = (string) $row['name'];
    }

    return $map;
}

/** The enrolled students who are members of the cohort, with progress. */
function cohortMembers(PDO $pdo, int $cohortId, int $courseId): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM cohort_members WHERE cohort_id = :c');
    $stmt->execute(['c' => $cohortId]);
    $ids = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    return array_values(array_filter(
        reportStudents($pdo, $courseId),
        static fn (array $student): bool => isset($ids[(int) $student['id']])
    ));
}

/**
 * Add enrolled students to a cohort. Students who are not enrolled in the
 * cohort's course are ignored, so a cohort can never hold an outsider.
 *
 * @param int[] $userIds
 * @return int number of students newly added
 */
function cohortAddMembers(PDO $pdo, int $cohortId, int $courseId, array $userIds): int
{
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO cohort_members (cohort_id, user_id)
         SELECT :cohort, e.user_id
         FROM enrollments e
         WHERE e.course_id = :course AND e.user_id = :user'
    );

    $added = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId < 1) {
            continue;
        }
        $insert->execute(['cohort' => $cohortId, 'course' => $courseId, 'user' => $userId]);
        $added += $insert->rowCount();
    }

    return $added;
}

/* ---------- Students ---------- */

/**
 * Split a pasted list (new lines, commas, semicolons or spaces) into unique,
 * valid, lower-case email addresses.
 *
 * @return string[]
 */
function cohortParseEmails(string $raw, int $limit = 200): array
{
    $emails = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
        $candidate = strtolower(trim($candidate));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $emails[$candidate] = true;
        }
    }

    return array_slice(array_keys($emails), 0, $limit);
}

/**
 * Enrol students (by email) in a course.
 *
 * @param string[] $emails
 * @return array{enrolled:int,already:int,not_found:string[],inactive:string[],ids:int[]}
 *         ids = every student who is enrolled afterwards (new and existing)
 */
function cohortEnrollByEmail(PDO $pdo, int $courseId, array $emails): array
{
    $result = ['enrolled' => 0, 'already' => 0, 'not_found' => [], 'inactive' => [], 'ids' => []];

    $find   = $pdo->prepare("SELECT id, status FROM users WHERE email = :email AND role = 'student' LIMIT 1");
    $insert = $pdo->prepare('INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (:user, :course)');

    foreach ($emails as $email) {
        $find->execute(['email' => $email]);
        $student = $find->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $result['not_found'][] = $email;
            continue;
        }
        if ($student['status'] !== 'active') {
            $result['inactive'][] = $email;
            continue;
        }

        $insert->execute(['user' => (int) $student['id'], 'course' => $courseId]);
        if ($insert->rowCount() > 0) {
            $result['enrolled']++;
        } else {
            $result['already']++;
        }
        $result['ids'][] = (int) $student['id'];
    }

    return $result;
}

/** One sentence that tells the instructor what an enrol-by-email did. */
function cohortEnrollSummary(array $r): string
{
    $parts = [];
    if ($r['enrolled'] > 0) {
        $parts[] = $r['enrolled'] . ' student(s) enrolled';
    }
    if ($r['already'] > 0) {
        $parts[] = $r['already'] . ' already enrolled';
    }
    if ($r['not_found']) {
        $parts[] = 'no student account for: ' . implode(', ', array_slice($r['not_found'], 0, 5))
            . (count($r['not_found']) > 5 ? ' and ' . (count($r['not_found']) - 5) . ' more' : '');
    }
    if ($r['inactive']) {
        $parts[] = 'inactive account: ' . implode(', ', array_slice($r['inactive'], 0, 5));
    }

    return $parts ? ucfirst(implode('. ', $parts)) . '.' : 'No valid email addresses were found.';
}

/**
 * Remove a student from a course. Cohort memberships of that course go too.
 * Their lesson progress, submissions and quiz attempts are kept.
 */
function cohortRemoveFromCourse(PDO $pdo, int $courseId, int $userId): bool
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE m FROM cohort_members m
             JOIN cohorts c ON c.id = m.cohort_id
             WHERE c.course_id = :course AND m.user_id = :user'
        )->execute(['course' => $courseId, 'user' => $userId]);

        $delete = $pdo->prepare('DELETE FROM enrollments WHERE course_id = :course AND user_id = :user');
        $delete->execute(['course' => $courseId, 'user' => $userId]);

        $pdo->commit();

        return $delete->rowCount() > 0;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/* ---------- Announcements ---------- */

/**
 * Validate an announcement.
 *
 * @param string[] $errors
 * @return array{values:array<string,string>,clean:?array}
 */
function cohortValidateAnnouncement(array $post, array &$errors): array
{
    $before = count($errors);
    $values = [
        'title'   => trim((string) ($post['title'] ?? '')),
        'message' => trim((string) ($post['message'] ?? '')),
    ];

    $titleLength = mb_strlen($values['title'], 'UTF-8');
    if ($titleLength < 1 || $titleLength > 190) {
        $errors[] = 'Announcement title is required and must be 190 characters or fewer.';
    }

    $messageLength = mb_strlen($values['message'], 'UTF-8');
    if ($messageLength < 1 || $messageLength > 5000) {
        $errors[] = 'Message is required and must be 5000 characters or fewer.';
    }

    return [
        'values' => $values,
        'clean'  => count($errors) === $before ? $values : null,
    ];
}

/** Announcements of one cohort, newest first. */
function cohortAnnouncements(PDO $pdo, int $cohortId, int $limit = 20): array
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.message, a.created_at, u.name AS author
         FROM announcements a
         JOIN users u ON u.id = a.author_id
         WHERE a.cohort_id = :cohort
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute(['cohort' => $cohortId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
