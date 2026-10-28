<?php
/* Ratings & reviews | Lesson comments. Needs $reviewTab ('reviews' or 'comments'). */
?>
<nav class="ins-assess-tabs" aria-label="Reviews or lesson comments">
    <a class="ins-assess-tab <?= $reviewTab === 'reviews' ? 'is-current' : '' ?>"
       href="<?= assessmentFormEscape(url('instructor/reviews/reviews.php')) ?>"
       <?= $reviewTab === 'reviews' ? 'aria-current="page"' : '' ?>>Ratings &amp; reviews</a>
    <a class="ins-assess-tab <?= $reviewTab === 'comments' ? 'is-current' : '' ?>"
       href="<?= assessmentFormEscape(url('instructor/reviews/comments.php')) ?>"
       <?= $reviewTab === 'comments' ? 'aria-current="page"' : '' ?>>Lesson comments</a>
</nav>
