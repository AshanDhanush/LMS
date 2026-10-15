<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to your learning account.">
    <title>Sign in | Learning Hub</title>
    <link rel="stylesheet" href="assests/theme.css">
    <link rel="stylesheet" href="assests/auth.css">
</head>
<body>
    <main class="account-auth">
        <section class="account-auth__shell" aria-label="Sign in to Learning Hub">
            <aside class="account-auth__welcome">
                <a class="account-auth__brand" href="./" aria-label="Learning Hub home">
                    <span class="account-auth__brand-mark" aria-hidden="true">L</span>
                    <span>Learning Hub</span>
                </a>

                <div class="account-auth__message">
                    <span class="account-auth__eyebrow">YOUR NEXT CHAPTER STARTS HERE</span>
                    <h1>A little curiosity.<br>A world of possibility.</h1>
                    <p>Build new skills, explore fresh ideas, and make progress at your own pace.</p>

                    <div class="account-auth__art">
                        <svg viewBox="0 0 440 320" role="img" aria-labelledby="learning-art-title">
                            <title id="learning-art-title">An open book and a graduation cap</title>
                            <circle cx="220" cy="157" r="120" fill="currentColor" opacity=".08"/>
                            <circle cx="345" cy="75" r="26" fill="#7667f8"/>
                            <path d="M345 34v-12m0 106v-12m41-41h12m-106 0h12m70-29 9-9m-75 75 9-9m57 0 9 9m-75-75 9 9" stroke="#7667f8" stroke-width="4" stroke-linecap="round"/>
                            <path d="M93 217c35-18 77-14 127 13v52c-50-27-92-31-127-13v-52Z" fill="#fff" stroke="#315be8" stroke-width="5" stroke-linejoin="round"/>
                            <path d="M347 217c-35-18-77-14-127 13v52c50-27 92-31 127-13v-52Z" fill="#f7f9ff" stroke="#315be8" stroke-width="5" stroke-linejoin="round"/>
                            <path d="M220 230v52" stroke="#315be8" stroke-width="5"/>
                            <path d="M112 231c23-8 49-8 78 1m-78 14c23-8 49-8 78 1m-78 14c23-8 49-8 78 1m-78 14c23-8 49-8 78 1" stroke="#b9c8ff" stroke-width="4" stroke-linecap="round"/>
                            <path d="M250 232c28-9 54-9 78-1m-78 16c28-9 54-9 78-1m-78 16c28-9 54-9 78-1m-78 16c28-9 54-9 78-1" stroke="#b9c8ff" stroke-width="4" stroke-linecap="round"/>
                            <path d="m150 127 71-35 70 35-70 34-71-34Z" fill="#315be8"/>
                            <path d="m178 141 43 21 43-21v28c-26 20-60 20-86 0v-28Z" fill="#7667f8"/>
                            <path d="M291 127v42" stroke="#315be8" stroke-width="5" stroke-linecap="round"/>
                            <circle cx="291" cy="175" r="7" fill="#315be8"/>
                            <path d="M115 105v-13m-6 6h12M325 231v-10m-5 5h10" stroke="#7667f8" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <p class="account-auth__caption">Every expert was once a beginner.</p>
                </div>
            </aside>

            <section class="account-auth__form-panel" aria-labelledby="login-title">
                <div class="account-auth__mobile-brand">
                    <span class="account-auth__brand-mark" aria-hidden="true">L</span>
                    <span>Learning Hub</span>
                </div>

                <div class="account-auth__form-content" id="login-panel">
                    <span class="account-auth__welcome-label">WELCOME BACK</span>
                    <h2 id="login-title">Sign in to your account</h2>
                    <p class="account-auth__intro">Enter your details below to continue learning.</p>
                    <p class="account-auth__status" id="login-status" role="status" aria-live="polite" hidden></p>

                    <form class="account-auth__form" data-auth-action="login" data-status-id="login-status">
                        <div class="form-group">
                            <label class="form-label" for="email">Email address</label>
                            <input class="form-control" id="email" name="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                        </div>

                        <div class="account-auth__options">
                            <span class="account-auth__help">Need help signing in? Contact your administrator.</span>
                        </div>

                        <button class="btn btn-primary btn-lg btn-block account-auth__submit" type="submit">
                            <span>Sign in</span>
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M4 10h12m-5-5 5 5-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </form>
                    <button class="account-auth__forgot-password" type="button" data-auth-view="forgot">Forgot Password?</button>

                    <p class="account-auth__signup">New to Learning Hub?<span class="account-auth__help">contact your administrator.</span></p>
                </div>

                <div class="account-auth__form-content account-auth__reset-content" id="forgot-panel" aria-labelledby="reset-title" hidden>
                    <button class="account-auth__back-link" type="button" data-auth-view="login">&larr; Back to sign in</button>
                    <span class="account-auth__welcome-label">ACCOUNT RECOVERY</span>
                    <h2 id="reset-title">Reset your password</h2>
                    <p class="account-auth__intro">Confirm your email, enter your verification code, and choose a new password.</p>
                    <p class="account-auth__status account-auth__reset-notice" role="note">
                        We’ll confirm your account and send a one-time code to your email.
                    </p>
                    <p class="account-auth__status" id="reset-status" role="status" aria-live="polite" hidden></p>

                    <form class="account-auth__form" id="reset-email-form" novalidate>
                        <div class="form-group">
                            <label class="form-label" for="reset-email">Email address</label>
                            <input class="form-control" id="reset-email" name="email" type="email" maxlength="190" placeholder="you@example.com" autocomplete="email" required>
                        </div>
                        <button class="btn btn-primary btn-block" type="submit">Confirm email</button>
                    </form>

                    <form class="account-auth__form account-auth__reset-step" id="reset-send-form" hidden>
                        <button class="btn btn-outline btn-block" type="submit">Send OTP</button>
                    </form>

                    <form class="account-auth__form account-auth__reset-step" id="reset-otp-form" hidden>
                        <div class="form-group">
                            <label class="form-label" for="reset-otp">One-time password</label>
                            <input class="form-control" id="reset-otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="6-digit code" required>
                        </div>
                        <p class="account-auth__status" id="otp-resend-countdown" role="status" aria-live="polite" hidden></p>
                        <button class="account-auth__resend-button" type="button" id="resend-otp-button" hidden>Resend code</button>
                        <button class="btn btn-outline btn-block" type="submit">Verify OTP</button>
                    </form>

                    <form class="account-auth__form account-auth__reset-step" id="reset-password-form" hidden>
                        <div class="form-group">
                            <label class="form-label" for="new-password">New password</label>
                            <input class="form-control" id="new-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm-new-password">Confirm new password</label>
                            <input class="form-control" id="confirm-new-password" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <button class="btn btn-primary btn-block" type="submit">Update password</button>
                    </form>
                </div>

                <footer class="account-auth__footer">
                    <span>© <?php echo date('Y'); ?> Learning Hub</span>
                    <span>Learn something new today.</span>
                </footer>
            </section>
        </section>
    </main>
    <script src="assests/auth.js?v=<?php echo (int) filemtime(__DIR__ . '/assests/auth.js'); ?>" defer></script>
</body>
</html>
