<?php
declare(strict_types=1);

function config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_write_atomic(string $path, $data, int $mode = 0644): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        return false;
    }

    $temporary = @tempnam($directory, basename($path) . '.tmp.');
    if ($temporary === false) {
        return false;
    }

    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }

    @chmod($temporary, $mode);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }

    return true;
}

function safe_external_url(?string $value): string
{
    $url = trim((string) $value);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return '';
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

function normalize_search(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    static $map = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a',
        'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y',
        'Œ' => 'oe', 'Æ' => 'ae',
    ];

    $value = strtr($value, $map);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function query_string(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean ? '?' . http_build_query($clean) : '';
}

function url(string $path = '/', array $query = []): string
{
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return base_path() . $path . query_string($query);
}

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url($path, ['v' => $version]);
}

function excerpt_text(string $text, int $limit = 190): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    if (!preg_match('/^.{0,' . $limit . '}/us', $text, $match)) {
        return $text;
    }

    $cut = rtrim($match[0]);
    if ($cut === $text) {
        return $text;
    }

    $space = strrpos($cut, ' ');
    if ($space !== false && $space > (int) ($limit * 0.6)) {
        $cut = substr($cut, 0, $space);
    }

    return rtrim($cut) . '…';
}

function format_date(?string $value): string
{
    if (!$value) {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : '';
}

function format_number(int $value): string
{
    return number_format($value, 0, ',', ' ');
}

function split_archs(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
}

function arch_label(string $arch): string
{
    $labels = [
        'x86_64' => 'x86_64',
        'x86' => 'i686 (32-bit)',
        'arm64' => 'ARM64',
        'arm' => 'ARM',
        'ppc64le' => 'POWER (LE)',
        'ppc64' => 'POWER',
        's390x' => 's390x',
        'riscv64' => 'RISC-V',
        'loongarch64' => 'LoongArch',
        'mips' => 'MIPS',
        'sparc' => 'SPARC',
        'sparc64' => 'SPARC64',
    ];
    return $labels[$arch] ?? $arch;
}

function source_label(string $source): string
{
    return match ($source) {
        'archiveos' => 'ArchiveOS',
        'isoslinux' => 'isoslinux',
        'both' => 'ArchiveOS + isoslinux',
        'manual' => 'Ajout manuel',
        default => $source,
    };
}

function host_label(string $host): string
{
    return preg_replace('/^www\./', '', $host ?: '') ?: 'lien externe';
}

function human_value(?string $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $lower = strtolower($text);
    if (in_array($lower, ['not yet verified', 'not verified', 'n/a', 'unknown', 'not specified'], true)) {
        return '';
    }
    if (str_starts_with($lower, 'consult')) {
        return '';
    }

    return $text;
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);

    $viewFile = __DIR__ . '/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        echo 'Vue introuvable.';
        return;
    }

    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();

    require __DIR__ . '/views/layout.php';
}
