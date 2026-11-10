<?php
declare(strict_types=1);

function renderEmailTemplate(string $template, array $data = []): string
{
    $templates = [
        'welcome' => 'welcome.php',
        'verify-email' => 'verify-email.php',
        'reset-password' => 'reset-password.php',
        'password-changed' => 'password-changed.php',
        'notification' => 'notification.php',
        'assignment-submitted' => 'assignment-submitted.php',
    ];
    if (!isset($templates[$template])) {
        throw new InvalidArgumentException('Unknown email template.');
    }


    $emailData = $data;
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        require dirname(__DIR__) . '/emails/' . $templates[$template];
        $html = ob_get_clean();
        if (!is_string($html)) {
            throw new RuntimeException('The email template could not be rendered.');
        }
        return $html;
    } catch (Throwable $error) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        throw $error;
    }
}