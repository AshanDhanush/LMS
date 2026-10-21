<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';

function profile_api_json(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function profile_api_current_user(): array
{
    $user = currentUser();
    if ($user === null) {
        profile_api_json(401, ['status' => 'error', 'message' => 'Please sign in again to view your profile.']);
    }

    return $user;
}

function profile_api_validate_request_origin(): void
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        profile_api_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($originHost) || strcasecmp($originHost, (string) parse_url('//' . $requestHost, PHP_URL_HOST)) !== 0) {
            profile_api_json(403, ['status' => 'error', 'message' => 'This request is not allowed.']);
        }
    }
}

function profile_api_badges(): array
{
    return [
        'first_lesson' => [
            'name' => 'First Steps',
            'icon' => '📘',
            'hint' => 'Complete your first lesson.',
            'earned_hint' => 'First lesson completed',
        ],
        'quick_learner' => [
            'name' => 'Quick Learner',
            'icon' => '⚡',
            'hint' => 'Complete five lessons.',
            'earned_hint' => 'Five lessons completed',
        ],
        'quiz_master' => [
            'name' => 'Quiz Master',
            'icon' => '🧠',
            'hint' => 'Pass three quizzes.',
            'earned_hint' => 'Three quizzes passed',
        ],
        'course_completer' => [
            'name' => 'Course Completer',
            'icon' => '🎓',
            'hint' => 'Complete a course.',
            'earned_hint' => 'Course completed',
        ],
        'rising_star' => [
            'name' => 'Rising Star',
            'icon' => '🌟',
            'hint' => 'Earn 100 points.',
            'earned_hint' => '100 points earned',
        ],
    ];
}

function profile_api_load_data(array $user): array
{
    $pdo = db();
    $userId = (int) $user['id'];

    $loadProfile = $pdo->prepare(
        'SELECT name, username, email, photo, points, role, created_at, email_verified_at
         FROM users WHERE id = :id'
    );
    $loadProfile->execute(['id' => $userId]);
    $profile = $loadProfile->fetch();
    if (!$profile) {
        profile_api_json(401, ['status' => 'error', 'message' => 'Your account could not be found. Please sign in again.']);
    }

    $instructorData = null;
    if ($profile['role'] === 'instructor') {
        $loadTeachingStats = $pdo->prepare(
            'SELECT COUNT(DISTINCT c.id) AS total_courses,
                    COALESCE(SUM(c.status = "published"), 0) AS active_courses,
                    COUNT(e.id) AS learners,
                    AVG(e.rating) AS average_rating,
                    COUNT(e.rating) AS review_count
             FROM courses c
             LEFT JOIN enrollments e ON e.course_id = c.id
             WHERE c.instructor_id = :instructor_id'
        );
        $loadTeachingStats->execute(['instructor_id' => $userId]);
        $teachingStats = $loadTeachingStats->fetch();

        $loadCourses = $pdo->prepare(
            'SELECT c.title, c.status, COUNT(e.id) AS learners, AVG(e.rating) AS average_rating
             FROM courses c
             LEFT JOIN enrollments e ON e.course_id = c.id
             WHERE c.instructor_id = :instructor_id
             GROUP BY c.id, c.title, c.status, c.updated_at
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT 6'
        );
        $loadCourses->execute(['instructor_id' => $userId]);
        $instructorCourses = $loadCourses->fetchAll();

        $instructorData = [
            'total_courses' => (int) ($teachingStats['total_courses'] ?? 0),
            'active_courses' => (int) ($teachingStats['active_courses'] ?? 0),
            'learners' => (int) ($teachingStats['learners'] ?? 0),
            'average_rating' => $teachingStats['average_rating'] !== null
                ? (float) $teachingStats['average_rating']
                : null,
            'review_count' => (int) ($teachingStats['review_count'] ?? 0),
            'courses' => array_map(
                static fn(array $course): array => [
                    'title' => (string) $course['title'],
                    'status' => (string) $course['status'],
                    'learners' => (int) $course['learners'],
                    'average_rating' => $course['average_rating'] !== null
                        ? (float) $course['average_rating']
                        : null,
                ],
                $instructorCourses
            ),
        ];
    }

    $lessonCountQuery = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id = :user_id');
    $lessonCountQuery->execute(['user_id' => $userId]);
    $lessonCount = (int) $lessonCountQuery->fetchColumn();

    $passedQuizQuery = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = :user_id AND passed = 1');
    $passedQuizQuery->execute(['user_id' => $userId]);
    $passedQuizCount = (int) $passedQuizQuery->fetchColumn();

    $completedCourseQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE user_id = :user_id AND completed_at IS NOT NULL'
    );
    $completedCourseQuery->execute(['user_id' => $userId]);
    $completedCourseCount = (int) $completedCourseQuery->fetchColumn();

    $earnedBadgesQuery = $pdo->prepare('SELECT badge_key FROM user_badges WHERE user_id = :user_id');
    $earnedBadgesQuery->execute(['user_id' => $userId]);
    $earnedBadgeKeys = array_map('strval', $earnedBadgesQuery->fetchAll(PDO::FETCH_COLUMN));

    $leaders = $pdo->query(
        'SELECT id, name, photo, points
         FROM users
         WHERE role = \'student\' AND status = \'active\'
         ORDER BY points DESC, id ASC
         LIMIT 10'
    )->fetchAll();
    foreach ($leaders as &$leader) {
        $leader['id'] = (int) $leader['id'];
        $leader['points'] = (int) $leader['points'];
        $leader['photo_url'] = is_string($leader['photo']) && $leader['photo'] !== ''
            ? url($leader['photo'])
            : null;
        unset($leader['photo']);
    }
    unset($leader);

    $badges = profile_api_badges();
    $earnedSet = array_fill_keys($earnedBadgeKeys, true);
    $badgeProgress = [
        'first_lesson' => $lessonCount >= 1,
        'quick_learner' => $lessonCount >= 5,
        'quiz_master' => $passedQuizCount >= 3,
        'course_completer' => $completedCourseCount >= 1,
        'rising_star' => (int) $profile['points'] >= 100,
    ];
    foreach ($badges as $key => &$badge) {
        $badge['earned'] = isset($earnedSet[$key]);
        $badge['requirement_met'] = !$badge['earned'] && $badgeProgress[$key];
    }
    unset($badge);

    return [
        'profile' => [
            'name' => (string) $profile['name'],
            'username' => (string) ($profile['username'] ?? ''),
            'email' => (string) $profile['email'],
            'photo_url' => is_string($profile['photo']) && $profile['photo'] !== ''
                ? url($profile['photo'])
                : null,
            'points' => (int) $profile['points'],
            'role' => (string) $profile['role'],
            'joined_at' => date('M Y', (int) strtotime((string) $profile['created_at'])),
            'email_verified' => $profile['email_verified_at'] !== null,
        ],
        'instructor' => $instructorData,
        'badges' => $badges,
        'leaderboard' => $leaders,
        'user_id' => $userId,
    ];
}

function profile_api_upload_avatar(array $user): never
{
    $upload = $_FILES['avatar'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'])) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Choose an image to upload.']);
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $message = match ((int) $upload['error']) {
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image must be no larger than 5 MB.',
            default => 'The image could not be uploaded. Please try again.',
        };
        profile_api_json(400, ['status' => 'error', 'message' => $message]);
    }
    if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        profile_api_json(400, ['status' => 'error', 'message' => 'The image must be no larger than 5 MB.']);
    }
    if (!is_uploaded_file((string) $upload['tmp_name'])) {
        profile_api_json(400, ['status' => 'error', 'message' => 'The uploaded image is not valid.']);
    }

    $imageInfo = @getimagesize((string) $upload['tmp_name']);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
    $allowedImages = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_array($imageInfo) || !is_string($mimeType) || !isset($allowedImages[$mimeType])) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Upload a valid JPEG, PNG, or WebP image.']);
    }
    if ($imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
        profile_api_json(400, ['status' => 'error', 'message' => 'The image dimensions must not exceed 6000 by 6000 pixels.']);
    }

    $avatarDirectory = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0755, true) && !is_dir($avatarDirectory)) {
        throw new RuntimeException('The avatar directory could not be created.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedImages[$mimeType];
    $destination = $avatarDirectory . '/' . $filename;
    if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
        throw new RuntimeException('The profile image could not be saved.');
    }

    try {
        $avatarPath = 'uploads/avatars/' . $filename;
        $saveAvatar = db()->prepare('UPDATE users SET photo = :photo WHERE id = :id');
        $saveAvatar->execute(['photo' => $avatarPath, 'id' => $user['id']]);
    } catch (Throwable $error) {
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $error;
    }

    profile_api_json(200, [
        'status' => 'success',
        'message' => 'Your profile photo was updated.',
        'photo_url' => url($avatarPath),
    ]);
}

function profile_api_change_password(array $user): never
{
    $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
        ? $_POST['password_confirmation']
        : '';

    if ($currentPassword === '' || strlen($currentPassword) > 4096) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Enter your current password.']);
    }
    if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Your new password must be between 8 and 72 characters.']);
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Password must contain at least one uppercase letter.']);
    }
    if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Password must contain at least one special character.']);
    }
    if ($newPassword !== $passwordConfirmation) {
        profile_api_json(400, ['status' => 'error', 'message' => 'The new passwords do not match.']);
    }

    $findPassword = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $findPassword->execute(['id' => $user['id']]);
    $passwordHash = $findPassword->fetchColumn();
    if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
        profile_api_json(400, ['status' => 'error', 'message' => 'Your current password is incorrect.']);
    }

    $updatePassword = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $updatePassword->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $user['id'],
    ]);

    profile_api_json(200, ['status' => 'success', 'message' => 'Your password was changed.']);
}

try {
    $user = profile_api_current_user();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        profile_api_json(200, ['status' => 'success', 'data' => profile_api_load_data($user)]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        profile_api_json(405, ['status' => 'error', 'message' => 'This endpoint accepts GET and POST requests.']);
    }

    profile_api_validate_request_origin();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'upload_avatar') {
        profile_api_upload_avatar($user);
    }
    if ($action === 'change_password') {
        profile_api_change_password($user);
    }

    profile_api_json(400, ['status' => 'error', 'message' => 'Choose a valid profile action.']);
} catch (Throwable $error) {
    error_log('Profile API failed: ' . $error->getMessage());
    profile_api_json(500, ['status' => 'error', 'message' => 'Your profile request could not be completed. Please try again.']);
}