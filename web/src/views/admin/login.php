<section class="admin-head">
    <div>
        <p class="admin-kicker">Espace protégé</p>
        <h1>Connexion</h1>
    </div>
</section>

<?php if (!admin_configured()): ?>
    <section class="admin-panel admin-panel--narrow">
        <h2>Aucun compte configuré</h2>
        <p>
            Crée le compte administrateur depuis le serveur, avec l'utilisateur qui fait
            tourner PHP (par exemple <code>www-data</code>) :
        </p>
        <pre class="admin-code">php web/cli/admin.php set-password --username admin</pre>
        <p class="admin-note">
            Le mot de passe est demandé de façon masquée puis stocké sous forme de hash
            dans <code>data/admin.json</code>, en permissions <code>0600</code>.
        </p>
        <p><a class="btn" href="<?= e(url('/')) ?>">Retour au catalogue</a></p>
    </section>
<?php else: ?>
    <section class="admin-panel admin-panel--narrow">
        <?php if (!empty($error)): ?>
            <p class="notice notice--error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form class="admin-form" method="post" action="<?= e(url('/admin/login')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

            <label class="field">
                <span class="field__label">Identifiant</span>
                <input class="field__input" type="text" name="username" value="<?= e($username) ?>"
                       autocomplete="username" maxlength="80" required autofocus>
            </label>

            <label class="field">
                <span class="field__label">Mot de passe</span>
                <input class="field__input" type="password" name="password"
                       autocomplete="current-password" required>
            </label>

            <div class="admin-actions">
                <button class="btn" type="submit">Se connecter</button>
                <a class="btn btn--line" href="<?= e(url('/')) ?>">Retour au catalogue</a>
            </div>
        </form>
    </section>
<?php endif; ?>
