<?php
declare(strict_types=1);

const SORT_OPTIONS = [
    'popular' => 'Popularité',
    'name'    => 'Nom (A→Z)',
    'recent'  => 'Mise à jour récente',
    'isos'    => 'Plus de liens ISO',
];

const SORT_SQL = [
    'name'    => 'd.name_norm ASC, d.name ASC',
    'recent'  => 'd.updated_at IS NULL, d.updated_at DESC, d.name_norm ASC',
    'isos'    => 'd.iso_count DESC, d.name_norm ASC',
    'popular' => 'd.popularity IS NULL, d.popularity ASC, d.name_norm ASC',
];

const SOURCE_OPTIONS = [
    'both'      => 'Les deux sources',
    'isoslinux' => 'isoslinux seul',
    'archiveos' => 'ArchiveOS seul',
];

function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function relevance_sql(string $query): array
{
    if ($query === '') {
        return ['', []];
    }

    $like = escape_like($query);

    return [
        "CASE
            WHEN d.name_norm = :r_exact THEN 0
            WHEN d.name_norm LIKE :r_prefix ESCAPE '\\' THEN 1
            WHEN d.name_norm LIKE :r_name ESCAPE '\\' THEN 2
            ELSE 3
         END",
        [
            ':r_exact'  => $query,
            ':r_prefix' => $like . '%',
            ':r_name'   => '%' . $like . '%',
        ],
    ];
}

function where_clauses(array $filters, array $skip = []): array
{
    $clauses = [];
    $params = [];

    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '' && !in_array('q', $skip, true)) {

        $clauses[] = "(d.name_norm LIKE :q_name ESCAPE '\\' OR d.keywords LIKE :q_keywords ESCAPE '\\')";
        $params[':q_name'] = '%' . escape_like(normalize_search($query)) . '%';
        $params[':q_keywords'] = $params[':q_name'];
    }

    $arch = (string) ($filters['arch'] ?? '');
    if ($arch !== '' && !in_array('arch', $skip, true)) {
        $clauses[] = 'EXISTS (SELECT 1 FROM distro_architectures a WHERE a.distro_id = d.id AND a.arch = :arch)';
        $params[':arch'] = $arch;
    }

    $source = (string) ($filters['source'] ?? '');
    if ($source !== '' && !in_array('source', $skip, true)) {
        $clauses[] = 'd.source = :source';
        $params[':source'] = $source;
    }

    if (!empty($filters['iso']) && !in_array('iso', $skip, true)) {
        $clauses[] = 'd.iso_count > 0';
    }

    return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
}

function search_distros(array $filters, string $sort, int $page, int $perPage): array
{
    $perPage = max(1, min(96, $perPage));
    [$where, $params] = where_clauses($filters);

    $query = normalize_search(trim((string) ($filters['q'] ?? '')));
    [$relevance, $relevanceParams] = relevance_sql($query);

    $countStatement = db()->prepare("SELECT COUNT(*) FROM distros d {$where}");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();

    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));

    $order = SORT_SQL[$sort] ?? SORT_SQL['name'];
    if ($relevance !== '') {
        $order = $relevance . ' ASC, ' . $order;
    }

    $statement = db()->prepare(
        "SELECT d.* FROM distros d {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset"
    );
    foreach ($params + $relevanceParams as $key => $value) {
        $statement->bindValue($key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $statement->execute();

    return [
        'rows'  => $statement->fetchAll(),
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
    ];
}

function facet_architectures(array $filters): array
{
    [$where, $params] = where_clauses($filters, ['arch']);
    $statement = db()->prepare(
        "SELECT a.arch, COUNT(*) AS total
           FROM distros d
           JOIN distro_architectures a ON a.distro_id = d.id
           {$where}
          GROUP BY a.arch
          ORDER BY total DESC, a.arch ASC"
    );
    $statement->execute($params);

    $result = [];
    foreach ($statement->fetchAll() as $row) {
        $result[(string) $row['arch']] = (int) $row['total'];
    }
    return $result;
}

function facet_sources(array $filters): array
{
    [$where, $params] = where_clauses($filters, ['source']);
    $statement = db()->prepare(
        "SELECT d.source, COUNT(*) AS total FROM distros d {$where} GROUP BY d.source"
    );
    $statement->execute($params);

    $result = [];
    foreach ($statement->fetchAll() as $row) {
        $result[(string) $row['source']] = (int) $row['total'];
    }
    return $result;
}

function count_distros(array $filters, array $skip = []): int
{
    [$where, $params] = where_clauses($filters, $skip);
    $statement = db()->prepare("SELECT COUNT(*) FROM distros d {$where}");
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function find_distro(string $slug): ?array
{
    $statement = db()->prepare('SELECT * FROM distros WHERE slug = :slug LIMIT 1');
    $statement->execute([':slug' => $slug]);
    $row = $statement->fetch();
    return $row ?: null;
}

function downloads_for(int $distroId): array
{
    $statement = db()->prepare(
        'SELECT * FROM downloads WHERE distro_id = :id ORDER BY variant_group ASC, version ASC, label ASC'
    );
    $statement->execute([':id' => $distroId]);
    return $statement->fetchAll();
}

function other_distros(string $slug, string $source, int $limit = 6): array
{
    $statement = db()->prepare(
        'SELECT slug, name, version, iso_count
           FROM distros
          WHERE slug != :slug AND source = :source
          ORDER BY iso_count DESC, name_norm ASC
          LIMIT :limit'
    );
    $statement->bindValue(':slug', $slug);
    $statement->bindValue(':source', $source);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll();
    return ['rows' => $rows, 'total' => count($rows)];
}

function catalogue_stats(): array
{
    $meta = [];
    foreach (db()->query('SELECT key, value FROM meta')->fetchAll() as $row) {
        $meta[(string) $row['key']] = (string) $row['value'];
    }

    $archCount = db()->query(
        'SELECT COUNT(DISTINCT arch) FROM distro_architectures'
    )->fetchColumn();

    $meta['arch_count'] = (string) $archCount;
    return $meta;
}
