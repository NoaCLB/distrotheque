<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) config('db_path');
    if (!is_file($path)) {
        error_log('Distrothèque : base de données introuvable (' . $path . ').');
        throw new RuntimeException('La base de données du catalogue est indisponible.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA query_only = 1');
    $pdo->exec('PRAGMA busy_timeout = 3000');

    return $pdo;
}
