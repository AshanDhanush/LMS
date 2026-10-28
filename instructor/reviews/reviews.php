<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/assessment_common.php';
require_once __DIR__ . '/../../api/api_report_service.php';
require_once __DIR__ . '/../../api/api_cohort_service.php';   // flash message helpers
require_once __DIR__ . '/../../api/api_review_service.php';

$user = assessmentRequireInstructor();
$pdo  = db();
$instructorId = (int) $user['id'];

$filters = reviewFilters($_GET);
$errors  = [];
$replyDraft = ['id' => 0, 'text' => ''];

/* ---------- Reply actions (POST, back to the same filtered page) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'instructor/reviews/reviews.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

    if (!assessmentCsrfValid((string) ($_POST['csrf_token'] ?? ''))) {
        cohortFlash('error', 'Your session expired. Please try again.');
        redirect($back);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        $review = $enrollmentId ? reviewOwned($pdo, (int) $enrollmentId, $instructorId) : null;

        if (!$review) {
            cohortFlash('error', 'That review could not be found.');
            redirect($back);
            exit;
        }

        if ($action === 'reply') {
            $reply = reviewValidateReply((string) ($_POST['reply'] ?? ''), $errors);
            if ($reply === null) {
                $replyDraft = ['id' => (int) $enrollmentId, 'text' => (string) ($_POST['reply'] ?? '')];
            } else {
                reviewSaveReply($pdo, (int) $enrollmentId, $reply);
                assessmentCsrfRotate();
                cohortFlash('success', 'Your reply was saved.');
                redirect($back);
                exit;
            }
        } elseif ($action === 'delete_reply') {
            reviewSaveReply($pdo, (int) $enrollmentId, null);
            assessmentCsrfRotate();
            cohortFlash('success', 'Your reply was deleted.');
            redirect($back);
            exit;
        }
    } catch (PDOException $exception) {
        error_log('Review reply failed: ' . $exception->getMessage());
        $errors[] = 'That could not be saved. Have you run the review replies SQL in phpMyAdmin?';
    }
}

/* ---------- Data ---------- */
$setupProblem = false;
$summary = ['total' => 0, 'average' => null, 'stars' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], 'with_comment' => 0, 'unreplied' => 0];
$courseStats = [];
$list = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];

try {
    $courseStats = reviewCourseStats($pdo, $instructorId);

    // Only accept a course filter that belongs to this instructor.
    $ownedIds = array_map(static fn (array $c): int => (int) $c['id'], $courseStats);
    if ($filters['course'] && !in_array($filters['course'], $ownedIds, true)) {
        $filters['course'] = null;
    }

    $summary = reviewSummary($pdo, $instructorId, $filters['course']);
    $list    = reviewList($pdo, $instructorId, $filters);
} catch (PDOException $exception) {
    error_log('Reviews page failed: ' . $exception->getMessage());
    $setupProblem = true;
}

$csrfToken = assessmentCsrfToken();
$pageUrl = static fn (array $overrides = []): string => url(
    'instructor/reviews/reviews.php?' . http_build_query(array_filter(
        array_merge([
            'course_id' => $filters['course'],
            'rating'    => $filters['rating'],
            'status'    => $filters['status'] !== 'all' ? $filters['status'] : null,
            'comments'  => $filters['comments'] ? '1' : null,
            'sort'      => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
            'q'         => $filters['q'] !== '' ? $filters['q'] : null,
        ], $overrides),
        static fn ($v) => $v !== null && $v !== ''
    ))
);
$stars = static fn (float|int|null $v): string => '<span class="ins-stars" style="--rating:' . (float) ($v ?? 0)
    . '" role="img" aria-label="' . number_format((float) ($v ?? 0), 1) . ' out of 5 stars"></span>';
$filtered = $filters['rating'] || $filters['status'] !== 'all' || $filters['comments'] || $filters['q'] !== '';

$reviewTab = 'reviews';
$pageTitleTag = 'Course reviews';
$pageHeading = 'Course reviews';

require dirname(__DIR__) . '/students/_chrome_top.php';
?>

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <h1>Course reviews</h1>
            <p>See what students say about your courses and reply to them.</p>
        </div>
        <?php if ($filters['course'] && !$setupProblem): ?>
            <a class="ins-btn" href="<?= $e(url('instructor/export-csv/reviews/' . (int) $filters['course'])) ?>">Download reviews CSV</a>
        <?php endif; ?>
    </header>

    <?php require dirname(__DIR__) . '/students/_messages.php'; ?>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <?php if ($setupProblem): ?>
        <div class="ins-lesson-message" role="alert">
            Reviews could not be loaded. Run <strong>20261028_review_replies.sql</strong> in phpMyAdmin (SQL tab, database lms_db), then reload this page.
        </div>
    <?php else: ?>

        <!-- Summary -->
        <section class="ins-card ins-review-summary" aria-label="Rating summary">
            <div class="ins-review-summary__score">
                <?php if ($summary['total'] > 0): ?>
                    <p class="ins-stat__value"><?= number_format((float) $summary['average'], 2) ?> <span>/ 5.0</span></p>
                    <?= $stars($summary['average']) ?>
                    <p><?= $summary['total'] ?> review<?= $summary['total'] === 1 ? '' : 's' ?>
                        &middot; <?= $summary['with_comment'] ?> with a written comment</p>
                    <p><strong><?= $summary['unreplied'] ?></strong> without your reply</p>
                <?php else: ?>
                    <p class="ins-stat__value ins-stat__value--quiet">No ratings yet</p>
                    <p>Students can rate a course after they enrol. Ratings will appear here.</p>
                <?php endif; ?>
            </div>

            <ol class="ins-review-bars" aria-label="Reviews by star rating">
                <?php foreach ([5, 4, 3, 2, 1] as $n): ?>
                    <?php $count = $summary['stars'][$n]; $share = $summary['total'] > 0 ? round($count / $summary['total'] * 100) : 0; ?>
                    <li>
                        <a href="<?= $e($pageUrl(['rating' => $filters['rating'] === $n ? null : $n, 'page' => null])) ?>"
                           class="<?= $filters['rating'] === $n ? 'is-current' : '' ?>">
                            <span><?= $n ?> star</span>
                            <span class="ins-report-bar"><span style="width: <?= (int) $share ?>%"></span></span>
                            <span><?= $count ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <!-- By course -->
        <?php if ($courseStats): ?>
            <div class="ins-table-wrap">
                <table class="ins-table">
                    <thead>
                        <tr><th>Course</th><th class="ins-table__num">Reviews</th><th>Average</th><th class="ins-table__num">Without reply</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($courseStats as $c): ?>
                        <tr>
                            <td><strong><?= $e($c['title']) ?></strong></td>
                            <td class="ins-table__num"><?= (int) $c['reviews'] ?></td>
                            <td>
                                <?php if ((int) $c['reviews'] > 0): ?>
                                    <?= $stars((float) $c['average']) ?> <?= number_format((float) $c['average'], 1) ?>
                                <?php else: ?><small>No ratings</small><?php endif; ?>
                            </td>
                            <td class="ins-table__num"><?= (int) $c['unreplied'] ?></td>
                            <td class="ins-table__actions">
                                <a class="ins-btn ins-btn--small" href="<?= $e($pageUrl(['course_id' => (int) $c['id'], 'page' => null])) ?>">View reviews</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="get" class="ins-report-picker" id="reviewFilters">
            <label for="reviewCourse">Course</label>
            <select id="reviewCourse" name="course_id">
                <option value="">All courses</option>
                <?php foreach ($courseStats as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filters['course'] === (int) $c['id'] ? 'selected' : '' ?>><?= $e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="reviewRating">Rating</label>
            <select id="reviewRating" name="rating">
                <option value="">Any</option>
                <?php foreach ([5, 4, 3, 2, 1] as $n): ?>
                    <option value="<?= $n ?>" <?= $filters['rating'] === $n ? 'selected' : '' ?>><?= $n ?> star<?= $n === 1 ? '' : 's' ?></option>
                <?php endforeach; ?>
            </select>

            <label for="reviewStatus">Reply</label>
            <select id="reviewStatus" name="status">
                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="unreplied" <?= $filters['status'] === 'unreplied' ? 'selected' : '' ?>>Not replied</option>
                <option value="replied" <?= $filters['status'] === 'replied' ? 'selected' : '' ?>>Replied</option>
            </select>

            <label for="reviewSort">Sort</label>
            <select id="reviewSort" name="sort">
                <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest</option>
                <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                <option value="highest" <?= $filters['sort'] === 'highest' ? 'selected' : '' ?>>Highest rating</option>
                <option value="lowest" <?= $filters['sort'] === 'lowest' ? 'selected' : '' ?>>Lowest rating</option>
            </select>

            <label class="ins-assess-check">
                <input type="checkbox" name="comments" value="1" <?= $filters['comments'] ? 'checked' : '' ?>>
                Written reviews only
            </label>

            <label for="reviewSearch">Search</label>
            <input type="search" id="reviewSearch" name="q" value="<?= $e($filters['q']) ?>" placeholder="Review text or student">

            <button type="submit" class="ins-btn">Apply</button>
            <?php if ($filtered || $filters['course']): ?>
                <a class="ins-btn" href="<?= $e(url('instructor/reviews/reviews.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Reviews -->
        <section class="ins-report-section" aria-label="Reviews">
            <p class="ins-report-summary">
                <strong><?= $list['total'] ?></strong> review<?= $list['total'] === 1 ? '' : 's' ?><?= $filtered ? ' match your filters' : '' ?>
            </p>

            <?php if (!$list['rows']): ?>
                <p class="ins-report-empty">
                    <?= $filtered ? 'No reviews match these filters.' : 'No student has reviewed your courses yet.' ?>
                </p>
            <?php endif; ?>

            <div class="ins-assess-items">
                <?php foreach ($list['rows'] as $r): ?>
                    <?php $draftHere = $replyDraft['id'] === (int) $r['id']; ?>
                    <article class="ins-assess-item" id="review-<?= (int) $r['id'] ?>">
                        <header class="ins-assess-item__head">
                            <div>
                                <h2><?= $e($r['student']) ?></h2>
                                <small><?= $e($r['course']) ?></small>
                            </div>
                            <div class="ins-review-meta">
                                <?= $stars((int) $r['rating']) ?>
                                <small><?= $e(assessmentFormatDate($r['reviewed_at'] ?: $r['enrolled_at'])) ?></small>
                            </div>
                        </header>

                        <?php if (trim((string) $r['review']) !== ''): ?>
                            <p class="ins-assess-item__desc"><?= nl2br($e($r['review'])) ?></p>
                        <?php else: ?>
                            <p class="ins-assess-item__desc"><small>Rating only, no written review.</small></p>
                        <?php endif; ?>

                        <?php if ($r['instructor_reply'] !== null && !$draftHere): ?>
                            <div class="ins-review-reply">
                                <p><strong>Your reply</strong> <small>&middot; <?= $e(assessmentFormatDate($r['replied_at'])) ?></small></p>
                                <p><?= nl2br($e($r['instructor_reply'])) ?></p>
                            </div>
                        <?php endif; ?>

                        <details class="ins-assess-details" <?= $draftHere ? 'open' : '' ?>>
                            <summary><?= $r['instructor_reply'] !== null ? 'Edit your reply' : 'Reply to this review' ?></summary>
                            <form method="post" action="" class="ins-lesson-form" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="enrollment_id" value="<?= (int) $r['id'] ?>">
                                <div class="ins-lesson-field">
                                    <label for="reply-<?= (int) $r['id'] ?>">Your reply (public to the student)</label>
                                    <textarea id="reply-<?= (int) $r['id'] ?>" name="reply" rows="3" maxlength="2000" required><?= $e($draftHere ? $replyDraft['text'] : (string) $r['instructor_reply']) ?></textarea>
                                </div>
                                <div class="ins-lesson-form__actions">
                                    <button type="submit" class="ins-btn ins-btn--primary ins-btn--small">Save reply</button>
                                </div>
                            </form>
                        </details>

                        <?php if ($r['instructor_reply'] !== null): ?>
                            <footer class="ins-assess-item__actions">
                                <form method="post" action="" data-confirm="Delete your reply to <?= $e($r['student']) ?>?">
                                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_reply">
                                    <input type="hidden" name="enrollment_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Delete reply</button>
                                </form>
                            </footer>
                        <?php endif; ?>
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
