<?php

?>
<section class="error">
    <h1><?= e($message) ?></h1>
    <?php if (!empty($showBuildHint)): ?>
        <p>La base n'est pas encore construite. Lancez la commande suivante à la racine du projet :</p>
        <p><code class="code">python3 update.py --skip-scrape</code></p>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/')) ?>">Retour au catalogue</a>
</section>
