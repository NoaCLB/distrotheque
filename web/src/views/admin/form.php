<?php

$isEdit = in_array($mode ?? 'new', ['edit', 'override'], true);
$isOverride = ($mode ?? 'new') === 'override';
$action = $isOverride
    ? url('/admin/distros/edit', ['slug' => (string) $slug, 'mode' => 'override'])
    : ($isEdit
        ? url('/admin/distros/edit', ['slug' => (string) $slug, 'mode' => 'manual'])
        : url('/admin/distros/new'));
$downloads = is_array($values['downloads'] ?? null) ? $values['downloads'] : [];
if (!$downloads) {
    $downloads[] = ['label' => '', 'version' => '', 'architecture' => '', 'url' => ''];
}
$errors = is_array($errors ?? null) ? $errors : [];
$images = is_array($images ?? null) ? $images : [];
$imageMode = $isOverride ? 'override' : 'manual';
?>
<section class="admin-head">
    <div>
        <p class="admin-kicker"><?= $isOverride ? 'Fiche du catalogue' : ($isEdit ? 'Modifier une fiche' : 'Nouvelle fiche') ?></p>
        <h1><?= $isOverride ? 'Modifier la fiche' : ($isEdit ? 'Modifier la distribution' : 'Ajouter une distribution') ?></h1>
        <p class="admin-sub">
            <?php if ($isOverride): ?>
                Cette fiche provient du catalogue. Tes modifications sont conservées dans
                <code>data/distro_overrides.json</code> et appliquées à chaque reconstruction.
                Utilise « Réinitialiser » dans la liste pour revenir aux données d'origine.
            <?php else: ?>
                Seuls le nom et au moins un lien HTTP(S) sont obligatoires. La base est reconstruite
                automatiquement après l'enregistrement.
            <?php endif; ?>
        </p>
    </div>
    <a class="btn btn--line" href="<?= e(url('/admin/distros')) ?>">Retour à la liste</a>
</section>

<?php if ($errors): ?>
    <div class="notice notice--error" role="alert">
        <p><strong>La fiche n'a pas été enregistrée :</strong></p>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e((string) $error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form class="admin-form admin-panel" method="post" action="<?= e($action) ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
        <input type="hidden" name="mode" value="<?= $isOverride ? 'override' : 'manual' ?>">
    <?php endif; ?>

    <fieldset class="admin-fieldset">
        <legend>Identité</legend>

        <div class="admin-grid">
            <label class="field field--wide">
                <span class="field__label">Nom <span class="field__req">obligatoire</span></span>
                <input class="field__input" type="text" name="name" maxlength="120" required
                       value="<?= e((string) ($values['name'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Version</span>
                <input class="field__input" type="text" name="version" maxlength="80"
                       value="<?= e((string) ($values['version'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Nom de version</span>
                <input class="field__input" type="text" name="release" maxlength="80"
                       value="<?= e((string) ($values['release'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Mise à jour</span>
                <input class="field__input" type="text" name="updated" maxlength="60"
                       placeholder="2026-11-13 ou 13 November 2026"
                       value="<?= e((string) ($values['updated'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Architectures</span>
                <input class="field__input" type="text" name="architectures" maxlength="200"
                       placeholder="x86_64, x86, arm64"
                       value="<?= e((string) ($values['architectures'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Rang de popularité</span>
                <input class="field__input" type="number" name="rank" min="1" max="99999"
                       value="<?= e((string) ($values['rank'] ?? '')) ?>">
            </label>
        </div>

        <label class="field">
            <span class="field__label">Description</span>
            <textarea class="field__input field__textarea" name="description" rows="7" maxlength="5000"><?= e((string) ($values['description'] ?? '')) ?></textarea>
        </label>
    </fieldset>

    <fieldset class="admin-fieldset">
        <legend>Liens</legend>

        <div class="admin-grid">
            <label class="field">
                <span class="field__label">Site officiel</span>
                <input class="field__input" type="url" name="website" maxlength="500"
                       placeholder="https://exemple.org/"
                       value="<?= e((string) ($values['website'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Page source</span>
                <input class="field__input" type="url" name="href" maxlength="500"
                       placeholder="https://exemple.org/telechargements"
                       value="<?= e((string) ($values['href'] ?? '')) ?>">
            </label>
        </div>
    </fieldset>

    <fieldset class="admin-fieldset">
        <legend>Prérequis</legend>

        <div class="admin-grid">
            <label class="field">
                <span class="field__label">Mémoire</span>
                <input class="field__input" type="text" name="memory" maxlength="120"
                       value="<?= e((string) ($values['memory'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Stockage</span>
                <input class="field__input" type="text" name="storage" maxlength="120"
                       value="<?= e((string) ($values['storage'] ?? '')) ?>">
            </label>

            <label class="field">
                <span class="field__label">Firmware</span>
                <input class="field__input" type="text" name="firmware" maxlength="120"
                       value="<?= e((string) ($values['firmware'] ?? '')) ?>">
            </label>
        </div>

        <label class="field">
            <span class="field__label">Note</span>
            <textarea class="field__input field__textarea" name="note" rows="3" maxlength="1000"><?= e((string) ($values['note'] ?? '')) ?></textarea>
        </label>
    </fieldset>

    <fieldset class="admin-fieldset">
        <legend>Liens de téléchargement</legend>
        <p class="admin-note">
            Au moins un lien HTTP(S) est nécessaire pour que la fiche apparaisse.
            Toutes les lignes sont conservées, même si elles partagent la même URL,
            tant que le libellé, la version ou l'architecture diffèrent.
        </p>

        <div class="downloads" data-downloads>
            <div class="downloads__head" aria-hidden="true">
                <span>Libellé</span>
                <span>Version</span>
                <span>Architecture</span>
                <span>URL</span>
                <span></span>
            </div>

            <?php foreach ($downloads as $download): ?>
                <div class="downloads__row" data-download-row>
                    <label class="field">
                        <span class="field__label">Libellé</span>
                        <input class="field__input" type="text" name="downloads[label][]" maxlength="120"
                               value="<?= e((string) ($download['label'] ?? '')) ?>">
                    </label>
                    <label class="field">
                        <span class="field__label">Version</span>
                        <input class="field__input" type="text" name="downloads[version][]" maxlength="80"
                               value="<?= e((string) ($download['version'] ?? '')) ?>">
                    </label>
                    <label class="field">
                        <span class="field__label">Architecture</span>
                        <input class="field__input" type="text" name="downloads[architecture][]" maxlength="40"
                               value="<?= e((string) ($download['architecture'] ?? '')) ?>">
                    </label>
                    <label class="field">
                        <span class="field__label">URL</span>
                        <input class="field__input" type="url" name="downloads[url][]" maxlength="500"
                               placeholder="https://exemple.org/image.iso"
                               value="<?= e((string) ($download['url'] ?? '')) ?>">
                    </label>
                    <button class="btn btn--line btn--sm downloads__remove" type="button"
                            data-download-remove>Retirer</button>
                </div>
            <?php endforeach; ?>
        </div>

        <template data-download-template>
            <div class="downloads__row" data-download-row>
                <label class="field">
                    <span class="field__label">Libellé</span>
                    <input class="field__input" type="text" name="downloads[label][]" maxlength="120">
                </label>
                <label class="field">
                    <span class="field__label">Version</span>
                    <input class="field__input" type="text" name="downloads[version][]" maxlength="80">
                </label>
                <label class="field">
                    <span class="field__label">Architecture</span>
                    <input class="field__input" type="text" name="downloads[architecture][]" maxlength="40">
                </label>
                <label class="field">
                    <span class="field__label">URL</span>
                    <input class="field__input" type="url" name="downloads[url][]" maxlength="500"
                           placeholder="https://exemple.org/image.iso">
                </label>
                <button class="btn btn--line btn--sm downloads__remove" type="button"
                        data-download-remove>Retirer</button>
            </div>
        </template>

        <button class="btn btn--line btn--sm" type="button" data-download-add>Ajouter un lien</button>
    </fieldset>

    <div class="admin-actions">
        <button class="btn" type="submit"><?= $isEdit ? 'Enregistrer' : 'Ajouter la distribution' ?></button>
        <a class="btn btn--line" href="<?= e(url('/admin/distros')) ?>">Annuler</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="admin-panel admin-images">
        <div class="admin-images__head">
            <div>
                <h2>Images du carrousel</h2>
                <p class="admin-note">
                    Ces images apparaissent à droite de la présentation, sur la fiche publique.
                    Sans image, la fiche reste inchangée. Tous les formats d'image sont acceptés,
                    <?= e(format_bytes(image_max_bytes())) ?> maximum par fichier et
                    <?= e((string) image_max_per_distro()) ?> images maximum.
                    Pour un zoom net, utilise une image d'au moins 1200 px de large.
                </p>
            </div>
        </div>

        <?php if ($images): ?>
            <ul class="admin-images__grid">
                <?php foreach ($images as $image): ?>
                    <li class="admin-image">
                        <a class="admin-image__thumb" href="<?= e(media_url((string) $slug, (string) $image['file'])) ?>"
                           target="_blank" rel="noopener">
                            <img src="<?= e(media_url((string) $slug, (string) $image['file'])) ?>"
                                 alt="<?= e((string) $image['name']) ?>" loading="lazy" decoding="async">
                        </a>
                        <div class="admin-image__meta">
                            <span class="admin-image__name" title="<?= e((string) $image['name']) ?>"><?= e((string) $image['name']) ?></span>
                            <span><?= e(format_bytes((int) $image['size'])) ?></span>
                        </div>
                        <form method="post" action="<?= e(url('/admin/distros/images/delete')) ?>"
                              data-confirm="Supprimer cette image ?">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
                            <input type="hidden" name="mode" value="<?= e($imageMode) ?>">
                            <input type="hidden" name="file" value="<?= e((string) $image['file']) ?>">
                            <button class="btn btn--line btn--sm" type="submit">Supprimer</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="admin-empty">Aucune image pour cette fiche : le carrousel n'est pas affiché.</p>
        <?php endif; ?>

        <form class="admin-images__upload" method="post" action="<?= e(url('/admin/distros/images')) ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
            <input type="hidden" name="mode" value="<?= e($imageMode) ?>">

            <label class="field">
                <span class="field__label">Ajouter des images</span>
                <input class="field__input" type="file" name="images[]" accept="image/*" multiple required>
            </label>

            <button class="btn" type="submit">Téléverser</button>
        </form>
    </section>
<?php endif; ?>
