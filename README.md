# Distrothèque

Catalogue de distributions Linux, BSD et Unix : recherche par nom, filtres par
architecture et par source, fiches détaillées et liens ISO directs.

Le projet fonctionne sous **Linux** et se compose de deux parties :

- **Python** : scraping des sources, fusion des données, construction de la base SQLite ;
- **PHP** : lecture de la base, affichage du site et administration des ajouts manuels.

## Comment ça marche

```
archiveos.org ─┐
               ├─> scrap/scrape_distros.py ─> distros.json
isoslinux.com ─┘                                    │
                                                    v
                                     builder/build_db.py
                                                    │
                                                    v
                                     data/distrotheque.db
                                                    │
                                                    v
                                        web/ (PHP + SQLite)
```

`distros.json` est le fichier intermédiaire entre le scraping et la base : il
est sauvegardé avant chaque mise à jour dans `data/backups/`, mais il n'est pas
versionné et peut être reconstruit à tout moment. La base SQLite est un fichier
généré, entièrement reconstruit à chaque mise à jour.

## Prérequis

| Outil | Version | Usage |
|---|---|---|
| Python | 3.9+ | scraping et construction de la base |
| PHP | 8.1+ avec `pdo_sqlite` | site |
| BeautifulSoup | 4.x | scraping uniquement |

Debian / Ubuntu :

```bash
sudo apt install python3 python3-bs4 php-cli php-sqlite3
```

La construction de la base depuis `distros.json` et le site n'utilisent que la
bibliothèque standard.

## Démarrage rapide

```bash
./start.sh
```

`start.py` vérifie PHP, construit la base si elle est absente ou plus ancienne
que `distros.json`, lance le serveur intégré de PHP et ouvre le navigateur sur
<http://127.0.0.1:8080>.

| Option | Effet |
|---|---|
| `--port 9000` | changer de port (bascule automatique si occupé) |
| `--host 0.0.0.0` | rendre le site accessible sur le réseau local |
| `--no-browser` | ne pas ouvrir le navigateur |
| `--rebuild` | reconstruire la base avant de démarrer |
| `--no-build` | démarrer sans construire la base |

Variables équivalentes : `DISTROTHEQUE_HOST`, `DISTROTHEQUE_PORT`,
`DISTROTHEQUE_PHP`.

Équivalent manuel :

```bash
php -S 127.0.0.1:8080 -t web/public web/public/router.php
```

## Le scraping

`scrap/scrape_distros.py` construit `distros.json` à partir de deux sites :

| Source | Rôle |
|---|---|
| **isoslinux.com** | catalogue principal : nom, version, architectures, rang de popularité, description, ISO, prérequis |
| **archiveos.org** | catalogue complémentaire : Linux, BSD et Unix, métadonnées et liens de téléchargement |

Le scraper n'utilise que `urllib` et BeautifulSoup. Les requêtes du catalogue
envoient un User-Agent de navigateur et réessaient jusqu'à 4 fois avec une
attente croissante (2 s, 4 s, 6 s) ; un 404 est considéré comme définitif. Les
résolutions de liens se contentent de 2 tentatives.

### Déroulé complet

1. **isoslinux.com** : la page d'accueil est lue en une seule requête. Chaque
   ligne `<tr data-name>` du tableau devient une distribution : nom
   (`span.catalog-title`), architectures, release et date (attributs `data-*`),
   rang et version (`td.rank`, `td.version`), lien de fiche et description
   (`span.catalog-description`). Les fiches sont ensuite ouvertes en parallèle
   pour lire les variantes de téléchargement et les prérequis.
2. **archiveos.org** : le catalogue est paginé (`/page/N/`). La dernière page est
   détectée dans le HTML de la première, puis toutes les pages sont lues en
   parallèle (8 par défaut) et remises dans l'ordre. Chaque `<article>` fournit
   le nom, l'URL de la fiche, la date de publication, les catégories et l'image.
   Les fiches sont dédoublonnées par URL.
3. **Filtre des catégories** : seules les fiches **Linux** et **BSD** sont
   gardées, plus celles du tag **`unix`** (voir ci-dessous). Les articles et
   actualités (`News`, `Articles`) sont écartés, sauf avec
   `--include-articles`.
4. **Fiches archiveos** : chaque fiche est ouverte. Le scraper lit les paires
   « Libellé : valeur » (`Web site`, `Origin`, `Category`, `Architecture`,
   `Based on`, `The last version | Released`, etc.), la date de dernière mise à
   jour (`.last-updt-div`), la description (paragraphes hors métadonnées) et le
   script de téléchargement
   (`script[src*="/get/pak/download-"]`).
5. **Liens de téléchargement** : le script JavaScript archiveos est lu pour
   extraire l'URL de la page intermédiaire (`open('/get/…html')` ou, à défaut,
   `/get/download-….html`). Cette page est ouverte, ses liens externes sont
   collectés, puis transformés en liens directs (voir la table ci-dessous).
6. **Fusion** : les deux catalogues sont fusionnés par nom normalisé.
7. **Nettoyage final** : toute fiche sans aucun lien de téléchargement est
   retirée, puis `distros.json` est écrit.

Pendant l'exécution, la progression est écrite sur la sortie d'erreur (pages,
fiches, liens). Un passage complet dure plusieurs minutes et résout près de
2 600 liens.

### Le tag `unix` d'archiveos

archiveos ne classe pas Unix dans une catégorie dédiée : ces distributions sont
souvent dans **Others** (parfois `DOS` ou `Apple`). Le scraper lit donc toutes
les pages de `https://archiveos.org/tag/unix/`, collecte les URLs des fiches et
les ajoute au catalogue. Une fiche reçoit `unix: true` si elle figure dans ce
tag **et** n'appartient pas aux catégories Linux ou BSD, pour ne pas mélanger
les familles (DYNIX et 4BSD restent des BSD). Le mot-clé est propagé jusqu'à la
base SQLite : la recherche « Unix » les trouve même quand leur nom ne contient
pas le mot.

Les Unix sans aucun lien de téléchargement sont finalement écartés comme les
autres : le site n'affiche que des fiches avec au moins une ISO.

### Résolution des liens de téléchargement

archiveos ne publie presque jamais d'ISO directement : la fiche pointe vers un
script `/get/pak/download-….js` qui ouvre une page intermédiaire, laquelle
contient un ou plusieurs liens externes. Le scraper suit cette chaîne, résout en
parallèle tous les liens externes uniques, puis applique un résolveur selon
l'hébergeur :

| Hôte | Traitement |
|---|---|
| `archive.org` | API `archive.org/metadata/<item>` : tous les fichiers d'image de l'item |
| `sourceforge.net` | ajout de `/download` aux fichiers |
| `github.com` | API des releases : assets de la première release contenant une image |
| `gitlab.com` | `/-/blob/` remplacé par `/-/raw/` |
| `*.freebsd.org` | suivi des dossiers `ISO-IMAGES/` (les 3 premiers) et lecture de leur listing |
| autre | lecture de la page et collecte des liens dont le chemin est une image (40 max) |

Sont considérés comme images : `.iso`, `.img`, `.ova`, `.raw`, `.dmg`, `.vmdk`,
`.vdi`, `.qcow2` et `.bin`, y compris compressés en `.gz`, `.xz`, `.bz2` ou
`.zip`. Seuls les liens dont le chemin se termine par une de ces extensions sont
conservés : les pages HTML, torrents et portails sans fichier sont écartés. Si
aucun lien direct n'est trouvé, la fiche est supprimée du résultat (hors mode
`--no-details`).

Pour chaque fiche, un lien « principal » est choisi : `.iso` d'abord, puis les
autres images brutes, puis les archives compressées, puis le reste. La liste
`downloads` est dédoublonnée par URL.

Seuls les schémas `http` et `https` sont acceptés pour les liens de
téléchargement et les sites externes, au scraping, à la construction de la base
et à l'affichage. Les liens `magnet`, `javascript`, `data` ou relatifs sont
écartés.

### Fusion des deux sources

isoslinux est la base : ses fiches sont ajoutées d'abord et indexées sous deux
clés calculées à partir du nom, une clé par mots et une clé concaténée. La
normalisation supprime les accents, la casse, les parenthèses, les numéros de
version et les mots vides (`linux`, `os`, `desktop`, `edition`, etc.). Les
doublons internes à isoslinux sont ignorés.

Chaque fiche archiveos est ensuite comparée à cet index. En cas de
correspondance, elle ne crée pas de doublon : elle complète la fiche existante
(architectures, version, date, description, prérequis, ISO manquantes) et passe
`source` à `both`. Sinon elle est ajoutée telle quelle (`source: archiveos`).

Cas particulier : une fiche archiveos taguée `unix` n'est fusionnée que si son
nom complet normalisé est identique. Cela évite qu'un Unix comme **MiNT** soit
absorbé par **Linux Mint** : le mot `linux` étant un mot vide, les deux noms se
réduisaient à `mint`.

### Format produit

`distros.json` est un tableau d'objets :

| Champ | Contenu |
|---|---|
| `name`, `version`, `release`, `updated`, `rank` | identité et dates |
| `architectures` | liste textuelle (`x86_64, arm64`) |
| `href`, `description` | fiche source et résumé |
| `downloads` | variantes : `label`, `group`, `version`, `architecture`, `desktop`, `purpose`, `status`, `health`, `url` |
| `requirements` | architectures, mémoire, stockage, firmware, note, site officiel |
| `source` | `isoslinux`, `archiveos` ou `both` |
| `unix` | `true` pour les fiches Unix |
| `archiveos` | métadonnées brutes archiveos (origine, catégorie, basé sur, etc.) |

Le fichier est généré : il n'est pas versionné, il est sauvegardé avant chaque
mise à jour dans `data/backups/`, et il peut être reconstruit à tout moment.

### Lancer le scraper

Scraping complet (scraping + base) :

```bash
python3 update.py
```

ou seulement le scraper :

```bash
python3 scrap/scrape_distros.py -o distros.json
```

Options du scraper :

| Option | Effet |
|---|---|
| `-o, --output` | fichier JSON de sortie (`distros.json` par défaut) |
| `--workers 8` | téléchargements simultanés |
| `--isos-url` | URL du catalogue isoslinux |
| `--start-page N` | première page archiveos |
| `--max-pages N` | limiter le nombre de pages archiveos (0 = tout) |
| `--limit N` | limiter le nombre de fiches archiveos |
| `--no-details` | ne pas ouvrir les fiches : test rapide de la pagination, sans métadonnées ni ISO |
| `--include-articles` | inclure les articles et actualités archiveos |
| `--all-categories` | inclure les catégories hors Linux/BSD/Unix (désactive aussi le tag `unix`) |
| `--skip-archiveos` | ignorer archiveos.org |
| `--skip-isoslinux` | ignorer isoslinux.com |

En cas d'échec sur une fiche archiveos, le scraper continue et stocke l'erreur
dans le champ `error`. Côté isoslinux, l'échec est noté dans `scrape_error` et la
fiche, sans ISO, est écartée. Si le HTML d'un site change, seuls les sélecteurs
de `scrap/scrape_distros.py` sont à ajuster.

## La base de données

`builder/build_db.py` transforme `distros.json` en base SQLite :

- normalisation des noms, slugs uniques et architectures (`amd64` → `x86_64`, etc.) ;
- normalisation des dates et calcul de la popularité ;
- dédoublonnage des variantes de téléchargement strictement identiques ;
- écriture dans un fichier temporaire `.tmp`, puis **remplacement atomique** de
  la base : le site ne voit jamais une base à moitié écrite.

Tables : `distros`, `downloads`, `distro_architectures`, `meta`.

Reconstruire la base sans scraper :

```bash
python3 update.py --skip-scrape
```

Ou appeler directement le constructeur :

```bash
python3 builder/build_db.py --json distros.json --db data/distrotheque.db
```

Le constructeur accepte aussi `--manual data/manual_distros.json` pour fusionner
les ajouts manuels, `--overrides data/distro_overrides.json` pour appliquer les
modifications de fiches et `--deletions data/distro_deletions.json` pour retirer
les fiches supprimées (c'est ce que fait `update.py`).

## Administration : ajouter et modifier des fiches

En plus du scraping, une page d'administration permet d'ajouter, modifier et
supprimer des distributions. Les fiches ajoutées à la main sont stockées dans
`data/manual_distros.json`, **séparément** de `distros.json` : elles survivent
donc aux reconstructions et aux nouveaux scrapings.

À chaque enregistrement, la base est reconstruite automatiquement avec
`update.py --skip-scrape --no-log`. Les fiches manuelles sont fusionnées avec
les fiches scrapées :

- si le nom normalisé existe déjà, la fiche manuelle **complète** la fiche
  existante (champs vides, prérequis, liens de téléchargement) au lieu de créer
  un doublon ;
- sinon elle est ajoutée avec `source: manual` et apparaît dans le filtre
  « Ajout manuel ».

Les lignes de téléchargement sont conservées individuellement : plusieurs
variantes peuvent partager la même URL si leur libellé, version, architecture
ou type diffère. Seules les lignes strictement identiques sont ignorées.

### Modifier une fiche scrapée

La liste de l'administration affiche **toutes les fiches** du catalogue, avec
recherche et pagination. Les fiches issues du scraping peuvent être modifiées
comme les autres :

- les changements sont stockés dans `data/distro_overrides.json` ;
- ils sont réappliqués après chaque fusion et chaque reconstruction, sans
  toucher à `distros.json` ;
- le slug et la source d'origine sont conservés, donc les liens publics ne
  changent pas ;
- le bouton « Réinitialiser » supprime la surcharge et restaure les données
  scrapées d'origine ;
- le formulaire remplace entièrement les liens de téléchargement affichés :
  penser à conserver les lignes que l'on souhaite garder.

Une fiche modifiée porte le badge « Modifié » dans la liste.

### Supprimer une fiche

Toutes les fiches peuvent être supprimées depuis la liste, y compris celles
issues du scraping :

- un **ajout manuel** est retiré de `data/manual_distros.json` : la suppression
  est définitive ;
- une **fiche scrapée** est enregistrée dans `data/distro_deletions.json` ; elle
  disparaît du catalogue à chaque reconstruction sans que `distros.json` soit
  modifié ;
- les fiches scrapées supprimées vont dans la **corbeille** (`/admin/deletions`)
  et peuvent y être restaurées à tout moment ;
- recréer un ajout manuel avec le même nom retire automatiquement l'ancienne
  suppression de la corbeille.

### Créer le compte administrateur

Le compte est unique et stocké dans `data/admin.json` sous forme de hash
(`password_hash`), avec des permissions `0600`. Il n'y a **pas** d'inscription
depuis le navigateur : le mot de passe se définit en ligne de commande, avec
l'utilisateur qui fait tourner PHP.

En local :

```bash
php web/cli/admin.php set-password --username admin
```

En production, avec l'utilisateur de PHP-FPM :

```bash
sudo -u www-data php web/cli/admin.php set-password --username admin
```

Le mot de passe est demandé deux fois, de façon masquée. Il doit contenir au
moins 10 caractères. Autres commandes :

| Commande | Effet |
|---|---|
| `php web/cli/admin.php status` | afficher le compte, son état et les permissions du fichier |
| `php web/cli/admin.php disable` | désactiver la connexion (les sessions en cours sont invalidées) |
| `php web/cli/admin.php enable` | réactiver le compte |

Un mot de passe peut aussi être fourni sans interaction avec
`--password '…'` ou la variable `DISTROTHEQUE_ADMIN_PASSWORD` (à éviter dans
l'historique du shell et les variables d'environnement partagées).

### Utiliser la page d'administration

Ouvrir <http://127.0.0.1:8080/admin> (ou `/admin` sur le domaine) :

1. se connecter avec le compte créé ;
2. la liste affiche toutes les fiches : « Modifier » ouvre l'éditeur, avec un
   badge « Modifié » pour les fiches surchargées et « Ajout manuel » pour les
   ajouts ;
3. « Ajouter une distribution » : seul le nom et au moins un lien HTTP(S) sont
   obligatoires ; les autres champs sont optionnels ;
4. après enregistrement, la fiche est immédiatement visible dans le catalogue ;
5. « Réinitialiser » restaure une fiche scrapée modifiée ; « Supprimer » retire
   n'importe quelle fiche, et la corbeille permet de restaurer les fiches
   scrapées supprimées.

Quand tu es connecté, un bouton « Modifier » apparaît aussi sur chaque fiche
publique, à gauche de « Site officiel » : il ouvre directement la bonne fiche
dans l'administration (ajout manuel ou fiche scrapée).

Format minimal d'une entrée dans `data/manual_distros.json` :

```json
[
  {
    "name": "Ma distribution",
    "version": "1.0",
    "architectures": "x86_64",
    "description": "Description…",
    "source": "manual",
    "downloads": [
      {
        "label": "ISO",
        "version": "1.0",
        "architecture": "x86_64",
        "url": "https://exemple.org/ma-distribution-1.0.iso"
      }
    ],
    "requirements": {
      "official_url": "https://exemple.org/"
    },
    "archiveos": {
      "slug": "ma-distribution"
    }
  }
]
```

Le fichier peut être édité à la main, mais il faut alors relancer la
reconstruction : `python3 update.py --skip-scrape`.

### Images et carrousel

Chaque fiche peut recevoir des images depuis l'éditeur d'administration,
section « Images du carrousel ». Elles s'affichent à droite de la présentation
sur la fiche publique, sous forme de carrousel. **Sans image, la fiche reste
exactement comme avant.**

- tous les formats reconnus comme `image/*` sont acceptés (JPEG, PNG, WebP,
  AVIF, GIF, SVG, HEIC, TIFF…) ;
- **100 Mo maximum par fichier**, 30 images par fiche ;
- les fichiers sont stockés dans `data/uploads/<slug>/` et servis par
  `/media/<slug>/<fichier>` : ils ne sont jamais exécutés ;
- les métadonnées sont dans `data/distro_images.json` ;
- le type MIME est vérifié côté serveur, les noms de fichiers sont générés
  aléatoirement et les SVG sont servis avec une CSP `sandbox`.

Penser à inclure `data/uploads/` dans les sauvegardes : les images ne sont pas
dans `distros.json`. Pour un zoom net, fournir des images d'au moins 1200 px de
large ; le bouton « Plein écran » de la visionneuse agrandit l'image au-delà de
sa taille réelle, avec la perte de netteté correspondante.

Les limites d'envoi sont dans `web/public/.user.ini` (100 Mo par fichier,
512 Mo de requête). En production, augmenter aussi `client_max_body_size` dans
nginx (512 Mo dans `deploy/nginx.conf`). Le serveur PHP intégré ignore
`.user.ini` : `start.py` / `start.sh` passent donc ces limites avec `-d`
(`upload_max_filesize=100M`, `post_max_size=512M`).

### Sécurité de l'administration

- un seul compte, mot de passe haché avec `password_hash` (argon2id ou bcrypt
  selon PHP) ;
- sessions stockées dans `data/sessions`, cookie `HttpOnly`, `SameSite=Strict`,
  `Secure` automatiquement en HTTPS ; expiration après 2 h d'inactivité et 12 h
  au total ;
- empreinte du User-Agent et régénération de l'identifiant de session à la
  connexion ;
- jeton CSRF sur tous les formulaires, vérification de la méthode HTTP ;
- limitation des tentatives : 5 échecs par IP sur 15 minutes, comptage
  atomique (verrou `flock`) pour qu'une rafale de requêtes parallèles ne puisse
  pas dépasser la limite ;
- en-têtes `no-store`, `noindex`, CSP stricte, `X-Frame-Options`,
  `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` et interdiction
  d'intégration en iframe ;
- écritures JSON atomiques (fichier temporaire unique puis `rename`) et
  reconstruction protégée par le verrou `data/.update.lock` ; si la
  reconstruction est déjà en cours, la fiche reste enregistrée et sera prise en
  compte au prochain passage.

En production, servir l'administration **en HTTPS** et ne jamais exposer le
dossier `data/`. Si TLS est terminé par un reverse proxy, transmettre
`fastcgi_param HTTPS on;` à PHP-FPM pour que le cookie de session soit marqué
`Secure`.

#### Points de vigilance

- Le limiteur de tentatives est **par IP** : une attaque distribuée (botnet,
  proxies) peut toujours tenter des mots de passe. Utiliser un mot de passe long
  et aléatoire, et restreindre `/admin` à un VPN ou à une plage d'IP si possible.
- Un attaquant connaissant l'IP de l'administrateur peut le bloquer 15 minutes
  en provoquant 5 échecs (déni de service limité et temporaire).
- Les en-têtes de sécurité des pages d'erreur générées par nginx/Apache
  dépendent de la configuration du serveur web : reprendre les `add_header` de
  `deploy/nginx.conf` dans les blocs d'erreur si nécessaire.
- `data/` et `logs/` ne doivent jamais être sous la racine web ; le document
  root doit pointer sur `web/public`.
- Le serveur de développement `php -S` affiche `X-Powered-By` et ne sert pas
  les en-têtes statiques : il ne doit pas être exposé au réseau.
- Les sessions et journaux sont locaux au serveur : sauvegarder `data/` sans
  versionner `data/admin.json`, `data/sessions/` ni `logs/`.

## La mise à jour complète

`update.py` orchestre tout :

1. prend un verrou `data/.update.lock` (un seul processus à la fois) ;
2. sauvegarde `distros.json` dans `data/backups/` (les 8 dernières sont gardées) ;
3. lance le scraping ;
4. vérifie que le JSON est valide et non vide ;
5. refuse la mise à jour si le nombre de distributions chute de plus de 50 %
   (garde-fou contre un scraping cassé), sauf avec `--force` ;
6. reconstruit la base et écrit un résumé.

En cas d'échec du scraping, **l'ancienne base reste en ligne**.

| Option | Effet |
|---|---|
| `--skip-scrape` | reconstruire la base depuis le JSON existant |
| `--force` | accepter une grosse baisse du nombre de distributions |
| `--json`, `--db` | chemins personnalisés |
| `--manual` | fichier des ajouts manuels (défaut `data/manual_distros.json`) |
| `--overrides` | fichier des modifications de fiches (défaut `data/distro_overrides.json`) |
| `--deletions` | fichier des fiches supprimées (défaut `data/distro_deletions.json`) |
| `--no-lock` | ignorer le verrou |
| `--no-log` | ne pas écrire de fichier de log |

Logs : `logs/update-AAAAAMMJJ-HHMMSS.log`.

## Mise à jour automatique du site

Aucun cron n'est nécessaire. Le site se met à jour **à la première visite**
après 3 heures :

1. PHP compare la date de construction enregistrée dans la base
   (`meta.built_at`) à l'intervalle configuré. La date du fichier n'est pas
   utilisée : copier ou restaurer `data/distrotheque.db` ne doit pas faire
   croire que les données sont fraîches ;
2. si la base est trop vieille, il lance `python3 update.py` en arrière-plan,
   détaché, puis répond immédiatement avec les données existantes ;
3. pendant le scraping, le site continue de fonctionner et le pied de page
   affiche « mise à jour en cours » ;
4. à la fin, la base est remplacée d'un coup.

La mise à jour automatique ne démarre qu'à la première visite après le délai.
En local, il faut donc laisser le site tourner : le scraping prend plusieurs
minutes et la base n'est remplacée qu'à la fin. Pour actualiser tout de suite,
lancer `python3 update.py` à la main.

Garde-fous :

| Mécanisme | Effet |
|---|---|
| `data/.update.lock` | un seul `update.py` à la fois ; un verrou dont le PID n'existe plus est ignoré |
| `data/.auto-update.stamp` | au plus une tentative toutes les 10 minutes |
| `logs/auto-update.log` | journal de la mise à jour automatique (rotation à 5 Mo) |
| Python ou `popen` absent | la mise à jour est ignorée, le site reste en ligne |

Réglages dans `web/config.php` : `update_interval` (3 h), `update_retry_delay`
(10 min), `lock_max_age` (6 h), `python_bin`, `update_args`.

Pour couper la mise à jour automatique, définir la variable
`DISTROTHEQUE_AUTOUPDATE=0` dans l'environnement de PHP-FPM.

Pour la forcer immédiatement :

```bash
python3 update.py
```

### Option : mise à jour planifiée

Sur un serveur toujours allumé, un cron ou un timer systemd garantit une base
fraîche même sans visiteur. Le verrou évite les doublons avec la mise à jour
automatique.

```cron
0 */3 * * * cd /var/www/distrotheque && /usr/sbin/runuser -u www-data -- /usr/bin/python3 update.py >> logs/cron.log 2>&1
```

Le cron doit toujours exécuter `update.py` en `www-data`, jamais en `root` :
le dossier de travail est accessible en écriture à `www-data`, donc un cron root
serait un vecteur d'élévation locale de privilèges.

Ou avec les fichiers fournis :

```bash
sudo cp deploy/systemd/distrotheque-update.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now distrotheque-update.timer
```

Le service fourni tourne en `www-data`. Les options de sandboxing systemd
(`ProtectSystem`, `PrivateTmp`, `ReadWritePaths`…) ne sont volontairement pas
activées : dans un conteneur LXC, elles échouent avec
« Failed to set up mount namespacing » (`status 226/NAMESPACE`). Le service
reste limité par l'utilisateur `www-data` et le verrou anti-concurrence.

## Production (nginx + PHP-FPM)

Le document root doit pointer sur `web/public`, **jamais** sur la racine du
projet, sinon `data/distrotheque.db` serait téléchargeable.

```bash
sudo mkdir -p /var/www/distrotheque
# copier le projet dans /var/www/distrotheque
cd /var/www/distrotheque
sudo mkdir -p data logs
sudo chown -R root:root .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

sudo chown -R www-data:www-data data logs
sudo chmod 750 data logs
sudo touch distros.json
sudo chown www-data:www-data distros.json
sudo chmod 664 distros.json

sudo chown root:www-data web/config.php
sudo chmod 640 web/config.php

sudo -u www-data /usr/bin/python3 update.py
sudo -u www-data php web/cli/admin.php set-password --username admin
```

`data/admin.json` et `data/manual_distros.json` sont créés par ces commandes
dans `data/`, déjà accessible en écriture à `www-data`. Ne jamais rendre
`data/admin.json` lisible par le serveur web : il ne doit être accessible qu'à
PHP-FPM et à l'administrateur système.

Le code, les scripts et `web/` restent en `root:root` : `www-data` ne doit
jamais pouvoir les modifier, sinon une compromission du site devient une
persistance immédiate. Seuls `data/`, `logs/` et `distros.json` sont écrits par
la mise à jour, qui tourne en `www-data`. `python3` doit être accessible avec
`beautifulsoup4`.

Bloc nginx :

```nginx
server {
    listen 80;
    server_name distro.example.org;

    root /var/www/distrotheque/web/public;
    index index.php;

    server_tokens off;
    client_max_body_size 512m;
    client_body_timeout 300s;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;
    add_header Cross-Origin-Resource-Policy "same-origin" always;
    add_header X-Permitted-Cross-Domain-Policies "none" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    location = /router.php {
        return 404;
    }

    location /assets/ {
        add_header Cache-Control "public, max-age=2592000, immutable";
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Cross-Origin-Opener-Policy "same-origin" always;
        add_header Cross-Origin-Resource-Policy "same-origin" always;
        add_header X-Permitted-Cross-Domain-Policies "none" always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ /\. {
        deny all;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 300s;
    }
}
```

Puis :

```bash
sudo nginx -t
sudo systemctl enable --now php8.2-fpm nginx
sudo systemctl reload nginx
```

Le fichier `deploy/nginx.conf` contient un exemple prêt à adapter. Pour Apache,
c'est `web/public/.htaccess` qui gère la réécriture.

### HTTPS obligatoire

L'administration ne doit **jamais** être exposée en HTTP simple : le mot de passe
et le cookie de session circuleraient en clair. Ajoute un serveur TLS et redirige
tout le trafic HTTP :

```nginx
server {
    listen 80;
    server_name distro.example.org;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name distro.example.org;

    ssl_certificate     /etc/letsencrypt/live/distro.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/distro.example.org/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    # … reprendre ici le root, les locations et les autres en-têtes du bloc HTTP.
}
```

Si TLS est terminé par un reverse proxy, transmets `fastcgi_param HTTPS on;` à
PHP-FPM pour que le cookie de session soit marqué `Secure`. En complément, il est
possible de restreindre l'accès à `/admin` à une plage IP ou à un VPN au niveau
du pare-feu ou du reverse proxy.

### Durcissement PHP-FPM

`deploy/php-fpm-hardening.ini` désactive `cgi.fix_pathinfo`, `allow_url_fopen`,
les uploads et les fonctions shell inutiles, et limite `open_basedir` au projet.
`exec` et `popen` restent autorisés pour la mise à jour automatique.

```bash
sudo cp deploy/php-fpm-hardening.ini /etc/php/8.2/fpm/conf.d/99-distrotheque.ini
sudo php-fpm8.2 -t
sudo systemctl restart php8.2-fpm
```

Adapter `open_basedir` si le projet n'est pas dans `/var/www/distrotheque`.
Vérifier aussi que `disable_functions` ne bloque pas `exec` / `popen` si la mise
à jour automatique doit fonctionner.

## Configuration

`web/config.php` :

| Clé | Rôle | Défaut |
|---|---|---|
| `db_path` | chemin de la base SQLite | `data/distrotheque.db` |
| `timezone` | fuseau d'affichage des dates | `Europe/Paris` |
| `per_page` | résultats par page | `24` |
| `auto_update` | activer la mise à jour à la visite | `true` |
| `update_interval` | âge maximal de la base | 3 h |
| `update_retry_delay` | délai entre deux tentatives | 10 min |
| `python_bin` | binaire Python | détection auto |
| `admin_file` | compte administrateur (hash) | `data/admin.json` |
| `admin_idle_timeout` | expiration de session après inactivité | 2 h |
| `admin_absolute_timeout` | durée maximale d'une session | 12 h |
| `admin_max_attempts` | échecs de connexion autorisés par IP | 5 |
| `manual_json` | ajouts manuels | `data/manual_distros.json` |
| `overrides_json` | modifications des fiches scrapées | `data/distro_overrides.json` |
| `deletions_json` | fiches supprimées (corbeille) | `data/distro_deletions.json` |
| `images_json` | métadonnées des images du carrousel | `data/distro_images.json` |
| `uploads_dir` | fichiers des images | `data/uploads` |
| `image_max_bytes` | taille maximale par image | 100 Mo |
| `image_max_per_distro` | nombre maximal d'images par fiche | 30 |

Variables d'environnement :

| Variable | Rôle |
|---|---|
| `DISTROTHEQUE_DB` | chemin de la base |
| `DISTROTHEQUE_TZ` | fuseau d'affichage (défaut `Europe/Paris`) |
| `DISTROTHEQUE_AUTOUPDATE` | `0` pour désactiver la mise à jour automatique |
| `DISTROTHEQUE_PYTHON` | binaire Python |
| `DISTROTHEQUE_UPDATE_ARGS` | arguments ajoutés à `update.py` |
| `DISTROTHEQUE_ADMIN` | chemin du compte administrateur |
| `DISTROTHEQUE_MANUAL_JSON` | chemin des ajouts manuels |
| `DISTROTHEQUE_OVERRIDES_JSON` | chemin des modifications de fiches |
| `DISTROTHEQUE_DELETIONS_JSON` | chemin des fiches supprimées |
| `DISTROTHEQUE_IMAGES_JSON` | chemin des métadonnées d'images |
| `DISTROTHEQUE_UPLOADS_DIR` | dossier des fichiers images |
| `DISTROTHEQUE_ADMIN_PASSWORD` | mot de passe pour `web/cli/admin.php set-password` |
| `DISTROTHEQUE_HOST` / `DISTROTHEQUE_PORT` | écoute de `start.py` |
| `DISTROTHEQUE_PHP` | binaire PHP utilisé par `start.py` |

## Structure

```
.
├── scrap/
│   ├── scrape_distros.py      # scraping + fusion -> distros.json
│   ├── archiveos/
│   └── isoslinux/
├── builder/
│   ├── build_db.py            # distros.json -> SQLite
│   └── schema.sql
├── update.py                  # orchestration scraping + base
├── start.py / start.sh        # lancement local
├── distros.json               # JSON généré (non versionné)
├── data/
│   ├── distrotheque.db        # base générée
│   ├── manual_distros.json    # ajouts manuels (non versionné)
│   ├── distro_overrides.json  # modifications de fiches (non versionné)
│   ├── distro_deletions.json  # fiches supprimées / corbeille (non versionné)
│   ├── distro_images.json     # métadonnées des images (non versionné)
│   ├── uploads/               # images du carrousel (non versionné)
│   ├── admin.json             # compte admin (hash, 0600, non versionné)
│   ├── sessions/              # sessions PHP de l'administration
│   └── backups/               # sauvegardes de distros.json
├── logs/                      # journaux de mise à jour et d'administration
├── web/
│   ├── config.php
│   ├── cli/admin.php          # création du compte administrateur
│   ├── src/                   # dont src/views/admin/
│   └── public/                # seul dossier exposé
└── deploy/
    ├── nginx.conf
    ├── php-fpm-hardening.ini
    ├── cron.txt
    └── systemd/
```

## Dépannage

**PHP introuvable** : installer PHP 8.1+ avec `pdo_sqlite`, ou définir
`DISTROTHEQUE_PHP`.

**Base introuvable** : `python3 update.py --skip-scrape` ou `./start.sh --rebuild`.

**Le scraping échoue** : consulter `logs/` ; l'ancienne base reste servie. Si
archiveos.org ou isoslinux.com change de structure HTML, seul
`scrap/scrape_distros.py` est à ajuster.

**La mise à jour automatique ne part pas** : vérifier que `www-data` peut
écrire dans `data/` et `logs/`, que `python3` est dans le `PATH`, que
`beautifulsoup4` est installé et que `exec` / `popen` ne sont pas désactivés
dans PHP-FPM. Vérifier aussi le pied de page : il affiche la date de
construction réelle de la base. Si `data/.update.lock` traîne après un arrêt
brutal, il est ignoré dès que son PID n'est plus vivant (ou au bout de 6 h).

**`database is locked`** : un seul `update.py` à la fois ; le verrou expire au
bout de 6 h en cas de plantage.
