<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$user = currentUser();
if ($user === null) {
    redirect('');
}
if ($user['role'] !== 'admin') {
    redirect(dashboardPath($user['role']));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

function sendAccountCredentials(string $name, string $email, string $username, string $password): bool
{
    $safeName = h($name);
    $safeUsername = h($username);
    $safePassword = h($password);
    $loginUrl = h(absUrl(''));
    $profileUrl = h(absUrl('profile'));
    $message = '<p>Hello ' . $safeName . ',</p>'
        . '<p>An account has been created for you on Learning Hub.</p>'
        . '<p><strong>Username:</strong> ' . $safeUsername . '<br>'
        . '<strong>Temporary password:</strong> ' . $safePassword . '</p>'
        . '<p><a href="' . $loginUrl . '">Sign in to Learning Hub</a>. '
        . 'After signing in, change your password from your <a href="' . $profileUrl . '">profile</a>.</p>';

    return sendMail($email, 'Your Learning Hub account', $message);
}

function readUserImportCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The CSV file could not be read.');
    }

    try {
        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($headers)) {
            throw new RuntimeException('The CSV file is empty or does not contain a header row.');
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $expectedHeaders = ['first name', 'last name', 'email', 'role'];
        $actualHeaders = array_map(
            static fn($header): string => strtolower(trim((string) $header)),
            $headers
        );
        if ($actualHeaders !== $expectedHeaders) {
            throw new RuntimeException('The CSV header must contain exactly these columns in order: First name, Last name, email, role.');
        }

        $rows = [];
        $seenEmails = [];
        $csvRow = 1;
        while (($columns = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $csvRow++;
            if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                continue;
            }

            if (count($columns) !== count($expectedHeaders)) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must contain exactly four columns.');
            }

            foreach ($columns as $column) {
                if (!mb_check_encoding((string) $column, 'UTF-8')) {
                    throw new RuntimeException('CSV row ' . $csvRow . ' contains text that is not valid UTF-8.');
                }
            }

            if (count($rows) >= 500) {
                throw new RuntimeException('A CSV file can contain no more than 500 users.');
            }

            $firstName = trim((string) $columns[0]);
            $lastName = trim((string) $columns[1]);
            $email = trim((string) $columns[2]);
            $role = strtolower(trim((string) $columns[3]));
            $name = trim($firstName . ' ' . $lastName);

            if ($firstName === '' || $lastName === '' || mb_strlen($name, 'UTF-8') > 100) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must contain first and last names (up to 100 characters total).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw new RuntimeException('CSV row ' . $csvRow . ' does not contain a valid email address.');
            }
            if (!in_array($role, ['student', 'instructor', 'admin'], true)) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must use the student, instructor, or admin role.');
            }

            $emailKey = strtolower($email);
            if (isset($seenEmails[$emailKey])) {
                throw new RuntimeException('The email address on CSV row ' . $csvRow . ' appears more than once.');
            }
            $seenEmails[$emailKey] = true;
            $rows[] = [
                'name' => $name,
                'last_name' => $lastName,
                'email' => $email,
                'role' => $role,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('The CSV file does not contain any users.');
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

$formError = '';
$emailError = '';
$firstNameError = '';
$lastNameError = '';
$statusError = '';
$importError = '';
$importSuccess = '';
$importEmailFailures = [];
$formFirstName = '';
$formLastName = '';
$formEmail = '';
$formRole = 'student';
$usersError = false;
$users = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

    if ($userId === false || $userId === null || $userId < 1) {
        $statusError = 'Select a valid user.';
    } elseif (!in_array($newStatus, ['active', 'inactive'], true)) {
        $statusError = 'Select a valid account status.';
    } elseif ((int) $userId === (int) $user['id'] && $newStatus === 'inactive') {
        $statusError = 'You cannot deactivate your own administrator account.';
    } else {
        try {
            $findUser = db()->prepare('SELECT id FROM users WHERE id = :id');
            $findUser->execute(['id' => $userId]);
            if ($findUser->fetchColumn() === false) {
                $statusError = 'The selected user could not be found.';
            } else {
                $updateStatus = db()->prepare('UPDATE users SET status = :status WHERE id = :id');
                $updateStatus->execute(['status' => $newStatus, 'id' => $userId]);
                redirect('admin/users?status_updated=1');
            }
        } catch (Throwable $error) {
            error_log('Admin user status update failed: ' . $error->getMessage());
            $statusError = 'The user status could not be updated. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $upload = $_FILES['users_csv'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'])) {
        $importError = 'Choose a CSV file to upload.';
    } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $importError = match ((int) $upload['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The CSV file must be no larger than 2 MB.',
            UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload.',
            default => 'The CSV file could not be uploaded. Please try again.',
        };
    } elseif (!is_uploaded_file((string) $upload['tmp_name'])) {
        $importError = 'The uploaded CSV file is not valid.';
    } elseif (strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        $importError = 'Upload a CSV file with a .csv extension.';
    } elseif ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        $importError = 'The CSV file must be no larger than 2 MB.';
    } else {
        $pdo = null;
        try {
            $importRows = readUserImportCsv((string) $upload['tmp_name']);
            $pdo = db();
            $pdo->beginTransaction();

            $findEmail = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $findUsername = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
            $insertUser = $pdo->prepare(
                'INSERT INTO users (name, username, email, password_hash, role, status, email_verified_at)
                 VALUES (:name, :username, :email, :password_hash, :role, \'active\', CURRENT_TIMESTAMP)'
            );
            $createdUsers = [];
            $plannedUsernames = [];

            foreach ($importRows as $index => $importRow) {
                $findEmail->execute(['email' => $importRow['email']]);
                if ($findEmail->fetchColumn() !== false) {
                    throw new RuntimeException('An account with ' . $importRow['email'] . ' already exists.');
                }

                $orderNumber = $index + 1;
                do {
                    $username = generateUsername($importRow['last_name'], $orderNumber++);
                    $findUsername->execute(['username' => $username]);
                    $usernameExists = $findUsername->fetchColumn() !== false
                        || isset($plannedUsernames[strtolower($username)]);
                } while ($usernameExists);

                $plannedUsernames[strtolower($username)] = true;
                $password = generateTemporaryPassword();
                $insertUser->execute([
                    'name' => $importRow['name'],
                    'username' => $username,
                    'email' => $importRow['email'],
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $importRow['role'],
                ]);
                $createdUsers[] = $importRow + [
                    'username' => $username,
                    'password' => $password,
                ];
            }

            $pdo->commit();

            foreach ($createdUsers as $createdUser) {
                if (!sendAccountCredentials(
                    $createdUser['name'],
                    $createdUser['email'],
                    $createdUser['username'],
                    $createdUser['password']
                )) {
                    $importEmailFailures[] = $createdUser['email'];
                }
            }

            $importSuccess = count($createdUsers) . ' user account(s) were created.';
        } catch (PDOException $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin CSV user import failed: ' . $error->getMessage());
            $importError = 'The users could not be imported. Check the CSV and try again.';
        } catch (RuntimeException $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $importError = $error->getMessage();
        } catch (Throwable $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin CSV user import failed: ' . $error->getMessage());
            $importError = 'The users could not be imported. Check the CSV and try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $formFirstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
    $formLastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
    $formEmail = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $formRole = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $formName = trim($formFirstName . ' ' . $formLastName);

    if ($formFirstName === '' || mb_strlen($formFirstName, 'UTF-8') > 100) {
        $firstNameError = 'Enter a first name of up to 100 characters.';
    } elseif ($formLastName === '' || mb_strlen($formLastName, 'UTF-8') > 100) {
        $lastNameError = 'Enter a last name of up to 100 characters.';
    } elseif (mb_strlen($formName, 'UTF-8') > 100) {
        $lastNameError = 'The full name must be no longer than 100 characters.';
    } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL) || strlen($formEmail) > 190) {
        $emailError = 'Enter a valid email address.';
    } elseif (!in_array($formRole, ['student', 'instructor', 'admin'], true)) {
        $formError = 'Choose a valid user role.';
    } else {
        try {
            $findEmail = db()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $findEmail->execute(['email' => $formEmail]);
            if ($findEmail->fetchColumn() !== false) {
                $emailError = 'An account with this email address already exists.';
            } else {
                $findUsername = db()->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
                $orderNumber = 1;
                do {
                    $username = generateUsername($formLastName, $orderNumber++);
                    $findUsername->execute(['username' => $username]);
                } while ($findUsername->fetchColumn() !== false);

                $password = generateTemporaryPassword();
                $insertUser = db()->prepare(
                    'INSERT INTO users (name, username, email, password_hash, role, status, email_verified_at)
                     VALUES (:name, :username, :email, :password_hash, :role, \'active\', CURRENT_TIMESTAMP)'
                );
                $insertUser->execute([
                    'name' => $formName,
                    'username' => $username,
                    'email' => $formEmail,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $formRole,
                ]);
                $emailSent = sendAccountCredentials($formName, $formEmail, $username, $password);
                redirect('admin/users?created=1' . ($emailSent ? '' : '&email_failed=1'));
            }
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                $emailError = 'An account with this email address already exists.';
            } else {
                error_log('Admin user creation failed: ' . $error->getMessage());
                $formError = 'The account could not be created. Please try again.';
            }
        } catch (Throwable $error) {
            error_log('Admin user creation failed: ' . $error->getMessage());
            $formError = 'The account could not be created. Please try again.';
        }
    }
}

$userSearch = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$userSearch = mb_substr($userSearch, 0, 100, 'UTF-8');

try {
    $userQuery = 'SELECT id, name, username, email, role, status, created_at FROM users';
    if ($userSearch !== '') {
        $userQuery .= ' WHERE name LIKE :name OR email LIKE :email';
    }
    $userQuery .= ' ORDER BY created_at DESC, id DESC LIMIT 100';
    $loadUsers = db()->prepare($userQuery);
    $searchTerm = '%' . $userSearch . '%';
    $loadUsers->execute($userSearch !== '' ? ['name' => $searchTerm, 'email' => $searchTerm] : []);
    $users = $loadUsers->fetchAll();
} catch (Throwable $error) {
    error_log('Admin users page failed to load: ' . $error->getMessage());
    $usersError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

    if ($userId === false || $userId === null || $userId < 1) {
        $statusError = 'Select a valid user.';
    } elseif ((int) $userId === (int) $user['id']) {
        $statusError = 'You cannot delete your own administrator account.';
    } else {
        try {
            $findUser = db()->prepare('SELECT id FROM users WHERE id = :id');
            $findUser->execute(['id' => $userId]);
            if ($findUser->fetchColumn() === false) {
                $statusError = 'The selected user could not be found.';
            } else {
                $deleteUser = db()->prepare('DELETE FROM users WHERE id = :id');
                $deleteUser->execute(['id' => $userId]);
                redirect('admin/users?deleted=1');
            }
        } catch (Throwable $error) {
            error_log('Admin user deletion failed: ' . $error->getMessage());
            $statusError = 'The user could not be deleted. Please try again.';
        }
    }
}
?>
<?php
$adminPageTitle = 'Users | Learning Hub Admin';
$adminActivePage = 'users';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero">
                    <h1>Users</h1>
                    <p>Manage platform accounts and add new users.</p>
                </section>

                <?php if (isset($_GET['created'])): ?>
                    <p class="admin-alert admin-alert--success" role="status">The user account was created.</p>
                <?php endif; ?>
                <?php if (isset($_GET['email_failed'])): ?>
                    <p class="admin-alert" role="alert">The account was created, but the credentials email could not be sent. Check the mail configuration and contact the user.</p>
                <?php endif; ?>
                <?php if (isset($_GET['status_updated'])): ?>
                    <p class="admin-alert admin-alert--success" role="status">The user status was updated.</p>
                <?php endif; ?>
                <?php if ($importSuccess !== ''): ?>
                    <p class="admin-alert admin-alert--success" role="status"><?= h($importSuccess) ?></p>
                <?php endif; ?>
                <?php if ($importEmailFailures !== []): ?>
                    <p class="admin-alert" role="alert">
                        Accounts were created, but email could not be sent to:
                        <?= h(implode(', ', $importEmailFailures)) ?>. Check the mail configuration and contact these users.
                    </p>
                <?php endif; ?>
                <?php if ($importError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($importError) ?></p>
                <?php endif; ?>
                <?php if ($statusError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($statusError) ?></p>
                <?php endif; ?>

                <section class="admin-card admin-user-form-card" aria-labelledby="create-user-title">
                    <div class="admin-card__head">
                        <h2 id="create-user-title">Add a user</h2>
                    </div>
                    <?php if ($formError !== ''): ?>
                        <p class="admin-alert" role="alert"><?= h($formError) ?></p>
                    <?php endif; ?>
                    <form class="admin-user-form" method="post">
                        <input type="hidden" name="action" value="create_user">
                        <div class="admin-user-form__field">
                            <label for="first-name">First name</label>
                            <input id="first-name" name="first_name" type="text" maxlength="100" autocomplete="given-name" value="<?= h($formFirstName) ?>" required>
                            <?php if ($firstNameError !== ''): ?>
                                <p class="admin-user-form__field-error" role="alert"><?= h($firstNameError) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="last-name">Last name</label>
                            <input id="last-name" name="last_name" type="text" maxlength="100" autocomplete="family-name" value="<?= h($formLastName) ?>" required>
                            <?php if ($lastNameError !== ''): ?>
                                <p class="admin-user-form__field-error" role="alert"><?= h($lastNameError) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= h($formEmail) ?>" required>
                            <?php if ($emailError !== ''): ?>
                                <p class="admin-user-form__field-error" role="alert"><?= h($emailError) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="role">Role</label>
                            <select id="role" name="role" required>
                                <option value="student" <?= $formRole === 'student' ? 'selected' : '' ?>>Student</option>
                                <option value="instructor" <?= $formRole === 'instructor' ? 'selected' : '' ?>>Instructor</option>
                                <option value="admin" <?= $formRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>
                        <button class="admin-btn admin-btn--primary" type="submit"><span>Add user</span></button>
                    </form>
                </section>

                <section class="admin-card admin-user-form-card" aria-labelledby="import-users-title">
                    <div class="admin-card__head">
                        <h2 id="import-users-title">Add users from CSV</h2>
                    </div>
                    <p>Upload a CSV with these headings: <strong>First name, Last name, email, role</strong>. Roles must be student, instructor, or admin. A username and temporary 8-character password will be generated for each user and emailed to them.</p>
                    <form class="admin-user-form" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_csv">
                        <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                        <div class="admin-user-form__field">
                            <label for="users-csv">CSV file (maximum 2 MB, 500 users)</label>
                            <input id="users-csv" name="users_csv" type="file" accept=".csv,text/csv" required>
                        </div>
                        <button class="admin-btn admin-btn--primary" type="submit"><span>Import users</span></button>
                    </form>
                </section>

                <section class="admin-card admin-users-list" aria-labelledby="users-list-title">
                    <div class="admin-card__head">
                        <h2 id="users-list-title"><?= $userSearch !== '' ? 'Search results' : 'Existing users' ?></h2>
                        <span class="admin-card__note"><?= $userSearch !== '' ? 'Matches for "' . h($userSearch) . '" (up to 100)' : 'Newest 100' ?></span>
                    </div>
                    <?php if ($usersError): ?>
                        <p class="admin-alert" role="alert">Users could not be loaded. Please reload the page.</p>
                    <?php elseif (!$users): ?>
                        <p class="admin-empty"><?= $userSearch !== '' ? 'No users match that name.' : 'No users yet.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col">User</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Joined</th>
                                        <th scope="col">Action</th>
                                        
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $row): ?>
                                        <tr>
                                            <td>
                                                <span class="admin-person">
                                                    <span class="admin-person__avatar" aria-hidden="true"><?= h(initials($row['name'])) ?></span>
                                                    <span class="admin-person__text">
                                                        <span class="admin-person__name"><?= h($row['name']) ?></span>
                                                        <span class="admin-person__email"><?= h($row['email']) ?></span>
                                                        <?php if ($row['username'] !== null): ?>
                                                            <span class="admin-person__email">Username: <?= h($row['username']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </span>
                                            </td>
                                            <td><span class="admin-chip admin-chip--<?= h($row['role']) ?>"><?= h(ucfirst($row['role'])) ?></span></td>
                                            <td>
                                                <form class="admin-status-form" method="post">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                    <label class="admin-sr-only" for="user-status-<?= (int) $row['id'] ?>">Status for <?= h($row['name']) ?></label>
                                                    <select id="user-status-<?= (int) $row['id'] ?>" name="status" aria-label="Status for <?= h($row['name']) ?>">
                                                        <option value="active" <?= $row['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                        <option value="inactive" <?= $row['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="admin-table__date"><?= h(date('j M Y', (int) strtotime($row['created_at']))) ?></td>
                                            <td>
                                                <form
                                                    method="post"
                                                    class="admin-delete-form"
                                                    onsubmit = "return confirm('Are you sure you want to delete the user <?= h($row['name']) ?>? This action cannot be undone.')"
                                                >
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                    <button class="admin-btn admin-delete-btn" type="submit" aria-label="Delete user <?= h($row['name']) ?>" title="Delete user">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    </button>


                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
    <script src="<?= h(url('assests/auth.js')) ?>" defer></script>

    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>
