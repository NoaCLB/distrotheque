<?php

$archs = split_archs((string) $distro['architectures']);
$website = safe_external_url((string) ($distro['official_url'] !== '' ? $distro['official_url'] : $distro['website']));
$sourcePage = safe_external_url((string) $distro['href']);
$wikipediaRaw = trim((string) ($archiveos['wikipedia'] ?? ''));
$wikipedia = safe_external_url($wikipediaRaw);
if ($wikipedia === '' && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._()\',&-]{0,120}$/u', $wikipediaRaw)) {
    $wikipedia = 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $wikipediaRaw));
}
$downloads = array_values(array_filter(
    $downloads,
    static fn(array $download): bool => safe_external_url((string) $download['url']) !== ''
));
$paragraphs = array_filter(array_map('trim', preg_split('/\n{1,}/', (string) $distro['description']) ?: []));

$release = trim((string) $distro['release_name']);
$releaseLabel = 'Publication';
$releaseValue = '';
if ($release !== '') {
    $releaseTime = strtotime($release);
    if ($releaseTime !== false) {
        $releaseValue = date('d/m/Y', $releaseTime);
    } else {
        $releaseLabel = 'Canal';
        $releaseValue = $release;
    }
}

$short = static fn(string $value): string => ($value !== '' && strlen($value) <= 64) ? $value : '';

$fileOf = static function (string $url): string {
    $parts = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn($part) => $part !== ''));
    while ($parts) {
        $last = (string) array_pop($parts);
        if (strcasecmp($last, 'download') !== 0 && strcasecmp($last, 'downloads') !== 0) {
            return rawurldecode($last);
        }
    }
    return '';
};

$genericLabels = ['', 'download', 'downloads', 'iso', 'image', 'file', 'lien'];
$filePattern = '/\.(iso|img|raw|xz|gz|bz2|zip|7z|torrent|sha1|sha256|sha512|md5|txt)$/i';

$meta = [];
if ($distro['version'] !== '') {
    $meta[] = (string) $distro['version'];
}
$meta[] = source_label((string) $distro['source']);
if ($distro['updated_at']) {
    $meta[] = 'maj ' . format_date((string) $distro['updated_at']);
}

$specs = array_filter([
    'Version'       => (string) $distro['version'],
    'Architectures' => implode(', ', array_map('arch_label', $archs)),
    $releaseLabel   => $releaseValue,
    'Source'        => source_label((string) $distro['source']),
    'Mise à jour'   => format_date((string) $distro['updated_at']),
    'Bureau'        => (string) ($archiveos['desktop_environment'] ?? ''),
], static fn($value): bool => trim((string) $value) !== '');

$facts = array_filter([
    'Auteur'    => (string) ($archiveos['author'] ?? ''),
    'Basé sur'  => (string) ($archiveos['based_on'] ?? ''),
    'Mémoire'   => $short(human_value($distro['memory'])),
    'Stockage'  => $short(human_value($distro['storage'])),
    'Démarrage' => $short(human_value($distro['firmware'])),
    'Catégorie' => (string) ($archiveos['category'] ?? ''),
    'Support'   => (string) ($archiveos['media'] ?? ''),
], static fn($value): bool => trim((string) $value) !== '');
?>

<a class="back" href="<?= e(url('/')) ?>">← Catalogue</a>

<header class="distro__head">
    <div>
        <h1><?= e($distro['name']) ?></h1>
        <?php if ($meta): ?>
            <p class="distro__meta">
                <?php foreach ($meta as $item): ?>
                    <span><?= e($item) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="actions">
        <?php if ($website !== ''): ?>
            <a class="btn" href="<?= e($website) ?>" target="_blank" rel="noopener noreferrer">Site officiel</a>
        <?php endif; ?>
        <?php if ($sourcePage !== ''): ?>
            <a class="btn btn--line" href="<?= e($sourcePage) ?>" target="_blank" rel="noopener noreferrer">Fiche source</a>
        <?php endif; ?>
    </div>
</header>

<?php if ($specs): ?>
    <dl class="specs">
        <?php foreach ($specs as $label => $value): ?>
            <div class="specs__cell">
                <dt><?= e($label) ?></dt>
                <dd><?= e((string) $value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<div class="sect">
    <h2 class="sect__title">Téléchargements</h2>
    <span class="sect__count"><?= e((string) count($downloads)) ?> fichier<?= count($downloads) > 1 ? 's' : '' ?></span>
</div>

<?php if (!$downloads): ?>
    <p class="prose">
        Aucune image ISO n'est référencée pour cette distribution. Le projet est peut-être arrêté,
        ou son site ne publie plus d'image.
    </p>
<?php else: ?>
    <table class="files">
        <thead>
            <tr>
                <th scope="col">Fichier</th>
                <th scope="col">Version</th>
                <th scope="col">Architecture</th>
                <th class="files__host" scope="col">Hébergeur</th>
                <th scope="col"><span class="vh">Lien</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($downloads as $download): ?>
                <?php
                $downloadUrl = safe_external_url((string) $download['url']);
                if ($downloadUrl === '') {
                    continue;
                }
                $rawLabel = trim((string) $download['label']);
                $file = $fileOf($downloadUrl);
                $generic = in_array(strtolower($rawLabel), $genericLabels, true);
                $name = $generic ? ($file !== '' ? $file : 'Image ISO') : $rawLabel;
                $note = (!$generic && $file !== '' && strcasecmp($file, $name) !== 0) ? $file : '';
                $isFile = (bool) preg_match($filePattern, $file);
                ?>
                <tr>
                    <td class="files__name<?= $generic ? ' files__name--file' : '' ?>">
                        <?= e($name) ?>
                        <?php if ($note !== ''): ?>
                            <span class="files__note"><?= e($note) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="files__data files__version"><?= e($download['version'] !== '' ? $download['version'] : '—') ?></td>
                    <td class="files__data files__arch"><?= e($download['architecture'] !== '' ? arch_label((string) $download['architecture']) : '—') ?></td>
                    <td class="files__data files__host"><?= e($download['host'] !== '' ? host_label((string) $download['host']) : '—') ?></td>
                    <td class="files__action">
                        <a class="btn btn--line btn--sm" href="<?= e($downloadUrl) ?>" target="_blank" rel="noopener noreferrer nofollow">
                            <?= $isFile ? 'Télécharger' : 'Ouvrir' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($paragraphs): ?>
    <div class="sect">
        <h2 class="sect__title">Présentation</h2>
    </div>
    <div class="prose">
        <?php foreach (array_slice($paragraphs, 0, 12) as $paragraph): ?>
            <p><?= e($paragraph) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<details class="more">
    <summary>Informations complémentaires</summary>
    <dl class="specs">
        <?php foreach ($facts as $label => $value): ?>
            <div class="specs__cell">
                <dt><?= e($label) ?></dt>
                <dd><?= e((string) $value) ?></dd>
            </div>
        <?php endforeach; ?>
        <?php if ($website !== ''): ?>
            <div class="specs__cell">
                <dt>Site officiel</dt>
                <dd><a href="<?= e($website) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e(host_label((string) parse_url($website, PHP_URL_HOST))) ?></a></dd>
            </div>
        <?php endif; ?>
        <?php if ($sourcePage !== ''): ?>
            <div class="specs__cell">
                <dt>Fiche source</dt>
                <dd><a href="<?= e($sourcePage) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e(source_label((string) $distro['source'])) ?></a></dd>
            </div>
        <?php endif; ?>
        <?php if ($wikipedia !== ''): ?>
            <div class="specs__cell">
                <dt>Wikipédia</dt>
                <dd><a href="<?= e($wikipedia) ?>" target="_blank" rel="noopener noreferrer nofollow">Article</a></dd>
            </div>
        <?php endif; ?>
    </dl>
</details>
