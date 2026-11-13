<?php
declare(strict_types=1);

function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base = ($directory === '' || $directory === '.' || $directory === '/') ? '' : $directory;

    return $base;
}

function current_route(): string
{
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $base = base_path();

    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    $path = '/' . trim($path, '/');

    if ($path === '/index.php' || $path === '/index.php/') {
        $path = '/';
    } elseif (str_starts_with($path, '/index.php/')) {
        $path = substr($path, strlen('/index.php'));
    }

    return $path === '/' ? '/' : rtrim($path, '/');
}

function route_with(array $changes, array $current): string
{
    $params = array_merge($current, $changes);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            unset($params[$key]);
        }
    }
    if (isset($params['page']) && (int) $params['page'] <= 1) {
        unset($params['page']);
    }

    $path = current_route() === '/' ? '/' : current_route();
    return url($path, $params);
}
