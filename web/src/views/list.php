<?php

$rows = $result['rows'];
$total = (int) $result['total'];
$page = (int) $result['page'];
$pages = (int) $result['pages'];
$hasFilters = $filters['q'] !== '' || $filters['arch'] !== '' || $filters['source'] !== '';

$pageUrl = static function (int $target) use ($query, $filters, $sort): string {
    return url('/', array_filter([
        'q'      => $query,
        'arch'   => $filters['arch'],
        'source' => $filters['source'],
        'sort'   => $sort === 'popular' ? '' : $sort,
        'page'   => $target > 1 ? $target : '',
    ], static fn($value) => $value !== '' && $value !== false));
};

$sourceChoices = [
    ''          => 'Toutes',
    'both'      => 'Les deux',
    'archiveos' => 'ArchiveOS',
    'isoslinux' => 'isoslinux',
    'manual'    => 'Ajouts manuels',
];
?>

<div class="page-head">
    <h1>Catalogue des distributions Linux, BSD et Unix</h1>
    <p>Les images ISO sont téléchargées depuis les serveurs officiels des projets.</p>
</div>

<form class="find" method="get" action="<?= e(url('/')) ?>" role="search">
    <div class="find__row">
        <div class="field">
            <label class="field__label" for="q">Nom de la distribution</label>
            <input
                id="q"
                class="input"
                type="search"
                name="q"
                value="<?= e($query) ?>"
                placeholder="Ubuntu, Debian, Alpine…"
                autocomplete="off"
                spellcheck="false"
                data-shortcut="/"
            >
        </div>
        <button class="btn" type="submit">Rechercher</button>
    </div>

    <div class="bar">
        <p class="bar__count">
            <?php if ($query !== ''): ?>
                <span class="bar__num"><?= e(format_number($total)) ?></span>
                résultat<?= $total > 1 ? 's' : '' ?> pour
                <span class="bar__q">«&nbsp;<?= e($query) ?>&nbsp;»</span>
            <?php else: ?>
                <span class="bar__num"><?= e(format_number($total)) ?></span>
                distribution<?= $total > 1 ? 's' : '' ?>
            <?php endif; ?>
        </p>

        <div class="bar__controls">
            <label class="pick">
                <span class="pick__label">Architecture</span>
                <select name="arch" data-autosubmit>
                    <option value="">Toutes</option>
                    <?php foreach ($facets as $arch => $count): ?>
                        <option value="<?= e((string) $arch) ?>" <?= $filters['arch'] === (string) $arch ? 'selected' : '' ?>>
                            <?= e(arch_label((string) $arch)) ?> (<?= e(format_number((int) $count)) ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php if ($filters['arch'] !== '' && !array_key_exists($filters['arch'], $facets)): ?>
                        <option value="<?= e($filters['arch']) ?>" selected><?= e(arch_label($filters['arch'])) ?></option>
                    <?php endif; ?>
                </select>
            </label>

            <label class="pick">
                <span class="pick__label">Source</span>
                <select name="source" data-autosubmit>
                    <?php foreach ($sourceChoices as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= $filters['source'] === (string) $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="pick">
                <span class="pick__label">Tri</span>
                <select name="sort" data-autosubmit>
                    <?php foreach (SORT_OPTIONS as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>" <?= $sort === (string) $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="btn btn--line btn--sm bar__apply" type="submit">Appliquer</button>
        </div>
    </div>
</form>

<?php if (!$rows): ?>
    <section class="empty">
        <h2>Aucun résultat</h2>
        <p>
            <?php if ($hasFilters): ?>
                Aucune distribution ne correspond à ces critères. <a href="<?= e(url('/')) ?>">Réinitialiser la recherche</a>.
            <?php else: ?>
                La base est vide pour le moment.
            <?php endif; ?>
        </p>
    </section>
<?php else: ?>
    <table class="index">
        <thead>
            <tr>
                <th class="index__num" scope="col">Nº</th>
                <th scope="col">Distribution</th>
                <th class="index__iso" scope="col">Images ISO</th>
                <th class="index__date" scope="col">Mise à jour</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $offset => $row): ?>
                <?php
                $archs = split_archs((string) $row['architectures']);
                $isoCount = (int) $row['iso_count'];
                $meta = [];
                if ($row['version'] !== '') {
                    $meta[] = (string) $row['version'];
                }
                foreach (array_slice($archs, 0, 2) as $arch) {
                    $meta[] = arch_label($arch);
                }
                $extraArchs = array_slice($archs, 2);
                $extraLabel = count($extraArchs) > 1
                    ? 'Afficher les ' . count($extraArchs) . ' autres architectures'
                    : "Afficher l'autre architecture";
                $date = format_date((string) $row['updated_at']);
                ?>
                <tr>
                    <td class="index__num"><?= e(str_pad((string) ($offset + 1), 2, '0', STR_PAD_LEFT)) ?></td>
                    <th class="index__name" scope="row">
                        <a href="<?= e(url('/distro/' . $row['slug'])) ?>"><?= e($row['name']) ?></a>
                        <?php if ($meta || $extraArchs): ?>
                            <span class="index__meta"><?= e(implode(' · ', $meta)) ?><?php if ($extraArchs): ?><span class="arch-more__rest" id="arch-rest-<?= e((string) $row['slug']) ?>"> · <?= e(implode(' · ', array_map('arch_label', $extraArchs))) ?></span><button type="button" class="arch-more" data-arch-toggle aria-expanded="false" aria-controls="arch-rest-<?= e((string) $row['slug']) ?>" aria-label="<?= e($extraLabel) ?>" data-label-more="<?= e($extraLabel) ?>" data-label-less="Réduire les architectures" hidden><span class="arch-more__sep"> · </span><span class="arch-more__more">+<?= e((string) count($extraArchs)) ?></span><span class="arch-more__less">−</span></button><?php endif; ?></span>
                        <?php endif; ?>
                    </th>
                    <td class="index__iso<?= $isoCount === 0 ? ' index__iso--none' : '' ?>">
                        <span class="index__isonum"><?= $isoCount > 0 ? e((string) $isoCount) : '—' ?></span>
                        <?php if ($isoCount > 0): ?>
                            <span class="index__isounit">ISO</span>
                        <?php endif; ?>
                    </td>
                    <td class="index__date"><?= $date !== '' ? e($date) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="pager__link" rel="prev" href="<?= e($pageUrl($page - 1)) ?>">← Précédent</a>
            <?php else: ?>
                <span class="pager__link" aria-disabled="true">← Précédent</span>
            <?php endif; ?>

            <span class="pager__pos"><?= e((string) $page) ?> / <?= e((string) $pages) ?></span>

            <?php if ($page < $pages): ?>
                <a class="pager__link" rel="next" href="<?= e($pageUrl($page + 1)) ?>">Suivant →</a>
            <?php else: ?>
                <span class="pager__link" aria-disabled="true">Suivant →</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
