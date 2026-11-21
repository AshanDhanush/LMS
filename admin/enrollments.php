<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin: students of one course
|
|   - see who is enrolled
|   - remove one student, or several at once
|   - enrol several students at once (only people who are not enrolled yet)
|   - set the course capacity (seat limit)
|
| Open it from the Courses page: admin/enrollments?course_id=12
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/capacity.php';
require_once __DIR__ . '/../api/api_admin_course_service.php';   // CSRF + adminCourseFind()
require_once __DIR__ . '/../api/api_cohort_service.php';          // cohortRemoveFromCourse()

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

/** Whole numbers from a list of posted ids: positive, unique, at most 100. */
function postedIds(string $key): array
{
    $raw = is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $raw),
        static fn(int $id): bool => $id > 0
    )));

    return array_slice($ids, 0, 100);
}

const ENROL_PICKER_LIMIT = 200;

$courseId  = filter_var($_GET['course_id'] ?? $_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$pageError = '';
$loadError = false;
$pdo       = null;
$course    = null;

try {
    $pdo = db();
    $course = $courseId ? adminCourseFind($pdo, $courseId) : null;
} catch (Throwable $error) {
    error_log('Admin enrollments: could not load the course: ' . $error->getMessage());
    $loadError = true;
}

$capacityReady = $pdo !== null && !$loadError && courseCapacityReady($pdo);
$pickerSearch  = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
$selfUrl = static function (array $extra = []) use ($courseId): string {
    return 'admin/enrollments?' . http_build_query(['course_id' => $courseId] + $extra);
};

/* ==========================================================================
   Actions (POST). Every one needs the form token and a request from this site.
   ========================================================================== */
if ($pdo && $course && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
            $pageError = 'Your session expired or the request could not be verified. Reload the page and try again.';

        } elseif ($action === 'enrol') {
            $ids = postedIds('student_ids');
            if ($ids === []) {
                $pageError = 'Select at least one student to enrol.';
            } elseif ($course['status'] !== 'published') {
                $pageError = 'Students can only be enrolled in a published course. Publish it first.';
            } else {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $find = $pdo->prepare("SELECT id, name, email FROM users WHERE role = 'student' AND status = 'active' AND id IN ($marks)");
                $find->execute($ids);
                $students = $find->fetchAll();

                $enrolled = $already = $full = 0;
                $enrolledEmails = [];
                foreach ($students as $student) {
                    $outcome = courseEnrol($pdo, (int) $course['id'], (int) $student['id']);
                    if ($outcome === 'enrolled') {
                        $enrolled++;
                        $enrolledEmails[] = (string) $student['email'];
                        notify($pdo, (int) $student['id'], 'enrollment',
                            'You were enrolled in ' . (string) $course['title'], null, 'course/' . (int) $course['id']);
                    } elseif ($outcome === 'already') {
                        $already++;
                    } elseif ($outcome === 'full') {
                        $full++;
                    }
                }
                $skipped = count($ids) - count($students);   // not a student, inactive or deleted

                if ($enrolled > 0) {
                    auditLog($pdo, $user, 'enrollment.add', 'course', (int) $course['id'],
                        'Enrolled ' . $enrolled . ' student(s) in "' . (string) $course['title'] . '"',
                        ['students' => implode(', ', array_slice($enrolledEmails, 0, 50)), 'count' => $enrolled]);
                }
                redirect($selfUrl(['enrolled' => $enrolled, 'already' => $already, 'full' => $full, 'skipped' => $skipped]));
            }

        } elseif ($action === 'unenrol') {
            $ids = postedIds('student_ids');
            if ($ids === []) {
                $pageError = 'Select at least one student to remove.';
            } else {
                $removed = 0;
                $removedEmails = [];
                $emailOf = $pdo->prepare('SELECT email FROM users WHERE id = :id');
                foreach ($ids as $studentId) {
                    $emailOf->execute(['id' => $studentId]);
                    $email = (string) $emailOf->fetchColumn();
                    if (cohortRemoveFromCourse($pdo, (int) $course['id'], $studentId)) {
                        $removed++;
                        $removedEmails[] = $email;
                    }
                }
                if ($removed > 0) {
                    auditLog($pdo, $user, 'enrollment.remove', 'course', (int) $course['id'],
                        'Removed ' . $removed . ' student(s) from "' . (string) $course['title'] . '"',
                        ['students' => implode(', ', array_slice($removedEmails, 0, 50)), 'count' => $removed]);
                }
                redirect($selfUrl(['removed' => $removed]));
            }

        } elseif ($action === 'capacity') {
            $raw = trim((string) ($_POST['capacity'] ?? ''));
            if ($raw !== '' && !ctype_digit($raw)) {
                $pageError = 'Enter a whole number, or leave the box empty for no limit.';
            } else {
                $capacity = $raw === '' ? null : (int) $raw;
                $problem = courseSetCapacity($pdo, (int) $course['id'], $capacity);
                if ($problem !== null) {
                    $pageError = $problem;
                } else {
                    auditLog($pdo, $user, 'course.capacity', 'course', (int) $course['id'],
                        ($capacity === null ? 'Removed the seat limit of' : 'Set the seat limit to ' . $capacity . ' for') . ' "' . (string) $course['title'] . '"',
                        ['capacity' => $capacity]);
                    redirect($selfUrl(['capacity_saved' => 1]));
                }
            }
        }
    } catch (Throwable $error) {
        error_log('Admin enrollment action failed: ' . $error->getMessage());
        $pageError = 'That could not be saved. Please try again.';
    }
}

/* ==========================================================================
   Load the page
   ========================================================================== */
$enrolledStudents = $available = [];
$capacity = null;
$enrolledCount = 0;

if ($pdo && $course && !$loadError) {
    try {
        $course = adminCourseFind($pdo, (int) $course['id']) ?: $course;
        $enrolledCount = (int) $course['students'];

        if ($capacityReady) {
            $cap = $pdo->prepare('SELECT capacity FROM courses WHERE id = :id');
            $cap->execute(['id' => $course['id']]);
            $value = $cap->fetchColumn();
            $capacity = $value === null || $value === false ? null : (int) $value;
        }

        $list = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.status, e.enrolled_at, e.completed_at
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :course
             ORDER BY u.name ASC, u.id ASC'
        );
        $list->execute(['course' => $course['id']]);
        $enrolledStudents = $list->fetchAll();

        $sql = "SELECT u.id, u.name, u.email FROM users u
                WHERE u.role = 'student' AND u.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = u.id AND e.course_id = :course)";
        $params = ['course' => $course['id']];
        if ($pickerSearch !== '') {
            $sql .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2)';
            $like = '%' . likeEscape($pickerSearch) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
        $pick = $pdo->prepare($sql . ' ORDER BY u.name ASC, u.id ASC LIMIT ' . ENROL_PICKER_LIMIT);
        $pick->execute($params);
        $available = $pick->fetchAll();
    } catch (Throwable $error) {
        error_log('Admin enrollments failed to load: ' . $error->getMessage());
        $loadError = true;
    }
}

$csrfToken = adminCsrfToken();
$seatsLeft = $capacity !== null ? max(0, $capacity - $enrolledCount) : null;

$adminPageTitle  = 'Course students | Learning Hub Admin';
$adminActivePage = 'courses';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero admin-user-profile-hero">
                    <div>
                        <h1>Course students</h1>
                        <p><?= $course ? h($course['title']) : 'Choose a course from the Courses page.' ?></p>
                    </div>
                    <a class="admin-btn admin-back-to-dashboard" href="<?= h(url('admin/courses')) ?>">Back to courses</a>
                </section>

                <?php if (isset($_GET['enrolled'])): ?>
                    <?php
                    $n = (int) $_GET['enrolled'];
                    $notes = [];
                    if ((int) ($_GET['already'] ?? 0) > 0) { $notes[] = (int) $_GET['already'] . ' already enrolled'; }
                    if ((int) ($_GET['full'] ?? 0) > 0)    { $notes[] = (int) $_GET['full'] . ' not enrolled because the course is full'; }
                    if ((int) ($_GET['skipped'] ?? 0) > 0) { $notes[] = (int) $_GET['skipped'] . ' skipped (not an active student)'; }
                    ?>
                    <p class="admin-alert <?= $n > 0 ? 'admin-alert--success' : '' ?>" role="status">
                        <?= $n ?> student<?= $n === 1 ? ' was' : 's were' ?> enrolled.<?= $notes ? ' ' . h(ucfirst(implode('; ', $notes))) . '.' : '' ?>
                    </p>
                <?php endif; ?>
                <?php if (isset($_GET['removed'])): ?>
                    <p class="admin-alert admin-alert--success" role="status"><?= (int) $_GET['removed'] ?> student(s) removed from the course.</p>
                <?php endif; ?>
                <?php if (isset($_GET['capacity_saved'])): ?>
                    <p class="admin-alert admin-alert--success" role="status">The course capacity was saved.</p>
                <?php endif; ?>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= h($pageError) ?></p>
                <?php endif; ?>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The enrolments could not be loaded. Please try again shortly.</p>
                <?php elseif (!$course): ?>
                    <p class="admin-alert" role="alert">That course could not be found.</p>
                <?php else: ?>

                <section class="admin-stats" aria-label="Course numbers">
                    <article class="admin-stat">
                        <p class="admin-stat__label">Enrolled</p>
                        <p class="admin-stat__value"><?= $enrolledCount ?></p>
                        <p class="admin-stat__hint"><?= $course['status'] === 'published' ? 'Published' : h(ucfirst((string) $course['status'])) ?></p>
                    </article>
                    <article class="admin-stat">
                        <p class="admin-stat__label">Capacity</p>
                        <p class="admin-stat__value"><?= $capacity !== null ? $capacity : '&infin;' ?></p>
                        <p class="admin-stat__hint"><?= $capacity !== null ? $seatsLeft . ' seat' . ($seatsLeft === 1 ? '' : 's') . ' left' : 'No limit' ?></p>
                    </article>
                </section>

                <section class="admin-card admin-user-form-card" aria-labelledby="capacity-title">
                    <div class="admin-card__head"><h2 id="capacity-title">Capacity</h2></div>
                    <?php if (!$capacityReady): ?>
                        <p class="admin-alert" role="alert">Capacity is not set up in this database yet. Run <code>database/migrations/002_course_capacity.sql</code> once to turn it on.</p>
                    <?php else: ?>
                        <form class="admin-user-form" method="post" action="<?= h(url('admin/enrollments')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="capacity">
                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                            <div class="admin-user-form__field">
                                <label for="course-capacity">Maximum number of students</label>
                                <input id="course-capacity" name="capacity" type="number" min="<?= max(1, $enrolledCount) ?>" max="<?= COURSE_CAPACITY_MAX ?>" step="1" inputmode="numeric"
                                       value="<?= $capacity !== null ? $capacity : '' ?>" placeholder="No limit" aria-describedby="course-capacity-note">
                                <p class="admin-card__note" id="course-capacity-note">Leave empty for no limit. It cannot be lower than the <?= $enrolledCount ?> student(s) already enrolled.</p>
                            </div>
                            <button class="admin-btn admin-btn--primary admin-btn--inline" type="submit"><span>Save capacity</span></button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="admin-card admin-users-list" aria-labelledby="enrolled-title">
                    <div class="admin-card__head">
                        <h2 id="enrolled-title">Enrolled students</h2>
                        <span class="admin-card__note"><?= $enrolledCount ?> in total</span>
                    </div>
                    <?php if (!$enrolledStudents): ?>
                        <p class="admin-empty">No students are enrolled in this course yet.</p>
                    <?php else: ?>
                        <form id="unenrol-form" class="admin-bulk" method="post" action="<?= h(url('admin/enrollments')) ?>" data-bulk-form
                              data-confirm-always="Remove {n} student(s) from this course? They lose access to it. Their lesson progress and grades are kept, but their rating and review of this course are removed.">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="unenrol">
                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                            <button class="admin-btn admin-btn--danger" type="submit" data-bulk-submit disabled>Remove selected</button>
                            <span class="admin-card__note" data-bulk-count aria-live="polite">0 selected</span>
                        </form>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><input type="checkbox" data-select-all data-bulk-for="unenrol-form" aria-label="Select all enrolled students"></th>
                                        <th scope="col">Student</th>
                                        <th scope="col">Enrolled on</th>
                                        <th scope="col">Progress</th>
                                        <th scope="col">Account</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($enrolledStudents as $row): ?>
                                    <tr>
                                        <td><input type="checkbox" form="unenrol-form" name="student_ids[]" value="<?= (int) $row['id'] ?>" data-select-row aria-label="Select <?= h($row['name']) ?>"></td>
                                        <td>
                                            <span class="admin-person">
                                                <span class="admin-person__avatar" aria-hidden="true"><?= h(initials((string) $row['name'])) ?></span>
                                                <span class="admin-person__text">
                                                    <span class="admin-person__name"><?= h($row['name']) ?></span>
                                                    <span class="admin-person__email"><?= h($row['email']) ?></span>
                                                </span>
                                            </span>
                                        </td>
                                        <td class="admin-table__date"><?= h(date('j M Y', (int) strtotime((string) $row['enrolled_at']))) ?></td>
                                        <td><?= $row['completed_at'] !== null ? '<span class="admin-chip admin-chip--success">Completed</span>' : '<span class="admin-chip admin-chip--muted">In progress</span>' ?></td>
                                        <td><?= $row['status'] === 'active' ? '<span class="admin-chip admin-chip--success">Active</span>' : '<span class="admin-chip admin-chip--muted">Inactive</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-card admin-users-list" aria-labelledby="enrol-title">
                    <div class="admin-card__head">
                        <h2 id="enrol-title">Enrol students</h2>
                        <span class="admin-card__note"><?= $seatsLeft !== null ? $seatsLeft . ' seat' . ($seatsLeft === 1 ? '' : 's') . ' left' : 'Only active students who are not enrolled yet' ?></span>
                    </div>
                    <?php if ($course['status'] !== 'published'): ?>
                        <p class="admin-alert" role="alert">Students can only be enrolled in a published course. Publish this course on the Courses page first.</p>
                    <?php else: ?>
                        <form class="admin-filter-form" method="get" action="<?= h(url('admin/enrollments')) ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                            <div class="admin-user-form__field">
                                <label for="enrol-search">Find a student</label>
                                <input id="enrol-search" name="q" type="search" maxlength="100" value="<?= h($pickerSearch) ?>" placeholder="Name or email">
                            </div>
                            <div class="admin-filter-form__actions">
                                <button class="admin-btn admin-btn--primary admin-btn--inline" type="submit"><span>Search</span></button>
                                <?php if ($pickerSearch !== ''): ?>
                                    <a class="admin-btn" href="<?= h(url($selfUrl())) ?>">Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if (!$available): ?>
                            <p class="admin-empty"><?= $pickerSearch !== '' ? 'No available students match that search.' : 'Every active student is already enrolled.' ?></p>
                        <?php else: ?>
                            <form id="enrol-form" class="admin-bulk" method="post" action="<?= h(url('admin/enrollments')) ?>" data-bulk-form>
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="enrol">
                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                <button class="admin-btn admin-btn--primary admin-btn--inline" type="submit" data-bulk-submit disabled>Enrol selected</button>
                                <span class="admin-card__note" data-bulk-count aria-live="polite">0 selected</span>
                            </form>
                            <?php if (count($available) >= ENROL_PICKER_LIMIT): ?>
                                <p class="admin-card__note">Showing the first <?= ENROL_PICKER_LIMIT ?> students. Use the search to find others.</p>
                            <?php endif; ?>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th scope="col"><input type="checkbox" data-select-all data-bulk-for="enrol-form" aria-label="Select all available students"></th>
                                            <th scope="col">Student</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($available as $row): ?>
                                        <tr>
                                            <td><input type="checkbox" form="enrol-form" name="student_ids[]" value="<?= (int) $row['id'] ?>" data-select-row aria-label="Select <?= h($row['name']) ?>"></td>
                                            <td>
                                                <span class="admin-person">
                                                    <span class="admin-person__avatar" aria-hidden="true"><?= h(initials((string) $row['name'])) ?></span>
                                                    <span class="admin-person__text">
                                                        <span class="admin-person__name"><?= h($row['name']) ?></span>
                                                        <span class="admin-person__email"><?= h($row['email']) ?></span>
                                                    </span>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
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
