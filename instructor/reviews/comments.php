<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/assessment_common.php';
require_once __DIR__ . '/../../api/api_report_service.php';
require_once __DIR__ . '/../../api/api_cohort_service.php';   // flash message helpers
require_once __DIR__ . '/../../api/api_comment_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$filters = commentFilters($_GET);
$errors  = [];
$draft   = ['key' => '', 'text' => ''];   // keeps the text of a reply that failed validation

/* ---------- Actions (POST, back to the same filtered page) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'instructor/reviews/comments.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        cohortFlash('error', 'Your session expired. Please try again.');
        redirect($back);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $commentId = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if ($action === 'reply') {
            $parent = $commentId ? commentAnswerable($pdo, (int) $commentId, $instructorId) : null;
            if (!$parent) {
                cohortFlash('error', 'That comment could not be found.');
                redirect($back);
                exit;
            }

            $body = commentValidateReply((string) ($_POST['reply'] ?? ''), $errors);
            if ($body === null) {
                $draft = ['key' => 'new-' . (int) $commentId, 'text' => (string) ($_POST['reply'] ?? '')];
            } else {
                commentAddReply($pdo, $parent, $instructorId, $body);
                assessmentCsrfRotate();
                cohortFlash('success', 'Your reply was posted.');
                redirect($back);
                exit;
            }
        } elseif ($action === 'edit_reply' || $action === 'delete_reply') {
            $own = $commentId ? commentOwnReply($pdo, (int) $commentId, $instructorId) : null;
            if (!$own) {
                cohortFlash('error', 'That reply could not be found.');
                redirect($back);
                exit;
            }

            if ($action === 'delete_reply') {
                commentDeleteReply($pdo, (int) $commentId, $instructorId);
                assessmentCsrfRotate();
                cohortFlash('success', 'Your reply was deleted.');
                redirect($back);
                exit;
            }

            $body = commentValidateReply((string) ($_POST['reply'] ?? ''), $errors);
            if ($body === null) {
                $draft = ['key' => 'edit-' . (int) $commentId, 'text' => (string) ($_POST['reply'] ?? '')];
            } else {
                commentUpdateReply($pdo, (int) $commentId, $instructorId, $body);
                assessmentCsrfRotate();
                cohortFlash('success', 'Your reply was updated.');
                redirect($back);
                exit;
            }
        }
    } catch (PDOException $exception) {
        error_log('Comment action failed: ' . $exception->getMessage());
        $errors[] = 'That could not be saved. Have you run the comment replies SQL in phpMyAdmin?';
    }
}

/* ---------- Data ---------- */
$setupProblem = false;
$lessons = [];
$summary = ['total' => 0, 'needs' => 0];
$list = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];

try {
    $lessons = commentLessons($pdo, $instructorId);

    // Only accept course and lesson filters that belong to this instructor.
    $courseIds = array_unique(array_map(static fn (array $l): int => (int) $l['course_id'], $lessons));
    $lessonIds = array_map(static fn (array $l): int => (int) $l['id'], $lessons);
    if ($filters['course'] && !in_array($filters['course'], $courseIds, true)) {
        $filters['course'] = null;
    }
    if ($filters['lesson'] && !in_array($filters['lesson'], $lessonIds, true)) {
        $filters['lesson'] = null;
    }

    $summary = commentSummary($pdo, $instructorId, $filters);
    $list    = commentList($pdo, $instructorId, $filters);
} catch (PDOException $exception) {
    error_log('Comments page failed: ' . $exception->getMessage());
    $setupProblem = true;
}

$courseTitles = [];
foreach ($lessons as $l) {
    $courseTitles[(int) $l['course_id']] = (string) $l['course'];
}

$csrfToken = assessmentCsrfToken();
$pageUrl = static fn (array $overrides = []): string => url(
    'instructor/reviews/comments.php?' . http_build_query(array_filter(
        array_merge([
            'course_id' => $filters['course'],
            'lesson_id' => $filters['lesson'],
            'status'    => $filters['status'] !== 'all' ? $filters['status'] : null,
            'sort'      => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
            'q'         => $filters['q'] !== '' ? $filters['q'] : null,
        ], $overrides),
        static fn ($v) => $v !== null && $v !== ''
    ))
);
$filtered = $filters['course'] || $filters['lesson'] || $filters['status'] !== 'all' || $filters['q'] !== '';

$reviewTab = 'comments';
$pageTitleTag = 'Lesson comments';
$pageHeading = 'Course reviews';

require dirname(__DIR__) . '/students/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Course reviews</h1>
            <p>Read what students write on your lessons and reply to them.</p>
        </div>
    </header>

    <?php require dirname(__DIR__) . '/students/_messages.php'; ?>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <?php if ($setupProblem): ?>
        <div class="ins-lesson-message" role="alert">
            Comments could not be loaded. Run <strong>20261028_comment_replies.sql</strong> in phpMyAdmin (SQL tab, database lms_db), then reload this page.
        </div>
    <?php else: ?>

        <p class="ins-report-summary">
            <strong><?= $summary['total'] ?></strong> student comment<?= $summary['total'] === 1 ? '' : 's' ?>
            &middot; <strong><?= $summary['needs'] ?></strong> without your reply
        </p>

        <form method="get" class="ins-report-picker">
            <label for="commentCourse">Course</label>
            <select id="commentCourse" name="course_id">
                <option value="">All courses</option>
                <?php foreach ($courseTitles as $cid => $ctitle): ?>
                    <option value="<?= (int) $cid ?>" <?= $filters['course'] === (int) $cid ? 'selected' : '' ?>><?= $e($ctitle) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="commentLesson">Lesson</label>
            <select id="commentLesson" name="lesson_id">
                <option value="">All lessons</option>
                <?php foreach ($lessons as $l): ?>
                    <option value="<?= (int) $l['id'] ?>" <?= $filters['lesson'] === (int) $l['id'] ? 'selected' : '' ?>>
                        <?= $e($l['course']) ?>: <?= $e($l['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="commentStatus">Reply</label>
            <select id="commentStatus" name="status">
                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="needs" <?= $filters['status'] === 'needs' ? 'selected' : '' ?>>Needs my reply</option>
                <option value="replied" <?= $filters['status'] === 'replied' ? 'selected' : '' ?>>I replied</option>
            </select>

            <label for="commentSort">Sort</label>
            <select id="commentSort" name="sort">
                <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest</option>
                <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest</option>
            </select>

            <label for="commentSearch">Search</label>
            <input type="search" id="commentSearch" name="q" value="<?= $e($filters['q']) ?>" placeholder="Comment text or student">

            <button type="submit" class="ins-btn">Apply</button>
            <?php if ($filtered): ?>
                <a class="ins-btn" href="<?= $e(url('instructor/reviews/comments.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <section class="ins-report-section" aria-label="Lesson comments">
            <p class="ins-report-summary">
                <strong><?= $list['total'] ?></strong> comment<?= $list['total'] === 1 ? '' : 's' ?><?= $filtered ? ' match your filters' : '' ?>
            </p>

            <?php if (!$list['rows']): ?>
                <p class="ins-report-empty">
                    <?= $filtered ? 'No comments match these filters.' : 'No student has commented on your lessons yet.' ?>
                </p>
            <?php endif; ?>

            <div class="ins-assess-items">
                <?php foreach ($list['rows'] as $r): ?>
                    <?php $newKey = 'new-' . (int) $r['id']; ?>
                    <article class="ins-assess-item" id="comment-<?= (int) $r['id'] ?>">
                        <header class="ins-assess-item__head">
                            <div>
                                <h2><?= $e($r['student']) ?></h2>
                                <small><?= $e($r['course']) ?> &rsaquo; <?= $e($r['lesson']) ?></small>
                            </div>
                            <small><?= $e(assessmentFormatDate($r['created_at'])) ?></small>
                        </header>

                        <p class="ins-assess-item__desc"><?= nl2br($e($r['body'])) ?></p>

                        <?php foreach ($r['replies'] as $reply): ?>
                            <?php
                            $mine = (int) $reply['user_id'] === $instructorId;
                            $editKey = 'edit-' . (int) $reply['id'];
                            $editingNow = $draft['key'] === $editKey;
                            ?>
                            <div class="ins-review-reply">
                                <p>
                                    <strong><?= $mine ? 'You' : $e($reply['name']) ?></strong>
                                    <small>&middot; <?= $e(assessmentFormatDate($reply['created_at'])) ?></small>
                                </p>
                                <p><?= nl2br($e($reply['body'])) ?></p>

                                <?php if ($mine): ?>
                                    <details class="ins-assess-details" <?= $editingNow ? 'open' : '' ?>>
                                        <summary>Edit</summary>
                                        <form method="post" action="" class="ins-lesson-form" novalidate>
                                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="edit_reply">
                                            <input type="hidden" name="comment_id" value="<?= (int) $reply['id'] ?>">
                                            <div class="ins-lesson-field">
                                                <label for="reply-<?= (int) $reply['id'] ?>">Your reply</label>
                                                <textarea id="reply-<?= (int) $reply['id'] ?>" name="reply" rows="3" maxlength="2000" required><?= $e($editingNow ? $draft['text'] : $reply['body']) ?></textarea>
                                            </div>
                                            <div class="ins-lesson-form__actions">
                                                <button type="submit" class="ins-btn ins-btn--primary ins-btn--small">Save</button>
                                            </div>
                                        </form>
                                    </details>
                                    <form method="post" action="" data-confirm="Delete your reply?">
                                        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_reply">
                                        <input type="hidden" name="comment_id" value="<?= (int) $reply['id'] ?>">
                                        <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <details class="ins-assess-details" <?= $draft['key'] === $newKey ? 'open' : '' ?>>
                            <summary>Reply</summary>
                            <form method="post" action="" class="ins-lesson-form" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="comment_id" value="<?= (int) $r['id'] ?>">
                                <div class="ins-lesson-field">
                                    <label for="new-<?= (int) $r['id'] ?>">Your reply</label>
                                    <textarea id="new-<?= (int) $r['id'] ?>" name="reply" rows="3" maxlength="2000" required><?= $e($draft['key'] === $newKey ? $draft['text'] : '') ?></textarea>
                                </div>
                                <div class="ins-lesson-form__actions">
                                    <button type="submit" class="ins-btn ins-btn--primary ins-btn--small">Post reply</button>
                                </div>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($list['pages'] > 1): ?>
                <nav class="ins-assess-tabs ins-review-pages" aria-label="Pages">
                    <?php if ($list['page'] > 1): ?>
                        <a class="ins-assess-tab" href="<?= $e($pageUrl(['page' => $list['page'] - 1])) ?>">&larr; Previous</a>
                    <?php endif; ?>
                    <span class="ins-assess-tab is-current">Page <?= $list['page'] ?> of <?= $list['pages'] ?></span>
                    <?php if ($list['page'] < $list['pages']): ?>
                        <a class="ins-assess-tab" href="<?= $e($pageUrl(['page' => $list['page'] + 1])) ?>">Next &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>

    <?php endif; ?>

<?php require dirname(__DIR__) . '/students/_chrome_bottom.php'; ?>
