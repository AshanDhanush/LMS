<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Points and badges (proposal: "points for learning activity, badges,
| leaderboard").
|
|   badgesSync($pdo, $userId)  recalculates a student's points from what they
|                              have actually done, then gives any badge whose
|                              rule is now met. Safe to call as often as you
|                              like: it never gives the same badge twice.
|
| Points are worked out from the data every time (never "add 10"), so ticking
| and un-ticking a lesson cannot farm points:
|
|   +POINTS_PER_LESSON   for each lesson marked complete
|   +the quiz pass mark  for each quiz passed (once per quiz)
|   +POINTS_PER_COURSE   for each course completed
|
| Change the numbers below to change the rewards.
|--------------------------------------------------------------------------
*/

const POINTS_PER_LESSON = 10;
const POINTS_PER_COURSE = 50;
const BADGE_RISING_STAR_POINTS = 100;
const BADGE_QUICK_LEARNER_LESSONS = 5;
const BADGE_QUIZ_MASTER_QUIZZES = 3;

/** The badges (same keys, names and icons as the dashboard and profile pages). */
function badgesCatalog(): array
{
    return [
        'first_lesson'     => ['name' => 'First Steps',       'icon' => '📘', 'hint' => 'First lesson completed'],
        'quick_learner'    => ['name' => 'Quick Learner',     'icon' => '⚡', 'hint' => 'Five lessons completed'],
        'quiz_master'      => ['name' => 'Quiz Master',       'icon' => '🧠', 'hint' => 'Three quizzes passed'],
        'course_completer' => ['name' => 'Course Completer', 'icon' => '🎓', 'hint' => 'Course completed'],
        'rising_star'      => ['name' => 'Rising Star',       'icon' => '🌟', 'hint' => '100 points earned'],
    ];
}

/**
 * Update a student's points and award any newly earned badges.
 *
 * @return array{points:int,new_badges:array<int,array{key:string,name:string,icon:string,hint:string}>}
 */
function badgesSync(PDO $pdo, int $userId): array
{
    $one = static function (string $sql) use ($pdo, $userId): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    };

    $role = $pdo->prepare('SELECT role, points FROM users WHERE id = :u LIMIT 1');
    $role->execute(['u' => $userId]);
    $user = $role->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['role'] !== 'student') {
        return ['points' => 0, 'new_badges' => []];
    }

    $lessons = $one('SELECT COUNT(*) FROM lesson_progress WHERE user_id = :u');
    $courses = $one('SELECT COUNT(*) FROM enrollments WHERE user_id = :u AND completed_at IS NOT NULL');
    $quizzesPassed = $one(
        'SELECT COUNT(DISTINCT quiz_id) FROM quiz_attempts WHERE user_id = :u AND passed = 1 AND total_marks > 0'
    );
    $quizPoints = $one(
        'SELECT COALESCE(SUM(q.pass_mark), 0) FROM quizzes q
         WHERE EXISTS (SELECT 1 FROM quiz_attempts qa
                       WHERE qa.quiz_id = q.id AND qa.user_id = :u AND qa.passed = 1 AND qa.total_marks > 0)'
    );

    $points = $lessons * POINTS_PER_LESSON + $quizPoints + $courses * POINTS_PER_COURSE;
    if ($points !== (int) $user['points']) {
        $pdo->prepare('UPDATE users SET points = :p WHERE id = :u')->execute(['p' => $points, 'u' => $userId]);
    }

    $rules = [
        'first_lesson'     => $lessons >= 1,
        'quick_learner'    => $lessons >= BADGE_QUICK_LEARNER_LESSONS,
        'quiz_master'      => $quizzesPassed >= BADGE_QUIZ_MASTER_QUIZZES,
        'course_completer' => $courses >= 1,
        'rising_star'      => $points >= BADGE_RISING_STAR_POINTS,
    ];

    $catalog = badgesCatalog();
    $insert = $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_key) VALUES (:u, :k)');
    $new = [];
    foreach ($rules as $key => $met) {
        if (!$met) {
            continue;
        }
        $insert->execute(['u' => $userId, 'k' => $key]);
        if ($insert->rowCount() === 1) { // 0 means the student already had it
            $new[] = ['key' => $key] + $catalog[$key];
        }
    }

    return ['points' => $points, 'new_badges' => $new];
}
