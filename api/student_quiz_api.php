<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student quiz (intro -> questions -> result)
|
|   GET  ?id=QUIZ                          -> quiz details, attempts, best score
|   POST action=start  (quiz_id)           -> the questions (no correct answers)
|   POST action=submit (quiz_id, answers)  -> grades, saves one attempt, returns the review
|
| Marking: multiple-choice questions are marked automatically. Written (essay)
| questions are saved with the attempt but are NOT part of the score or the
| total marks, because nothing in the system marks them. The model answer is
| shown in the review. Points: the pass mark (e.g. 80) is awarded the first
| time the student passes a quiz, never again for the same quiz.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/student_course_common.php';
require_once __DIR__ . '/api_quiz_service.php';

const SQ_ESSAY_MAX = 5000;

[$pdo, $user] = sc_api_start();
$userId = (int) $user['id'];
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($post) {
    sc_require_csrf();
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    sc_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$quizId = filter_var($post ? ($_POST['quiz_id'] ?? null) : ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($quizId === false) {
    sc_json(422, ['status' => 'error', 'message' => 'A valid quiz is required.']);
}

$stmt = $pdo->prepare(
    "SELECT q.*, c.title AS course_title, u.name AS instructor
     FROM quizzes q
     JOIN courses c ON c.id = q.course_id AND c.status = 'published'
     JOIN users u ON u.id = c.instructor_id
     WHERE q.id = :id LIMIT 1"
);
$stmt->execute([':id' => $quizId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    sc_json(404, ['status' => 'error', 'message' => 'That quiz is not available.']);
}
$courseId = (int) $quiz['course_id'];
if (sc_enrollment($pdo, $courseId, $userId) === null) {
    sc_json(403, [
        'status' => 'error', 'code' => 'not_enrolled',
        'message' => 'Enrol in this course to take its quizzes.',
        'redirect' => url('course/' . $courseId),
    ]);
}

$questions = json_decode((string) $quiz['questions'], true);
$questions = is_array($questions) ? array_values($questions) : [];
$passMark = (int) $quiz['pass_mark'];
$maxAttempts = (int) $quiz['max_attempts'];

function sq_percent(int $score, int $total): int
{
    return $total > 0 ? (int) round($score * 100 / $total) : 0;
}

/** Marks that can be marked automatically (multiple choice only). */
function sq_graded_total(array $questions): int
{
    $sum = 0;
    foreach ($questions as $q) {
        if (($q['type'] ?? '') === 'mcq') {
            $sum += (int) ($q['marks'] ?? 0);
        }
    }
    return $sum;
}

function sq_attempts(PDO $pdo, int $quizId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, score, total_marks, passed, attempted_at FROM quiz_attempts WHERE quiz_id = :q AND user_id = :u ORDER BY id ASC');
    $stmt->execute([':q' => $quizId, ':u' => $userId]);
    $list = [];
    foreach ($stmt->fetchAll() as $i => $row) {
        $list[] = [
            'number'  => $i + 1,
            'score'   => (int) $row['score'],
            'total'   => (int) $row['total_marks'],
            'percent' => sq_percent((int) $row['score'], (int) $row['total_marks']),
            'passed'  => (bool) (int) $row['passed'],
            'date'    => sc_iso($row['attempted_at']),
        ];
    }
    return $list;
}

function sq_info(PDO $pdo, array $quiz, array $questions, int $userId): array
{
    $attempts = sq_attempts($pdo, (int) $quiz['id'], $userId);
    $left = max(0, (int) $quiz['max_attempts'] - count($attempts));
    $availability = quizAvailability($quiz['start_time'], $quiz['end_time']);
    $mcq = count(array_filter($questions, static fn (array $q): bool => ($q['type'] ?? '') === 'mcq'));
    $total = sq_graded_total($questions);

    $best = null;
    foreach ($attempts as $a) {
        if ($best === null || $a['percent'] > $best['percent']) {
            $best = $a;
        }
    }
    $reason = null;
    if (!$questions) {
        $reason = 'This quiz has no questions yet.';
    } elseif ($availability === 'scheduled') {
        $reason = 'This quiz opens on ' . (new DateTimeImmutable($quiz['start_time']))->format('d M Y, H:i') . '.';
    } elseif ($availability === 'closed') {
        $reason = 'This quiz closed on ' . (new DateTimeImmutable($quiz['end_time']))->format('d M Y, H:i') . '.';
    } elseif ($left === 0) {
        $reason = 'You have used all your attempts.';
    }

    return [
        'id'             => (int) $quiz['id'],
        'title'          => (string) $quiz['title'],
        'description'    => (string) ($quiz['description'] ?? ''),
        'course'         => ['id' => (int) $quiz['course_id'], 'title' => (string) $quiz['course_title'], 'instructor' => (string) $quiz['instructor'], 'url' => url('course/' . (int) $quiz['course_id'])],
        'question_count' => count($questions),
        'mcq_count'      => $mcq,
        'essay_count'    => count($questions) - $mcq,
        'total_marks'    => $total,
        'pass_mark'      => (int) $quiz['pass_mark'],
        'marks_to_pass'  => (int) ceil($total * (int) $quiz['pass_mark'] / 100),
        'max_attempts'   => (int) $quiz['max_attempts'],
        'attempts_used'  => count($attempts),
        'attempts_left'  => $left,
        'availability'   => $availability,
        'start_time'     => sc_iso($quiz['start_time']),
        'end_time'       => sc_iso($quiz['end_time']),
        'can_start'      => $reason === null,
        'reason'         => $reason,
        'best'           => $best,
        'attempts'       => array_reverse($attempts),
        'points_reward'  => (int) $quiz['pass_mark'],
    ];
}

/* ----------------------------------------------------------------- POST */
if ($post) {
    $info = sq_info($pdo, $quiz, $questions, $userId);
    $action = (string) ($_POST['action'] ?? '');

    if (!$info['can_start']) {
        sc_json(409, ['status' => 'error', 'message' => $info['reason'], 'quiz' => $info]);
    }

    if ($action === 'start') {
        $safe = [];
        foreach ($questions as $i => $q) {
            $safe[] = [
                'id'      => (int) ($q['id'] ?? $i + 1),
                'number'  => $i + 1,
                'type'    => (string) $q['type'],
                'text'    => (string) $q['text'],
                'marks'   => (int) $q['marks'],
                'options' => $q['type'] === 'mcq' ? array_values(array_map('strval', $q['options'] ?? [])) : [],
            ];
        }
        sc_json(200, ['status' => 'ok', 'questions' => $safe, 'attempt_number' => $info['attempts_used'] + 1]);
    }

    if ($action === 'submit') {
        $given = json_decode((string) ($_POST['answers'] ?? ''), true);
        if (!is_array($given)) {
            sc_json(422, ['status' => 'error', 'message' => 'Your answers could not be read. Please try again.']);
        }

        // Mark every question.
        $score = 0;
        $correctCount = 0;
        $gradedCount = 0;
        $saved = [];
        $review = [];
        foreach ($questions as $i => $q) {
            $id = (string) ($q['id'] ?? $i + 1);
            $raw = $given[$id] ?? null;
            $item = [
                'number' => $i + 1, 'type' => (string) $q['type'], 'text' => (string) $q['text'], 'marks' => (int) $q['marks'],
                'options' => [], 'chosen' => null, 'correct' => null, 'is_correct' => null, 'answer' => null, 'model_answer' => null,
            ];
            if ($q['type'] === 'mcq') {
                $options = array_values(array_map('strval', $q['options'] ?? []));
                $chosen = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;
                if ($chosen !== null && ($chosen < 0 || $chosen >= count($options))) {
                    $chosen = null;
                }
                $right = (int) $q['correct'];
                $isRight = $chosen !== null && $chosen === $right;
                $gradedCount++;
                if ($isRight) {
                    $score += (int) $q['marks'];
                    $correctCount++;
                }
                $saved[$id] = $chosen;
                $item = array_merge($item, ['options' => $options, 'chosen' => $chosen, 'correct' => $right, 'is_correct' => $isRight]);
            } else {
                $text = is_string($raw) ? trim($raw) : '';
                $text = mb_substr($text, 0, SQ_ESSAY_MAX, 'UTF-8');
                $saved[$id] = $text !== '' ? $text : null;
                $item['answer'] = $text !== '' ? $text : null;
                $item['model_answer'] = isset($q['model_answer']) ? (string) $q['model_answer'] : null;
            }
            $review[] = $item;
        }
        $total = sq_graded_total($questions);
        $percent = sq_percent($score, $total);
        $passed = $total === 0 || $percent >= $passMark;

        // One attempt per submission. The user row is locked so two quick
        // submissions cannot use more attempts than allowed.
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM users WHERE id = :u FOR UPDATE')->execute([':u' => $userId]);
            $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(passed), 0) FROM quiz_attempts WHERE quiz_id = :q AND user_id = :u');
            $stmt->execute([':q' => $quizId, ':u' => $userId]);
            [$used, $passedBefore] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
            if ($used >= $maxAttempts) {
                $pdo->rollBack();
                sc_json(409, ['status' => 'error', 'message' => 'You have used all your attempts.', 'quiz' => sq_info($pdo, $quiz, $questions, $userId)]);
            }
            $pdo->prepare(
                'INSERT INTO quiz_attempts (quiz_id, user_id, score, total_marks, passed, answers) VALUES (:q, :u, :s, :t, :p, :a)'
            )->execute([
                ':q' => $quizId, ':u' => $userId, ':s' => $score, ':t' => $total, ':p' => $passed ? 1 : 0,
                ':a' => json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $points = ($passed && $total > 0 && $passedBefore === 0) ? $passMark : 0;
            if ($points > 0) {
                $pdo->prepare('UPDATE users SET points = points + :p WHERE id = :u')->execute([':p' => $points, ':u' => $userId]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $info = sq_info($pdo, $quiz, $questions, $userId);
        sc_json(200, [
            'status' => 'ok',
            'result' => [
                'attempt_number' => $info['attempts_used'],
                'score'          => $score,
                'total'          => $total,
                'percent'        => $percent,
                'passed'         => $passed,
                'correct'        => $correctCount,
                'graded'         => $gradedCount,
                'points'         => $points,
                'review'         => $review,
            ],
            'quiz' => $info,
        ]);
    }

    sc_json(422, ['status' => 'error', 'message' => 'Unknown action.']);
}

/* ------------------------------------------------------------------ GET */
sc_json(200, ['status' => 'ok', 'quiz' => sq_info($pdo, $quiz, $questions, $userId)]);
