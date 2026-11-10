<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim((string) ($emailData['name'] ?? ''));
$username = trim((string) ($emailData['username'] ?? ''));
$temporaryPassword = (string) ($emailData['temporary_password'] ?? '');
$profileUrl = trim((string) ($emailData['profile_url'] ?? ''));
$emailTitle = 'Welcome to Learning Hub';
$emailPreheader = 'Your account is ready. Sign in to get started.';
$emailActionUrl = trim((string) ($emailData['login_url'] ?? ''));
$emailActionLabel = 'Sign in to Learning Hub';
$emailContent = '<p style="margin:0 0 16px;color:#566074;">Hello ' . $e($name !== '' ? $name : 'there') . ',</p>'
    . '<p style="margin:0 0 20px;color:#566074;">An account has been created for you on Learning Hub. Use these credentials to sign in:</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:collapse;background-color:#f5f6f9;border:1px solid #dfe3ea;border-radius:10px;">'
    . '<tr><td style="padding:15px 18px;color:#566074;font-size:13px;">Username</td><td align="right" style="padding:15px 18px;color:#172033;font-size:14px;font-weight:600;word-break:break-word;">' . $e($username) . '</td></tr>'
    . '<tr><td colspan="2" style="padding:0 18px;"><div style="height:1px;background-color:#dfe3ea;"></div></td></tr>'
    . '<tr><td style="padding:15px 18px;color:#566074;font-size:13px;">Temporary password</td><td align="right" style="padding:15px 18px;color:#172033;font-family:Consolas,Monaco,monospace;font-size:14px;font-weight:600;word-break:break-word;">' . $e($temporaryPassword) . '</td></tr>'
    . '</table>'
    . '<p style="margin:0;color:#566074;">For your security, change your temporary password after signing in'
    . ($profileUrl !== '' ? ' from your <a href="' . $e($profileUrl) . '" style="color:#1d4fd7;text-decoration:underline;">profile</a>' : '')
    . '.</p>';
require __DIR__ . '/layout.php';