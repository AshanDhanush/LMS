<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim((string) ($emailData['name'] ?? ''));
$title = trim((string) ($emailData['title'] ?? ''));
$message = trim((string) ($emailData['message'] ?? ''));
$emailTitle = $title !== '' ? $title : 'You have a new notification';
$emailPreheader = $emailTitle;
$emailActionUrl = trim((string) ($emailData['action_url'] ?? ''));
$emailActionLabel = trim((string) ($emailData['action_label'] ?? ''));
$emailContent = ($name !== '' ? '<p style="margin:0 0 16px;color:#566074;">Hello ' . $e($name) . ',</p>' : '')
    . '<p style="margin:0;color:#566074;white-space:pre-line;">' . $e($message) . '</p>';
require __DIR__ . '/layout.php';