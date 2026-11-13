<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../api/api_admin_course_service.php';   // adminCsrfToken() and friends

/* Only an admin may see this page */
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
    $last  = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

/** Clean a category name, or return an error text. */
function categoryNameProblem(string $name): ?string
{
    if ($name === '') {
        return 'Enter a category name.';
    }
    if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 100) {
        return 'The category name can be up to 100 characters.';
    }
    return null;
}

$pageError  = '';
$createName = '';
$loadError  = false;
$pdo        = null;

try {
    $pdo = db();
} catch (Throwable $error) {
    error_log('Admin categories: database unavailable: ' . $error->getMessage());
    $loadError = true;
}

/* ---------------- Actions (POST) ---------------- */
if ($pdo && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id     = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $name   = trim((string) ($_POST['name'] ?? ''));

    try {
        if (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
            $pageError = 'Your session expired. Please try again.';
        } elseif ($action === 'create') {
            $createName = $name;
            if (($problem = categoryNameProblem($name)) !== null) {
                $pageError = $problem;
            } else {
                $pdo->prepare('INSERT INTO categories (name) VALUES (:name)')->execute(['name' => $name]);
                auditLog($pdo, $user, 'category.create', 'category', (int) $pdo->lastInsertId(), 'Created category "' . $name . '"');
                adminCsrfRotate();
                redirect('admin/categories?created=1');
            }
        } elseif ($action === 'rename') {
            $find = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
            $find->execute(['id' => $id]);
            $old = $id ? $find->fetchColumn() : false;
            if ($old === false) {
                $pageError = 'That category could not be found.';
            } elseif (($problem = categoryNameProblem($name)) !== null) {
                $pageError = $problem;
            } elseif ($name !== $old) {
                $pdo->prepare('UPDATE categories SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $id]);
                auditLog($pdo, $user, 'category.rename', 'category', $id, 'Renamed category "' . $old . '" to "' . $name . '"', ['from' => $old, 'to' => $name]);
                adminCsrfRotate();
                redirect('admin/categories?renamed=1');
            } else {
                redirect('admin/categories');
            }
        } elseif ($action === 'delete') {
            $find = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
            $find->execute(['id' => $id]);
            $old = $id ? $find->fetchColumn() : false;
            if ($old === false) {
                $pageError = 'That category could not be found.';
            } else {
                // No foreign keys: courses that used it become "uncategorised" by hand.
                $pdo->beginTransaction();
                $moved = $pdo->prepare('UPDATE courses SET category_id = NULL WHERE category_id = :id');
                $moved->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();
                auditLog($pdo, $user, 'category.delete', 'category', $id, 'Deleted category "' . $old . '"', ['name' => $old, 'courses_uncategorised' => $moved->rowCount()]);
                adminCsrfRotate();
                redirect('admin/categories?deleted=1');
            }
        }
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error->getCode() === '23000') {
            $pageError = 'A category with that name already exists.';
        } else {
            error_log('Admin category action failed: ' . $error->getMessage());
            $pageError = 'That could not be saved. Please try again.';
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin category action failed: ' . $error->getMessage());
        $pageError = 'That could not be saved. Please try again.';
    }
}

/* ---------------- Load the list ---------------- */
$categories = [];
if ($pdo) {
    try {
        $categories = $pdo->query(
            "SELECT cat.id, cat.name, COUNT(c.id) AS courses, COALESCE(SUM(c.status = 'published'), 0) AS published
             FROM categories cat LEFT JOIN courses c ON c.category_id = cat.id
             GROUP BY cat.id, cat.name ORDER BY cat.name ASC"
        )->fetchAll();
    } catch (Throwable $error) {
        error_log('Admin categories failed to load: ' . $error->getMessage());
        $loadError = true;
    }
}

$csrfToken       = adminCsrfToken();
$adminPageTitle  = 'Categories | Learning Hub Admin';
$adminActivePage = 'categories';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero">
                    <h1>Categories</h1>
                    <p>Group courses so students can find them in the catalog.</p>
                </section>

                <?php foreach (['created' => 'The category was added.', 'renamed' => 'The category was renamed.', 'deleted' => 'The category was deleted. Its courses are now uncategorised.'] as $key => $message): ?>
                    <?php if (isset($_GET[$key])): ?>
                        <p class="admin-alert admin-alert--success" role="status"><?= h($message) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($pageError) ?></p>
                <?php endif; ?>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The categories could not be loaded. Please try again shortly.</p>
                <?php else: ?>

                <section class="admin-card admin-user-form-card" aria-labelledby="add-category-title">
                    <div class="admin-card__head"><h2 id="add-category-title">Add a category</h2></div>
                    <form class="admin-user-form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="admin-user-form__field admin-user-form__field--wide">
                            <label for="new-category">Category name</label>
                            <input id="new-category" name="name" type="text" maxlength="100" value="<?= h($createName) ?>" required>
                        </div>
                        <button class="admin-btn admin-btn--primary" type="submit"><span>Add category</span></button>
                    </form>
                </section>

                <section class="admin-card" aria-labelledby="category-list-title">
                    <div class="admin-card__head">
                        <h2 id="category-list-title">All categories</h2>
                        <span class="admin-card__note"><?= count($categories) ?> in total</span>
                    </div>
                    <?php if (!$categories): ?>
                        <p class="admin-empty">No categories yet. Add the first one above.</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th scope="col">Name</th><th scope="col">Courses</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($categories as $row): ?>
                                    <tr>
                                        <td>
                                            <form class="admin-actions" method="post" action="">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="rename">
                                                <input type="hidden" name="category_id" value="<?= (int) $row['id'] ?>">
                                                <label class="admin-sr-only" for="cat-<?= (int) $row['id'] ?>">Name of category <?= h($row['name']) ?></label>
                                                <input class="admin-status-select" id="cat-<?= (int) $row['id'] ?>" name="name" type="text" maxlength="100" value="<?= h($row['name']) ?>" required>
                                                <button class="admin-btn" type="submit">Save</button>
                                            </form>
                                        </td>
                                        <td><?= (int) $row['courses'] ?> <span class="admin-person__email">(<?= (int) $row['published'] ?> published)</span></td>
                                        <td>
                                            <form method="post" action="" class="admin-delete-form"
                                                  onsubmit="return confirm('Delete the category <?= h(addslashes($row['name'])) ?>? Its <?= (int) $row['courses'] ?> course(s) will become uncategorised.')">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="category_id" value="<?= (int) $row['id'] ?>">
                                                <button class="admin-btn admin-delete-btn" type="submit" aria-label="Delete category <?= h($row['name']) ?>" title="Delete category">
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
                <?php endif; ?>
            </div>
        </main>
    </div>

    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
</body>
</html>
