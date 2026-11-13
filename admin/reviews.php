<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
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

$pageError = '';
$loadError = false;
$notReady  = false;
$pending   = $recent = [];
$outline   = [];
$pdo       = null;

try {
    $pdo = db();
    $notReady = !courseReviewReady($pdo);
} catch (Throwable $error) {
    error_log('Admin reviews: database unavailable: ' . $error->getMessage());
    $loadError = true;
}

/* ---------------- Actions (POST) ---------------- */
if ($pdo && !$notReady && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action   = (string) ($_POST['action'] ?? '');
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $note     = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000);

    try {
        if (!adminCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
            $pageError = 'Your session expired. Please try again.';
        } else {
            $find = $pdo->prepare("SELECT id, title, instructor_id FROM courses WHERE id = :id AND status = 'pending'");
            $find->execute(['id' => $courseId]);
            $course = $courseId ? $find->fetch() : false;

            if (!$course) {
                $pageError = 'That course is no longer waiting for review.';
            } elseif ($action === 'approve') {
                $lessons = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = :id');
                $lessons->execute(['id' => $course['id']]);
                if ((int) $lessons->fetchColumn() < 1) {
                    $pageError = 'This course has no lessons yet, so it cannot be published. Send it back instead.';
                } else {
                    $pdo->prepare("UPDATE courses SET status = 'published', reviewed_by = :admin, reviewed_at = NOW(), review_note = :note WHERE id = :id AND status = 'pending'")
                        ->execute(['admin' => $user['id'], 'note' => $note !== '' ? $note : null, 'id' => $course['id']]);
                    auditLog($pdo, $user, 'course.approve', 'course', (int) $course['id'], 'Approved and published course "' . $course['title'] . '"', ['note' => $note]);
                    notifyCourseInstructor($pdo, (int) $course['id'], 'course', 'Course approved: ' . $course['title'],
                        'Your course is now published and visible to students.' . ($note !== '' ? ' Note: ' . $note : ''), 'instructor/courses/course_lesson/' . (int) $course['id']);
                    adminCsrfRotate();
                    redirect('admin/reviews?approved=1');
                }
            } elseif ($action === 'reject') {
                if ($note === '') {
                    $pageError = 'Write a short note so the instructor knows what to fix.';
                } else {
                    $pdo->prepare("UPDATE courses SET status = 'draft', reviewed_by = :admin, reviewed_at = NOW(), review_note = :note WHERE id = :id AND status = 'pending'")
                        ->execute(['admin' => $user['id'], 'note' => $note, 'id' => $course['id']]);
                    auditLog($pdo, $user, 'course.reject', 'course', (int) $course['id'], 'Sent course "' . $course['title'] . '" back to draft', ['note' => $note]);
                    notifyCourseInstructor($pdo, (int) $course['id'], 'course', 'Course needs changes: ' . $course['title'],
                        'Admin note: ' . $note, 'instructor/courses/course_lesson/' . (int) $course['id']);
                    adminCsrfRotate();
                    redirect('admin/reviews?rejected=1');
                }
            }
        }
    } catch (Throwable $error) {
        error_log('Admin course review failed: ' . $error->getMessage());
        $pageError = 'That could not be saved. Please try again.';
    }
}

/* ---------------- Load the queue ---------------- */
if ($pdo && !$notReady && !$loadError) {
    try {
        $base = 'SELECT c.id, c.title, c.description, c.status, c.submitted_at, c.reviewed_at, c.review_note,
                        u.name AS instructor, u.email AS instructor_email, cat.name AS category,
                        (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS modules,
                        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons,
                        (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id) AS assignments,
                        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id) AS quizzes
                 FROM courses c
                 JOIN users u ON u.id = c.instructor_id
                 LEFT JOIN categories cat ON cat.id = c.category_id';
        $pending = $pdo->query($base . " WHERE c.status = 'pending' ORDER BY c.submitted_at ASC, c.id ASC")->fetchAll();
        $recent  = $pdo->query($base . ' WHERE c.reviewed_at IS NOT NULL ORDER BY c.reviewed_at DESC LIMIT 10')->fetchAll();

        // Lesson titles per pending course, so the admin can see what is inside
        $lessonStmt = $pdo->prepare('SELECT title, type FROM lessons WHERE course_id = :id ORDER BY position ASC, id ASC');
        foreach ($pending as $c) {
            $lessonStmt->execute(['id' => $c['id']]);
            $outline[(int) $c['id']] = $lessonStmt->fetchAll();
        }
    } catch (Throwable $error) {
        error_log('Admin reviews failed to load: ' . $error->getMessage());
        $loadError = true;
    }
}

$csrfToken       = adminCsrfToken();
$adminPageTitle  = 'Course reviews | Learning Hub Admin';
$adminActivePage = 'reviews';
require __DIR__ . '/header.php';
?>

        <main class="admin-main" id="main">
            <div class="admin-container">
                <section class="admin-hero">
                    <h1>Course reviews</h1>
                    <p>Instructors submit courses here. Approve a course to publish it, or send it back with a note.</p>
                </section>

                <?php if (isset($_GET['approved'])): ?><p class="admin-alert admin-alert--success" role="status">The course was approved and is now published. The instructor was notified.</p><?php endif; ?>
                <?php if (isset($_GET['rejected'])): ?><p class="admin-alert admin-alert--success" role="status">The course was sent back to draft with your note. The instructor was notified.</p><?php endif; ?>
                <?php if ($pageError !== ''): ?><p class="admin-alert" role="alert"><?= h($pageError) ?></p><?php endif; ?>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">The reviews could not be loaded. Please try again shortly.</p>
                <?php elseif ($notReady): ?>
                    <p class="admin-alert" role="alert">The course review step is not switched on yet. Run <strong>database/migrations/001_admin_tools.sql</strong> once in phpMyAdmin (SQL tab), then reload this page. Until then, instructors can still publish directly.</p>
                <?php else: ?>

                <section class="admin-card" aria-labelledby="queue-title">
                    <div class="admin-card__head">
                        <h2 id="queue-title">Waiting for review</h2>
                        <span class="admin-card__note"><?= count($pending) ?> course<?= count($pending) === 1 ? '' : 's' ?>, oldest first</span>
                    </div>
                    <?php if (!$pending): ?>
                        <p class="admin-empty admin-empty--good">Nothing is waiting for review.</p>
                    <?php else: ?>
                        <?php foreach ($pending as $c): ?>
                            <article class="admin-review">
                                <div class="admin-review__head">
                                    <div>
                                        <h3 class="admin-review__title"><?= h($c['title']) ?></h3>
                                        <p class="admin-person__email">
                                            By <?= h($c['instructor']) ?> (<?= h($c['instructor_email']) ?>)
                                            · <?= $c['category'] ? h($c['category']) : 'No category' ?>
                                            · Submitted <?= $c['submitted_at'] ? h(date('j M Y, H:i', (int) strtotime($c['submitted_at']))) : 'earlier' ?>
                                        </p>
                                    </div>
                                    <span class="admin-chip admin-chip--warning">Pending</span>
                                </div>
                                <p class="admin-review__text"><?= $c['description'] ? h(mb_strimwidth((string) $c['description'], 0, 400, '…', 'UTF-8')) : 'No description written.' ?></p>
                                <p class="admin-person__email">
                                    <?= (int) $c['modules'] ?> module<?= (int) $c['modules'] === 1 ? '' : 's' ?>
                                    · <?= (int) $c['lessons'] ?> lesson<?= (int) $c['lessons'] === 1 ? '' : 's' ?>
                                    · <?= (int) $c['assignments'] ?> assignment<?= (int) $c['assignments'] === 1 ? '' : 's' ?>
                                    · <?= (int) $c['quizzes'] ?> quiz<?= (int) $c['quizzes'] === 1 ? '' : 'zes' ?>
                                </p>
                                <?php if ((int) $c['lessons'] === 0): ?>
                                    <p class="admin-alert" role="alert">This course has no lessons, so it cannot be approved yet.</p>
                                <?php else: ?>
                                    <details>
                                        <summary class="admin-person__email">See the lessons</summary>
                                        <ol class="admin-review__lessons">
                                            <?php foreach ($outline[(int) $c['id']] ?? [] as $lesson): ?>
                                                <li><?= h($lesson['title']) ?> <span class="admin-person__email">(<?= h($lesson['type']) ?>)</span></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    </details>
                                <?php endif; ?>
                                <?php if ($c['review_note']): ?>
                                    <p class="admin-person__email">Your earlier note: <?= h($c['review_note']) ?></p>
                                <?php endif; ?>

                                <form class="admin-review__form" method="post" action="">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                    <label for="note-<?= (int) $c['id'] ?>">Note to the instructor <span class="admin-person__email">(required when sending back)</span></label>
                                    <textarea id="note-<?= (int) $c['id'] ?>" name="note" rows="2" maxlength="1000"></textarea>
                                    <div class="admin-actions">
                                        <button class="admin-btn" type="submit" name="action" value="approve"<?= (int) $c['lessons'] === 0 ? ' disabled' : '' ?>>Approve and publish</button>
                                        <button class="admin-btn admin-delete-btn" type="submit" name="action" value="reject">Send back</button>
                                    </div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="admin-card" aria-labelledby="recent-title">
                    <div class="admin-card__head"><h2 id="recent-title">Recently reviewed</h2><span class="admin-card__note">Last 10</span></div>
                    <?php if (!$recent): ?>
                        <p class="admin-empty">No course has been reviewed yet.</p>
                    <?php else: ?>
                        <ul class="admin-list">
                            <?php foreach ($recent as $c): ?>
                                <li class="admin-list__item">
                                    <span class="admin-list__main">
                                        <span class="admin-list__title"><?= h($c['title']) ?></span>
                                        <span class="admin-list__sub"><?= h($c['instructor']) ?> · <?= h(date('j M Y', (int) strtotime($c['reviewed_at']))) ?><?= $c['review_note'] ? ' · ' . h($c['review_note']) : '' ?></span>
                                    </span>
                                    <?php if ($c['status'] === 'published'): ?><span class="admin-chip admin-chip--success">Published</span>
                                    <?php elseif ($c['status'] === 'pending'): ?><span class="admin-chip admin-chip--warning">Pending</span>
                                    <?php else: ?><span class="admin-chip admin-chip--muted">Draft</span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
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
