#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import resource
except ImportError:  # pragma: no cover - Windows
    resource = None

ROOT = Path(__file__).resolve().parent
SCRAPER = ROOT / "scrap" / "scrape_distros.py"
BUILDER = ROOT / "builder" / "build_db.py"
DEFAULT_JSON = ROOT / "distros.json"
DEFAULT_DB = ROOT / "data" / "distrotheque.db"
DEFAULT_MANUAL = ROOT / "data" / "manual_distros.json"
DEFAULT_OVERRIDES = ROOT / "data" / "distro_overrides.json"
DEFAULT_DELETIONS = ROOT / "data" / "distro_deletions.json"
BACKUP_DIR = ROOT / "data" / "backups"
LOG_DIR = ROOT / "logs"
LOCK_FILE = ROOT / "data" / ".update.lock"

# Dans certains contextes (notamment lancé par PHP), sys.executable peut être
# vide : on retombe sur le python3 du système pour les sous-processus.
PYTHON = sys.executable or shutil.which("python3") or "/usr/bin/python3"

KEEP_BACKUPS = 8
LOCK_MAX_AGE = 6 * 3600
MIN_RATIO = 0.5

class UpdateError(RuntimeError):
    pass

class Logger:
    def __init__(self, path: Path | None):
        self.handle = None
        if path:
            path.parent.mkdir(parents=True, exist_ok=True)
            self.handle = path.open("a", encoding="utf-8")

    def __call__(self, message: str = "") -> None:
        line = f"[{datetime.now():%Y-%m-%d %H:%M:%S}] {message}" if message else ""
        print(line, flush=True)
        if self.handle:
            self.handle.write(line + "\n")
            self.handle.flush()

    def close(self) -> None:
        if self.handle:
            self.handle.close()

def process_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    except OSError:
        return False
    return True

def acquire_lock(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        age = time.time() - path.stat().st_mtime
        try:
            pid = int(path.read_text(encoding="utf-8").strip() or "0")
        except (OSError, ValueError):
            pid = 0

        if age < LOCK_MAX_AGE and (pid <= 0 or process_alive(pid)):
            raise UpdateError(
                f"Une mise à jour tourne déjà (verrou {path}, "
                f"âge {age / 60:.0f} min, PID {pid or 'inconnu'})."
            )
        path.unlink()
    path.write_text(str(os.getpid()), encoding="utf-8")

def release_lock(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass

def prune_backups(directory: Path, keep: int) -> None:
    backups = sorted(directory.glob("distros-*.json"))
    for old in backups[:-keep] if len(backups) > keep else []:
        old.unlink()

def backup_json(source: Path, directory: Path, log) -> Path | None:
    if not source.is_file():
        return None
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / f"distros-{datetime.now():%Y%m%d-%H%M%S}.json"
    shutil.copy2(source, target)
    prune_backups(directory, KEEP_BACKUPS)
    log(f"Sauvegarde : {target.relative_to(ROOT)}")
    return target

def previous_count(db_path: Path) -> int:
    if not db_path.is_file():
        return 0
    connection = None
    try:
        import sqlite3

        connection = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
        row = connection.execute(
            "SELECT value FROM meta WHERE key = 'distro_count'"
        ).fetchone()
        return int(row[0]) if row else 0
    except Exception:
        return 0
    finally:
        if connection is not None:
            connection.close()

def run(command: list[str], label: str, log) -> None:
    log(f"$ {' '.join(command)}")
    started = time.time()
    environment = dict(os.environ)
    environment["PYTHONIOENCODING"] = "utf-8"
    process = subprocess.run(
        command,
        cwd=str(ROOT),
        env=environment,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    for line in (process.stdout or "").splitlines():
        log(f"  {line}")
    duration = time.time() - started
    if process.returncode != 0:
        raise UpdateError(f"{label} a échoué (code {process.returncode}).")
    log(f"{label} terminé en {duration:.0f} s.")

def apply_memory_limit() -> None:
    """Garde une marge pour nginx/PHP/sshd même si le scraper dérape."""
    if resource is None:
        return

    limit = 3 * 1024 * 1024 * 1024
    try:
        raw = Path("/sys/fs/cgroup/memory.max").read_text(encoding="utf-8").strip()
        if raw.isdigit():
            limit = max(512 * 1024 * 1024, int(raw) * 3 // 4)
    except OSError:
        pass
    limit = min(limit, 3 * 1024 * 1024 * 1024)

    try:
        resource.setrlimit(resource.RLIMIT_AS, (limit, limit))
    except (OSError, ValueError):
        pass

def main() -> int:
    parser = argparse.ArgumentParser(description="Met à jour la Distrothèque.")
    parser.add_argument("--json", default=str(DEFAULT_JSON), help="Fichier JSON")
    parser.add_argument("--db", default=str(DEFAULT_DB), help="Base SQLite")
    parser.add_argument(
        "--manual",
        default=str(DEFAULT_MANUAL),
        help="Ajouts manuels (JSON) fusionnés à la base",
    )
    parser.add_argument(
        "--overrides",
        default=str(DEFAULT_OVERRIDES),
        help="Surcharges des fiches existantes (JSON)",
    )
    parser.add_argument(
        "--deletions",
        default=str(DEFAULT_DELETIONS),
        help="Fiches supprimées depuis l'administration (JSON)",
    )
    parser.add_argument(
        "--skip-scrape",
        action="store_true",
        help="Ne pas scraper, seulement reconstruire la base",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Accepter une baisse de plus de 50 %% du nombre de distributions",
    )
    parser.add_argument(
        "--no-lock", action="store_true", help="Ignorer le verrou anti-concurrence"
    )
    parser.add_argument(
        "--no-log", action="store_true", help="Ne pas écrire de fichier de log"
    )
    parser.add_argument(
        "--workers",
        type=int,
        default=1,
        help="Téléchargements simultanés du scraper (défaut : 1, adapté aux petits serveurs)",
    )
    args = parser.parse_args()

    workers = max(1, int(args.workers))
    try:
        os.nice(19)
    except OSError:
        pass
    apply_memory_limit()
    json_path = Path(args.json).resolve()
    db_path = Path(args.db).resolve()

    log_path = None
    if not args.no_log:
        log_path = LOG_DIR / f"update-{datetime.now():%Y%m%d-%H%M%S}.log"
    log = Logger(log_path)

    locked = False
    try:
        if not args.no_lock:
            acquire_lock(LOCK_FILE)
            locked = True

        log("=== Mise à jour Distrothèque ===")

        if not args.skip_scrape:
            backup_json(json_path, BACKUP_DIR, log)
            log(f"Scraping avec {workers} worker(s) simultané(s).")
            run(
                [
                    PYTHON,
                    str(SCRAPER),
                    "-o",
                    str(json_path),
                    "--workers",
                    str(workers),
                ],
                "Scraping",
                log,
            )
        else:
            log("Scraping ignoré (--skip-scrape).")

        if not json_path.is_file():
            raise UpdateError(f"JSON introuvable : {json_path}")

        try:
            entries = json.loads(json_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as error:
            raise UpdateError(f"JSON invalide : {error}") from error

        if not isinstance(entries, list) or not entries:
            raise UpdateError("Le JSON ne contient aucune distribution.")

        old_count = previous_count(db_path)
        new_count = len(entries)
        manual_count = 0
        manual_path = Path(args.manual)
        if manual_path.is_file():
            try:
                manual_data = json.loads(manual_path.read_text(encoding="utf-8"))
                if isinstance(manual_data, list):
                    manual_count = len(
                        [item for item in manual_data if isinstance(item, dict)]
                    )
            except (OSError, json.JSONDecodeError):
                manual_count = 0
        effective_count = new_count + manual_count
        if old_count and not args.force and effective_count < old_count * MIN_RATIO:
            raise UpdateError(
                f"Seulement {effective_count} distributions contre {old_count} "
                "auparavant : le scraping a probablement échoué. "
                "Relance avec --force pour accepter."
            )

        run(
            [
                PYTHON,
                str(BUILDER),
                "--json",
                str(json_path),
                "--db",
                str(db_path),
                "--manual",
                str(Path(args.manual).resolve()),
                "--overrides",
                str(Path(args.overrides).resolve()),
                "--deletions",
                str(Path(args.deletions).resolve()),
            ],
            "Construction de la base",
            log,
        )

        size_kb = db_path.stat().st_size / 1024
        built_count = previous_count(db_path) or effective_count
        log(
            f"OK : {built_count} distributions (avant : {old_count or '—'}), "
            f"base {size_kb:.0f} Ko."
        )
        return 0

    except UpdateError as error:
        log(f"ERREUR : {error}")
        return 1
    except KeyboardInterrupt:
        log("Interrompu.")
        return 130
    except Exception as error:
        log(f"ERREUR INATTENDUE : {error}")
        return 1
    finally:
        if locked:
            release_lock(LOCK_FILE)
        log.close()

if __name__ == "__main__":
    raise SystemExit(main())
