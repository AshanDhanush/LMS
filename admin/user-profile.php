<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$admin = currentUser();
if ($admin === null) {
    redirect('');
}
if ($admin['role'] !== 'admin') {
    redirect(dashboardPath((string) $admin['role']));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function userProfileInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8') ?: '?';
}

$hasExplicitSelection = $_SERVER['REQUEST_METHOD'] === 'POST' || array_key_exists('id', $_GET);
if ($hasExplicitSelection) {
    $selectionValue = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['user_id'] ?? null)
        : ($_GET['id'] ?? null);
    $selectedUserId = filter_var($selectionValue, FILTER_VALIDATE_INT);
    if ($selectedUserId !== false && $selectedUserId !== null && $selectedUserId > 0) {
        $_SESSION['admin_profile_user_id'] = (int) $selectedUserId;
        redirect('admin/user-profile');
    }
    $userId = false;
} else {
    $userId = filter_var($_SESSION['admin_profile_user_id'] ?? null, FILTER_VALIDATE_INT);
}
$profileUser = null;
$loadError = false;

if ($userId !== false && $userId !== null && $userId > 0) {
    try {
        $loadUser = db()->prepare(
            'SELECT id, name, username, email, role, status, photo, points, email_verified_at, created_at
             FROM users
             WHERE id = :id'
        );
        $loadUser->execute(['id' => $userId]);
        $profileUser = $loadUser->fetch() ?: null;
    } catch (Throwable $error) {
        error_log('Admin user profile failed to load: ' . $error->getMessage());
        $loadError = true;
    }
}

if ($profileUser === null && !$loadError) {
    http_response_code(404);
}
?>
<?php
$adminPageTitle = ($profileUser ? (string) $profileUser['name'] . ' | User Profile' : 'User Profile') . ' | Learning Hub Admin';
$adminActivePage = 'users';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero admin-user-profile-hero">
                    <div>
                        <h1>User profile</h1>
                        <p>Read-only account details.</p>
                    </div>
                    <a class="admin-btn admin-back-to-dashboard" href="<?= h(url('admin/dashboard')) ?>">Back to dashboard</a>
                </section>
                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The user details could not be loaded. Please try again.</p>
                <?php elseif ($profileUser === null): ?>
                    <p class="admin-alert" role="alert">This user could not be found.</p>
                <?php else: ?>
                    <section class="admin-card admin-user-profile" aria-labelledby="user-profile-name">
                        <div class="admin-user-profile__identity">
                            <?php if (is_string($profileUser['photo']) && $profileUser['photo'] !== ''): ?>
                                <img class="admin-user-profile__avatar" src="<?= h(url($profileUser['photo'])) ?>" alt="">
                            <?php else: ?>
                                <span class="admin-user-profile__avatar" aria-hidden="true"><?= h(userProfileInitials((string) $profileUser['name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <h2 id="user-profile-name"><?= h($profileUser['name']) ?></h2>
                                <span class="admin-chip admin-chip--<?= h($profileUser['role']) ?>"><?= h(ucfirst($profileUser['role'])) ?></span>
                            </div>
                        </div>
                        <dl class="admin-user-profile__details">
                            <div><dt>Email</dt><dd><?= h($profileUser['email']) ?></dd></div>
                            <div><dt>Username</dt><dd><?= h($profileUser['username'] ?: 'Not set') ?></dd></div>
                            <div><dt>Status</dt><dd><?= h(ucfirst($profileUser['status'])) ?></dd></div>
                            <div><dt>Email verified</dt><dd><?= $profileUser['email_verified_at'] !== null ? 'Yes' : 'No' ?></dd></div>
                            <div><dt>Points</dt><dd><?= number_format((int) $profileUser['points']) ?></dd></div>
                            <div><dt>Joined</dt><dd><?= h(date('j M Y', (int) strtotime($profileUser['created_at']))) ?></dd></div>
                        </dl>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>