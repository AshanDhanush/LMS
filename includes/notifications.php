<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| In-app notifications
|
|   notify($pdo, $userId, $type, $title, $body, $link, $dedupeKey)
|   notifyUsers / notifyAdmins / notifyCourseStudents / notifyCourseInstructor
|
| Rules:
|   - A notification must never break the action that caused it, so every
|     function here catches its own errors, logs them, and returns quietly
|     (for example when the notifications table has not been created yet).
|   - $link is a path inside the site WITHOUT the leading slash
|     ("assignment/5", "course/2#reviews"). The API turns it into a full URL.
|   - $dedupeKey stops the same notification being created twice for a user
|     (for example "due24:assignment:5"). Leave it null for one-off events.
|
| Types (the bell picks an icon from these):
|   grade, assignment, quiz, reminder, reply, badge, course, submission,
|   enrollment, review, comment, security
|--------------------------------------------------------------------------
*/

/** Create one notification. Returns true if a new one was saved. */
function notify(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $link = null,
    ?string $dedupeKey = null
): bool {
    if ($userId < 1) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO notifications (user_id, type, title, body, link, dedupe_key)
             VALUES (:user, :type, :title, :body, :link, :dedupe)'
        );
        $stmt->execute([
            'user'   => $userId,
            'type'   => mb_substr($type, 0, 40, 'UTF-8'),
            'title'  => mb_substr($title, 0, 160, 'UTF-8'),
            'body'   => $body !== null && $body !== '' ? mb_substr($body, 0, 300, 'UTF-8') : null,
            'link'   => $link !== null && $link !== '' ? mb_substr(ltrim($link, '/'), 0, 255, 'UTF-8') : null,
            'dedupe' => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 120, 'UTF-8') : null,
        ]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $error) {
        error_log('Notification not saved: ' . $error->getMessage());
        return false;
    }
}

/**
 * Notify several people. With a $dedupeKey each person gets their own copy
 * of the key, so it only stops repeats per person.
 *
 * @param int[] $userIds
 */
function notifyUsers(PDO $pdo, array $userIds, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupeKey = null): int
{
    $sent = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        $sent += notify($pdo, $userId, $type, $title, $body, $link, $dedupeKey) ? 1 : 0;
    }
    return $sent;
}

/** Every active admin. */
function notifyAdmins(PDO $pdo, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupeKey = null): int
{
    try {
        $ids = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $error) {
        error_log('Notification admins lookup failed: ' . $error->getMessage());
        return 0;
    }
    return notifyUsers($pdo, $ids, $type, $title, $body, $link, $dedupeKey);
}

/** Every active student enrolled in a course. */
function notifyCourseStudents(PDO $pdo, int $courseId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupeKey = null): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT e.user_id FROM enrollments e JOIN users u ON u.id = e.user_id AND u.status = 'active'
             WHERE e.course_id = :course"
        );
        $stmt->execute(['course' => $courseId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $error) {
        error_log('Notification students lookup failed: ' . $error->getMessage());
        return 0;
    }
    return notifyUsers($pdo, $ids, $type, $title, $body, $link, $dedupeKey);
}

/** The instructor who owns a course. */
function notifyCourseInstructor(PDO $pdo, int $courseId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupeKey = null): bool
{
    try {
        $stmt = $pdo->prepare('SELECT instructor_id FROM courses WHERE id = :course LIMIT 1');
        $stmt->execute(['course' => $courseId]);
        $instructorId = (int) $stmt->fetchColumn();
    } catch (Throwable $error) {
        error_log('Notification instructor lookup failed: ' . $error->getMessage());
        return false;
    }
    return notify($pdo, $instructorId, $type, $title, $body, $link, $dedupeKey);
}

/** A course title, for the text of a notification ("" if it cannot be found). */
function notificationCourseTitle(PDO $pdo, int $courseId): string
{
    try {
        $stmt = $pdo->prepare('SELECT title FROM courses WHERE id = :course LIMIT 1');
        $stmt->execute(['course' => $courseId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $error) {
        return '';
    }
}

/** First characters of some text, for the body of a notification. */
function notificationExcerpt(string $text, int $length = 120): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length - 1, 'UTF-8') . '…' : $text;
}

/**
 * Deadline reminders for a student: assignments due and quizzes closing within
 * the next 24 hours that they have not handed in or attempted. Created when
 * the student loads a page (no scheduled job needed); the dedupe key means
 * each item is only announced once.
 */
function notificationsDueSoon(PDO $pdo, int $studentId): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.due_date, c.title AS course
             FROM assignments a
             JOIN courses c ON c.id = a.course_id AND c.status = 'published'
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
             WHERE s.id IS NULL AND a.due_date > NOW() AND a.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
             ORDER BY a.due_date ASC LIMIT 20"
        );
        $stmt->execute([$studentId, $studentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            notify(
                $pdo, $studentId, 'reminder', 'Due soon: ' . $row['title'],
                $row['course'] . ' · due ' . (new DateTimeImmutable((string) $row['due_date']))->format('j M, H:i'),
                'assignment/' . (int) $row['id'], 'due24:assignment:' . (int) $row['id']
            );
        }

        $stmt = $pdo->prepare(
            "SELECT q.id, q.title, q.end_time, c.title AS course
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id AND c.status = 'published'
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             WHERE q.end_time > NOW() AND q.end_time <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
               AND NOT EXISTS (SELECT 1 FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?)
             ORDER BY q.end_time ASC LIMIT 20"
        );
        $stmt->execute([$studentId, $studentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            notify(
                $pdo, $studentId, 'reminder', 'Quiz closing soon: ' . $row['title'],
                $row['course'] . ' · closes ' . (new DateTimeImmutable((string) $row['end_time']))->format('j M, H:i'),
                'quiz/' . (int) $row['id'], 'due24:quiz:' . (int) $row['id']
            );
        }
    } catch (Throwable $error) {
        error_log('Notification reminders failed: ' . $error->getMessage());
    }
}
