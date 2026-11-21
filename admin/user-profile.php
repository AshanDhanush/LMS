<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../api/api_admin_course_service.php';   // adminCsrfToken() / adminCsrfValid()

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

$csrfToken = adminCsrfToken();
$formErrors = [];
$updatedNotice = isset($_GET['updated']);

/* ---------------- Save changes (name, email, role) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $newName  = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    $newEmail = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $newRole  = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

    try {
        $pdo = db();
        $find = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
        $find->execute(['id' => $targetId ?: 0]);
        $target = $find->fetch();

        if (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
            $formErrors[] = 'Your session expired or the request could not be verified. Reload the page and try again.';
        } elseif ($target === false) {
            $formErrors[] = 'That user could not be found.';
        } else {
            $_SESSION['admin_profile_user_id'] = (int) $target['id'];
            $isSelf = (int) $target['id'] === (int) $admin['id'];

            if ($isSelf) {
                $newRole = 'admin';   // the role box is locked for your own account
            }
            if (($problem = plainTextProblem($newName, 'Name', 100)) !== null) {
                $formErrors[] = $problem;
            }
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 190) {
                $formErrors[] = 'Enter a valid email address.';
            }
            if (!in_array($newRole, ['student', 'instructor', 'admin'], true)) {
                $formErrors[] = 'Choose a valid role.';
            }

            if (!$formErrors) {
                $duplicate = $pdo->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $duplicate->execute(['email' => $newEmail, 'id' => $target['id']]);
                if ($duplicate->fetchColumn() !== false) {
                    $formErrors[] = 'Another account already uses this email address.';
                }
            }
            if (!$formErrors && $newRole !== $target['role'] && $target['role'] === 'instructor') {
                $owned = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE instructor_id = :id');
                $owned->execute(['id' => $target['id']]);
                $courseCount = (int) $owned->fetchColumn();
                if ($courseCount > 0) {
                    $formErrors[] = 'This instructor still owns ' . $courseCount . ' course(s). Give those courses to another instructor, or delete them, before changing the role.';
                }
            }

            if (!$formErrors) {
                $changes = [];
                foreach (['name' => $newName, 'email' => $newEmail, 'role' => $newRole] as $field => $value) {
                    if ((string) $target[$field] !== $value) {
                        $changes[$field . '_from'] = (string) $target[$field];
                        $changes[$field . '_to'] = $value;
                    }
                }
                if ($changes) {
                    $pdo->prepare('UPDATE users SET name = :name, email = :email, role = :role WHERE id = :id')
                        ->execute(['name' => $newName, 'email' => $newEmail, 'role' => $newRole, 'id' => $target['id']]);
                    $summary = isset($changes['role_to'])
                        ? 'Changed role of ' . $newName . ' from ' . $target['role'] . ' to ' . $newRole
                        : 'Updated account details of ' . $newName;
                    auditLog($pdo, $admin, isset($changes['role_to']) ? 'user.role' : 'user.update', 'user', (int) $target['id'], $summary, $changes);
                }
                redirect('admin/user-profile?updated=1');
            }
        }
    } catch (PDOException $error) {
        error_log('Admin user update failed: ' . $error->getMessage());
        $formErrors[] = $error->getCode() === '23000'
            ? 'Another account already uses this email address.'
            : 'The changes could not be saved. Please try again.';
    } catch (Throwable $error) {
        error_log('Admin user update failed: ' . $error->getMessage());
        $formErrors[] = 'The changes could not be saved. Please try again.';
    }
}

/* ---------------- Which user to show ---------------- */
// Opening a profile (a link with ?id=, or the search box posting user_id) remembers the user and shows a clean address.
$isSelection = array_key_exists('id', $_GET)
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']));
if ($isSelection) {
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

// After a failed save, show what the admin typed instead of the stored values.
$formValues = [
    'name'  => $formErrors && isset($newName) ? $newName : (string) ($profileUser['name'] ?? ''),
    'email' => $formErrors && isset($newEmail) ? $newEmail : (string) ($profileUser['email'] ?? ''),
    'role'  => $formErrors && isset($newRole) ? $newRole : (string) ($profileUser['role'] ?? 'student'),
];
$isOwnProfile = $profileUser !== null && (int) $profileUser['id'] === (int) $admin['id'];
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
                        <p>Account details. You can change the name, email and role.</p>
                    </div>
                    <a class="admin-btn admin-back-to-dashboard" href="<?= h(url('admin/users')) ?>">Back to users</a>
                </section>
                <?php if ($updatedNotice): ?>
                    <p class="admin-alert admin-alert--success" role="status">The account was updated.</p>
                <?php endif; ?>
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

                    <section class="admin-card admin-user-form-card admin-user-profile" aria-labelledby="edit-user-title">
                        <div class="admin-card__head"><h2 id="edit-user-title">Edit account</h2></div>
                        <?php foreach ($formErrors as $error): ?>
                            <p class="admin-alert" role="alert"><?= h($error) ?></p>
                        <?php endforeach; ?>
                        <form class="admin-user-form" method="post" action="<?= h(url('admin/user-profile')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="user_id" value="<?= (int) $profileUser['id'] ?>">
                            <div class="admin-user-form__field">
                                <label for="edit-user-name">Full name</label>
                                <input id="edit-user-name" name="name" type="text" maxlength="100" value="<?= h($formValues['name']) ?>" required>
                            </div>
                            <div class="admin-user-form__field">
                                <label for="edit-user-email">Email address</label>
                                <input id="edit-user-email" name="email" type="email" maxlength="190" value="<?= h($formValues['email']) ?>" required>
                            </div>
                            <div class="admin-user-form__field">
                                <label for="edit-user-role">Role</label>
                                <select id="edit-user-role" name="role" <?= $isOwnProfile ? 'disabled aria-describedby="edit-user-role-note"' : '' ?>>
                                    <?php foreach (['student' => 'Student', 'instructor' => 'Instructor', 'admin' => 'Admin'] as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= $formValues['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($isOwnProfile): ?>
                                    <p class="admin-card__note" id="edit-user-role-note">You cannot change your own role.</p>
                                <?php else: ?>
                                    <p class="admin-card__note">The new role applies the next time the person opens a page.</p>
                                <?php endif; ?>
                            </div>
                            <button class="admin-btn admin-btn--primary admin-btn--inline" type="submit"><span>Save changes</span></button>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>
