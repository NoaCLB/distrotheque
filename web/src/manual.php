<?php
declare(strict_types=1);

function manual_path(): string
{
    return (string) config('manual_json');
}

function manual_entries(): array
{
    $path = manual_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    return array_values(array_filter($data, 'is_array'));
}

function manual_write(array $entries): bool
{
    return json_write_atomic(manual_path(), array_values($entries), 0640);
}

function manual_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function manual_slugify(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) {
            $value = $ascii;
        }
    }

    $value = strtolower($value);
    $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    if ($value === '') {
        return 'distro';
    }

    return substr($value, 0, 80);
}

function manual_entry_slug(array $entry): string
{
    $archiveos = is_array($entry['archiveos'] ?? null) ? $entry['archiveos'] : [];
    $slug = trim((string) ($archiveos['slug'] ?? ''));

    return $slug !== '' ? $slug : manual_slugify((string) ($entry['name'] ?? ''));
}

function manual_find(string $slug): ?array
{
    foreach (manual_entries() as $entry) {
        if (manual_entry_slug($entry) === $slug) {
            return $entry;
        }
    }

    return null;
}

function manual_name_taken(string $name, string $ignore = ''): bool
{
    $key = normalize_search($name);
    if ($key === '') {
        return false;
    }

    foreach (manual_entries() as $entry) {
        if ($ignore !== '' && manual_entry_slug($entry) === $ignore) {
            continue;
        }
        if (normalize_search((string) ($entry['name'] ?? '')) === $key) {
            return true;
        }
    }

    return false;
}

function manual_unique_slug(string $base, array $entries, string $ignore = ''): string
{
    $base = manual_slugify($base);
    $taken = [];
    foreach ($entries as $entry) {
        $slug = manual_entry_slug($entry);
        if ($slug !== '' && $slug !== $ignore) {
            $taken[$slug] = true;
        }
    }

    $candidate = $base;
    $suffix = 2;
    while (isset($taken[$candidate])) {
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function manual_replace(string $slug, array $entry): bool
{
    $entries = manual_entries();
    foreach ($entries as $index => $candidate) {
        if (manual_entry_slug($candidate) === $slug) {
            $entries[$index] = $entry;
            return manual_write($entries);
        }
    }

    return false;
}

function manual_delete(string $slug): bool
{
    $entries = manual_entries();
    $kept = [];
    $found = false;
    foreach ($entries as $entry) {
        if (manual_entry_slug($entry) === $slug) {
            $found = true;
            continue;
        }
        $kept[] = $entry;
    }

    return $found && manual_write($kept);
}

function manual_valid_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return safe_external_url($value);
}

function manual_from_input(array $input, ?array $existing = null): array
{
    $errors = [];

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Le nom de la distribution est obligatoire.';
    } elseif (manual_text_length($name) > 120) {
        $errors[] = 'Le nom ne doit pas dépasser 120 caractères.';
    }

    $version = trim((string) ($input['version'] ?? ''));
    if (manual_text_length($version) > 80) {
        $errors[] = 'La version ne doit pas dépasser 80 caractères.';
    }

    $release = trim((string) ($input['release'] ?? ''));
    if (manual_text_length($release) > 80) {
        $errors[] = 'Le nom de version ne doit pas dépasser 80 caractères.';
    }

    $updated = trim((string) ($input['updated'] ?? ''));
    if (manual_text_length($updated) > 60) {
        $errors[] = 'La date de mise à jour ne doit pas dépasser 60 caractères.';
    }

    $architectures = trim((string) ($input['architectures'] ?? ''));
    if (manual_text_length($architectures) > 200) {
        $errors[] = 'Les architectures ne doivent pas dépasser 200 caractères.';
    } elseif ($architectures !== '' && !preg_match('/^[A-Za-z0-9 ,+\/_-]+$/', $architectures)) {
        $errors[] = 'Les architectures contiennent des caractères invalides.';
    }

    $description = trim((string) ($input['description'] ?? ''));
    if (manual_text_length($description) > 5000) {
        $errors[] = 'La description ne doit pas dépasser 5 000 caractères.';
    }

    $websiteInput = trim((string) ($input['website'] ?? ''));
    $website = manual_valid_url($websiteInput);
    if ($websiteInput !== '' && $website === '') {
        $errors[] = 'Le site officiel doit être une URL HTTP(S) valide.';
    }

    $hrefInput = trim((string) ($input['href'] ?? ''));
    $href = manual_valid_url($hrefInput);
    if ($hrefInput !== '' && $href === '') {
        $errors[] = 'La page source doit être une URL HTTP(S) valide.';
    }

    $rank = trim((string) ($input['rank'] ?? ''));
    if ($rank !== '' && (!ctype_digit($rank) || (int) $rank < 1 || (int) $rank > 99999)) {
        $errors[] = 'Le rang doit être un nombre entier entre 1 et 99 999.';
    }

    $memory = trim((string) ($input['memory'] ?? ''));
    $storage = trim((string) ($input['storage'] ?? ''));
    $firmware = trim((string) ($input['firmware'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    foreach ([$memory, $storage, $firmware] as $value) {
        if (manual_text_length($value) > 120) {
            $errors[] = 'Les prérequis ne doivent pas dépasser 120 caractères par champ.';
            break;
        }
    }
    if (manual_text_length($note) > 1000) {
        $errors[] = 'La note de prérequis ne doit pas dépasser 1 000 caractères.';
    }

    $rows = is_array($input['downloads'] ?? null) ? $input['downloads'] : [];
    $labels = is_array($rows['label'] ?? null) ? array_values($rows['label']) : [];
    $versions = is_array($rows['version'] ?? null) ? array_values($rows['version']) : [];
    $downloadArchs = is_array($rows['architecture'] ?? null) ? array_values($rows['architecture']) : [];
    $urls = is_array($rows['url'] ?? null) ? array_values($rows['url']) : [];
    $rowCount = max(count($labels), count($versions), count($downloadArchs), count($urls));

    $downloads = [];
    for ($index = 0; $index < $rowCount; $index++) {
        $urlInput = trim((string) ($urls[$index] ?? ''));
        if ($urlInput === '') {
            continue;
        }

        $url = manual_valid_url($urlInput);
        if ($url === '') {
            $errors[] = 'Lien de téléchargement invalide : ' . $urlInput;
            continue;
        }

        $downloads[] = [
            'label'        => trim((string) ($labels[$index] ?? '')),
            'group'        => '',
            'version'      => trim((string) ($versions[$index] ?? '')),
            'architecture' => trim((string) ($downloadArchs[$index] ?? '')),
            'desktop'      => '',
            'purpose'      => '',
            'status'       => '',
            'health'       => '',
            'url'          => $url,
        ];
    }

    if (!$downloads) {
        $errors[] = 'Ajoute au moins un lien de téléchargement HTTP(S).';
    }

    $entry = is_array($existing) ? $existing : [];
    $archiveos = is_array($entry['archiveos'] ?? null) ? $entry['archiveos'] : [];
    $requirements = is_array($entry['requirements'] ?? null) ? $entry['requirements'] : [];

    $entry['name'] = $name;
    $entry['version'] = $version;
    $entry['release'] = $release;
    $entry['updated'] = $updated;
    $entry['architectures'] = $architectures;
    $entry['description'] = $description;
    $entry['href'] = $href;
    $entry['rank'] = $rank;
    $entry['source'] = 'manual';
    $entry['downloads'] = $downloads;
    $entry['requirements'] = array_merge($requirements, [
        'architectures' => $architectures,
        'official_url'  => $website,
        'memory'        => $memory,
        'storage'       => $storage,
        'firmware'      => $firmware,
        'note'          => $note,
    ]);
    $archiveos['website'] = $website;
    $entry['archiveos'] = $archiveos;

    return [$entry, $errors];
}

function manual_download_counts(array $entry): array
{
    $total = 0;
    $variants = [];
    foreach ((array) ($entry['downloads'] ?? []) as $download) {
        if (!is_array($download)) {
            continue;
        }
        $total++;
        $url = trim((string) ($download['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $variants[implode("\x1f", [
            $url,
            (string) ($download['label'] ?? ''),
            (string) ($download['group'] ?? ''),
            (string) ($download['version'] ?? ''),
            (string) ($download['architecture'] ?? ''),
            (string) ($download['desktop'] ?? ''),
            (string) ($download['purpose'] ?? ''),
            (string) ($download['status'] ?? ''),
            (string) ($download['health'] ?? ''),
        ])] = true;
    }

    return [$total, count($variants)];
}

function manual_form_values_from_entry(array $entry): array
{
    $requirements = is_array($entry['requirements'] ?? null) ? $entry['requirements'] : [];
    $archiveos = is_array($entry['archiveos'] ?? null) ? $entry['archiveos'] : [];

    $website = trim((string) ($requirements['official_url'] ?? ''));
    if ($website === '') {
        $website = trim((string) ($archiveos['website'] ?? ''));
    }

    $downloads = [];
    foreach ((array) ($entry['downloads'] ?? []) as $download) {
        if (!is_array($download)) {
            continue;
        }
        $downloads[] = [
            'label'        => (string) ($download['label'] ?? ''),
            'version'      => (string) ($download['version'] ?? ''),
            'architecture' => (string) ($download['architecture'] ?? ''),
            'url'          => (string) ($download['url'] ?? ''),
        ];
    }

    return [
        'name'          => (string) ($entry['name'] ?? ''),
        'version'       => (string) ($entry['version'] ?? ''),
        'release'       => (string) ($entry['release'] ?? ''),
        'updated'       => (string) ($entry['updated'] ?? ''),
        'architectures' => (string) ($entry['architectures'] ?? ''),
        'description'   => (string) ($entry['description'] ?? ''),
        'website'       => $website,
        'href'          => (string) ($entry['href'] ?? ''),
        'rank'          => (string) ($entry['rank'] ?? ''),
        'memory'        => (string) ($requirements['memory'] ?? ''),
        'storage'       => (string) ($requirements['storage'] ?? ''),
        'firmware'      => (string) ($requirements['firmware'] ?? ''),
        'note'          => (string) ($requirements['note'] ?? ''),
        'downloads'     => $downloads,
    ];
}

function manual_form_values_from_post(array $input): array
{
    $rows = is_array($input['downloads'] ?? null) ? $input['downloads'] : [];
    $labels = is_array($rows['label'] ?? null) ? array_values($rows['label']) : [];
    $versions = is_array($rows['version'] ?? null) ? array_values($rows['version']) : [];
    $archs = is_array($rows['architecture'] ?? null) ? array_values($rows['architecture']) : [];
    $urls = is_array($rows['url'] ?? null) ? array_values($rows['url']) : [];
    $count = max(count($labels), count($versions), count($archs), count($urls));

    $downloads = [];
    for ($index = 0; $index < $count; $index++) {
        $downloads[] = [
            'label'        => (string) ($labels[$index] ?? ''),
            'version'      => (string) ($versions[$index] ?? ''),
            'architecture' => (string) ($archs[$index] ?? ''),
            'url'          => (string) ($urls[$index] ?? ''),
        ];
    }

    return [
        'name'          => (string) ($input['name'] ?? ''),
        'version'       => (string) ($input['version'] ?? ''),
        'release'       => (string) ($input['release'] ?? ''),
        'updated'       => (string) ($input['updated'] ?? ''),
        'architectures' => (string) ($input['architectures'] ?? ''),
        'description'   => (string) ($input['description'] ?? ''),
        'website'       => (string) ($input['website'] ?? ''),
        'href'          => (string) ($input['href'] ?? ''),
        'rank'          => (string) ($input['rank'] ?? ''),
        'memory'        => (string) ($input['memory'] ?? ''),
        'storage'       => (string) ($input['storage'] ?? ''),
        'firmware'      => (string) ($input['firmware'] ?? ''),
        'note'          => (string) ($input['note'] ?? ''),
        'downloads'     => $downloads,
    ];
}

function manual_empty_values(): array
{
    return [
        'name'          => '',
        'version'       => '',
        'release'       => '',
        'updated'       => '',
        'architectures' => '',
        'description'   => '',
        'website'       => '',
        'href'          => '',
        'rank'          => '',
        'memory'        => '',
        'storage'       => '',
        'firmware'      => '',
        'note'          => '',
        'downloads'     => [],
    ];
}

function manual_rebuild(): array
{
    $script = (string) config('update_script');
    if (!is_file($script)) {
        return [false, 'Script de mise à jour introuvable.'];
    }

    $python = function_exists('python_binary') ? python_binary() : null;
    if ($python === null) {
        return [false, 'Python est introuvable.'];
    }

    $command = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --skip-scrape --no-log'
        . ' --json ' . escapeshellarg(project_root() . '/distros.json')
        . ' --db ' . escapeshellarg((string) config('db_path'))
        . ' --manual ' . escapeshellarg(manual_path())
        . ' --overrides ' . escapeshellarg((string) config('overrides_json'))
        . ' --deletions ' . escapeshellarg((string) config('deletions_json'));

    $output = [];
    $code = 1;

    if (function_exists('exec')) {
        @exec($command . ' 2>&1', $output, $code);
    } elseif (function_exists('popen')) {
        $handle = @popen($command . ' 2>&1', 'r');
        if ($handle === false) {
            return [false, 'Impossible de lancer la reconstruction.'];
        }
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false) {
                $output[] = rtrim($line);
            }
        }
        $code = pclose($handle);
    } else {
        return [false, 'exec et popen sont désactivés dans PHP.'];
    }

    return [$code === 0, trim(implode("\n", $output))];
}

function manual_summary(string $output): string
{
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $output)),
        static fn($line) => $line !== ''
    ));
    if (!$lines) {
        return 'aucun détail disponible.';
    }

    $summary = implode(' ', array_slice($lines, -2));
    if (manual_text_length($summary) > 300) {
        $summary = substr($summary, 0, 300) . '…';
    }

    return $summary;
}
