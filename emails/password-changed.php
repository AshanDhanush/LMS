<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim((string) ($emailData['name'] ?? ''));
$emailTitle = 'Your password was changed';
$emailPreheader = 'A password change was completed for your Learning Hub account.';
$emailActionUrl = trim((string) ($emailData['login_url'] ?? ''));
$emailActionLabel = 'Sign in to Learning Hub';
$emailContent = '<p style="margin:0 0 16px;color:#566074;">Hello ' . $e($name !== '' ? $name : 'there') . ',</p>'
    . '<p style="margin:0 0 16px;color:#566074;">The password for your Learning Hub account was changed successfully.</p>'
    . '<div style="margin:0;padding:14px 16px;border:1px solid #dfe3ea;border-radius:10px;background-color:#f5f6f9;color:#566074;font-size:13px;">If you did not make this change, contact your administrator as soon as possible.</div>';
require __DIR__ . '/layout.php';