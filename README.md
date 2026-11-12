# Distrothèque

Catalogue de distributions Linux, BSD et Unix : recherche par nom, filtres par
architecture et par source, fiches détaillées et liens ISO directs.

Le projet fonctionne sous **Linux** et se compose de deux parties :

- **Python** : scraping des sources, fusion des données, construction de la base SQLite ;
- **PHP** : lecture seule de la base et affichage du site.

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

`distros.json` est la **source de vérité** : c'est lui qu'on sauvegarde et qu'on
versionne. La base SQLite est un fichier généré, entièrement reconstruit à
chaque mise à jour.

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

`start.py` vérifie PHP, construit la base si elle est absente, lance le serveur
intégré de PHP et ouvre le navigateur sur <http://127.0.0.1:8080>.

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

`scrap/scrape_distros.py` interroge deux sites et produit `distros.json` :

- **isoslinux.com** : catalogue principal (nom, version, architectures, rang de
  popularité, description, liens de téléchargement, prérequis) ;
- **archiveos.org** : toutes les pages du catalogue, filtrées sur les catégories
  **Linux et BSD**, plus les fiches du tag **`unix`** (souvent classées
  « Others »). Les articles et actualités sont écartés. Les fiches sans ISO sont
  écartées, y compris les fiches Unix.

Les deux sources sont fusionnées par **nom normalisé** (accents, casse,
mots vides et numéros de version ignorés). En cas de doublon, les données
isoslinux sont conservées et archiveos complète les champs manquants. Le champ
`source` vaut alors `both` ; sinon `isoslinux` ou `archiveos`.

Les distributions Unix reçoivent le mot-clé `unix` dans la base : taper
« Unix » dans la recherche les fait apparaître, même quand leur nom ne contient
pas le mot.

Lancer un scraping complet :

```bash
python3 update.py
```

ou seulement le scraper :

```bash
python3 scrap/scrape_distros.py -o distros.json
```

Options utiles du scraper :

| Option | Effet |
|---|---|
| `--workers 8` | téléchargements simultanés |
| `--limit N` | limiter le nombre de fiches archiveos |
| `--max-pages N` | limiter les pages archiveos |
| `--no-details` | ne pas ouvrir les fiches (plus rapide, moins complet) |
| `--skip-archiveos` | ignorer archiveos.org |
| `--skip-isoslinux` | ignorer isoslinux.com |
| `--include-articles` | inclure les articles archiveos |

## La base de données

`builder/build_db.py` transforme `distros.json` en base SQLite :

- normalisation des noms, slugs uniques et architectures (`amd64` → `x86_64`, etc.) ;
- normalisation des dates et calcul de la popularité ;
- dédoublonnage des liens ISO ;
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
| `--no-lock` | ignorer le verrou |
| `--no-log` | ne pas écrire de fichier de log |

Logs : `logs/update-AAAAAMMJJ-HHMMSS.log`.

## Mise à jour automatique du site

Aucun cron n'est nécessaire. Le site se met à jour **à la première visite**
après 3 heures :

1. PHP compare la date du fichier `data/distrotheque.db` à l'intervalle configuré ;
2. si la base est trop vieille, il lance `python3 update.py` en arrière-plan,
   détaché, puis répond immédiatement avec les données existantes ;
3. pendant le scraping, le site continue de fonctionner et le pied de page
   affiche « mise à jour en cours » ;
4. à la fin, la base est remplacée d'un coup.

Garde-fous :

| Mécanisme | Effet |
|---|---|
| `data/.update.lock` | un seul `update.py` à la fois |
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
0 */3 * * * cd /var/www/distrotheque && /usr/bin/python3 update.py >> logs/cron.log 2>&1
```

Ou avec les fichiers fournis :

```bash
sudo cp deploy/systemd/distrotheque-update.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now distrotheque-update.timer
```

## Production (nginx + PHP-FPM)

Le document root doit pointer sur `web/public`, **jamais** sur la racine du
projet, sinon `data/distrotheque.db` serait téléchargeable.

```bash
sudo mkdir -p /var/www/distrotheque
# copier le projet dans /var/www/distrotheque
cd /var/www/distrotheque
mkdir -p data logs
python3 update.py --skip-scrape
sudo chown -R root:www-data /var/www/distrotheque
sudo chmod -R g+rwX /var/www/distrotheque
```

`www-data` doit pouvoir écrire dans `data/` et `logs/` pour que la mise à jour
automatique fonctionne, et `python3` doit être accessible avec `beautifulsoup4`.

Bloc nginx :

```nginx
server {
    listen 80;
    server_name distro.example.org;

    root /var/www/distrotheque/web/public;
    index index.php;

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location /assets/ {
        add_header Cache-Control "public, max-age=2592000, immutable";
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

## Configuration

`web/config.php` :

| Clé | Rôle | Défaut |
|---|---|---|
| `db_path` | chemin de la base SQLite | `data/distrotheque.db` |
| `per_page` | résultats par page | `24` |
| `auto_update` | activer la mise à jour à la visite | `true` |
| `update_interval` | âge maximal de la base | 3 h |
| `update_retry_delay` | délai entre deux tentatives | 10 min |
| `python_bin` | binaire Python | détection auto |

Variables d'environnement :

| Variable | Rôle |
|---|---|
| `DISTROTHEQUE_DB` | chemin de la base |
| `DISTROTHEQUE_AUTOUPDATE` | `0` pour désactiver la mise à jour automatique |
| `DISTROTHEQUE_PYTHON` | binaire Python |
| `DISTROTHEQUE_UPDATE_ARGS` | arguments ajoutés à `update.py` |
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
├── distros.json               # données brutes
├── data/
│   ├── distrotheque.db        # base générée
│   └── backups/               # sauvegardes de distros.json
├── logs/                      # journaux de mise à jour
├── web/
│   ├── config.php
│   ├── src/
│   └── public/                # seul dossier exposé
└── deploy/
    ├── nginx.conf
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
dans PHP-FPM.

**`database is locked`** : un seul `update.py` à la fois ; le verrou expire au
bout de 6 h en cas de plantage.
