<?php
declare(strict_types=1);

return [
    'site_name' => 'Distrothèque',
    'tagline'   => 'Le catalogue des distributions Linux, BSD et Unix',
    'db_path'   => getenv('DISTROTHEQUE_DB') ?: dirname(__DIR__) . '/data/distrotheque.db',
    'per_page'  => 24,
    'max_q_length' => 80,

    'auto_update'        => (getenv('DISTROTHEQUE_AUTOUPDATE') ?: '1') !== '0',
    'update_interval'    => 3 * 3600,
    'update_retry_delay' => 600,
    'lock_max_age'       => 6 * 3600,
    'update_script'      => dirname(__DIR__) . '/update.py',
    'update_args'        => getenv('DISTROTHEQUE_UPDATE_ARGS') ?: '',

    'python_bin'         => getenv('DISTROTHEQUE_PYTHON') ?: '',
];
