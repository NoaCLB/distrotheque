<?php

$entries = is_array($entries ?? null) ? $entries : [];
$total = count($entries);
?>
<section class="admin-head">
    <div>
        <p class="admin-kicker">Catalogue</p>
        <h1>Corbeille</h1>
        <p class="admin-sub">
            Les fiches scrapées supprimées restent ici et peuvent être restaurées.
            Les ajouts manuels supprimés, eux, le sont définitivement.
        </p>
    </div>
    <div class="admin-head__actions">
        <a class="btn btn--line" href="<?= e(url('/admin/distros')) ?>">Retour aux fiches</a>
    </div>
</section>

<p class="admin-count">
    <?= e(format_number($total)) ?> fiche<?= $total > 1 ? 's' : '' ?> supprimée<?= $total > 1 ? 's' : '' ?>
</p>

<?php if (!$entries): ?>
    <section class="admin-panel">
        <p class="admin-empty">La corbeille est vide.</p>
    </section>
<?php else: ?>
    <section class="admin-panel">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Nom</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Supprimée le</th>
                        <th scope="col" class="admin-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php $slug = (string) ($entry['target_slug'] ?? ''); ?>
                        <tr>
                            <td data-label="Nom"><strong><?= e(deletion_label($entry)) ?></strong></td>
                            <td data-label="Slug"><span class="admin-slug"><?= e($slug !== '' ? $slug : '—') ?></span></td>
                            <td data-label="Supprimée le"><?= e((string) ($entry['deleted_at'] ?? '—')) ?></td>
                            <td data-label="Actions" class="admin-table__actions">
                                <?php if ($slug !== ''): ?>
                                    <form class="admin-inline-form" method="post"
                                          action="<?= e(url('/admin/distros/restore')) ?>">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                        <button class="btn btn--line btn--sm" type="submit">Restaurer</button>
                                    </form>
                                <?php else: ?>
                                    <span class="admin-empty">Slug manquant</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
