<?php
declare(strict_types=1);

function project_root(): string
{
    return dirname(__DIR__, 2);
}

function update_lock_path(): string
{
    return project_root() . '/data/.update.lock';
}

function update_stamp_path(): string
{
    return project_root() . '/data/.auto-update.stamp';
}

function update_log_path(): string
{
    return project_root() . '/logs/auto-update.log';
}

function data_age(): int
{
    $path = (string) config('db_path');
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return PHP_INT_MAX;
    }

    return max(0, time() - (int) filemtime($path));
}

function update_in_progress(): bool
{
    $lock = update_lock_path();
    clearstatcache(true, $lock);
    if (!is_file($lock)) {
        return false;
    }

    return (time() - (int) filemtime($lock)) < (int) config('lock_max_age');
}

function auto_update_due(): bool
{
    return data_age() >= (int) config('update_interval');
}

function rotate_log(string $path): void
{
    clearstatcache(true, $path);
    if (is_file($path) && filesize($path) > 5 * 1024 * 1024) {
        @rename($path, $path . '.1');
    }
}

function python_candidates(): array
{
    $configured = trim((string) config('python_bin'));
    if ($configured !== '') {
        return [$configured];
    }

    return ['python3', 'python'];
}

function python_command_exists(string $binary): bool
{
    if (str_contains($binary, '/')) {
        return is_file($binary) && is_executable($binary);
    }

    if (!function_exists('exec')) {
        return true;
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return true;
    }

    $output = [];
    $code = 1;
    @exec(
        'command -v ' . escapeshellarg($binary) . ' > /dev/null 2>&1',
        $output,
        $code
    );

    return $code === 0;
}

function python_binary(): ?string
{
    foreach (python_candidates() as $candidate) {
        if (python_command_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function spawn_update(): bool
{
    if (!function_exists('popen')) {
        return false;
    }

    $python = python_binary();
    if ($python === null) {
        return false;
    }

    $log = update_log_path();
    if (!is_dir(dirname($log))) {
        @mkdir(dirname($log), 0775, true);
    }
    rotate_log($log);

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg((string) config('update_script'));

    $args = trim((string) config('update_args'));
    if ($args !== '') {
        $command .= ' ' . $args;
    }

    $command = 'nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 &';

    try {
        $handle = @popen($command, 'r');
        if ($handle === false) {
            return false;
        }
        @pclose($handle);

        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function maybe_auto_update(): void
{
    if (!config('auto_update') || !auto_update_due() || update_in_progress()) {
        return;
    }

    $handle = @fopen(update_stamp_path(), 'c+');
    if ($handle === false) {
        return;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return;
    }

    rewind($handle);
    $last = (int) trim((string) stream_get_contents($handle));
    $retry = (int) config('update_retry_delay');

    if ($last > 0 && (time() - $last) < $retry) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) time());
    fflush($handle);

    spawn_update();

    flock($handle, LOCK_UN);
    fclose($handle);
}
