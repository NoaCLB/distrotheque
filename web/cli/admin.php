#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

function cli_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function cli_path(string $path): string
{
    $root = project_root();
    if (str_starts_with($path, $root . '/')) {
        return substr($path, strlen($root) + 1);
    }

    return $path;
}

function cli_prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);

    $hidden = $hidden && function_exists('stream_isatty') && @stream_isatty(STDIN);
    if ($hidden) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $value = fgets(STDIN);
    if ($value === false) {
        $value = '';
    }

    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    return trim($value);
}

function cli_admin_path(): string
{
    return (string) config('admin_file');
}

function cli_read_admin(): array
{
    $path = cli_admin_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : [];
}

function cli_write_admin(array $data): void
{
    $path = cli_admin_path();
    if (!json_write_atomic($path, $data, 0600)) {
        cli_fail('Impossible d\'écrire ' . $path . '. Vérifie les droits du dossier data/.');
    }
    @chmod($path, 0600);
}

function cli_clear_attempts(): void
{
    $path = (string) config('admin_attempts_file');
    if (is_file($path)) {
        @unlink($path);
    }
}

function cli_usage(): void
{
    $script = 'php web/cli/admin.php';

    fwrite(STDOUT, <<<TXT
Gestion du compte administrateur de la Distrothèque.

Usage :
  {$script} set-password [--username NOM] [--password MOT_DE_PASSE]
  {$script} status
  {$script} disable
  {$script} enable

Notes :
  - sans --password, le mot de passe est demandé de façon masquée ;
  - la variable DISTROTHEQUE_ADMIN_PASSWORD est aussi acceptée ;
  - le compte est stocké dans data/admin.json (hash bcrypt/argon2, permissions 0600).

En production, lance la commande avec l'utilisateur de PHP-FPM (par exemple www-data) :
  sudo -u www-data {$script} set-password --username admin

TXT);
}

function cli_status(): void
{
    $path = cli_admin_path();
    $data = cli_read_admin();

    if (!$data || empty($data['username']) || empty($data['password_hash'])) {
        fwrite(STDOUT, 'Aucun compte administrateur configuré.' . PHP_EOL);
        fwrite(STDOUT, 'Fichier attendu : ' . cli_path($path) . PHP_EOL);
        fwrite(STDOUT, 'Crée-le avec : php web/cli/admin.php set-password --username admin' . PHP_EOL);
        return;
    }

    $enabled = ($data['enabled'] ?? true) !== false;
    fwrite(STDOUT, 'Compte administrateur : ' . (string) $data['username'] . PHP_EOL);
    fwrite(STDOUT, 'État : ' . ($enabled ? 'actif' : 'désactivé') . PHP_EOL);
    fwrite(STDOUT, 'Fichier : ' . cli_path($path) . PHP_EOL);
    if (!empty($data['updated_at'])) {
        fwrite(STDOUT, 'Mis à jour : ' . (string) $data['updated_at'] . PHP_EOL);
    }
    $permissions = @fileperms($path);
    if ($permissions !== false) {
        fwrite(STDOUT, 'Permissions : ' . substr(sprintf('%o', $permissions), -4) . PHP_EOL);
    }
}

function cli_set_enabled(bool $enabled): void
{
    $data = cli_read_admin();
    if (!$data || empty($data['username']) || empty($data['password_hash'])) {
        cli_fail('Aucun compte administrateur configuré.');
    }

    $data['enabled'] = $enabled;
    $data['updated_at'] = date('Y-m-d H:i:s');
    cli_write_admin($data);

    fwrite(STDOUT, ($enabled ? 'Compte administrateur activé.' : 'Compte administrateur désactivé.') . PHP_EOL);
}

function cli_set_password(array $arguments): void
{
    $existing = cli_read_admin();
    $username = trim((string) ($arguments['username'] ?? ''));
    if ($username === '') {
        $username = trim((string) ($existing['username'] ?? ''));
    }
    if ($username === '') {
        $username = 'admin';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        cli_fail('Identifiant invalide : 3 à 64 caractères parmi lettres, chiffres, point, tiret et underscore.');
    }

    $password = (string) ($arguments['password'] ?? '');
    if ($password === '') {
        $password = (string) (getenv('DISTROTHEQUE_ADMIN_PASSWORD') ?: '');
    }

    if ($password === '') {
        $password = cli_prompt('Mot de passe : ', true);
        if (strlen($password) < 10) {
            cli_fail('Le mot de passe doit contenir au moins 10 caractères.');
        }
        $confirmation = cli_prompt('Confirmation : ', true);
        if (!hash_equals($password, $confirmation)) {
            cli_fail('Les deux mots de passe ne correspondent pas.');
        }
    }

    if (strlen($password) < 10) {
        cli_fail('Le mot de passe doit contenir au moins 10 caractères.');
    }

    $now = date('Y-m-d H:i:s');
    $data = [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'enabled'       => true,
        'created_at'    => (string) ($existing['created_at'] ?? $now),
        'updated_at'    => $now,
    ];

    cli_write_admin($data);
    cli_clear_attempts();

    fwrite(STDOUT, 'Mot de passe défini pour « ' . $username . ' ».' . PHP_EOL);
    fwrite(STDOUT, 'Fichier : ' . cli_path(cli_admin_path()) . ' (0600)' . PHP_EOL);
}

$arguments = [];
$tokens = array_slice($argv, 2);
$valueOptions = ['username', 'password'];

for ($index = 0; $index < count($tokens); $index++) {
    $argument = (string) $tokens[$index];

    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match)) {
        $arguments[$match[1]] = $match[2];
        continue;
    }

    if (preg_match('/^--([a-z-]+)$/', $argument, $match)) {
        $name = $match[1];
        $next = $tokens[$index + 1] ?? null;
        if (in_array($name, $valueOptions, true) && is_string($next) && !str_starts_with($next, '--')) {
            $arguments[$name] = $next;
            $index++;
            continue;
        }
        $arguments[$name] = true;
    }
}

$command = (string) ($argv[1] ?? '');

switch ($command) {
    case 'set-password':
        cli_set_password($arguments);
        break;
    case 'status':
        cli_status();
        break;
    case 'disable':
        cli_set_enabled(false);
        break;
    case 'enable':
        cli_set_enabled(true);
        break;
    case 'help':
    case '--help':
    case '-h':
    case '':
        cli_usage();
        break;
    default:
        fwrite(STDERR, 'Commande inconnue : ' . $command . PHP_EOL . PHP_EOL);
        cli_usage();
        exit(1);
}
