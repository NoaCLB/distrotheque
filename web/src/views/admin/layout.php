<?php

$siteName = (string) config('site_name');
$title = !empty($pageTitle) ? $pageTitle . ' · Administration · ' . $siteName : 'Administration · ' . $siteName;
$adminUser = (string) ($adminUser ?? '');
$csrf = (string) ($csrf ?? '');
$flashes = is_array($flashes ?? null) ? $flashes : [];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f4f2ec">
    <link rel="icon" href="<?= e(asset('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('/assets/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/admin.css')) ?>">
</head>
<body class="admin">
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <header class="admin-bar">
        <div class="shell admin-bar__in">
            <a class="wordmark" href="<?= e(url('/')) ?>">
                <span class="wordmark__mark" aria-hidden="true"></span>
                <?= e($siteName) ?>
            </a>
            <span class="admin-bar__label">Administration</span>
            <?php if ($adminUser !== ''): ?>
                <div class="admin-bar__end">
                    <span class="admin-bar__user"><?= e($adminUser) ?></span>
                    <a class="btn btn--line btn--sm" href="<?= e(url('/admin/distros')) ?>">Fiches</a>
                    <a class="btn btn--line btn--sm" href="<?= e(url('/admin/deletions')) ?>">Corbeille</a>
                    <form class="admin-bar__logout" method="post" action="<?= e(url('/admin/logout')) ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn--line btn--sm" type="submit">Déconnexion</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main id="contenu" class="shell admin-main">
        <?php foreach ($flashes as $flash): ?>
            <p class="notice notice--<?= e((string) ($flash['type'] ?? 'success')) ?>" role="status">
                <?= e((string) ($flash['message'] ?? '')) ?>
            </p>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

    <script src="<?= e(asset('/assets/admin.js')) ?>" defer></script>
</body>
</html>
