<?php
declare(strict_types=1);

return [
    'site_name' => 'Distrothèque',
    'tagline'   => 'Le catalogue des distributions Linux, BSD et Unix',
    'db_path'   => getenv('DISTROTHEQUE_DB') ?: dirname(__DIR__) . '/data/distrotheque.db',
    'timezone'  => getenv('DISTROTHEQUE_TZ') ?: 'Europe/Paris',
    'per_page'  => 24,
    'max_q_length' => 80,

    'auto_update'        => (getenv('DISTROTHEQUE_AUTOUPDATE') ?: '1') !== '0',
    'update_interval'    => 3 * 3600,
    'update_retry_delay' => 600,
    'lock_max_age'       => 6 * 3600,
    'update_script'      => dirname(__DIR__) . '/update.py',
    'update_args'        => getenv('DISTROTHEQUE_UPDATE_ARGS') ?: '',

    'python_bin'         => getenv('DISTROTHEQUE_PYTHON') ?: '',

    'admin_file'         => getenv('DISTROTHEQUE_ADMIN') ?: dirname(__DIR__) . '/data/admin.json',
    'admin_attempts_file' => dirname(__DIR__) . '/data/admin_attempts.json',
    'admin_session_name' => 'distrotheque_admin',
    'admin_idle_timeout' => 2 * 3600,
    'admin_absolute_timeout' => 12 * 3600,
    'admin_max_attempts' => 5,
    'admin_attempt_window' => 15 * 60,
    'manual_json'        => getenv('DISTROTHEQUE_MANUAL_JSON') ?: dirname(__DIR__) . '/data/manual_distros.json',
    'overrides_json'     => getenv('DISTROTHEQUE_OVERRIDES_JSON') ?: dirname(__DIR__) . '/data/distro_overrides.json',
    'deletions_json'     => getenv('DISTROTHEQUE_DELETIONS_JSON') ?: dirname(__DIR__) . '/data/distro_deletions.json',
    'images_json'        => getenv('DISTROTHEQUE_IMAGES_JSON') ?: dirname(__DIR__) . '/data/distro_images.json',
    'uploads_dir'        => getenv('DISTROTHEQUE_UPLOADS_DIR') ?: dirname(__DIR__) . '/data/uploads',
    'image_max_bytes'    => 100 * 1024 * 1024,
    'image_max_per_distro' => 30,
];
