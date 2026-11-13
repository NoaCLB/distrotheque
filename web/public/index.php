<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self'; script-src 'self'; object-src 'none'; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);

$route = current_route();

if ($route === '/admin' || str_starts_with($route, '/admin/')) {
    require dirname(__DIR__) . '/src/admin.php';
    admin_handle($route);
    exit;
}

maybe_auto_update();

try {
    $stats = catalogue_stats();
} catch (Throwable $error) {
    error_log('Distrothèque : ' . $error->getMessage());
    http_response_code(500);
    render('error', [
        'pageTitle' => 'Base indisponible',
        'message'   => 'La base de données du catalogue est indisponible.',
        'stats'     => [],
        'showBuildHint' => true,
    ]);
    exit;
}

$slug = '';
if (preg_match('#^/distro/([a-z0-9][a-z0-9-]{0,80})$#', $route, $match)) {
    $slug = $match[1];
} elseif ($route === '/' && !empty($_GET['distro'])) {
    $candidate = (string) $_GET['distro'];
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $candidate)) {
        http_response_code(404);
        render('error', [
            'pageTitle' => 'Distribution introuvable',
            'message'   => 'Aucune distribution ne correspond à cette adresse.',
            'stats'     => $stats,
            'query'     => '',
        ]);
        exit;
    }
    $slug = $candidate;
}

if ($slug !== '') {
    $distro = find_distro($slug);

    if ($distro === null) {
        http_response_code(404);
        render('error', [
            'pageTitle' => 'Distribution introuvable',
            'message'   => "Aucune distribution ne correspond à « {$slug} ».",
            'stats'     => $stats,
            'query'     => '',
        ]);
        exit;
    }

    $archiveos = [];
    if (!empty($distro['archiveos_json'])) {
        $decoded = json_decode((string) $distro['archiveos_json'], true);
        if (is_array($decoded)) {
            $archiveos = $decoded;
        }
    }

    $related = other_distros((string) $distro['slug'], (string) $distro['source'], 6);

    render('detail', [
        'pageTitle' => (string) $distro['name'],
        'distro'    => $distro,
        'downloads' => downloads_for((int) $distro['id']),
        'archiveos' => $archiveos,
        'related'   => $related['rows'],
        'stats'     => $stats,
        'query'     => '',
    ]);
    exit;
}

if ($route !== '/') {
    http_response_code(404);
    render('error', [
        'pageTitle' => 'Page introuvable',
        'message'   => 'Cette page n\'existe pas dans la Distrothèque.',
        'stats'     => $stats,
        'query'     => '',
    ]);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$maxLength = (int) config('max_q_length');
if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, $maxLength, 'UTF-8');
} else {
    $query = substr($query, 0, $maxLength);
}

$arch = (string) ($_GET['arch'] ?? '');
if (!preg_match('/^[a-z0-9_]{1,24}$/', $arch)) {
    $arch = '';
}

$source = (string) ($_GET['source'] ?? '');
if (!array_key_exists($source, SOURCE_OPTIONS)) {
    $source = '';
}

$sort = (string) ($_GET['sort'] ?? 'popular');
if (!array_key_exists($sort, SORT_OPTIONS)) {
    $sort = 'popular';
}

$filters = [
    'q'      => $query,
    'arch'   => $arch,
    'source' => $source,
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = search_distros($filters, $sort, $page, (int) config('per_page'));

$activeQuery = [
    'q'      => $query,
    'arch'   => $arch,
    'source' => $source,
    'sort'   => $sort === 'popular' ? '' : $sort,
];

render('list', [
    'pageTitle'   => $query !== '' ? 'Recherche : ' . $query : null,
    'filters'     => $filters,
    'sort'        => $sort,
    'result'      => $result,
    'facets'      => facet_architectures($filters),
    'sources'     => facet_sources($filters),
    'arch_total'  => count_distros($filters, ['arch']),
    'activeQuery' => $activeQuery,
    'stats'       => $stats,
    'query'       => $query,
]);
