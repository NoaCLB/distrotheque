<?php

$siteName = (string) config('site_name');
$title = !empty($pageTitle) ? $pageTitle . ' · ' . $siteName : $siteName . ' — ' . (string) config('tagline');
$builtAt = (string) ($stats['built_at'] ?? '');
$count = (int) ($stats['distro_count'] ?? 0);
$githubUrl = 'https://github.com/NoaCLB/distrotheque';
$githubIcon = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="Catalogue de distributions Linux : recherche par nom, filtre par architecture et liens de téléchargement ISO.">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f4f2ec">
    <link rel="icon" href="<?= e(asset('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('/assets/style.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <header class="masthead">
        <div class="shell masthead__in">
            <div class="masthead__start">
                <a class="github-link" href="<?= e($githubUrl) ?>" aria-label="Code source sur GitHub" title="Code source sur GitHub" rel="noopener noreferrer" target="_blank">
                    <?= $githubIcon ?>
                </a>
                <a class="wordmark" href="<?= e(url('/')) ?>">
                    <span class="wordmark__mark" aria-hidden="true"></span>
                    <?= e($siteName) ?>
                </a>
            </div>
            <?php if ($count > 0): ?>
                <p class="masthead__meta">
                    <span class="mono"><?= e(format_number($count)) ?></span> distributions
                </p>
            <?php endif; ?>
        </div>
    </header>

    <main id="contenu" class="shell">
        <?= $content ?>
    </main>

    <footer class="footer">
        <div class="shell footer__in">
            <p>
                Données :
                <a href="https://archiveos.org/" rel="noopener noreferrer" target="_blank">archiveos.org</a>
                ·
                <a href="https://isoslinux.com/" rel="noopener noreferrer" target="_blank">isoslinux.com</a>
                <a class="github-link github-link--footer" href="<?= e($githubUrl) ?>" aria-label="Code source sur GitHub" title="Code source sur GitHub" rel="noopener noreferrer" target="_blank">
                    <?= $githubIcon ?>
                </a>
            </p>
            <p>
                <?php if ($builtAt !== ''): ?>
                    Base du <?= e(format_date($builtAt)) ?> à <?= e(substr($builtAt, 11, 5)) ?>
                <?php endif; ?>
                <?php if (update_in_progress()): ?>
                    <span class="live">· mise à jour en cours</span>
                <?php endif; ?>
            </p>
        </div>
    </footer>

    <script src="<?= e(asset('/assets/app.js')) ?>" defer></script>
</body>
</html>
