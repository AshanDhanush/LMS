<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$profileViewer = currentUser();
if ($profileViewer === null) {
    redirect('');
}
$showStudentProgress = $profileViewer['role'] === 'student';
$showInstructorProfile = $profileViewer['role'] === 'instructor';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Learning Hub</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(url('assests/css/theme.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(url('assests/css/profile.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="topbar profile-topbar">
        <a class="brand" href="<?= htmlspecialchars(url(dashboardPath((string) $profileViewer['role'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <span class="brand__mark" aria-hidden="true">L</span>
            <span>Learning Hub</span>
        </a>
        <span class="topbar__spacer"></span>
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(url(dashboardPath((string) $profileViewer['role'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Back to dashboard</a>
    </header>

    <main class="content profile-page" id="main" data-profile-endpoint="<?= htmlspecialchars(url('api/profile_api.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <p class="alert alert--info" data-profile-status role="status">Loading your profile…</p>
        <div class="profile-content" data-profile-content hidden>
            <header class="profile-page-heading">
                <div>
                    <p class="profile-eyebrow"><?= $showInstructorProfile ? 'FACULTY WORKSPACE' : 'YOUR ACCOUNT' ?></p>
                    <h1><?= $showInstructorProfile ? 'Instructor Profile &amp; Faculty Settings' : 'Profile &amp; Settings' ?></h1>
                    <p class="text-muted"><?= $showStudentProgress ? 'Manage your account, security, and learning achievements.' : ($showInstructorProfile ? 'Review your faculty account, teaching impact, and account security.' : 'Manage your account and security settings.') ?></p>
                </div>
            </header>

            <div class="profile-layout<?= $showStudentProgress || $showInstructorProfile ? '' : ' profile-layout--single' ?><?= $showInstructorProfile ? ' profile-layout--instructor' : '' ?>">
                <div class="profile-main-column">
                    <section class="profile-card profile-head" aria-labelledby="profile-title">
                        <div class="profile-avatar" data-profile-avatar></div>
                        <div class="profile-head__details">
                            <span class="chip chip--primary"><?= $showInstructorProfile ? 'Faculty account' : htmlspecialchars(ucfirst((string) $profileViewer['role']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' account' ?></span>
                            <h2 class="profile-head__name" id="profile-title" data-profile-name></h2>
                            <p class="profile-head__email" data-profile-email></p>
                            <?php if ($showInstructorProfile): ?>
                            <dl class="profile-instructor-meta">
                                <div><dt>Username</dt><dd data-profile-username></dd></div>
                                <div><dt>Faculty since</dt><dd data-profile-joined></dd></div>
                                <div><dt>Email status</dt><dd data-profile-email-status></dd></div>
                            </dl>
                            <?php endif; ?>
                        </div>
                        <?php if (!$showInstructorProfile): ?>
                        <div class="profile-head__points">
                            <span class="profile-stat__label">Total points</span>
                            <strong class="points-total" data-profile-points></strong>
                        </div>
                        <?php endif; ?>
                        <form class="profile-avatar-form" data-profile-form="upload_avatar" enctype="multipart/form-data">
                            <div>
                                <label for="avatar">Profile photo</label>
                                <small class="text-muted">JPEG, PNG, or WebP, up to 5 MB.</small>
                            </div>
                            <div class="profile-avatar-form__controls">
                                <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required>
                                <button class="btn btn-outline btn-sm" type="submit">Upload photo</button>
                            </div>
                        </form>
                    </section>

                    <section class="card profile-section" id="security" aria-labelledby="password-title">
                        <div class="card__header">
                            <div>
                                <h2 id="password-title"><?= $showInstructorProfile ? 'Institutional Security' : 'Security &amp; Password' ?></h2>
                                <p class="text-muted"><?= $showInstructorProfile ? 'Protect your faculty account by updating your password regularly.' : 'Update your password to keep your account secure.' ?></p>
                            </div>
                            <span class="chip chip--success"<?= $showInstructorProfile ? ' data-profile-security-status' : '' ?>><?= $showInstructorProfile ? 'Checking status' : 'Account protected' ?></span>
                        </div>
                        <form class="form-grid profile-password-form" data-profile-form="change_password">
                            <div class="form-group">
                                <label class="form-label" for="current-password">Current password</label>
                                <div class="password-field">
                                    <input class="form-control" id="current-password" name="current_password" type="password" autocomplete="current-password" required>
                                    <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-controls="current-password" aria-pressed="false" title="Show password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /><path class="password-toggle__slash" d="m4 4 16 16" /></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="profile-password-pair">
                                <div class="form-group">
                                    <label class="form-label" for="new-password">New password</label>
                                    <div class="password-field">
                                        <input class="form-control" id="new-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required aria-describedby="password-guidance">
                                        <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-controls="new-password" aria-pressed="false" title="Show password">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /><path class="password-toggle__slash" d="m4 4 16 16" /></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="password-confirmation">Confirm new password</label>
                                    <div class="password-field">
                                        <input class="form-control" id="password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                                        <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-controls="password-confirmation" aria-pressed="false" title="Show password">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /><path class="password-toggle__slash" d="m4 4 16 16" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="password-guidance" id="password-guidance">
                                <div class="password-meter" role="meter" aria-label="Password strength" aria-valuemin="0" aria-valuemax="3" aria-valuenow="0" data-password-meter>
                                    <span data-password-meter-fill></span>
                                </div>
                                <p class="password-strength-label" data-password-strength-label>Use 8 or more characters, an uppercase letter, and a symbol.</p>
                                <div class="password-rules" aria-label="Password requirements">
                                    <span data-password-rule="length">8-72 characters</span>
                                    <span data-password-rule="uppercase">Uppercase letter</span>
                                    <span data-password-rule="symbol">Symbol</span>
                                </div>
                            </div>
                            <div class="profile-password-actions">
                                <button class="btn btn-primary" type="submit">Update password</button>
                            </div>
                        </form>
                    </section>
                </div>

                <?php if ($showStudentProgress): ?>
                <aside class="profile-side-column" aria-label="Learning achievements">
                    <section class="card profile-section" aria-labelledby="badges-title">
                        <div class="card__header">
                            <div>
                                <h2 id="badges-title">Achievement Badges</h2>
                                <p class="text-muted">Milestones from your learning journey.</p>
                            </div>
                            <span class="chip chip--success" data-profile-badge-count>0 earned</span>
                        </div>
                        <div class="badge-grid" data-profile-badges></div>
                    </section>

                    <section class="card profile-section" aria-labelledby="leaderboard-title">
                        <div class="card__header">
                            <div>
                                <h2 id="leaderboard-title">Semester Leaderboard</h2>
                                <p class="text-muted">Top learners by total points.</p>
                            </div>
                            <span class="chip chip--points" data-profile-rank hidden></span>
                        </div>
                        <div data-profile-leaderboard></div>
                    </section>
                </aside>
                <?php elseif ($showInstructorProfile): ?>
                <aside class="profile-side-column profile-instructor-sidebar" aria-label="Teaching overview">
                    <section class="card profile-section" aria-labelledby="teaching-impact-title">
                        <div class="card__header">
                            <div>
                                <h2 id="teaching-impact-title">Teaching Impact</h2>
                                <p class="text-muted">Your courses and learner reach.</p>
                            </div>
                        </div>
                        <div class="profile-teaching-stats" data-instructor-stats>
                            <article><span>Active courses</span><strong data-instructor-active-courses>—</strong></article>
                            <article><span>Total courses</span><strong data-instructor-total-courses>—</strong></article>
                            <article><span>Learner enrollments</span><strong data-instructor-learners>—</strong></article>
                            <article><span>Average rating</span><strong data-instructor-rating>—</strong><small data-instructor-reviews></small></article>
                        </div>
                    </section>

                    <section class="card profile-section" aria-labelledby="assigned-courses-title">
                        <div class="card__header">
                            <div>
                                <h2 id="assigned-courses-title">Your Courses</h2>
                                <p class="text-muted">Recently updated courses you teach.</p>
                            </div>
                            <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(url('instructor/courses/my_courses'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">My Courses</a>
                        </div>
                        <div class="profile-course-list" data-instructor-courses></div>
                    </section>

                    <section class="card profile-section profile-instructor-tools" aria-labelledby="faculty-tools-title">
                        <div class="card__header">
                            <div>
                                <h2 id="faculty-tools-title">Faculty Tools</h2>
                                <p class="text-muted">Continue managing your teaching workspace.</p>
                            </div>
                        </div>
                        <a class="btn btn-outline" href="<?= htmlspecialchars(url('instructor/dashboard'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Open instructor dashboard</a>
                        <a class="btn btn-outline" href="#security">Review account security</a>
                    </section>
                </aside>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="<?= htmlspecialchars(url('assests/profile.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" defer></script>
</body>
</html>
