# Distrothèque

Catalogue de distributions Linux : recherche par nom, filtres par architecture
et par source, fiches détaillées, et liens ISO directs vers les serveurs
officiels.

Le projet est prévu pour **Linux**. Le lancement passe par `start.py`, qui
démarre PHP et construit la base si nécessaire.

- **Scraping / base** : Python (`scrap/`, `builder/`, `update.py`)
- **Site** : PHP 8.1+ en lecture seule sur SQLite (`web/`)
- **Mise à jour** : automatique et paresseuse — la première visite après 3 h
  lance le scraping en arrière-plan (aucun cron obligatoire)

```
.
├── scrap/                        # scrapers existants (archiveos + isoslinux)
│   ├── scrape_distros.py         #   produit distros.json (fusion des 2 sources)
│   ├── archiveos/
│   └── isoslinux/
├── builder/
│   ├── build_db.py               # distros.json -> data/distrotheque.db (atomique)
│   └── schema.sql
├── update.py                     # orchestre scraping + construction de la base
├── start.py                      # lanceur : serveur PHP + base + navigateur
├── start.sh                      # raccourci : exécute start.py
├── distros.json                  # données brutes (source de vérité, versionnable)
├── data/
│   ├── distrotheque.db           # base lue par PHP (générée, non versionnée)
│   └── backups/                  # sauvegardes automatiques de distros.json
├── web/
│   ├── config.php                # chemin de la base, pagination
│   ├── src/                      # db.php, repo.php, updater.php, helpers.php,
│   │                             # router.php, views/
│   └── public/                   # seul dossier exposé par le serveur web
│       ├── index.php             # point d'entrée unique
│       ├── .htaccess             # réécriture Apache
│       └── assets/
├── deploy/
│   ├── cron.txt
│   ├── systemd/                  # service + timer toutes les 3 h
│   └── nginx.conf
└── requirements.txt
```

## Prérequis

| Outil | Version | Rôle |
|---|---|---|
| Python | 3.9+ | scraping, construction de la base, lanceur |
| PHP | 8.1+ avec `pdo_sqlite` | site |
| SQLite | fourni avec PHP | base de données |

`beautifulsoup4` n'est nécessaire que pour le scraping :

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

La construction de la base depuis `distros.json` et le site n'utilisent que la
bibliothèque standard de Python et PHP.

## Démarrage rapide

```bash
./start.sh
# ou, sans le raccourci :
python3 start.py
```

`start.py` vérifie PHP, construit `data/distrotheque.db` s'il est absent,
démarre le serveur intégré de PHP et ouvre le navigateur.

Options :

| Option | Effet |
|---|---|
| `--port 9000` | utiliser un autre port (bascule automatiquement si occupé) |
| `--host 0.0.0.0` | exposer le site sur le réseau local |
| `--no-browser` | ne pas ouvrir le navigateur |
| `--rebuild` | reconstruire la base depuis `distros.json` avant de démarrer |
| `--no-build` | démarrer même si la base est absente |

Variables d'environnement équivalentes : `DISTROTHEQUE_HOST`,
`DISTROTHEQUE_PORT`, `DISTROTHEQUE_PHP`.

### Lancer le site à la main

```bash
php -S 127.0.0.1:8080 -t web/public web/public/router.php
```

Puis <http://127.0.0.1:8080>. `Ctrl+C` pour arrêter.

## Installation de PHP

### Debian / Ubuntu

```bash
sudo apt install php-cli php-sqlite3
```

### Fedora

```bash
sudo dnf install php-cli php-pdo
```

### Arch Linux

```bash
sudo pacman -S php php-sqlite
```

Vérifie ensuite que l'extension est active :

```bash
php -m | grep -i sqlite
```

## Construction de la base sans scraper

Utile pour démarrer tout de suite avec le `distros.json` déjà présent :

```bash
python3 update.py --skip-scrape
```

Sortie attendue :

```
OK : 1017 distributions (avant : —), base 5,6 Mo.
```

## Lancer un scraping complet

```bash
python3 update.py
```

Étapes : sauvegarde de `distros.json` → scraping → contrôle de vraisemblance
(refus si le nombre de distributions chute de plus de 50 %) → reconstruction de
la base. En cas d'échec du scraping, **l'ancienne base reste en ligne**.

Options utiles :

| Option | Effet |
|---|---|
| `--skip-scrape` | reconstruire la base depuis `distros.json` |
| `--force` | accepter une baisse massive du nombre de distributions |
| `--json`, `--db` | chemins personnalisés |
| `--no-log` | pas de fichier dans `logs/` |

Les logs vont dans `logs/update-AAAAAMMJJ-HHMMSS.log`.

## Interface

L'interface suit un parti pris de **registre imprimé** : fond papier, encre
noire, filets d'un pixel, aucun arrondi, aucune ombre, aucun dégradé. Un seul
accent, le vermillon (`#b23a1e`), réservé au carré du logotype, aux survols, au
focus et au bouton `+` des informations repliées.

- Typographie embarquée : **IBM Plex Sans** (fichier variable, 45 Ko) et
  **IBM Plex Mono** (400/500), servis depuis `web/public/assets/fonts/`.
  Aucun appel à Google Fonts : la page ne dépend d'aucun tiers.
- La liste est un vrai `<table>` d'index : numéro de ligne, nom, nombre d'ISO,
  date de mise à jour. Elle est triée par **popularité** par défaut ; les
  distributions sans classement connues passent après. En dessous de 800 px,
  chaque ligne devient un bloc et le tableau se lit comme une liste.
- La recherche porte sur le **nom** des distributions, pas sur leur
  description : chercher « Ubuntu » ne renvoie pas les fiches qui le
  mentionnent seulement dans leur texte.
- La fiche est une fiche technique : en-tête, caractéristiques en deux
  colonnes, fichiers ISO (nom de fichier réel + bouton *Télécharger* ou
  *Ouvrir* selon l'URL), présentation, informations complémentaires repliées.
- Sans JavaScript, les filtres restent utilisables : le bouton **Appliquer**
  apparaît et le formulaire se soumet normalement.

Les fichiers de police doivent être servis en `font/woff2`. C'est le cas par
défaut d'Apache et de nginx ; si un serveur renvoie
`application/octet-stream`, ajouter :

```nginx
types { font/woff2 woff2; }
```

## Production (Apache)

Le document root doit être `web/public`, jamais la racine du projet.
`web/public/.htaccess` gère déjà la réécriture vers `index.php`.

```apache
<VirtualHost *:80>
    ServerName distro.example.org
    DocumentRoot /var/www/distrotheque/web/public

    <Directory /var/www/distrotheque/web/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Production (nginx)

Voir `deploy/nginx.conf`.

## Variables d'environnement

| Variable | Rôle | Défaut |
|---|---|---|
| `DISTROTHEQUE_DB` | chemin de la base SQLite | `data/distrotheque.db` |
| `DISTROTHEQUE_AUTOUPDATE` | `0` pour couper la mise à jour à la visite | `1` |
| `DISTROTHEQUE_PYTHON` | binaire Python à appeler | détection auto (`python3`, puis `python`) |
| `DISTROTHEQUE_UPDATE_ARGS` | arguments ajoutés à `update.py` | vide |
| `DISTROTHEQUE_HOST` | interface d'écoute de `start.py` | `127.0.0.1` |
| `DISTROTHEQUE_PORT` | port d'écoute de `start.py` | `8080` |
| `DISTROTHEQUE_PHP` | binaire PHP utilisé par `start.py` | `php` trouvé dans le `PATH` |

## Mise à jour automatique : déclenchée par la visite

**Aucun cron n'est nécessaire.** Le rafraîchissement est paresseux (*lazy*) :

1. à chaque requête, PHP compare l'âge du fichier `data/distrotheque.db` à
   `update_interval` (3 h par défaut) ;
2. si la base est plus vieille, il lance `python3 update.py` **en arrière-plan,
   détaché**, puis répond immédiatement avec les données existantes ;
3. le scraping se fait pendant que le site continue de tourner ; dès qu'il a
   fini, la base est remplacée d'un coup et les visiteurs suivants voient les
   nouvelles données ;
4. le pied de page affiche « mise à jour en cours… » pendant l'opération.

Conséquences pratiques : le site se met à jour **tout seul dès qu'il y a du
monde**, sans tâche planifiée ni serveur supplémentaire ; s'il n'y a aucune
visite, aucune mise à jour n'est lancée (c'est sans intérêt : personne ne lit
les données).

Garde-fous :

| Mécanisme | Effet |
|---|---|
| `update_in_progress()` | un seul `update.py` à la fois (via `data/.update.lock`) |
| `data/.auto-update.stamp` + `flock` | pas plus d'une tentative toutes les 10 min, même sous un pic de trafic |
| `popen` indisponible / Python absent | on ignore silencieusement : le site reste en ligne |
| `logs/auto-update.log` | sortie de la mise à jour automatique, rotation au-delà de 5 Mo |

Réglages dans `web/config.php` : `update_interval` (3 h), `update_retry_delay`
(10 min), `python_bin`, `update_args`.

Pour forcer une mise à jour immédiate, il suffit de lancer
`python3 update.py` à la main, ou de supprimer `data/.auto-update.stamp` et de
recharger la page.

### Option : rafraîchir même sans visiteur

Sur un serveur toujours allumé, un timer garantit une base fraîche même la nuit.
Les deux mécanismes cohabitent sans conflit (le verrou empêche les doublons).

#### Linux — cron

```cron
0 */3 * * * cd /var/www/distrotheque && python3 update.py >> logs/cron.log 2>&1
```

#### Linux — systemd (recommandé si VPS)

```bash
sudo cp deploy/systemd/distrotheque-update.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now distrotheque-update.timer
systemctl list-timers distrotheque-update.timer
```

`Persistent=true` relance la mise à jour après un redémarrage manqué.

## Schéma de la base

| Table | Rôle |
|---|---|
| `distros` | une ligne par distribution (nom, version, architecture, description, dates normalisées) |
| `downloads` | une ligne par lien ISO (label, version, architecture, hébergeur, URL) |
| `distro_architectures` | table de jointure pour filtrer par architecture |
| `meta` | date de construction, compteurs, provenance du JSON |

La base est **entièrement reconstruite** à chaque mise à jour : aucun état à
migrer, aucune écriture concurrente côté PHP (`PRAGMA query_only`).

## Dépannage

**« PHP est introuvable »** — installe PHP 8.1+ avec `pdo_sqlite`, ou indique
le binaire avec `DISTROTHEQUE_PHP=/chemin/vers/php ./start.sh`.

**« Python est introuvable »** — installe Python 3.9+, ou indique le binaire
avec `DISTROTHEQUE_PYTHON=/chemin/vers/python`.

**« Base de données introuvable »** — lance `python3 update.py --skip-scrape`,
ou `./start.sh --rebuild`.

**Le scraping échoue** — consulte `logs/` ; l'ancienne base reste servie. Si
archiveos.org ou isoslinux.com change de structure HTML, `scrap/scrape_distros.py`
est le seul fichier à ajuster.

**Accents cassés dans une console** — sans incidence sur les données, tout est
en UTF-8 dans les fichiers et la base.

**Erreur `database is locked`** — un seul `update.py` à la fois : le verrou
`data/.update.lock` l'impose, et il expire au bout de 6 h en cas de plantage.
