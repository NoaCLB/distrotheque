<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/manual.php';
require_once __DIR__ . '/overrides.php';
require_once __DIR__ . '/deletions.php';

function admin_send_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self'; script-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
    );
}

function admin_redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

function admin_render(string $view, array $data = []): void
{
    $data['flashes'] = admin_take_flashes();
    $data['adminUser'] = $_SESSION['admin']['username'] ?? '';
    $data['csrf'] = admin_csrf_token();

    extract($data, EXTR_SKIP);

    $viewFile = __DIR__ . '/views/admin/' . $view . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        echo 'Vue introuvable.';
        return;
    }

    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();

    require __DIR__ . '/views/admin/layout.php';
}

function admin_not_found(string $message): void
{
    http_response_code(404);
    admin_render('error', [
        'pageTitle' => 'Introuvable',
        'message'   => $message,
    ]);
    exit;
}

function admin_require_auth(): void
{
    if (!admin_is_authenticated()) {
        admin_redirect('/admin/login');
    }
}

function admin_flash_rebuild(bool $ok, string $output): void
{
    if ($ok) {
        admin_flash('success', 'Base reconstruite : ' . manual_summary($output));
        return;
    }

    admin_flash(
        'warning',
        'La modification est enregistrée, mais la reconstruction a échoué : '
        . manual_summary($output)
    );
}

function admin_handle_login(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_csrf_check();

        $username = trim((string) ($_POST['username'] ?? ''));
        if (function_exists('mb_substr')) {
            $username = mb_substr($username, 0, 80, 'UTF-8');
        } else {
            $username = substr($username, 0, 80);
        }
        $password = (string) ($_POST['password'] ?? '');
        $error = '';

        if (strlen($password) > 1024) {
            $error = 'Identifiants incorrects.';
        } elseif (!admin_configured()) {
            $error = 'Aucun compte administrateur n\'est configuré.';
        } elseif (admin_throttle_blocked(admin_client_ip())) {
            $error = 'Trop de tentatives. Réessaie dans quelques minutes.';
            admin_log('Connexion refusée (trop de tentatives)');
        } elseif (!admin_login($username, $password)) {
            $error = 'Identifiants incorrects.';
        } else {
            admin_flash('success', 'Connexion réussie.');
            admin_redirect('/admin/distros');
        }

        admin_render('login', [
            'pageTitle' => 'Connexion',
            'error'     => $error,
            'username'  => $username,
        ]);
        return;
    }

    if (admin_is_authenticated()) {
        admin_redirect('/admin/distros');
    }

    admin_render('login', [
        'pageTitle' => 'Connexion',
        'error'     => '',
        'username'  => '',
    ]);
}

function admin_handle_list(): void
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $maxLength = (int) config('max_q_length');
    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, $maxLength, 'UTF-8');
    } else {
        $query = substr($query, 0, $maxLength);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $result = search_distros(['q' => $query], 'name', $page, 40);

    $manualBySlug = [];
    $manualByName = [];
    foreach (manual_entries() as $entry) {
        $slug = manual_entry_slug($entry);
        $nameKey = normalize_search((string) ($entry['name'] ?? ''));
        if ($slug !== '') {
            $manualBySlug[$slug] = $entry;
        }
        if ($nameKey !== '') {
            $manualByName[$nameKey] = $entry;
        }
    }

    $overrideBySlug = [];
    $overrideByName = [];
    foreach (overrides_entries() as $override) {
        $slug = (string) ($override['target_slug'] ?? '');
        $nameKey = (string) ($override['target_name_norm'] ?? '');
        if ($slug !== '') {
            $overrideBySlug[$slug] = $override;
        }
        if ($nameKey !== '') {
            $overrideByName[$nameKey] = $override;
        }
    }

    foreach ($result['rows'] as $index => $row) {
        $slug = (string) ($row['slug'] ?? '');
        $nameKey = (string) ($row['name_norm'] ?? '');
        $manual = $manualBySlug[$slug] ?? null;
        if ($manual === null && $nameKey !== '') {
            $manual = $manualByName[$nameKey] ?? null;
        }

        $result['rows'][$index]['admin_manual'] = $manual;
        $result['rows'][$index]['admin_override'] = $overrideBySlug[$slug]
            ?? ($nameKey !== '' ? ($overrideByName[$nameKey] ?? null) : null);
    }

    admin_render('list', [
        'pageTitle'    => 'Toutes les fiches',
        'result'       => $result,
        'query'        => $query,
        'deletionCount' => count(deletions_entries()),
    ]);
}

function admin_handle_new(): void
{
    $errors = [];
    $values = manual_empty_values();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_csrf_check();
        [$entry, $errors] = manual_from_input($_POST);

        if (!$errors && manual_name_taken((string) ($entry['name'] ?? ''))) {
            $errors[] = 'Un ajout manuel portant ce nom existe déjà : modifie-le au lieu d\'en créer un second.';
        }

        if (!$errors) {
            $entries = manual_entries();
            $slug = manual_unique_slug((string) ($entry['name'] ?? ''), $entries);
            $archiveos = is_array($entry['archiveos'] ?? null) ? $entry['archiveos'] : [];
            $archiveos['slug'] = $slug;
            $entry['archiveos'] = $archiveos;

            $entries[] = $entry;
            if (!manual_write($entries)) {
                $errors[] = 'Impossible d\'écrire le fichier des ajouts manuels.';
            } else {
                deletion_delete_for_name((string) $entry['name']);
                [$ok, $output] = manual_rebuild();
                admin_flash('success', '« ' . (string) $entry['name'] . ' » a été ajoutée.');
                admin_flash_rebuild($ok, $output);
                admin_log('Ajout manuel de « ' . (string) $entry['name'] . ' » (slug ' . $slug . ')');
                admin_redirect('/admin/distros');
            }
        }

        $values = manual_form_values_from_post($_POST);
    }

    admin_render('form', [
        'pageTitle' => 'Nouvelle distribution',
        'mode'      => 'new',
        'values'    => $values,
        'errors'    => $errors,
        'slug'      => '',
        'images'    => [],
    ]);
}

function admin_handle_manual_edit(string $slug): void
{
    $existing = manual_find($slug);
    if ($existing === null) {
        admin_not_found('Aucun ajout manuel ne correspond à « ' . $slug . ' ».');
    }

    $errors = [];
    $values = manual_form_values_from_entry($existing);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_csrf_check();
        [$entry, $errors] = manual_from_input($_POST, $existing);

        if (!$errors && manual_name_taken((string) ($entry['name'] ?? ''), $slug)) {
            $errors[] = 'Un autre ajout manuel porte déjà ce nom.';
        }

        if (!$errors) {
            $archiveos = is_array($entry['archiveos'] ?? null) ? $entry['archiveos'] : [];
            $archiveos['slug'] = $slug;
            $entry['archiveos'] = $archiveos;

            if (!manual_replace($slug, $entry)) {
                $errors[] = 'Impossible d\'écrire le fichier des ajouts manuels.';
            } else {
                [$ok, $output] = manual_rebuild();
                admin_flash('success', '« ' . (string) $entry['name'] . ' » a été modifiée.');
                admin_flash_rebuild($ok, $output);
                admin_log('Modification de l\'ajout manuel « ' . (string) $entry['name'] . ' » (slug ' . $slug . ')');
                admin_redirect('/admin/distros');
            }
        }

        $values = manual_form_values_from_post($_POST);
    }

    admin_render('form', [
        'pageTitle' => 'Modifier ' . (string) ($values['name'] !== '' ? $values['name'] : $slug),
        'mode'      => 'edit',
        'values'    => $values,
        'errors'    => $errors,
        'slug'      => $slug,
        'images'    => images_for_slug($slug),
    ]);
}

function admin_handle_override_edit(array $distro): void
{
    $slug = (string) ($distro['slug'] ?? '');
    $override = override_find_for_distro($distro);
    $targetSlug = (string) ($override['target_slug'] ?? $slug);
    $targetNameNorm = (string) ($override['target_name_norm'] ?? $distro['name_norm'] ?? '');

    $errors = [];
    if ($override !== null && is_array($override['data'] ?? null)) {
        $values = manual_form_values_from_entry($override['data']);
    } else {
        $values = override_form_values($distro, downloads_for((int) $distro['id']));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        admin_csrf_check();
        [$entry, $errors] = manual_from_input($_POST);

        if (!$errors) {
            unset($entry['source']);
            if (is_array($entry['archiveos'] ?? null)) {
                unset($entry['archiveos']['slug']);
            }

            if (!override_save($targetSlug, $targetNameNorm, $entry)) {
                $errors[] = 'Impossible d\'écrire le fichier des modifications.';
            } else {
                [$ok, $output] = manual_rebuild();
                admin_flash('success', '« ' . (string) $entry['name'] . ' » a été modifiée.');
                admin_flash_rebuild($ok, $output);
                admin_log('Surcharge de la fiche « ' . (string) $entry['name'] . ' » (slug ' . $targetSlug . ')');
                admin_redirect('/admin/distros');
            }
        }

        $values = manual_form_values_from_post($_POST);
    }

    admin_render('form', [
        'pageTitle' => 'Modifier ' . (string) ($values['name'] !== '' ? $values['name'] : $slug),
        'mode'      => 'override',
        'values'    => $values,
        'errors'    => $errors,
        'slug'      => $slug,
        'override'  => $override,
        'images'    => images_for_slug($slug),
    ]);
}

function admin_image_mode(string $slug, string $mode): string
{
    if ($mode === 'manual' || $mode === 'override') {
        return $mode;
    }

    $manual = manual_find($slug);
    $distro = find_distro($slug);
    if ($manual !== null && $distro !== null && (string) ($distro['source'] ?? '') === 'manual') {
        return 'manual';
    }

    return 'override';
}

function admin_image_redirect(string $slug, string $mode): void
{
    admin_redirect('/admin/distros/edit', [
        'slug' => $slug,
        'mode' => admin_image_mode($slug, $mode),
    ]);
}

function admin_handle_images_upload(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Méthode non autorisée.');
    }

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        admin_flash('error', 'L\'envoi dépasse la limite autorisée par le serveur. Réduis la taille des fichiers.');
        admin_redirect('/admin/distros');
    }

    admin_csrf_check();

    $slug = (string) ($_POST['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $distro = find_distro($slug);
    if ($distro === null) {
        admin_not_found('Aucune fiche ne correspond à « ' . $slug . ' ».');
    }

    $mode = admin_image_mode($slug, '');
    $files = $_FILES['images'] ?? null;
    if (!is_array($files) || !isset($files['name'])) {
        admin_flash('warning', 'Aucun fichier reçu.');
        admin_image_redirect($slug, $mode);
    }

    $names = $files['name'];
    if (!is_array($names)) {
        $names = [$names];
    }

    $added = 0;
    $errors = [];
    foreach (array_keys($names) as $index) {
        $name = $files['name'][$index] ?? '';
        $temporary = $files['tmp_name'][$index] ?? '';
        $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        $size = $files['size'][$index] ?? 0;

        if (!is_string($name) || !is_string($temporary) || !is_numeric($uploadError) || !is_numeric($size)) {
            $errors[] = 'Fichier invalide.';
            continue;
        }

        [$record, $error] = images_add_upload($slug, [
            'name'     => $name,
            'tmp_name' => $temporary,
            'error'    => (int) $uploadError,
            'size'     => (int) $size,
        ]);
        if ($record !== null) {
            $added++;
        } elseif ($error !== '') {
            $errors[] = $error;
        }
    }

    if ($added > 0) {
        admin_flash('success', $added . ' image' . ($added > 1 ? 's ajoutées' : ' ajoutée') . ' à « ' . (string) $distro['name'] . ' ».');
        admin_log('Ajout de ' . $added . ' image(s) à « ' . (string) $distro['name'] . ' » (slug ' . $slug . ')');
    }

    foreach (array_unique($errors) as $error) {
        admin_flash('error', $error);
    }

    admin_image_redirect($slug, $mode);
}

function admin_handle_image_delete(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Méthode non autorisée.');
    }

    admin_csrf_check();

    $slug = (string) ($_POST['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $distro = find_distro($slug);
    if ($distro === null) {
        admin_not_found('Aucune fiche ne correspond à « ' . $slug . ' ».');
    }

    $file = (string) ($_POST['file'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}\.[a-z0-9]{1,8}$/', $file)) {
        admin_not_found('Image introuvable.');
    }

    $mode = admin_image_mode($slug, '');
    if (!images_delete($slug, $file)) {
        admin_flash('warning', 'Cette image est introuvable.');
    } else {
        admin_flash('success', 'Image supprimée de « ' . (string) $distro['name'] . ' ».');
        admin_log('Suppression d\'une image de « ' . (string) $distro['name'] . ' » (slug ' . $slug . ')');
    }

    admin_image_redirect($slug, $mode);
}

function admin_handle_edit(): void
{
    $slug = (string) ($_POST['slug'] ?? $_GET['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $distro = find_distro($slug);
    if ($distro === null) {
        admin_not_found('Aucune fiche ne correspond à « ' . $slug . ' ».');
    }

    $manual = manual_find($slug);
    $mode = (string) ($_POST['mode'] ?? $_GET['mode'] ?? '');
    $manualRequested = $mode === 'manual'
        || ($mode !== 'override' && $manual !== null && (string) $distro['source'] === 'manual');

    if ($manualRequested) {
        if ($manual === null) {
            admin_not_found('Aucun ajout manuel ne correspond à « ' . $slug . ' ».');
        }
        admin_handle_manual_edit($slug);
        return;
    }

    admin_handle_override_edit($distro);
}

function admin_handle_reset(): void
{
    admin_csrf_check();

    $slug = (string) ($_POST['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $distro = find_distro($slug);
    if ($distro === null) {
        admin_not_found('Aucune fiche ne correspond à « ' . $slug . ' ».');
    }

    if (!override_delete_for_distro($distro)) {
        admin_flash('warning', 'Aucune modification à réinitialiser pour cette fiche.');
        admin_redirect('/admin/distros');
    }

    [$ok, $output] = manual_rebuild();
    admin_flash('success', 'Les données d\'origine de « ' . override_summary($distro) . ' » ont été restaurées.');
    admin_flash_rebuild($ok, $output);
    admin_log('Réinitialisation de la fiche « ' . override_summary($distro) . ' » (slug ' . $slug . ')');
    admin_redirect('/admin/distros');
}

function admin_handle_delete(): void
{
    admin_csrf_check();

    $slug = (string) ($_POST['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $distro = find_distro($slug);
    if ($distro === null) {
        admin_not_found('Aucune fiche ne correspond à « ' . $slug . ' ».');
    }

    $name = (string) ($distro['name'] ?? $slug);
    $manual = manual_find($slug);
    $isPureManual = (string) ($distro['source'] ?? '') === 'manual' && $manual !== null;

    if ($isPureManual) {
        if (!manual_delete($slug)) {
            admin_flash('error', 'Impossible de supprimer cet ajout manuel.');
            admin_redirect('/admin/distros');
        }
        $message = '« ' . $name . ' » a été supprimée définitivement.';
        images_purge($slug);
        admin_log('Suppression de l\'ajout manuel « ' . $name . ' » (slug ' . $slug . ')');
    } else {
        if (deletion_find_for_distro($distro) !== null) {
            admin_flash('warning', '« ' . $name . ' » est déjà dans la corbeille.');
            admin_redirect('/admin/distros');
        }

        $nameNorm = (string) ($distro['name_norm'] ?? '');
        if (!deletion_save($slug, $nameNorm, $name)) {
            admin_flash('error', 'Impossible d\'enregistrer la suppression.');
            admin_redirect('/admin/distros');
        }
        $message = '« ' . $name . ' » a été retirée du catalogue. Elle peut être restaurée depuis la corbeille.';
        admin_log('Suppression de la fiche « ' . $name . ' » (slug ' . $slug . ')');
    }

    [$ok, $output] = manual_rebuild();
    admin_flash('success', $message);
    admin_flash_rebuild($ok, $output);
    admin_redirect('/admin/distros');
}

function admin_handle_restore(): void
{
    admin_csrf_check();

    $slug = (string) ($_POST['slug'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) {
        admin_not_found('Fiche introuvable.');
    }

    $record = deletion_find_by_slug($slug);
    if ($record === null) {
        admin_flash('warning', 'Cette fiche n\'est pas dans la corbeille.');
        admin_redirect('/admin/deletions');
    }

    if (!deletion_delete_by_slug($slug)) {
        admin_flash('error', 'Impossible de restaurer cette fiche.');
        admin_redirect('/admin/deletions');
    }

    [$ok, $output] = manual_rebuild();
    admin_flash('success', '« ' . deletion_label($record) . ' » a été restaurée.');
    admin_flash_rebuild($ok, $output);
    admin_log('Restauration de la fiche « ' . deletion_label($record) . ' » (slug ' . $slug . ')');
    admin_redirect('/admin/deletions');
}

function admin_handle_deletions(): void
{
    $entries = deletions_entries();
    usort($entries, static function (array $a, array $b): int {
        return strcasecmp(deletion_label($a), deletion_label($b));
    });

    admin_render('deletions', [
        'pageTitle' => 'Corbeille',
        'entries'   => $entries,
    ]);
}

function admin_handle(string $route): void
{
    admin_send_headers();
    admin_session_start();

    if ($route === '/admin/login') {
        admin_handle_login();
        return;
    }

    if ($route === '/admin/logout') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Méthode non autorisée.');
        }
        admin_csrf_check();
        admin_logout();
        admin_redirect('/admin/login');
    }

    if ($route === '/admin' || $route === '/admin/') {
        admin_redirect(admin_is_authenticated() ? '/admin/distros' : '/admin/login');
    }

    admin_require_auth();

    switch ($route) {
        case '/admin/distros':
            admin_handle_list();
            return;
        case '/admin/distros/new':
            admin_handle_new();
            return;
        case '/admin/distros/edit':
            admin_handle_edit();
            return;
        case '/admin/distros/images':
            admin_handle_images_upload();
            return;
        case '/admin/distros/images/delete':
            admin_handle_image_delete();
            return;
        case '/admin/distros/reset':
            admin_handle_reset();
            return;
        case '/admin/distros/delete':
            admin_handle_delete();
            return;
        case '/admin/distros/restore':
            admin_handle_restore();
            return;
        case '/admin/deletions':
            admin_handle_deletions();
            return;
    }

    admin_not_found('Cette page d\'administration n\'existe pas.');
}
