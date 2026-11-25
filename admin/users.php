<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../api/api_assignment_service.php';   // assessmentDeleteStoredFile()
require_once __DIR__ . '/../api/data_cleanup.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../api/api_admin_course_service.php';   // adminCsrfToken() for the bulk form

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
    $message = renderEmailTemplate('welcome', [
        'name' => $name,
        'username' => $username,
        'temporary_password' => $password,
        'login_url' => absUrl(''),
        'profile_url' => absUrl('profile'),
    ]);

    return sendMail($email, 'Your Learning Hub account', $message);
}

function readUserImportCsv(string $path, bool $isPro = false): array
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
        $actualHeaders = array_map(
            static fn($header): string => strtolower(trim((string) $header)),
            $headers
        );

        $hasPasswordCol = (count($actualHeaders) === 5 && $actualHeaders[4] === 'password');
        $isFourCol = (count($actualHeaders) === 4 && $actualHeaders === ['first name', 'last name', 'email', 'role']);

        if (!$isPro && !$hasPasswordCol) {
            throw new RuntimeException('On the Free plan, the CSV header must contain five columns: First name, Last name, email, role, password.');
        }

        if ($isPro && !$isFourCol && !$hasPasswordCol) {
            throw new RuntimeException('The CSV header must contain: First name, Last name, email, role (optional 5th column: password).');
        }

        $expectedCount = $hasPasswordCol ? 5 : 4;
        $rows = [];
        $seenEmails = [];
        $csvRow = 1;
        while (($columns = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $csvRow++;
            if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                continue;
            }

            if (count($columns) !== $expectedCount) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must contain exactly ' . $expectedCount . ' columns.');
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
            $password = $hasPasswordCol ? trim((string) $columns[4]) : null;
            $name = trim($firstName . ' ' . $lastName);

            if ($firstName === '' || $lastName === '' || mb_strlen($name, 'UTF-8') > 100) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must contain first and last names (up to 100 characters total).');
            }
            if (plainTextProblem($name, 'The name', 100) !== null) {
                throw new RuntimeException('CSV row ' . $csvRow . ' has a name with characters that are not allowed (such as < or >).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw new RuntimeException('CSV row ' . $csvRow . ' does not contain a valid email address.');
            }
            if (!in_array($role, ['student', 'instructor', 'admin'], true)) {
                throw new RuntimeException('CSV row ' . $csvRow . ' must use the student, instructor, or admin role.');
            }
            if ($hasPasswordCol && (strlen((string) $password) < 8 || strlen((string) $password) > 72)) {
                throw new RuntimeException('CSV row ' . $csvRow . ' password must be between 8 and 72 characters.');
            }
            if (!$isPro && empty($password)) {
                throw new RuntimeException('CSV row ' . $csvRow . ' requires a password on the Free plan.');
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
                'password' => $password,
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

$isPro = isPro($user);
$formError = '';
$emailError = '';
$firstNameError = '';
$lastNameError = '';
$passwordError = '';
$statusError = '';
$importError = '';
$importSuccess = '';
$importEmailFailures = [];
$formFirstName = '';
$formLastName = '';
$formEmail = '';
$formPassword = '';
$formRole = 'student';
$usersError = false;
$users = [];

/* Every change goes through ONE gate: the secret form token must match and the request must come from this site. */
$postAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $importError = 'That upload is too large. A CSV file can be at most 2 MB.';
        $postAction = '';
    } elseif (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        $statusError = 'Your session expired or the request could not be verified. Reload the page and try again.';
        $postAction = '';
    }
}

if ($postAction === 'update_status') {
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
            $findUser = db()->prepare('SELECT id, name, email, status FROM users WHERE id = :id');
            $findUser->execute(['id' => $userId]);
            $target = $findUser->fetch();
            if ($target === false) {
                $statusError = 'The selected user could not be found.';
            } else {
                $updateStatus = db()->prepare('UPDATE users SET status = :status WHERE id = :id');
                $updateStatus->execute(['status' => $newStatus, 'id' => $userId]);
                if ($target['status'] !== $newStatus) {
                    auditLog(db(), $user, 'user.status', 'user', (int) $userId, ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' account of ' . $target['name'], ['email' => $target['email'], 'status_from' => $target['status'], 'status_to' => $newStatus]);
                }
                redirect('admin/users?status_updated=1');
            }
        } catch (Throwable $error) {
            error_log('Admin user status update failed: ' . $error->getMessage());
            $statusError = 'The user status could not be updated. Please try again.';
        }
    }
}

if ($postAction === 'import_csv') {
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
            $importRows = readUserImportCsv((string) $upload['tmp_name'], $isPro);
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

                if (!empty($importRow['password'])) {
                    $password = (string) $importRow['password'];
                } elseif ($isPro) {
                    $password = generateTemporaryPassword();
                } else {
                    throw new RuntimeException('CSV row ' . ($index + 2) . ': password is required on the Free plan.');
                }

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

            auditLog($pdo, $user, 'user.import', 'user', null, 'Imported ' . count($createdUsers) . ' user(s) from a CSV file',
                ['count' => count($createdUsers), 'file' => (string) ($upload['name'] ?? ''), 'is_pro' => $isPro, 'emails' => implode(', ', array_slice(array_column($createdUsers, 'email'), 0, 50))]);

            if ($isPro) {
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

                $importSuccess = count($createdUsers) . ' user account(s) were created and emailed their credentials.';
            } else {
                $importSuccess = count($createdUsers) . ' user account(s) were created with the passwords from your CSV. (Credentials were not emailed because email delivery requires the Pro plan).';
            }
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

if ($postAction === 'create_user') {
    $formFirstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
    $formLastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
    $formEmail = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $formRole = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $formPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $formName = trim($formFirstName . ' ' . $formLastName);

    if (($problem = plainTextProblem($formFirstName, 'First name', 100)) !== null) {
        $firstNameError = $problem;
    } elseif (($problem = plainTextProblem($formLastName, 'Last name', 100)) !== null) {
        $lastNameError = $problem;
    } elseif (($problem = plainTextProblem($formName, 'The full name', 100)) !== null) {
        $lastNameError = $problem;
    } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL) || strlen($formEmail) > 190) {
        $emailError = 'Enter a valid email address.';
    } elseif (!in_array($formRole, ['student', 'instructor', 'admin'], true)) {
        $formError = 'Choose a valid user role.';
    } elseif (!$isPro && (strlen($formPassword) < 8 || strlen($formPassword) > 72)) {
        $passwordError = 'Password is required on the Free plan (between 8 and 72 characters).';
    } elseif ($isPro && $formPassword !== '' && (strlen($formPassword) < 8 || strlen($formPassword) > 72)) {
        $passwordError = 'Password must be between 8 and 72 characters if provided.';
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

                // If Pro and no password provided, auto-generate temporary password. Otherwise use manual password.
                $password = ($isPro && $formPassword === '')
                    ? generateTemporaryPassword()
                    : $formPassword;

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
                $newUserId = (int) db()->lastInsertId();
                auditLog(db(), $user, 'user.create', 'user', $newUserId, 'Created ' . $formRole . ' account for ' . $formName, ['email' => $formEmail, 'username' => $username, 'role' => $formRole, 'is_pro' => $isPro]);

                if ($isPro) {
                    $emailSent = sendAccountCredentials($formName, $formEmail, $username, $password);
                    redirect('admin/users?created=1' . ($emailSent ? '' : '&email_failed=1'));
                } else {
                    // Free plan: no email delivery
                    redirect('admin/users?created=1&no_email=1');
                }
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

if ($postAction === 'bulk_users') {
    $bulkAction = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
    $bulkIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []),
        static fn(int $id): bool => $id > 0
    )));

    if (!in_array($bulkAction, ['activate', 'deactivate', 'delete'], true)) {
        $statusError = 'Choose what to do with the selected users.';
    } elseif ($bulkIds === []) {
        $statusError = 'Select at least one user first.';
    } elseif (count($bulkIds) > 100) {
        $statusError = 'Select up to 100 users at a time.';
    } else {
        try {
            $bulkPdo = db();
            // Never act on your own account (you could lock yourself out).
            $skippedSelf = in_array((int) $user['id'], $bulkIds, true);
            $bulkIds = array_values(array_diff($bulkIds, [(int) $user['id']]));
            $done = 0;

            if ($bulkIds !== []) {
                $marks = implode(',', array_fill(0, count($bulkIds), '?'));
                $select = $bulkPdo->prepare("SELECT id, name, email, role, status FROM users WHERE id IN ($marks)");
                $select->execute($bulkIds);

                foreach ($select->fetchAll() as $target) {
                    if ($bulkAction === 'delete') {
                        $files = dataCleanupUserFiles($bulkPdo, (int) $target['id']);
                        if (dataCleanupDeleteUser($bulkPdo, (int) $target['id'])) {
                            foreach ($files as $file) {
                                assessmentDeleteStoredFile($file);
                            }
                            auditLog($bulkPdo, $user, 'user.delete', 'user', (int) $target['id'], 'Deleted ' . $target['role'] . ' account of ' . $target['name'] . ' (bulk)', ['email' => $target['email'], 'role' => $target['role']]);
                            $done++;
                        }
                    } else {
                        $newStatus = $bulkAction === 'activate' ? 'active' : 'inactive';
                        if ($target['status'] !== $newStatus) {
                            $bulkPdo->prepare('UPDATE users SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $target['id']]);
                            auditLog($bulkPdo, $user, 'user.status', 'user', (int) $target['id'], ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' account of ' . $target['name'] . ' (bulk)', ['email' => $target['email'], 'status_from' => $target['status'], 'status_to' => $newStatus]);
                            $done++;
                        }
                    }
                }
            }

            adminCsrfRotate();
            redirect('admin/users?bulk_done=' . $done . '&bulk_what=' . $bulkAction . ($skippedSelf ? '&bulk_self=1' : ''));
        } catch (Throwable $error) {
            error_log('Admin bulk user action failed: ' . $error->getMessage());
            $statusError = 'The bulk action could not be completed. Some users may have been changed; reload and check.';
        }
    }
}

$userSearch = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$userSearch = mb_substr($userSearch, 0, 100, 'UTF-8');

/* Filters and sorting come from the address bar. Only the words in these lists are ever used in the query. */
const ADMIN_USER_SORTS = ['name' => 'name', 'email' => 'email', 'role' => 'role', 'status' => 'status', 'joined' => 'created_at'];
$filterRole   = in_array($_GET['role'] ?? '', ['student', 'instructor', 'admin'], true) ? (string) $_GET['role'] : '';
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? (string) $_GET['status'] : '';
$sortKey      = is_string($_GET['sort'] ?? null) && isset(ADMIN_USER_SORTS[$_GET['sort']]) ? $_GET['sort'] : 'joined';
$sortDir      = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$usersFiltered = $userSearch !== '' || $filterRole !== '' || $filterStatus !== '';

/** Address of this page with the current filters and sorting, plus any changes in $over. */
$usersUrl = static function (array $over = []) use ($userSearch, $filterRole, $filterStatus, $sortKey, $sortDir): string {
    $query = array_filter(array_merge([
        'q' => $userSearch, 'role' => $filterRole, 'status' => $filterStatus,
        'sort' => $sortKey !== 'joined' || $sortDir !== 'desc' ? $sortKey : null,
        'dir'  => $sortKey !== 'joined' || $sortDir !== 'desc' ? $sortDir : null,
    ], $over), static fn($value): bool => $value !== null && $value !== '');

    return url('admin/users' . ($query ? '?' . http_build_query($query) : ''));
};

try {
    $where = [];
    $params = [];
    if ($userSearch !== '') {
        $where[] = '(name LIKE :name OR email LIKE :email OR username LIKE :username)';
        $searchTerm = '%' . likeEscape($userSearch) . '%';
        $params += ['name' => $searchTerm, 'email' => $searchTerm, 'username' => $searchTerm];
    }
    if ($filterRole !== '') {
        $where[] = 'role = :role';
        $params['role'] = $filterRole;
    }
    if ($filterStatus !== '') {
        $where[] = 'status = :status';
        $params['status'] = $filterStatus;
    }

    $column = ADMIN_USER_SORTS[$sortKey];
    $direction = $sortDir === 'asc' ? 'ASC' : 'DESC';
    $userQuery = 'SELECT id, name, username, email, role, status, created_at FROM users'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY $column $direction, id $direction LIMIT 100";
    $loadUsers = db()->prepare($userQuery);
    $loadUsers->execute($params);
    $users = $loadUsers->fetchAll();
} catch (Throwable $error) {
    error_log('Admin users page failed to load: ' . $error->getMessage());
    $usersError = true;
}

if ($postAction === 'delete_user') {
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

    if ($userId === false || $userId === null || $userId < 1) {
        $statusError = 'Select a valid user.';
    } elseif ((int) $userId === (int) $user['id']) {
        $statusError = 'You cannot delete your own administrator account.';
    } else {
        try {
            $findUser = db()->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
            $findUser->execute(['id' => $userId]);
            $target = $findUser->fetch();
            if ($target === false) {
                $statusError = 'The selected user could not be found.';
            } else {
                // The user's rows, and the courses they teach, go together (no foreign keys do it for us).
                $cleanupPdo = db();
                $userFiles = dataCleanupUserFiles($cleanupPdo, (int) $userId);
                dataCleanupDeleteUser($cleanupPdo, (int) $userId);
                foreach ($userFiles as $userFile) {
                    assessmentDeleteStoredFile($userFile);
                }
                auditLog($cleanupPdo, $user, 'user.delete', 'user', (int) $userId, 'Deleted ' . $target['role'] . ' account of ' . $target['name'], ['email' => $target['email'], 'role' => $target['role']]);
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
$bulkCsrfToken = adminCsrfToken();   // must be created before any HTML is sent (it starts the session)
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
                    <p class="admin-alert admin-alert--success" role="status">The user account was created.<?= isset($_GET['no_email']) ? ' (Manual password saved. Credentials were not emailed because the system is on the Free plan).' : (isset($_GET['email_failed']) ? '' : ' Credentials have been emailed.') ?></p>
                <?php endif; ?>
                <?php if (isset($_GET['email_failed'])): ?>
                    <p class="admin-alert" role="alert">The account was created, but the credentials email could not be sent. Check the mail configuration and contact the user.</p>
                <?php endif; ?>
                <?php if (isset($_GET['bulk_done'])): ?>
                    <?php $bulkWord = ['activate' => 'activated', 'deactivate' => 'deactivated', 'delete' => 'deleted'][$_GET['bulk_what'] ?? ''] ?? 'changed'; ?>
                    <p class="admin-alert admin-alert--success" role="status"><?= (int) $_GET['bulk_done'] ?> user<?= (int) $_GET['bulk_done'] === 1 ? ' was' : 's were' ?> <?= h($bulkWord) ?>.<?= isset($_GET['bulk_self']) ? ' Your own account was skipped.' : '' ?></p>
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
                        <?php if ($isPro): ?>
                            <span class="admin-chip admin-chip--success">Pro Plan &bull; Auto-Email Active</span>
                        <?php else: ?>
                            <span class="admin-chip admin-chip--warning">Free Plan &bull; Manual Password</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($formError !== ''): ?>
                        <p class="admin-alert" role="alert"><?= h($formError) ?></p>
                    <?php endif; ?>
                    <form class="admin-user-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($bulkCsrfToken) ?>">
                        <input type="hidden" name="action" value="create_user">
                        <div class="admin-user-form__field">
                            <label for="first-name">First name</label>
                            <input id="first-name" name="first_name" type="text" maxlength="100" autocomplete="given-name" pattern="[^&lt;&gt;]*" title="Letters, numbers and punctuation. The characters &lt; and &gt; are not allowed." value="<?= h($formFirstName) ?>" required>
                            <?php if ($firstNameError !== ''): ?>
                                <p class="admin-user-form__field-error" role="alert"><?= h($firstNameError) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="last-name">Last name</label>
                            <input id="last-name" name="last_name" type="text" maxlength="100" autocomplete="family-name" pattern="[^&lt;&gt;]*" title="Letters, numbers and punctuation. The characters &lt; and &gt; are not allowed." value="<?= h($formLastName) ?>" required>
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
                            <label for="password">Password <?= !$isPro ? '<span class="admin-required" aria-hidden="true">*</span>' : '<span class="admin-card__note" style="margin:0 0 0 4px;">(Optional — leave blank to auto-generate)</span>' ?></label>
                            <input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" value="<?= h($formPassword) ?>" <?= !$isPro ? 'required' : '' ?> placeholder="<?= !$isPro ? 'Enter a password (min. 8 characters)' : 'Auto-generated temporary password if empty' ?>">
                            <?php if ($passwordError !== ''): ?>
                                <p class="admin-user-form__field-error" role="alert"><?= h($passwordError) ?></p>
                            <?php elseif (!$isPro): ?>
                                <p class="admin-card__note">Free plan: manual password is required. (Credentials will not be emailed).</p>
                            <?php else: ?>
                                <p class="admin-card__note">Pro plan: leave blank to auto-generate and email credentials to the user.</p>
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
                        <?php if ($isPro): ?>
                            <span class="admin-chip admin-chip--success">Pro Plan &bull; Auto-Email Active</span>
                        <?php else: ?>
                            <span class="admin-chip admin-chip--warning">Free Plan &bull; Password Column Required</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isPro): ?>
                        <p>Upload a CSV with these headings: <strong>First name, Last name, email, role</strong> (optional 5th column: <strong>password</strong>). Roles must be student, instructor, or admin. A username and temporary 8-character password will be auto-generated and emailed to each user. <a href="<?= h(url('assests/templates/users_template_pro.csv')) ?>" download>Download Pro CSV template</a></p>
                    <?php else: ?>
                        <p>Upload a CSV with these headings: <strong>First name, Last name, email, role, password</strong>. Roles must be student, instructor, or admin. Passwords must be at least 8 characters. (Free plan: automatic email delivery is disabled; accounts will be created with the passwords in your CSV). <a href="<?= h(url('assests/templates/users_template_free.csv')) ?>" download>Download Free CSV template</a></p>
                    <?php endif; ?>
                    <form class="admin-user-form" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= h($bulkCsrfToken) ?>">
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
                        <h2 id="users-list-title"><?= $usersFiltered ? 'Matching users' : 'Existing users' ?></h2>
                        <span class="admin-card__note"><?= $usersFiltered ? count($users) . ' found (up to 100)' : 'Up to 100 shown' ?></span>
                    </div>
                    <form class="admin-filter-form" method="get" action="<?= h(url('admin/users')) ?>">
                        <div class="admin-user-form__field">
                            <label for="filter-users-q">Search</label>
                            <input id="filter-users-q" name="q" type="search" maxlength="100" value="<?= h($userSearch) ?>" placeholder="Name, email or username">
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-users-role">Role</label>
                            <select id="filter-users-role" name="role">
                                <option value="">All roles</option>
                                <?php foreach (['student' => 'Student', 'instructor' => 'Instructor', 'admin' => 'Admin'] as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $filterRole === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-users-status">Status</label>
                            <select id="filter-users-status" name="status">
                                <option value="">Any status</option>
                                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <?php if ($sortKey !== 'joined' || $sortDir !== 'desc'): ?>
                            <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
                            <input type="hidden" name="dir" value="<?= h($sortDir) ?>">
                        <?php endif; ?>
                        <div class="admin-filter-form__actions">
                            <button class="admin-btn admin-btn--primary admin-btn--inline" type="submit"><span>Apply</span></button>
                            <?php if ($usersFiltered): ?>
                                <a class="admin-btn" href="<?= h(url('admin/users')) ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($usersError): ?>
                        <p class="admin-alert" role="alert">Users could not be loaded. Please reload the page.</p>
                    <?php elseif (!$users): ?>
                        <p class="admin-empty"><?= $usersFiltered ? 'No users match these filters.' : 'No users yet.' ?></p>
                    <?php else: ?>
                        <form id="bulk-users-form" class="admin-bulk" method="post" action="" data-bulk-form>
                            <input type="hidden" name="csrf_token" value="<?= h($bulkCsrfToken) ?>">
                            <input type="hidden" name="action" value="bulk_users">
                            <label class="admin-sr-only" for="bulk-action">Action for the selected users</label>
                            <select id="bulk-action" class="admin-status-select" name="bulk_action" data-bulk-action>
                                <option value="activate">Activate selected</option>
                                <option value="deactivate">Deactivate selected</option>
                                <option value="delete">Delete selected</option>
                            </select>
                            <button class="admin-btn" type="submit" data-bulk-submit disabled>Apply</button>
                            <span class="admin-card__note" data-bulk-count aria-live="polite">0 selected</span>
                        </form>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><input type="checkbox" data-select-all data-bulk-for="bulk-users-form" aria-label="Select all users in this list"></th>
                                        <?php
                                        $sortHeader = static function (string $key, string $label) use ($sortKey, $sortDir, $usersUrl): string {
                                            $active = $sortKey === $key;
                                            $next = $active && $sortDir === 'asc' ? 'desc' : 'asc';
                                            $arrow = $active ? ($sortDir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
                                            return '<th scope="col" aria-sort="' . ($active ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none') . '">'
                                                . '<a class="admin-sort" href="' . h($usersUrl(['sort' => $key, 'dir' => $next])) . '" title="Sort by ' . h(strtolower($label)) . ' (' . ($next === 'asc' ? 'A to Z' : 'Z to A') . ')">'
                                                . h($label) . $arrow . '</a></th>';
                                        };
                                        echo $sortHeader('name', 'User'), $sortHeader('role', 'Role'), $sortHeader('status', 'Status'), $sortHeader('joined', 'Joined');
                                        ?>
                                        <th scope="col">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $row): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" form="bulk-users-form" name="ids[]" value="<?= (int) $row['id'] ?>" data-select-row
                                                    aria-label="Select <?= h($row['name']) ?>"<?= (int) $row['id'] === (int) $user['id'] ? ' disabled title="You cannot select your own account"' : '' ?>>
                                            </td>
                                            <td>
                                                <span class="admin-person">
                                                    <span class="admin-person__avatar" aria-hidden="true"><?= h(initials($row['name'])) ?></span>
                                                    <span class="admin-person__text">
                                                        <a class="admin-person__name admin-person__link" href="<?= h(url('admin/user-profile/' . (int) $row['id'])) ?>"><?= h($row['name']) ?></a>
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
                                                    <input type="hidden" name="csrf_token" value="<?= h($bulkCsrfToken) ?>">
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
                                                <div class="admin-actions">
                                                <a class="admin-btn admin-edit-btn" href="<?= h(url('admin/user-profile/' . (int) $row['id'])) ?>" aria-label="Edit <?= h($row['name']) ?>">Edit</a>
                                                <form
                                                    method="post"
                                                    class="admin-delete-form"
                                                    data-confirm="Are you sure you want to delete the user &quot;<?= h($row['name']) ?>&quot;? This action cannot be undone."
                                                >
                                                    <input type="hidden" name="csrf_token" value="<?= h($bulkCsrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                    <button class="admin-btn admin-delete-btn" type="submit" aria-label="Delete user <?= h($row['name']) ?>" title="Delete user">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    </button>


                                                </form>
                                                </div>
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
