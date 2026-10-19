<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$user = currentUser();
if ($user === null) {
    redirect('');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errorMessage = '';
$passwordUpdated = isset($_GET['password_updated']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
        ? $_POST['password_confirmation']
        : '';

    if ($currentPassword === '' || strlen($currentPassword) > 4096) {
        $errorMessage = 'Enter your current password.';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        $errorMessage = 'Your new password must be between 8 and 72 characters.';
    } elseif ($newPassword !== $passwordConfirmation) {
        $errorMessage = 'The new passwords do not match.';
    } else {
        try {
            $findPassword = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
            $findPassword->execute(['id' => $user['id']]);
            $passwordHash = $findPassword->fetchColumn();

            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                $errorMessage = 'Your current password is incorrect.';
            } else {
                $updatePassword = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $updatePassword->execute([
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
                redirect('profile?password_updated=1');
            }
        } catch (Throwable $error) {
            error_log('Profile password update failed: ' . $error->getMessage());
            $errorMessage = 'Your password could not be changed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Learning Hub</title>
    <link rel="stylesheet" href="<?= h(url('assests/theme.css')) ?>">
    <link rel="stylesheet" href="<?= h(url('assests/dashboard.css')) ?>">
</head>
<body class="admin">
    <main class="admin-main" id="main">
        <div class="admin-container">
            <section class="admin-hero">
                <h1>Your profile</h1>
                <p><?= h($user['name']) ?> | <?= h($user['email']) ?></p>
            </section>
            <section class="admin-card admin-user-form-card" aria-labelledby="change-password-title">
                <div class="admin-card__head">
                    <h2 id="change-password-title">Change password</h2>
                </div>
                <?php if ($passwordUpdated): ?>
                    <p class="admin-alert admin-alert--success" role="status">Your password was changed.</p>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($errorMessage) ?></p>
                <?php endif; ?>
                <form class="admin-user-form" method="post">
                    <div class="admin-user-form__field">
                        <label for="current-password">Current password</label>
                        <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="admin-user-form__field">
                        <label for="new-password">New password</label>
                        <input id="new-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div class="admin-user-form__field">
                        <label for="password-confirmation">Confirm new password</label>
                        <input id="password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <button class="admin-btn admin-btn--primary" type="submit"><span>Change password</span></button>
                </form>
            </section>
            <p><a href="<?= h(url(dashboardPath($user['role']))) ?>">Return to dashboard</a></p>
        </div>
    </main>
</body>
</html>