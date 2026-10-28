<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../api/api_assignment_service.php';   // assessmentDeleteStoredFile()
require_once __DIR__ . '/../api/api_admin_course_service.php';

/* ==========================================================================
   Only an admin may see this page
   ========================================================================== */
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

$filters = adminCourseFilters($_GET);

/** The list page address that keeps the current filters. */
$listUrl = static function (array $extra = []) use ($filters): string {
    $query = array_filter(array_merge([
        'q'          => $filters['q'],
        'status'     => $filters['status'] !== 'all' ? $filters['status'] : null,
        'category'   => $filters['category'],
        'instructor' => $filters['instructor'],
        'sort'       => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
        'page'       => $filters['page'] > 1 ? $filters['page'] : null,
    ], $extra), static fn ($v) => $v !== null && $v !== '');

    return 'admin/courses' . ($query ? '?' . http_build_query($query) : '');
};

$pageError   = '';
$formErrors  = [];
$createValues = ['title' => '', 'description' => '', 'category_id' => '', 'instructor_id' => '', 'status' => 'draft'];
$editId      = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editValues  = null;
$loadError   = false;
$pdo = null;

try {
    $pdo = db();
} catch (Throwable $error) {
    error_log('Admin courses page: database unavailable: ' . $error->getMessage());
    $loadError = true;
}

/* ==========================================================================
   Actions (POST)
   ========================================================================== */
if ($pdo && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

    try {
        if (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
            $pageError = 'Your session expired. Please try again.';
        } elseif ($action === 'create') {
            $result = adminCourseValidate($pdo, $_POST, $formErrors, null, 0, true);
            $createValues = $result['values'];

            if (!$formErrors && $result['clean'] !== null) {
                adminCourseCreate($pdo, $result['clean']);
                adminCsrfRotate();
                redirect($listUrl(['created' => 1]));
                exit;
            }
        } elseif ($action === 'update') {
            $course = $courseId ? adminCourseFind($pdo, $courseId) : null;
            if (!$course) {
                $pageError = 'That course could not be found.';
            } else {
                $editId = (int) $course['id'];
                $result = adminCourseValidate($pdo, $_POST, $formErrors, (int) $course['instructor_id'], (int) $course['lessons'], false);
                $editValues = $result['values'];

                if (!$formErrors && $result['clean'] !== null) {
                    adminCourseUpdate($pdo, (int) $course['id'], $result['clean']);
                    adminCsrfRotate();
                    redirect($listUrl(['updated' => 1]));
                exit;
                }
            }
        } elseif ($action === 'set_status') {
            $course = $courseId ? adminCourseFind($pdo, $courseId) : null;
            if (!$course) {
                $pageError = 'That course could not be found.';
            } else {
                $newStatus = (string) ($_POST['status'] ?? '');
                $problem = adminCourseSetStatus($pdo, (int) $course['id'], $newStatus);
                if ($problem !== null) {
                    $pageError = $problem;
                } else {
                    adminCsrfRotate();
                    redirect($listUrl([$newStatus === 'published' ? 'published' : 'unpublished' => 1]));
                exit;
                }
            }
        } elseif ($action === 'delete') {
            if ($courseId && adminCourseDelete($pdo, $courseId)) {
                adminCsrfRotate();
                redirect($listUrl(['deleted' => 1]));
                exit;
            }
            $pageError = 'That course could not be found.';
        }
    } catch (Throwable $error) {
        error_log('Admin course action failed: ' . $error->getMessage());
        $pageError = 'That could not be saved. Please try again.';
    }
}

/* ==========================================================================
   Load the page
   ========================================================================== */
$summary = ['total' => 0, 'published' => 0, 'draft' => 0, 'enrolments' => 0, 'ratings' => 0, 'average' => null];
$list = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
$categories = $instructors = $filterInstructors = [];
$editCourse = null;

if ($pdo) {
    try {
        $summary = adminCourseSummary($pdo);
        $categories = adminCourseCategories($pdo);
        $filterInstructors = adminCourseAllInstructors($pdo);
        $list = adminCourseList($pdo, $filters);

        if ($editId) {
            $editCourse = adminCourseFind($pdo, $editId);
            if ($editCourse && $editValues === null) {
                $editValues = [
                    'title'         => (string) $editCourse['title'],
                    'description'   => (string) ($editCourse['description'] ?? ''),
                    'category_id'   => (string) ($editCourse['category_id'] ?? ''),
                    'instructor_id' => (string) $editCourse['instructor_id'],
                    'status'        => (string) $editCourse['status'],
                ];
            }
        }

        $instructors = adminCourseInstructors($pdo, $editCourse ? (int) $editCourse['instructor_id'] : null);
    } catch (Throwable $error) {
        error_log('Admin courses page failed to load: ' . $error->getMessage());
        $loadError = true;
    }
}

$csrfToken = adminCsrfToken();
$filtered = $filters['q'] !== '' || $filters['status'] !== 'all' || $filters['category'] !== null || $filters['instructor'];
$pageLink = static fn (int $page): string => url($listUrl(['page' => $page]));

$adminPageTitle = 'Courses | Learning Hub Admin';
$adminActivePage = 'courses';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero">
                    <h1>Courses</h1>
                    <p>See every course on the platform, create courses for instructors, and publish or remove them.</p>
                </section>

                <?php foreach (['created' => 'The course was created as a draft.', 'updated' => 'The course was updated.',
                    'published' => 'The course is now published.', 'unpublished' => 'The course was moved back to draft.',
                    'deleted' => 'The course and its content were deleted.'] as $key => $message): ?>
                    <?php if (isset($_GET[$key])): ?>
                        <p class="admin-alert admin-alert--success" role="status"><?= h($message) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($pageError) ?></p>
                <?php endif; ?>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The courses could not be loaded. Please try again shortly.</p>
                <?php else: ?>

                <section class="admin-stats" aria-label="Course numbers">
                    <article class="admin-stat">
                        <p class="admin-stat__label">Courses</p>
                        <p class="admin-stat__value"><?= $summary['total'] ?></p>
                        <p class="admin-stat__hint">On the platform</p>
                    </article>
                    <article class="admin-stat">
                        <p class="admin-stat__label">Published</p>
                        <p class="admin-stat__value"><?= $summary['published'] ?></p>
                        <p class="admin-stat__hint">Visible to students</p>
                    </article>
                    <article class="admin-stat">
                        <p class="admin-stat__label">Drafts</p>
                        <p class="admin-stat__value"><?= $summary['draft'] ?></p>
                        <p class="admin-stat__hint">Not published yet</p>
                    </article>
                    <article class="admin-stat">
                        <p class="admin-stat__label">Enrolments</p>
                        <p class="admin-stat__value"><?= $summary['enrolments'] ?></p>
                        <p class="admin-stat__hint">
                            <?= $summary['average'] !== null
                                ? h(number_format((float) $summary['average'], 1)) . ' / 5 average from ' . $summary['ratings'] . ' rating' . ($summary['ratings'] === 1 ? '' : 's')
                                : 'No ratings yet' ?>
                        </p>
                    </article>
                </section>

                <?php if ($editCourse && $editValues !== null): ?>
                    <!-- Edit a course -->
                    <section class="admin-card admin-user-form-card" aria-labelledby="edit-course-title">
                        <div class="admin-card__head">
                            <h2 id="edit-course-title">Edit course</h2>
                            <a class="admin-btn" href="<?= h(url($listUrl())) ?>">Cancel</a>
                        </div>
                        <?php foreach ($formErrors as $error): ?>
                            <p class="admin-alert" role="alert"><?= h($error) ?></p>
                        <?php endforeach; ?>
                        <form class="admin-user-form" method="post" action="" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="course_id" value="<?= (int) $editCourse['id'] ?>">

                            <div class="admin-user-form__field admin-user-form__field--wide">
                                <label for="edit-title">Title</label>
                                <input id="edit-title" name="title" type="text" maxlength="190" value="<?= h($editValues['title']) ?>" required>
                            </div>
                            <div class="admin-user-form__field">
                                <label for="edit-instructor">Instructor</label>
                                <select id="edit-instructor" name="instructor_id" required>
                                    <option value="">Choose an instructor</option>
                                    <?php foreach ($instructors as $i): ?>
                                        <option value="<?= (int) $i['id'] ?>" <?= $editValues['instructor_id'] === (string) $i['id'] ? 'selected' : '' ?>>
                                            <?= h($i['name']) ?> (<?= h($i['email']) ?>)<?= $i['status'] !== 'active' ? ' - inactive' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-user-form__field">
                                <label for="edit-category">Category</label>
                                <select id="edit-category" name="category_id">
                                    <option value="">No category</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= $editValues['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-user-form__field">
                                <label for="edit-status">Status</label>
                                <select id="edit-status" name="status">
                                    <option value="draft" <?= $editValues['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="published" <?= $editValues['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                                </select>
                            </div>
                            <div class="admin-user-form__field admin-user-form__field--wide">
                                <label for="edit-description">Description</label>
                                <textarea id="edit-description" name="description" rows="5" maxlength="5000"><?= h($editValues['description']) ?></textarea>
                            </div>
                            <button class="admin-btn admin-btn--primary" type="submit"><span>Save changes</span></button>
                        </form>
                    </section>
                <?php else: ?>
                    <?php if ($editId): ?>
                        <p class="admin-alert" role="alert">That course could not be found.</p>
                    <?php endif; ?>

                    <!-- Create a course -->
                    <section class="admin-card admin-user-form-card" aria-labelledby="create-course-title">
                        <details <?= $formErrors ? 'open' : '' ?>>
                            <summary class="admin-card__head"><h2 id="create-course-title">Create a course</h2></summary>
                            <?php foreach ($formErrors as $error): ?>
                                <p class="admin-alert" role="alert"><?= h($error) ?></p>
                            <?php endforeach; ?>
                            <p class="admin-card__note">A new course starts as a draft. The instructor adds lessons, then you or they can publish it.</p>
                            <form class="admin-user-form" method="post" action="" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="create">

                                <div class="admin-user-form__field admin-user-form__field--wide">
                                    <label for="new-title">Title</label>
                                    <input id="new-title" name="title" type="text" maxlength="190" value="<?= h($createValues['title']) ?>" required>
                                </div>
                                <div class="admin-user-form__field">
                                    <label for="new-instructor">Instructor</label>
                                    <select id="new-instructor" name="instructor_id" required>
                                        <option value="">Choose an instructor</option>
                                        <?php foreach ($instructors as $i): ?>
                                            <option value="<?= (int) $i['id'] ?>" <?= $createValues['instructor_id'] === (string) $i['id'] ? 'selected' : '' ?>>
                                                <?= h($i['name']) ?> (<?= h($i['email']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="admin-user-form__field">
                                    <label for="new-category">Category</label>
                                    <select id="new-category" name="category_id">
                                        <option value="">No category</option>
                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>" <?= $createValues['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="admin-user-form__field admin-user-form__field--wide">
                                    <label for="new-description">Description (optional)</label>
                                    <textarea id="new-description" name="description" rows="4" maxlength="5000"><?= h($createValues['description']) ?></textarea>
                                </div>
                                <button class="admin-btn admin-btn--primary" type="submit"><span>Create course</span></button>
                            </form>
                        </details>
                    </section>
                <?php endif; ?>

                <!-- Filters -->
                <section class="admin-card" aria-labelledby="course-list-title">
                    <div class="admin-card__head">
                        <h2 id="course-list-title">All courses</h2>
                        <span class="admin-chip admin-chip--muted"><?= $list['total'] ?> course<?= $list['total'] === 1 ? '' : 's' ?><?= $filtered ? ' found' : '' ?></span>
                    </div>

                    <form class="admin-filter-form" method="get" action="<?= h(url('admin/courses')) ?>">
                        <div class="admin-user-form__field">
                            <label for="filter-q">Search</label>
                            <input id="filter-q" name="q" type="search" value="<?= h($filters['q']) ?>" placeholder="Course title or instructor">
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-status">Status</label>
                            <select id="filter-status" name="status">
                                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                                <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-category">Category</label>
                            <select id="filter-category" name="category">
                                <option value="">All</option>
                                <option value="none" <?= $filters['category'] === 'none' ? 'selected' : '' ?>>No category</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-instructor">Instructor</label>
                            <select id="filter-instructor" name="instructor">
                                <option value="">All</option>
                                <?php foreach ($filterInstructors as $i): ?>
                                    <option value="<?= (int) $i['id'] ?>" <?= $filters['instructor'] === (int) $i['id'] ? 'selected' : '' ?>><?= h($i['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-user-form__field">
                            <label for="filter-sort">Sort by</label>
                            <select id="filter-sort" name="sort">
                                <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest</option>
                                <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                                <option value="title" <?= $filters['sort'] === 'title' ? 'selected' : '' ?>>Title A-Z</option>
                                <option value="enrolled" <?= $filters['sort'] === 'enrolled' ? 'selected' : '' ?>>Most students</option>
                                <option value="rated" <?= $filters['sort'] === 'rated' ? 'selected' : '' ?>>Highest rated</option>
                            </select>
                        </div>
                        <div class="admin-filter-form__actions">
                            <button class="admin-btn admin-btn--primary" type="submit"><span>Apply</span></button>
                            <?php if ($filtered): ?>
                                <a class="admin-btn" href="<?= h(url('admin/courses')) ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (!$list['rows']): ?>
                        <p class="admin-empty"><?= $filtered ? 'No courses match these filters.' : 'There are no courses yet. Create the first one above.' ?></p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Course</th>
                                        <th scope="col">Instructor</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Lessons</th>
                                        <th scope="col">Students</th>
                                        <th scope="col">Rating</th>
                                        <th scope="col">Created</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($list['rows'] as $row): ?>
                                    <?php $isPublished = $row['status'] === 'published'; ?>
                                    <tr>
                                        <td>
                                            <span class="admin-person__text">
                                                <span class="admin-person__name"><?= h($row['title']) ?></span>
                                                <span class="admin-person__email"><?= h($row['category'] ?? 'No category') ?>
                                                    &middot; <?= (int) $row['assignments'] ?> assignment<?= (int) $row['assignments'] === 1 ? '' : 's' ?>,
                                                    <?= (int) $row['quizzes'] ?> quiz<?= (int) $row['quizzes'] === 1 ? '' : 'zes' ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="admin-person">
                                                <span class="admin-person__avatar" aria-hidden="true"><?= h(initials((string) $row['instructor'])) ?></span>
                                                <span class="admin-person__text">
                                                    <span class="admin-person__name"><?= h($row['instructor']) ?></span>
                                                    <span class="admin-person__email"><?= h($row['instructor_email']) ?></span>
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="post" action="" class="admin-status-form">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="course_id" value="<?= (int) $row['id'] ?>">
                                                <select class="admin-status-select" name="status"
                                                    aria-label="Status for <?= h($row['title']) ?>">
                                                    <option value="published" <?= $isPublished ? 'selected' : '' ?>
                                                        <?= !$isPublished && (int) $row['lessons'] < 1 ? 'disabled title="Add a lesson before publishing"' : '' ?>>Published</option>
                                                    <option value="draft" <?= !$isPublished ? 'selected' : '' ?>>Draft</option>
                                                </select>
                                            </form>
                                        </td>
                                        <td><?= (int) $row['lessons'] ?></td>
                                        <td><?= (int) $row['students'] ?></td>
                                        <td>
                                            <?php if ((int) $row['rating_count'] > 0): ?>
                                                &#9733; <?= h(number_format((float) $row['rating_average'], 1)) ?>
                                                <span class="admin-person__email">(<?= (int) $row['rating_count'] ?>)</span>
                                            <?php else: ?>
                                                <span class="admin-person__email">No ratings</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="admin-table__date"><?= h(date('j M Y', (int) strtotime((string) $row['created_at']))) ?></td>
                                        <td>
                                            <div class="admin-actions">
                                                <a class="admin-btn" href="<?= h(url($listUrl(['edit' => (int) $row['id']]))) ?>">Edit</a>

                                                <form method="post" action="" class="admin-delete-form"
                                                      data-confirm="Delete the course &quot;<?= h($row['title']) ?>&quot;? This permanently deletes its <?= (int) $row['lessons'] ?> lesson(s), <?= (int) $row['students'] ?> enrolment(s) with the students' progress, and all its assignments, submissions and quizzes. This cannot be undone.">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="course_id" value="<?= (int) $row['id'] ?>">
                                                    <button class="admin-btn admin-delete-btn" type="submit" aria-label="Delete course <?= h($row['title']) ?>" title="Delete course">
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

                        <?php if ($list['pages'] > 1): ?>
                            <nav class="admin-pager" aria-label="Pages">
                                <?php if ($list['page'] > 1): ?>
                                    <a class="admin-btn" href="<?= h($pageLink($list['page'] - 1)) ?>">&larr; Previous</a>
                                <?php endif; ?>
                                <span>Page <?= $list['page'] ?> of <?= $list['pages'] ?></span>
                                <?php if ($list['page'] < $list['pages']): ?>
                                    <a class="admin-btn" href="<?= h($pageLink($list['page'] + 1)) ?>">Next &rarr;</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="<?= h(url('assests/auth.js')) ?>" defer></script>

    <footer class="admin-footer"><span>© <?= date('Y') ?> Learning Hub</span></footer>
    <script src="<?= h(url('assests/dashboard.js')) ?>" defer></script>
    <script>
        // Ask before a destructive action: any form with a data-confirm message.
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });
        document.querySelectorAll('.admin-status-select').forEach(function (select) {
            select.addEventListener('change', function () {
                select.form.requestSubmit();
            });
        });
    </script>
</body>
</html>
