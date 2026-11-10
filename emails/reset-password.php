<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$code = trim((string) ($emailData['code'] ?? ''));
$expiresMinutes = max(1, (int) ($emailData['expires_minutes'] ?? 60));
$emailTitle = 'Reset your password';
$emailPreheader = 'Use the verification code in this email to reset your password.';
$emailActionUrl = '';
$emailActionLabel = '';
$emailContent = '<p style="margin:0 0 16px;color:#566074;">Use this verification code to reset your Learning Hub password:</p>'
    . '<div style="margin:0 0 20px;padding:18px 16px;border:1px solid #dfe3ea;border-radius:10px;background-color:#f5f6f9;color:#172033;font-family:Consolas,Monaco,monospace;font-size:30px;font-weight:700;letter-spacing:8px;line-height:1.3;text-align:center;">' . $e($code) . '</div>'
    . '<p style="margin:0 0 12px;color:#566074;">This code expires in ' . $expiresMinutes . ' minutes and can only be used once.</p>'
    . '<p style="margin:0;color:#78829a;font-size:13px;">If you did not request a password reset, you can ignore this email. Your password will remain unchanged.</p>';
require __DIR__ . '/layout.php';