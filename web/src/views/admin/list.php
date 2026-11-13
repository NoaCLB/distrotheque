<?php

$rows = $result['rows'];
$total = (int) $result['total'];
$page = (int) $result['page'];
$pages = (int) $result['pages'];

$pageUrl = static function (int $target) use ($query): string {
    return url('/admin/distros', array_filter([
        'q'    => $query,
        'page' => $target > 1 ? $target : '',
    ], static fn($value) => $value !== '' && $value !== false));
};

$deletionCount = (int) ($deletionCount ?? 0);
?>
<section class="admin-head">
    <div>
        <p class="admin-kicker">Catalogue</p>
        <h1>Toutes les fiches</h1>
        <p class="admin-sub">
            Modifie ou supprime n'importe quelle distribution, scrapée ou ajoutée à la
            main. Les modifications des fiches scrapées sont conservées dans
            <code>data/distro_overrides.json</code> et réappliquées à chaque reconstruction.
            Les fiches supprimées vont dans la corbeille.
        </p>
    </div>
    <div class="admin-head__actions">
        <a class="btn btn--line" href="<?= e(url('/admin/deletions')) ?>">
            Corbeille<?= $deletionCount > 0 ? ' (' . e((string) $deletionCount) . ')' : '' ?>
        </a>
        <a class="btn" href="<?= e(url('/admin/distros/new')) ?>">Ajouter une distribution</a>
    </div>
</section>

<form class="admin-search" method="get" action="<?= e(url('/admin/distros')) ?>">
    <label class="field">
        <span class="field__label">Rechercher une fiche</span>
        <input class="field__input" type="search" name="q" value="<?= e($query) ?>"
               maxlength="<?= e((string) config('max_q_length')) ?>" placeholder="Nom ou mot-clé">
    </label>
    <button class="btn" type="submit">Rechercher</button>
    <?php if ($query !== ''): ?>
        <a class="btn btn--line" href="<?= e(url('/admin/distros')) ?>">Effacer</a>
    <?php endif; ?>
</form>

<p class="admin-count">
    <?= e(format_number($total)) ?> fiche<?= $total > 1 ? 's' : '' ?>
    <?php if ($query !== ''): ?>
        pour « <?= e($query) ?> »
    <?php endif; ?>
</p>

<?php if (!$rows): ?>
    <section class="admin-panel">
        <p class="admin-empty">Aucune fiche ne correspond à cette recherche.</p>
    </section>
<?php else: ?>
    <section class="admin-panel">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Nom</th>
                        <th scope="col">Version</th>
                        <th scope="col">Architectures</th>
                        <th scope="col">Liens</th>
                        <th scope="col">Source</th>
                        <th scope="col" class="admin-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $manual = $row['admin_manual'] ?? null;
                        $override = $row['admin_override'] ?? null;
                        $isManualSource = (string) ($row['source'] ?? '') === 'manual';
                        $manualSlug = is_array($manual) ? manual_entry_slug($manual) : '';
                        $deleteIsManual = $isManualSource && $manualSlug !== '';
                        $deleteSlug = $deleteIsManual ? $manualSlug : (string) $row['slug'];

                        if ($isManualSource && $manualSlug !== '') {
                            $editUrl = url('/admin/distros/edit', [
                                'slug' => $manualSlug,
                                'mode' => 'manual',
                            ]);
                        } else {
                            $editUrl = url('/admin/distros/edit', [
                                'slug' => (string) $row['slug'],
                                'mode' => 'override',
                            ]);
                        }
                        ?>
                        <tr>
                            <td data-label="Nom">
                                <strong><?= e((string) $row['name']) ?></strong>
                                <span class="admin-slug"><?= e((string) $row['slug']) ?></span>
                                <span class="admin-badges">
                                    <?php if ($isManualSource): ?>
                                        <span class="badge badge--manual">Ajout manuel</span>
                                    <?php elseif ($manual !== null): ?>
                                        <span class="badge badge--manual">+ ajout manuel</span>
                                    <?php endif; ?>
                                    <?php if ($override !== null): ?>
                                        <span class="badge badge--override">Modifié</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Version"><?= e((string) ($row['version'] !== '' ? $row['version'] : '—')) ?></td>
                            <td data-label="Architectures"><?= e((string) ($row['architectures'] !== '' ? $row['architectures'] : '—')) ?></td>
                            <td data-label="Liens"><?= e((string) (int) $row['iso_count']) ?></td>
                            <td data-label="Source"><?= e(source_label((string) $row['source'])) ?></td>
                            <td data-label="Actions" class="admin-table__actions">
                                <a class="btn btn--line btn--sm" href="<?= e($editUrl) ?>">Modifier</a>

                                <?php if ($manual !== null && !$isManualSource): ?>
                                    <a class="btn btn--line btn--sm"
                                       href="<?= e(url('/admin/distros/edit', ['slug' => $manualSlug, 'mode' => 'manual'])) ?>">Ajout manuel</a>
                                <?php endif; ?>

                                <?php if ($isManualSource && $manualSlug !== ''): ?>
                                    <form class="admin-inline-form" method="post"
                                          action="<?= e(url('/admin/distros/delete')) ?>"
                                          data-confirm="Supprimer définitivement « <?= e((string) ($manual['name'] ?? $row['name'])) ?> » ?">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="slug" value="<?= e($deleteSlug) ?>">
                                        <button class="btn btn--line btn--sm btn--danger" type="submit">Supprimer</button>
                                    </form>
                                <?php else: ?>
                                    <form class="admin-inline-form" method="post"
                                          action="<?= e(url('/admin/distros/delete')) ?>"
                                          data-confirm="Retirer « <?= e((string) $row['name']) ?> » du catalogue ? Elle pourra être restaurée depuis la corbeille.">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="slug" value="<?= e($deleteSlug) ?>">
                                        <button class="btn btn--line btn--sm btn--danger" type="submit">Supprimer</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($override !== null): ?>
                                    <form class="admin-inline-form" method="post"
                                          action="<?= e(url('/admin/distros/reset')) ?>"
                                          data-confirm="Restaurer les données d'origine de « <?= e((string) $row['name']) ?> » ?">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="slug" value="<?= e((string) $row['slug']) ?>">
                                        <button class="btn btn--line btn--sm" type="submit">Réinitialiser</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($pages > 1): ?>
        <nav class="admin-pagination" aria-label="Pagination des fiches">
            <?php if ($page > 1): ?>
                <a class="btn btn--line btn--sm" href="<?= e($pageUrl(1)) ?>">Début</a>
                <a class="btn btn--line btn--sm" href="<?= e($pageUrl($page - 1)) ?>">Précédent</a>
            <?php endif; ?>
            <span class="admin-pagination__status">Page <?= e((string) $page) ?> sur <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn--line btn--sm" href="<?= e($pageUrl($page + 1)) ?>">Suivant</a>
                <a class="btn btn--line btn--sm" href="<?= e($pageUrl($pages)) ?>">Fin</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
