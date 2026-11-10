<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The notification bell. Echo it inside a header:
|
|   <?php require_once __DIR__ . '/../includes/notifications_bell.php'; notificationsBell(); ?>
|
| It brings its own stylesheet and script, so a header only needs this one
| line. The look (colours, radius) comes from assests/css/theme.css, so it
| follows light and dark mode like the rest of the page.
|--------------------------------------------------------------------------
*/

function notificationsBell(): void
{
    static $count = 0;
    $count++;
    $id = 'notif-panel-' . $count;
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if ($count === 1) {
        echo '<link rel="stylesheet" href="' . $e(url('assests/css/notifications.css')) . '">' . "\n";
        echo '<script src="' . $e(url('assests/notifications.js')) . '" defer></script>' . "\n";
    }
    ?>
<div class="notif" data-notifications data-endpoint="<?= $e(url('api/notifications_api.php')) ?>">
    <button class="notif__button" type="button" aria-expanded="false" aria-controls="<?= $e($id) ?>" aria-label="Notifications" data-notif-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span class="notif__badge" data-notif-badge hidden aria-hidden="true"></span>
    </button>
    <div class="notif__panel" id="<?= $e($id) ?>" role="region" aria-label="Notifications" hidden>
        <div class="notif__head">
            <h2 class="notif__title">Notifications</h2>
            <button class="notif__markall" type="button" data-notif-readall disabled>Mark all as read</button>
        </div>
        <ul class="notif__list" data-notif-list></ul>
        <p class="notif__empty" data-notif-empty hidden>You are all caught up.</p>
        <p class="notif__error" data-notif-error role="alert" hidden></p>
    </div>
    <span class="notif__sr" role="status" aria-live="polite" data-notif-live></span>
</div>
<?php
}
