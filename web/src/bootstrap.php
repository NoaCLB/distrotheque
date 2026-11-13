<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$timezone = (string) config('timezone');
if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($timezone);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/updater.php';
