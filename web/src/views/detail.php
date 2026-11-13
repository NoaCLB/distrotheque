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
        <?php if (!empty($adminLoggedIn)): ?>
            <a class="btn btn--line" href="<?= e(url('/admin/distros/edit', ['slug' => (string) $distro['slug']])) ?>"
               title="Modifier cette fiche dans l'administration">Modifier</a>
        <?php endif; ?>
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

<?php
$images = is_array($images ?? null) ? $images : [];
$galleryCount = count($images);
$hasImages = $galleryCount > 0;
?>

<?php if ($paragraphs || $hasImages): ?>
    <div class="sect">
        <h2 class="sect__title">Présentation</h2>
        <?php if ($hasImages): ?>
            <span class="sect__count"><?= e((string) $galleryCount) ?> image<?= $galleryCount > 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="<?= $hasImages ? 'presentation presentation--media' . ($paragraphs ? '' : ' presentation--solo') : 'prose' ?>">
        <?php if ($paragraphs): ?>
            <?php if ($hasImages): ?><div class="prose"><?php endif; ?>
            <?php foreach (array_slice($paragraphs, 0, 12) as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>
            <?php if ($hasImages): ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($hasImages): ?>
            <div class="carousel" data-carousel tabindex="0"
                 aria-roledescription="carrousel"
                 aria-label="Aperçus de <?= e($distro['name']) ?>">
                <div class="carousel__viewport">
                    <div class="carousel__track" data-carousel-track>
                        <?php foreach ($images as $index => $image): ?>
                            <figure class="carousel__slide" data-carousel-slide
                                    role="group"
                                    aria-roledescription="diapositive"
                                    aria-label="<?= e(($index + 1) . ' sur ' . $galleryCount) ?>"
                                    aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                                <img src="<?= e(media_url((string) $distro['slug'], (string) $image['file'])) ?>"
                                     alt="<?= e($distro['name'] . ' — aperçu ' . ($index + 1)) ?>"
                                     loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"
                                     decoding="async">
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <span class="carousel__zoom" aria-hidden="true">Agrandir</span>
                </div>

                <?php if ($galleryCount > 1): ?>
                    <div class="carousel__bar">
                        <div class="carousel__dots" data-carousel-dots></div>
                        <div class="carousel__controls">
                            <span class="carousel__counter" data-carousel-counter>1 / <?= e((string) $galleryCount) ?></span>
                            <button class="carousel__nav carousel__nav--prev" type="button"
                                    data-carousel-prev aria-label="Image précédente">‹</button>
                            <button class="carousel__nav carousel__nav--next" type="button"
                                    data-carousel-next aria-label="Image suivante">›</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($hasImages): ?>
    <div class="lightbox" data-lightbox hidden>
        <div class="lightbox__backdrop" aria-hidden="true"></div>
        <div class="lightbox__dialog" role="dialog" aria-modal="true" aria-label="Aperçu des images">
            <div class="lightbox__bar">
                <?php if ($galleryCount > 1): ?>
                    <span class="lightbox__counter" data-lightbox-counter></span>
                <?php endif; ?>
                <div class="lightbox__actions">
                    <button class="lightbox__zoom" type="button" data-lightbox-zoom
                            aria-pressed="false">Plein écran</button>
                    <button class="lightbox__close" type="button" data-lightbox-close
                            aria-label="Fermer l'aperçu">×</button>
                </div>
            </div>
            <div class="lightbox__stage">
                <?php if ($galleryCount > 1): ?>
                    <button class="lightbox__nav lightbox__nav--prev" type="button"
                            data-lightbox-prev aria-label="Image précédente">‹</button>
                <?php endif; ?>
                <img class="lightbox__image" data-lightbox-image alt="">
                <?php if ($galleryCount > 1): ?>
                    <button class="lightbox__nav lightbox__nav--next" type="button"
                            data-lightbox-next aria-label="Image suivante">›</button>
                <?php endif; ?>
            </div>
        </div>
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
