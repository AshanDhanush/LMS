<?php
/* Students | Cohorts tabs. Needs $courseId and $activeTab ('students' or 'cohorts'). */
$tabBase = url('instructor/students/');
?>
<nav class="ins-assess-tabs" aria-label="Students or cohorts">
    <a class="ins-assess-tab <?= $activeTab === 'students' ? 'is-current' : '' ?>"
       href="<?= assessmentFormEscape($tabBase . 'students/' . (int) $courseId) ?>"
       <?= $activeTab === 'students' ? 'aria-current="page"' : '' ?>>Students</a>
    <a class="ins-assess-tab <?= $activeTab === 'cohorts' ? 'is-current' : '' ?>"
       href="<?= assessmentFormEscape($tabBase . 'cohorts/' . (int) $courseId) ?>"
       <?= $activeTab === 'cohorts' ? 'aria-current="page"' : '' ?>>Cohorts</a>
</nav>
