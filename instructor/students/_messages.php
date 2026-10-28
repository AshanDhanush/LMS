<?php
/* Shows the one-time flash message and the $errors list. */
$flashMessage = cohortTakeFlash();
?>
<?php if ($flashMessage): ?>
    <div class="ins-alert ins-course-notice ins-course-notice--<?= $flashMessage['type'] === 'error' ? 'error' : 'success' ?>" role="status">
        <?= assessmentFormEscape($flashMessage['text']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="ins-lesson-message" role="alert">
        <?php foreach ($errors as $error): ?>
            <?= assessmentFormEscape($error) ?><br>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
