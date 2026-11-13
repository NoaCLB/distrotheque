<?php
declare(strict_types=1);

function overrides_path(): string
{
    return (string) config('overrides_json');
}

function overrides_entries(): array
{
    $path = overrides_path();
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

function overrides_write(array $entries): bool
{
    return json_write_atomic(overrides_path(), array_values($entries), 0640);
}

function override_matches_distro(array $override, array $distro): bool
{
    $slug = trim((string) ($distro['slug'] ?? ''));
    $nameNorm = trim((string) ($distro['name_norm'] ?? ''));

    $targetSlug = trim((string) ($override['target_slug'] ?? ''));
    $targetName = trim((string) ($override['target_name_norm'] ?? ''));

    return ($slug !== '' && $targetSlug === $slug)
        || ($nameNorm !== '' && $targetName === $nameNorm);
}

function override_find_for_distro(array $distro): ?array
{
    foreach (overrides_entries() as $override) {
        if (override_matches_distro($override, $distro)) {
            return $override;
        }
    }

    return null;
}

function override_save(string $targetSlug, string $targetNameNorm, array $data): bool
{
    $entries = overrides_entries();
    $record = [
        'target_slug'      => $targetSlug,
        'target_name_norm' => $targetNameNorm,
        'data'             => $data,
        'updated_at'       => date('Y-m-d H:i:s'),
    ];

    $kept = [];
    $replaced = false;
    foreach ($entries as $entry) {
        $same = ($targetSlug !== '' && (string) ($entry['target_slug'] ?? '') === $targetSlug)
            || ($targetNameNorm !== '' && (string) ($entry['target_name_norm'] ?? '') === $targetNameNorm);

        if ($same) {
            if (!$replaced) {
                $kept[] = $record;
                $replaced = true;
            }
            continue;
        }

        $kept[] = $entry;
    }

    if (!$replaced) {
        $kept[] = $record;
    }

    return overrides_write($kept);
}

function override_delete_for_distro(array $distro): bool
{
    $entries = overrides_entries();
    $kept = [];
    $found = false;
    foreach ($entries as $entry) {
        if (override_matches_distro($entry, $distro)) {
            $found = true;
            continue;
        }
        $kept[] = $entry;
    }

    return $found && overrides_write($kept);
}

function override_form_values(array $distro, array $downloads): array
{
    $entry = [
        'name'          => (string) ($distro['name'] ?? ''),
        'version'       => (string) ($distro['version'] ?? ''),
        'release'       => (string) ($distro['release_name'] ?? ''),
        'updated'       => (string) ($distro['updated_raw'] ?? ''),
        'architectures' => (string) ($distro['architectures'] ?? ''),
        'description'   => (string) ($distro['description'] ?? ''),
        'href'          => (string) ($distro['href'] ?? ''),
        'rank'          => (string) ($distro['popularity'] ?? ''),
        'requirements'  => [
            'official_url' => (string) ($distro['official_url'] ?? ''),
            'memory'       => (string) ($distro['memory'] ?? ''),
            'storage'      => (string) ($distro['storage'] ?? ''),
            'firmware'     => (string) ($distro['firmware'] ?? ''),
            'note'         => (string) ($distro['requirements_note'] ?? ''),
        ],
        'archiveos' => [
            'website' => (string) ($distro['website'] ?? ''),
        ],
        'downloads' => array_map(
            static fn(array $download): array => [
                'label'        => (string) ($download['label'] ?? ''),
                'version'      => (string) ($download['version'] ?? ''),
                'architecture' => (string) ($download['architecture'] ?? ''),
                'url'          => (string) ($download['url'] ?? ''),
            ],
            $downloads
        ),
    ];

    return manual_form_values_from_entry($entry);
}

function override_summary(array $distro): string
{
    $name = trim((string) ($distro['name'] ?? ''));
    return $name !== '' ? $name : (string) ($distro['slug'] ?? '');
}
