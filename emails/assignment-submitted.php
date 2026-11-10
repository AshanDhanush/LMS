<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = trim((string) ($emailData['name'] ?? ''));
$assignmentTitle = trim((string) ($emailData['assignment_title'] ?? ''));
$courseTitle = trim((string) ($emailData['course_title'] ?? ''));
$fileName = trim((string) ($emailData['file_name'] ?? ''));
$isLate = !empty($emailData['is_late']);
$isReplacement = !empty($emailData['is_replacement']);
$emailTitle = $isReplacement ? 'Your assignment was resubmitted' : 'Your assignment was submitted';
$emailPreheader = $isReplacement
    ? 'Your updated assignment file has been received.'
    : 'Your assignment file has been received.';
$emailActionUrl = trim((string) ($emailData['assignment_url'] ?? ''));
$emailActionLabel = 'View assignment';
$emailContent = '<p style="margin:0 0 16px;color:#566074;">Hello ' . $e($name !== '' ? $name : 'there') . ',</p>'
    . '<p style="margin:0 0 20px;color:#566074;">' . ($isReplacement
        ? 'Your updated file was received successfully.'
        : 'Your work was submitted successfully. Keep this email as confirmation of your submission.') . '</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:collapse;background-color:#f5f6f9;border:1px solid #dfe3ea;border-radius:10px;">'
    . '<tr><td style="padding:13px 16px;color:#566074;font-size:13px;">Assignment</td><td align="right" style="padding:13px 16px;color:#172033;font-size:14px;font-weight:600;">' . $e($assignmentTitle) . '</td></tr>'
    . '<tr><td colspan="2" style="padding:0 16px;"><div style="height:1px;background-color:#dfe3ea;"></div></td></tr>'
    . '<tr><td style="padding:13px 16px;color:#566074;font-size:13px;">Course</td><td align="right" style="padding:13px 16px;color:#172033;font-size:14px;font-weight:600;">' . $e($courseTitle) . '</td></tr>'
    . '<tr><td colspan="2" style="padding:0 16px;"><div style="height:1px;background-color:#dfe3ea;"></div></td></tr>'
    . '<tr><td style="padding:13px 16px;color:#566074;font-size:13px;">Submitted file</td><td align="right" style="padding:13px 16px;color:#172033;font-size:14px;font-weight:600;word-break:break-word;">' . $e($fileName) . '</td></tr>'
    . '</table>'
    . ($isLate
        ? '<p style="margin:0;color:#a15c07;font-size:13px;">This submission was received after the due date.</p>'
        : '<p style="margin:0;color:#566074;font-size:13px;">You can view your submission and any feedback from the assignment page.</p>');
require __DIR__ . '/layout.php';
