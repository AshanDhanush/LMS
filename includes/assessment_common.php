<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Small helpers shared by the instructor assignment and quiz pages.
| Needs includes/config.php to be loaded first (currentUser, url, redirect, db).
|--------------------------------------------------------------------------
*/

if (!function_exists('assessmentFormEscape')) {
    function assessmentFormEscape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** The signed-in instructor, or a redirect to the home page. */
function assessmentRequireInstructor(): array
{
    $user = currentUser();

    if (!$user || ($user['role'] ?? '') !== 'instructor') {
        redirect(url('index.php'));
        exit;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return $user;
}

function assessmentCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['assessment_form_csrf'])) {
        $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['assessment_form_csrf'];
}

function assessmentCsrfValid(string $posted): bool
{
    return $posted !== '' && hash_equals(assessmentCsrfToken(), $posted);
}

function assessmentCsrfRotate(): void
{
    $_SESSION['assessment_form_csrf'] = bin2hex(random_bytes(32));
}

/** "2026-11-01 09:30:00" -> "01 Nov 2026, 09:30". */
function assessmentFormatDate(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('d M Y, H:i') : '';
}

/** A course of this instructor, or null. */
function assessmentOwnedCourse(PDO $pdo, int $courseId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, title FROM courses WHERE id = :id AND instructor_id = :instructor LIMIT 1'
    );
    $stmt->execute(['id' => $courseId, 'instructor' => $instructorId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);

    return $course ?: null;
}
