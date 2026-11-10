<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim((string) ($emailData['name'] ?? ''));
$expiresMinutes = max(1, (int) ($emailData['expires_minutes'] ?? 60));
$emailTitle = 'Verify your email address';
$emailPreheader = 'Confirm your email address to finish setting up your account.';
$emailActionUrl = trim((string) ($emailData['verification_url'] ?? ''));
$emailActionLabel = 'Verify email address';
$emailContent = '<p style="margin:0 0 16px;color:#566074;">Hello ' . $e($name !== '' ? $name : 'there') . ',</p>'
    . '<p style="margin:0 0 16px;color:#566074;">Please confirm this email address to finish setting up your Learning Hub account.</p>'
    . '<p style="margin:0;color:#78829a;font-size:13px;">This verification link expires in ' . $expiresMinutes . ' minutes. If you did not create this account, you can ignore this message.</p>';
require __DIR__ . '/layout.php';