<?php
declare(strict_types=1);

function deletions_path(): string
{
    return (string) config('deletions_json');
}

function deletions_entries(): array
{
    $path = deletions_path();
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

function deletions_write(array $entries): bool
{
    return json_write_atomic(deletions_path(), array_values($entries), 0640);
}

function deletion_matches_distro(array $record, array $distro): bool
{
    $slug = trim((string) ($distro['slug'] ?? ''));
    $nameNorm = trim((string) ($distro['name_norm'] ?? ''));

    $targetSlug = trim((string) ($record['target_slug'] ?? ''));
    $targetName = trim((string) ($record['target_name_norm'] ?? ''));

    return ($slug !== '' && $targetSlug === $slug)
        || ($nameNorm !== '' && $targetName === $nameNorm);
}

function deletion_find_for_distro(array $distro): ?array
{
    foreach (deletions_entries() as $record) {
        if (deletion_matches_distro($record, $distro)) {
            return $record;
        }
    }

    return null;
}

function deletion_find_by_slug(string $slug): ?array
{
    foreach (deletions_entries() as $record) {
        if ((string) ($record['target_slug'] ?? '') === $slug) {
            return $record;
        }
    }

    return null;
}

function deletion_save(string $targetSlug, string $targetNameNorm, string $name): bool
{
    $entries = deletions_entries();
    $record = [
        'target_slug'      => $targetSlug,
        'target_name_norm' => $targetNameNorm,
        'name'             => $name,
        'deleted_at'       => date('Y-m-d H:i:s'),
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

    return deletions_write($kept);
}

function deletion_delete_by_slug(string $slug): bool
{
    $entries = deletions_entries();
    $kept = [];
    $found = false;
    foreach ($entries as $entry) {
        if ((string) ($entry['target_slug'] ?? '') === $slug) {
            $found = true;
            continue;
        }
        $kept[] = $entry;
    }

    return $found && deletions_write($kept);
}

function deletion_delete_for_name(string $name): bool
{
    $key = normalize_search($name);
    if ($key === '') {
        return false;
    }

    $entries = deletions_entries();
    $kept = [];
    $found = false;
    foreach ($entries as $entry) {
        if ((string) ($entry['target_name_norm'] ?? '') === $key) {
            $found = true;
            continue;
        }
        $kept[] = $entry;
    }

    return $found && deletions_write($kept);
}

function deletion_label(array $record): string
{
    $name = trim((string) ($record['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $slug = trim((string) ($record['target_slug'] ?? ''));
    return $slug !== '' ? $slug : 'Fiche supprimée';
}
