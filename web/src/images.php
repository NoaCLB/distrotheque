<?php
declare(strict_types=1);

function images_path(): string
{
    return (string) config('images_json');
}

function uploads_dir(): string
{
    return rtrim((string) config('uploads_dir'), '/');
}

function image_max_bytes(): int
{
    return (int) config('image_max_bytes');
}

function image_max_per_distro(): int
{
    return (int) config('image_max_per_distro');
}

function image_valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug);
}

function image_valid_file(string $file): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}\.[a-z0-9]{1,8}$/', $file);
}

function images_all(): array
{
    $path = images_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error_log('Distrothèque : fichier d\'images illisible (' . $path . ').');
        return [];
    }

    return $data;
}

function images_for_slug(string $slug): array
{
    if (!image_valid_slug($slug)) {
        return [];
    }

    $all = images_all();
    $items = $all[$slug] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $file = (string) ($item['file'] ?? '');
        if (!image_valid_file($file)) {
            continue;
        }
        $path = uploads_dir() . '/' . $slug . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $clean[] = [
            'file'  => $file,
            'name'  => (string) ($item['name'] ?? $file),
            'mime'  => (string) ($item['mime'] ?? 'application/octet-stream'),
            'size'  => (int) ($item['size'] ?? filesize($path)),
            'added' => (string) ($item['added'] ?? ''),
        ];
    }

    return $clean;
}

function images_write(array $data): bool
{
    return json_write_atomic(images_path(), $data, 0640);
}

function image_clean_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
    $name = trim($name);
    if ($name === '') {
        $name = 'image';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 120, 'UTF-8');
    }

    return substr($name, 0, 120);
}

function image_detect_mime(string $path): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) ? $mime : '';
    }

    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        return is_string($mime) ? $mime : '';
    }

    return '';
}

function image_extension(string $mime): string
{
    $map = [
        'image/jpeg'             => 'jpg',
        'image/pjpeg'            => 'jpg',
        'image/png'              => 'png',
        'image/apng'             => 'apng',
        'image/gif'              => 'gif',
        'image/webp'             => 'webp',
        'image/avif'             => 'avif',
        'image/svg+xml'          => 'svg',
        'image/bmp'              => 'bmp',
        'image/x-ms-bmp'         => 'bmp',
        'image/tiff'             => 'tiff',
        'image/x-icon'           => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/heic'             => 'heic',
        'image/heif'             => 'heif',
        'image/jxl'              => 'jxl',
        'image/jp2'              => 'jp2',
        'image/x-xcf'            => 'xcf',
    ];

    if (isset($map[$mime])) {
        return $map[$mime];
    }

    $subtype = strtolower((string) substr($mime, strlen('image/')));
    $subtype = (string) preg_replace('/[^a-z0-9]+/', '', $subtype);
    $subtype = substr($subtype, 0, 8);

    return $subtype !== '' ? $subtype : 'img';
}

/**
 * Enregistre un fichier envoyé par l'administration.
 *
 * @return array{0: array|null, 1: string} [enregistrement, message d'erreur]
 */
function images_add_upload(string $slug, array $file): array
{
    if (!image_valid_slug($slug)) {
        return [null, 'Fiche invalide.'];
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, 'Aucun fichier sélectionné.'];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Fichier trop volumineux : ' . format_bytes(image_max_bytes()) . ' maximum par image.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return [null, 'L\'envoi du fichier a échoué (code ' . $error . ').'];
    }

    $temporary = (string) ($file['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        return [null, 'Fichier temporaire invalide.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return [null, 'Le fichier est vide.'];
    }
    if ($size > image_max_bytes()) {
        return [null, 'Fichier trop volumineux : ' . format_bytes(image_max_bytes()) . ' maximum par image.'];
    }

    $mime = image_detect_mime($temporary);
    if ($mime === '' || !str_starts_with($mime, 'image/')) {
        return [null, 'Le fichier n\'est pas reconnu comme une image.'];
    }
    if (preg_match('/(php|html|xhtml)/i', $mime)) {
        return [null, 'Ce type d\'image n\'est pas autorisé.'];
    }

    $all = images_all();
    $existing = $all[$slug] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }
    if (count($existing) >= image_max_per_distro()) {
        return [null, 'Limite de ' . image_max_per_distro() . ' images atteinte pour cette fiche.'];
    }

    $extension = image_extension($mime);
    $filename = bin2hex(random_bytes(8)) . '.' . $extension;
    $directory = uploads_dir() . '/' . $slug;
    if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
        return [null, 'Impossible de créer le dossier des images.'];
    }
    @chmod($directory, 0750);

    $destination = $directory . '/' . $filename;
    if (!@move_uploaded_file($temporary, $destination)) {
        return [null, 'Impossible d\'enregistrer l\'image sur le disque.'];
    }
    @chmod($destination, 0640);

    $record = [
        'file'  => $filename,
        'name'  => image_clean_name((string) ($file['name'] ?? '')),
        'mime'  => $mime,
        'size'  => $size,
        'added' => gmdate('c'),
    ];

    $all[$slug] = $existing;
    $all[$slug][] = $record;
    if (!images_write($all)) {
        @unlink($destination);
        return [null, 'Impossible d\'enregistrer les métadonnées de l\'image.'];
    }

    return [$record, ''];
}

function images_delete(string $slug, string $file): bool
{
    if (!image_valid_slug($slug) || !image_valid_file($file)) {
        return false;
    }

    $all = images_all();
    $items = $all[$slug] ?? [];
    if (!is_array($items)) {
        return false;
    }

    $found = false;
    $remaining = [];
    foreach ($items as $item) {
        if (is_array($item) && (string) ($item['file'] ?? '') === $file) {
            $found = true;
            continue;
        }
        $remaining[] = $item;
    }

    if (!$found) {
        return false;
    }

    if ($remaining) {
        $all[$slug] = $remaining;
    } else {
        unset($all[$slug]);
    }

    if (!images_write($all)) {
        return false;
    }

    $path = uploads_dir() . '/' . $slug . '/' . $file;
    if (is_file($path)) {
        @unlink($path);
    }

    $directory = uploads_dir() . '/' . $slug;
    if (is_dir($directory)) {
        @rmdir($directory);
    }

    return true;
}

function images_purge(string $slug): void
{
    if (!image_valid_slug($slug)) {
        return;
    }

    $all = images_all();
    $items = $all[$slug] ?? [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = (string) ($item['file'] ?? '');
            if (!image_valid_file($file)) {
                continue;
            }
            $path = uploads_dir() . '/' . $slug . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    if (isset($all[$slug])) {
        unset($all[$slug]);
        images_write($all);
    }

    $directory = uploads_dir() . '/' . $slug;
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

function media_url(string $slug, string $file): string
{
    return url('/media/' . rawurlencode($slug) . '/' . rawurlencode($file));
}

function images_serve(string $slug, string $file): void
{
    if (!image_valid_slug($slug) || !image_valid_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image introuvable.';
        return;
    }

    $record = null;
    foreach (images_for_slug($slug) as $item) {
        if ($item['file'] === $file) {
            $record = $item;
            break;
        }
    }

    $path = uploads_dir() . '/' . $slug . '/' . $file;
    if ($record === null || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image introuvable.';
        return;
    }

    $mime = $record['mime'];
    if ($mime === '' || !str_starts_with($mime, 'image/')) {
        $mime = 'application/octet-stream';
    }

    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="' . addcslashes($record['file'], "\"\\") . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return;
    }

    readfile($path);
}
