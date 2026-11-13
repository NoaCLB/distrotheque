<section class="admin-panel admin-panel--narrow">
    <h1><?= e((string) ($pageTitle ?? 'Erreur')) ?></h1>
    <p><?= e((string) ($message ?? 'Une erreur est survenue.')) ?></p>
    <p><a class="btn btn--line" href="<?= e(url('/admin/distros')) ?>">Retour à la liste</a></p>
</section>
