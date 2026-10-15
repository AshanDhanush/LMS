<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create your Learning Hub account.">
    <title>Create account | Learning Hub</title>
    <link rel="stylesheet" href="assests/theme.css">
    <link rel="stylesheet" href="assests/auth.css">
</head>
<body>
    <main class="account-auth">
        <section class="account-auth__shell account-auth__shell--single" aria-label="Create a Learning Hub account">
            <section class="account-auth__form-panel" aria-labelledby="signup-title">
                <div class="account-auth__mobile-brand">
                    <a class="account-auth__brand" href="./" aria-label="Learning Hub home">
                        <span class="account-auth__brand-mark" aria-hidden="true">L</span>
                        <span>Learning Hub</span>
                    </a>
                </div>

                <div class="account-auth__form-content">
                    <span class="account-auth__welcome-label">GET STARTED</span>
                    <h2 id="signup-title">Create your account</h2>
                    <p class="account-auth__intro">Sign up to start learning at your own pace.</p>
                    <p class="account-auth__status" id="signup-status" role="status" aria-live="polite" hidden></p>

                    <form class="account-auth__form" data-auth-action="register" data-status-id="signup-status">
                        <div class="form-group">
                            <label class="form-label" for="name">Full name</label>
                            <input class="form-control" id="name" name="name" type="text" maxlength="100" autocomplete="name" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email address</label>
                            <input class="form-control" id="email" name="email" type="email" maxlength="190" placeholder="you@example.com" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password-confirmation">Confirm password</label>
                            <input class="form-control" id="password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="role">Role</label>
                            <select class="form-control" id="role" name="role" required>
                                <option value="student" selected>Student</option>
                                <option value="teacher">Teacher</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>

                        <button class="btn btn-primary btn-lg btn-block account-auth__submit" type="submit">
                            <span>Create account</span>
                        </button>
                    </form>

                    <p class="account-auth__signup">Already have an account? <a href="index.php">Sign in</a></p>
                </div>

                <footer class="account-auth__footer">
                    <span>© <?php echo date('Y'); ?> Learning Hub</span>
                    <span>Learn something new today.</span>
                </footer>
            </section>
        </section>
    </main>
    <script src="assests/auth.js" defer></script>
</body>
</html>
