<?php
declare(strict_types=1);

$emailEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$emailActionUrl = trim((string) ($emailActionUrl ?? ''));
$emailActionLabel = trim((string) ($emailActionLabel ?? ''));
$emailActionScheme = $emailActionUrl !== '' ? strtolower((string) parse_url($emailActionUrl, PHP_URL_SCHEME)) : '';
if ($emailActionUrl !== '' && (!filter_var($emailActionUrl, FILTER_VALIDATE_URL) || !in_array($emailActionScheme, ['http', 'https'], true))) {
    throw new InvalidArgumentException('Email action links must be valid HTTP or HTTPS URLs.');
}
if (($emailActionUrl === '') !== ($emailActionLabel === '')) {
    throw new InvalidArgumentException('Email action links require both a URL and a label.');
}
$emailTitle = (string) ($emailTitle ?? 'A message from Learning Hub');
$emailPreheader = (string) ($emailPreheader ?? $emailTitle);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title><?= $emailEscape($emailTitle) ?></title>
    <style>
        @media only screen and (max-width: 600px) {
            .email-shell { width: 100% !important; }
            .email-card { padding: 28px 22px !important; }
            .email-footer { padding-right: 22px !important; padding-left: 22px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f5f6f9;color:#172033;font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;line-height:1.6;-webkit-text-size-adjust:100%;">
    <div style="display:none;font-size:1px;color:#f5f6f9;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        <?= $emailEscape($emailPreheader) ?>&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:#f5f6f9;">
        <tr>
            <td align="center" style="padding:36px 16px;">
                <table class="email-shell" role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;border-spacing:0;">
                    <tr>
                        <td style="padding:0 0 20px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td width="38" height="38" align="center" valign="middle" style="width:38px;height:38px;border-radius:11px;background-color:#2563eb;color:#ffffff;font-size:19px;font-weight:700;line-height:38px;">L</td>
                                    <td style="padding-left:10px;color:#172033;font-size:17px;font-weight:700;letter-spacing:-0.3px;">Learning Hub</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-card" style="padding:38px 40px;background-color:#ffffff;border:1px solid #dfe3ea;border-radius:14px;box-shadow:0 4px 14px rgba(20,28,48,0.06);">
                            <h1 style="margin:0 0 18px;color:#172033;font-size:25px;font-weight:700;line-height:1.25;letter-spacing:-0.5px;"><?= $emailEscape($emailTitle) ?></h1>
                            <?= $emailContent ?? '' ?>
                            <?php if ($emailActionUrl !== ''): ?>
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;border-collapse:collapse;">
                                    <tr>
                                        <td align="center" style="border-radius:10px;background-color:#2563eb;">
                                            <a href="<?= $emailEscape($emailActionUrl) ?>" style="display:inline-block;padding:13px 22px;border:1px solid #2563eb;border-radius:10px;color:#ffffff;font-size:14px;font-weight:600;line-height:1.2;text-decoration:none;"><?= $emailEscape($emailActionLabel) ?></a>
                                        </td>
                                    </tr>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-footer" style="padding:22px 12px 0;color:#78829a;font-size:12px;line-height:1.6;text-align:center;">
                            <p style="margin:0 0 6px;">Learning Hub · Your place to learn and grow</p>
                            <p style="margin:0;">If you were not expecting this email, you can safely ignore it.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
