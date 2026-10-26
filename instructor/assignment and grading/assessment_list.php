<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_service.php';
require_once dirname(__DIR__, 2) . '/includes/assessment_common.php';

$user = assessmentRequireInstructor();
$pdo  = db();

$courseId = filter_var($_GET['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$courseId) {
    http_response_code(400);
    exit('Invalid course ID.');
}

$course = assessmentOwnedCourse($pdo, (int) $courseId, (int) $user['id']);
if (!$course) {
    http_response_code(404);
    exit('Course not found or you do not own it.');
}

$type = ($_GET['type'] ?? 'assignment') === 'quiz' ? 'quiz' : 'assignment';

$assessmentsPageUrl = url('instructor/assignment and grading/assignments_and_grading.php');
$pageUrl   = static fn (string $t): string => url(
    'instructor/assignment and grading/assessment_list.php?course_id=' . (int) $courseId . '&type=' . $t
);
$addUrl    = url('instructor/assignment and grading/assessment_add_form?course_id=' . (int) $courseId);

/* ---- Load both lists (the tabs show both counts) ---- */

$stmt = $pdo->prepare(
    'SELECT a.*,
            (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS submission_count
     FROM assignments a
     WHERE a.course_id = :course_id
     ORDER BY a.due_date DESC, a.id DESC'
);
$stmt->execute(['course_id' => $courseId]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT q.*,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count
     FROM quizzes q
     WHERE q.course_id = :course_id
     ORDER BY q.created_at DESC, q.id DESC'
);
$stmt->execute(['course_id' => $courseId]);
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = assessmentCsrfToken();
$flashKey = null;
foreach (['added', 'updated', 'deleted'] as $key) {
    if (isset($_GET[$key])) {
        $flashKey = $key;
    }
}

$availabilityLabels = [
    'always'    => ['Always open', 'ins-chip--muted'],
    'open'      => ['Open now', 'ins-chip--success'],
    'scheduled' => ['Scheduled', 'ins-chip--primary'],
    'closed'    => ['Closed', 'ins-chip--danger'],
];

$pageTitle = 'Assignments and Quizzes';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= assessmentFormEscape($course['title']) ?> | Assignments and Quizzes | Learning Hub</title>
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= assessmentFormEscape(url('assests/css/instructor_dashboard.css')) ?>">
</head>

<body class="ins">

<a class="ins-skip" href="#main">Skip to content</a>

<div class="ins-layout">

    <?php require dirname(__DIR__) . '/sidebar.php'; ?>

    <div class="ins-body">

        <?php $instructorHeaderTitle = 'Assignments & grading'; require dirname(__DIR__) . '/header.php'; ?>

<main class="ins-main ins-curriculum" id="main">

    <header class="ins-curr-header">
        <div class="ins-curr-header__left">
            <a class="ins-lesson-back" href="<?= assessmentFormEscape($assessmentsPageUrl) ?>">&larr; Back to Assignments and Quizzes</a>
            <h1><?= assessmentFormEscape($course['title']) ?></h1>
            <p>Choose Assignments or Quizzes, then view, edit or delete them.</p>
        </div>
        <a class="ins-btn ins-btn--primary" href="<?= assessmentFormEscape($addUrl) ?>">+ Add assignment or quiz</a>
    </header>

    <?php if ($flashKey): ?>
        <?php
        $flashWhat = in_array((string) $_GET[$flashKey], ['quiz', 'assignment'], true) ? ucfirst((string) $_GET[$flashKey]) : 'Item';
        $flashHow  = ['added' => 'added', 'updated' => 'updated', 'deleted' => 'deleted'][$flashKey];
        ?>
        <div class="ins-alert ins-course-notice ins-course-notice--success" role="status">
            <?= assessmentFormEscape($flashWhat . ' ' . $flashHow . ' successfully.') ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="ins-lesson-message" role="alert">
            <?= assessmentFormEscape((string) $_GET['error'] === 'csrf'
                ? 'Your session expired. Please try again.'
                : 'That item could not be found or changed.') ?>
        </div>
    <?php endif; ?>

    <nav class="ins-assess-tabs" aria-label="Assignments or quizzes">
        <a class="ins-assess-tab <?= $type === 'assignment' ? 'is-current' : '' ?>"
           href="<?= assessmentFormEscape($pageUrl('assignment')) ?>"
           <?= $type === 'assignment' ? 'aria-current="page"' : '' ?>>
            Assignments <span><?= count($assignments) ?></span>
        </a>
        <a class="ins-assess-tab <?= $type === 'quiz' ? 'is-current' : '' ?>"
           href="<?= assessmentFormEscape($pageUrl('quiz')) ?>"
           <?= $type === 'quiz' ? 'aria-current="page"' : '' ?>>
            Quizzes <span><?= count($quizzes) ?></span>
        </a>
    </nav>

    <?php if ($type === 'assignment'): ?>

        <?php if (!$assignments): ?>
            <div class="ins-curr-empty-state">
                <h2>No assignments yet</h2>
                <p>This course has no assignments. Use "Add assignment or quiz" to create one.</p>
            </div>
        <?php endif; ?>

        <section class="ins-assess-items" aria-label="Assignments">
            <?php foreach ($assignments as $item): ?>
                <?php $isPast = strtotime((string) $item['due_date']) < time(); ?>
                <article class="ins-assess-item">
                    <header class="ins-assess-item__head">
                        <h2><?= assessmentFormEscape($item['title']) ?></h2>
                        <span class="ins-chip <?= $isPast ? 'ins-chip--muted' : 'ins-chip--success' ?>">
                            <?= $isPast ? 'Past due' : 'Open' ?>
                        </span>
                    </header>

                    <?php if (!empty($item['description'])): ?>
                        <p class="ins-assess-item__desc"><?= nl2br(assessmentFormEscape($item['description'])) ?></p>
                    <?php endif; ?>

                    <dl class="ins-assess-meta">
                        <div><dt>Due</dt><dd><?= assessmentFormEscape(assessmentFormatDate($item['due_date'])) ?></dd></div>
                        <div><dt>Max mark</dt><dd><?= (int) $item['max_mark'] ?></dd></div>
                        <div><dt>Submissions</dt><dd><?= (int) $item['submission_count'] ?></dd></div>
                        <div><dt>File</dt><dd><?= assessmentFormEscape($item['file_name'] ?? 'None') ?></dd></div>
                    </dl>

                    <footer class="ins-assess-item__actions">
                        <a class="ins-btn ins-btn--small"
                           href="<?= assessmentFormEscape(url('instructor/assignment and grading/assignment_edit_form.php?id=' . (int) $item['id'])) ?>">Edit</a>

                        <form method="post" action="<?= assessmentFormEscape(url('instructor/assignment and grading/assessment_delete.php')) ?>"
                              data-confirm="Delete the assignment &quot;<?= assessmentFormEscape($item['title']) ?>&quot;?<?= (int) $item['submission_count'] > 0
                                  ? ' Its ' . (int) $item['submission_count'] . ' student submission(s) and marks will be deleted too.' : '' ?> This cannot be undone.">
                            <input type="hidden" name="csrf_token" value="<?= assessmentFormEscape($csrfToken) ?>">
                            <input type="hidden" name="type" value="assignment">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                            <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Delete</button>
                        </form>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>

    <?php else: ?>

        <?php if (!$quizzes): ?>
            <div class="ins-curr-empty-state">
                <h2>No quizzes yet</h2>
                <p>This course has no quizzes. Use "Add assignment or quiz" to create one.</p>
            </div>
        <?php endif; ?>

        <section class="ins-assess-items" aria-label="Quizzes">
            <?php foreach ($quizzes as $item): ?>
                <?php
                $availability = quizAvailability($item['start_time'] ?? null, $item['end_time'] ?? null);
                [$availabilityText, $availabilityClass] = $availabilityLabels[$availability];
                $questions = json_decode((string) $item['questions'], true);
                $questions = is_array($questions) ? $questions : [];
                ?>
                <article class="ins-assess-item">
                    <header class="ins-assess-item__head">
                        <h2><?= assessmentFormEscape($item['title']) ?></h2>
                        <span class="ins-chip <?= assessmentFormEscape($availabilityClass) ?>"><?= assessmentFormEscape($availabilityText) ?></span>
                    </header>

                    <?php if (!empty($item['description'])): ?>
                        <p class="ins-assess-item__desc"><?= nl2br(assessmentFormEscape($item['description'])) ?></p>
                    <?php endif; ?>

                    <dl class="ins-assess-meta">
                        <div><dt>Questions</dt><dd><?= count($questions) ?></dd></div>
                        <div><dt>Total marks</dt><dd><?= (int) ($item['total_marks'] ?? 0) ?></dd></div>
                        <div><dt>Pass mark</dt><dd><?= (int) $item['pass_mark'] ?>%</dd></div>
                        <div><dt>Attempts</dt><dd><?= (int) $item['max_attempts'] ?> allowed, <?= (int) $item['attempt_count'] ?> taken</dd></div>
                        <div><dt>Starts</dt><dd><?= assessmentFormEscape(assessmentFormatDate($item['start_time'] ?? null) ?: 'No limit') ?></dd></div>
                        <div><dt>Ends</dt><dd><?= assessmentFormEscape(assessmentFormatDate($item['end_time'] ?? null) ?: 'No limit') ?></dd></div>
                    </dl>

                    <details class="ins-assess-details">
                        <summary>View quiz questions and answers</summary>

                        <?php if (!$questions): ?>
                            <p>No questions in this quiz.</p>
                        <?php endif; ?>

                        <ol class="ins-assess-questions">
                            <?php foreach ($questions as $q): ?>
                                <li>
                                    <p class="ins-assess-q__text">
                                        <?= assessmentFormEscape($q['text'] ?? '') ?>
                                        <span class="ins-chip ins-chip--muted">
                                            <?= ($q['type'] ?? '') === 'mcq' ? 'MCQ' : 'Essay' ?>, <?= (int) ($q['marks'] ?? 0) ?> mark(s)
                                        </span>
                                    </p>

                                    <?php if (($q['type'] ?? '') === 'mcq'): ?>
                                        <ul class="ins-assess-options">
                                            <?php foreach (($q['options'] ?? []) as $index => $option): ?>
                                                <?php $isCorrect = (int) ($q['correct'] ?? -1) === (int) $index; ?>
                                                <li class="<?= $isCorrect ? 'is-correct' : '' ?>">
                                                    <?= assessmentFormEscape(chr(65 + (int) $index)) ?>. <?= assessmentFormEscape($option) ?>
                                                    <?= $isCorrect ? '<strong> &#10003; Correct answer</strong>' : '' ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php elseif (!empty($q['model_answer'])): ?>
                                        <p class="ins-assess-model"><strong>Model answer:</strong> <?= nl2br(assessmentFormEscape($q['model_answer'])) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </details>

                    <footer class="ins-assess-item__actions">
                        <a class="ins-btn ins-btn--small"
                           href="<?= assessmentFormEscape(url('instructor/assignment and grading/quiz_edit_form.php?id=' . (int) $item['id'])) ?>">Edit</a>

                        <form method="post" action="<?= assessmentFormEscape(url('instructor/assignment and grading/assessment_delete.php')) ?>"
                              data-confirm="Delete the quiz &quot;<?= assessmentFormEscape($item['title']) ?>&quot;?<?= (int) $item['attempt_count'] > 0
                                  ? ' Its ' . (int) $item['attempt_count'] . ' student attempt(s) and scores will be deleted too.' : '' ?> This cannot be undone.">
                            <input type="hidden" name="csrf_token" value="<?= assessmentFormEscape($csrfToken) ?>">
                            <input type="hidden" name="type" value="quiz">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                            <button type="submit" class="ins-btn ins-btn--small ins-btn--danger">Delete</button>
                        </form>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>

    <?php endif; ?>

</main>

    </div>
</div>

<script src="<?= assessmentFormEscape(url('assests/instructor_dashboard.js')) ?>" defer></script>

</body>
</html>
