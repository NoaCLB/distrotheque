<?php
declare(strict_types=1);

function admin_dummy_hash(): string
{
    return '$2y$10$7nsjy0YsALJbf6j7EBDlcuHewrvTUYvMM72h0r25NdLCi.Vx4/lsi';
}

function admin_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $directory = project_root() . '/data/sessions';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    if (is_dir($directory)) {
        @chmod($directory, 0700);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
        http_response_code(500);
        exit('Le dossier des sessions d\'administration est indisponible. Vérifie les droits de data/sessions.');
    }
    session_save_path($directory);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (base_path() === '' ? '' : base_path()) . '/',
        'secure'   => admin_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name((string) config('admin_session_name'));
    if (!session_start()) {
        http_response_code(500);
        exit('Impossible de démarrer la session d\'administration.');
    }
}

function admin_credentials(): ?array
{
    $path = (string) config('admin_file');
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    if (($data['enabled'] ?? true) === false) {
        return null;
    }

    $username = trim((string) ($data['username'] ?? ''));
    $hash = (string) ($data['password_hash'] ?? '');
    if ($username === '' || $hash === '') {
        return null;
    }

    return ['username' => $username, 'password_hash' => $hash] + $data;
}

function admin_configured(): bool
{
    return admin_credentials() !== null;
}

function admin_session_version(): string
{
    $stored = admin_credentials();
    if ($stored === null) {
        return '';
    }

    return hash(
        'sha256',
        (string) $stored['password_hash'] . '|' . (string) ($stored['updated_at'] ?? '')
    );
}

function admin_verify_credentials(string $username, string $password): bool
{
    $stored = admin_credentials();
    $hash = $stored['password_hash'] ?? admin_dummy_hash();
    $usernameOk = $stored !== null
        && hash_equals((string) $stored['username'], $username);
    $passwordOk = password_verify($password, $hash);

    if (!$stored || !$usernameOk || !$passwordOk) {
        return false;
    }

    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if (password_needs_rehash($hash, $algorithm)) {
        $stored['password_hash'] = password_hash($password, $algorithm);
        $stored['updated_at'] = date('Y-m-d H:i:s');
        json_write_atomic((string) config('admin_file'), $stored, 0600);
    }

    return true;
}

function admin_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'inconnu';
}

function admin_attempts_open()
{
    $path = (string) config('admin_attempts_file');
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }
    @chmod($path, 0600);

    return $handle;
}

function admin_attempts_read($handle, int $window): array
{
    rewind($handle);
    $raw = (string) stream_get_contents($handle);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $now = time();
    $clean = [];
    foreach ($data as $ip => $timestamps) {
        if (!is_array($timestamps)) {
            continue;
        }
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn($timestamp) => is_numeric($timestamp) && (int) $timestamp > $now - $window
        ));
        if ($timestamps) {
            $clean[(string) $ip] = $timestamps;
        }
    }

    return $clean;
}

function admin_attempts_write($handle, array $data): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
}

function admin_throttle_blocked(string $ip): bool
{
    $handle = admin_attempts_open();
    if ($handle === null) {
        return false;
    }

    $window = (int) config('admin_attempt_window');
    $max = (int) config('admin_max_attempts');
    $blocked = false;
    if (flock($handle, LOCK_EX)) {
        $attempts = admin_attempts_read($handle, $window);
        $blocked = count($attempts[$ip] ?? []) >= $max;
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return $blocked;
}

function admin_throttle_acquire(string $ip): bool
{
    $handle = admin_attempts_open();
    if ($handle === null) {
        return true;
    }

    $window = (int) config('admin_attempt_window');
    $max = (int) config('admin_max_attempts');
    $allowed = false;
    if (flock($handle, LOCK_EX)) {
        $attempts = admin_attempts_read($handle, $window);
        if (count($attempts[$ip] ?? []) < $max) {
            $attempts[$ip][] = time();
            admin_attempts_write($handle, $attempts);
            $allowed = true;
        }
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return $allowed;
}

function admin_throttle_clear(string $ip): void
{
    $handle = admin_attempts_open();
    if ($handle === null) {
        return;
    }

    $window = (int) config('admin_attempt_window');
    if (flock($handle, LOCK_EX)) {
        $attempts = admin_attempts_read($handle, $window);
        unset($attempts[$ip]);
        admin_attempts_write($handle, $attempts);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

function admin_log(string $message): void
{
    $directory = project_root() . '/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }
    if (is_dir($directory)) {
        @chmod($directory, 0750);
    }

    $line = sprintf(
        "[%s] %s ip=%s\n",
        date('Y-m-d H:i:s'),
        (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message),
        admin_client_ip()
    );
    $logFile = $directory . '/admin.log';
    @file_put_contents(
        $logFile,
        $line,
        FILE_APPEND | LOCK_EX
    );
    @chmod($logFile, 0640);
}

function admin_login(string $username, string $password): bool
{
    $ip = admin_client_ip();
    if (!admin_throttle_acquire($ip)) {
        admin_log('Connexion refusée (trop de tentatives)');
        return false;
    }

    if (!admin_verify_credentials($username, $password)) {
        admin_log('Échec de connexion pour « ' . $username . ' »');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin'] = [
        'username' => $username,
        'started'  => time(),
        'seen'     => time(),
        'ua'       => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'version'  => admin_session_version(),
    ];

    admin_throttle_clear($ip);
    admin_log('Connexion réussie pour « ' . $username . ' »');

    return true;
}

function admin_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
}

function admin_invalidate_session(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function admin_is_authenticated(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $state = $_SESSION['admin'] ?? null;
    if (!is_array($state)) {
        return false;
    }

    $now = time();
    $seen = (int) ($state['seen'] ?? 0);
    $started = (int) ($state['started'] ?? 0);
    if (
        $seen <= 0
        || $started <= 0
        || $now - $seen > (int) config('admin_idle_timeout')
        || $now - $started > (int) config('admin_absolute_timeout')
    ) {
        admin_invalidate_session();
        return false;
    }

    $fingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!hash_equals((string) ($state['ua'] ?? ''), $fingerprint)) {
        admin_invalidate_session();
        return false;
    }

    $version = admin_session_version();
    if (
        $version === ''
        || (string) ($state['username'] ?? '') === ''
        || !hash_equals($version, (string) ($state['version'] ?? ''))
    ) {
        admin_invalidate_session();
        return false;
    }

    $_SESSION['admin']['seen'] = $now;
    return true;
}

function admin_is_logged_in(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return admin_is_authenticated();
    }

    $cookie = (string) config('admin_session_name');
    if ($cookie === '' || empty($_COOKIE[$cookie])) {
        return false;
    }

    $directory = project_root() . '/data/sessions';
    if (!is_dir($directory) || !is_writable($directory)) {
        return false;
    }

    admin_session_start();
    return admin_is_authenticated();
}

function admin_csrf_token(bool $create = true): string
{
    $token = (string) ($_SESSION['admin_csrf'] ?? '');
    if ($token === '') {
        if (!$create) {
            return '';
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['admin_csrf'] = $token;
    }

    return $token;
}

function admin_csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Méthode non autorisée.');
    }

    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(admin_csrf_token(false), $token)) {
        http_response_code(403);
        exit('Jeton CSRF invalide. Recharge la page et réessaie.');
    }
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'][] = [
        'type'    => in_array($type, ['success', 'warning', 'error'], true) ? $type : 'success',
        'message' => $message,
    ];
}

function admin_take_flashes(): array
{
    $flashes = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);

    return is_array($flashes) ? $flashes : [];
}
