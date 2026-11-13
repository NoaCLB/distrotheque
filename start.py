#!/usr/bin/env python3

from __future__ import annotations

import argparse
import os
import shutil
import socket
import sqlite3
import subprocess
import sys
import threading
import time
import webbrowser
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PUBLIC_DIR = ROOT / "web" / "public"
ROUTER = PUBLIC_DIR / "router.php"
DB_PATH = ROOT / "data" / "distrotheque.db"
UPDATE_SCRIPT = ROOT / "update.py"

MIN_PHP = (8, 1)
PHP_CANDIDATES = ("php", "php8.4", "php8.3", "php8.2", "php8.1")

class StartError(RuntimeError):
    pass

def configure_stdio() -> None:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(errors="replace")
        except Exception:
            pass

def env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, str(default)))
    except (TypeError, ValueError):
        return default

def find_php() -> str:
    override = os.environ.get("DISTROTHEQUE_PHP", "").strip()
    if override:
        return override

    for name in PHP_CANDIDATES:
        path = shutil.which(name)
        if path:
            return path

    raise StartError(
        "PHP est introuvable dans le PATH.\n"
        "Installe PHP 8.1+ avec l'extension pdo_sqlite, ou indique le binaire "
        "avec la variable DISTROTHEQUE_PHP."
    )

def php_version(php: str) -> tuple[int, ...]:
    try:
        result = subprocess.run(
            [php, "-r", "echo PHP_VERSION;"],
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (OSError, subprocess.SubprocessError) as error:
        raise StartError(f"Impossible d'exécuter PHP : {error}") from error

    if result.returncode != 0:
        raise StartError(
            "PHP ne répond pas correctement :\n"
            + (result.stderr or result.stdout or "").strip()
        )

    try:
        return tuple(int(part) for part in result.stdout.strip().split(".")[:3])
    except ValueError:
        return (0,)

def check_php_modules(php: str) -> None:
    try:
        result = subprocess.run(
            [php, "-r", "echo extension_loaded('pdo_sqlite') ? '1' : '0';"],
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (OSError, subprocess.SubprocessError):
        return

    if result.returncode == 0 and result.stdout.strip() != "1":
        print(
            "Attention : l'extension PHP pdo_sqlite n'est pas activée.\n"
            "Le site ne pourra pas lire la base. Active pdo_sqlite puis réessaie.",
            file=sys.stderr,
        )

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

def update_in_progress() -> bool:
    lock = ROOT / "data" / ".update.lock"
    if not lock.is_file():
        return False
    try:
        pid = int(lock.read_text(encoding="utf-8").strip() or "0")
    except (OSError, ValueError):
        pid = 0
    return process_alive(pid)

def database_json_mtime(path: Path) -> float:
    if not path.is_file():
        return 0.0

    connection = None
    try:
        connection = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        row = connection.execute(
            "SELECT value FROM meta WHERE key = 'json_mtime'"
        ).fetchone()
        if not row or not row[0]:
            return 0.0
        return datetime.strptime(str(row[0]), "%Y-%m-%d %H:%M:%S").replace(
            tzinfo=timezone.utc
        ).timestamp()
    except Exception:
        return 0.0
    finally:
        if connection is not None:
            connection.close()

def ensure_database(force: bool) -> None:
    json_path = ROOT / "distros.json"

    if DB_PATH.is_file() and not force:
        try:
            json_mtime = json_path.stat().st_mtime
        except OSError:
            return

        if json_mtime <= database_json_mtime(DB_PATH) + 1.0:
            return

        print(
            "distros.json est plus récent que la base : reconstruction…",
            flush=True,
        )

    if not UPDATE_SCRIPT.is_file():
        raise StartError(f"Script de construction introuvable : {UPDATE_SCRIPT}")

    if update_in_progress():
        print(
            "Une mise à jour est déjà en cours : la base sera actualisée à la fin.",
            flush=True,
        )
        return

    print("Construction de la base SQLite…", flush=True)
    result = subprocess.run(
        [sys.executable, str(UPDATE_SCRIPT), "--skip-scrape"],
        cwd=str(ROOT),
    )
    if result.returncode != 0:
        raise StartError(
            "La construction de la base a échoué. Consulte les messages ci-dessus "
            "ou le dossier logs/."
        )

def choose_port(host: str, port: int) -> int:
    for candidate in range(port, port + 20):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            try:
                sock.bind((host, candidate))
            except OSError:
                continue
            return candidate

    raise StartError(
        f"Aucun port libre entre {port} et {port + 19}. "
        "Utilise --port pour en choisir un autre."
    )

def open_browser_later(url: str) -> None:
    time.sleep(1.0)
    try:
        webbrowser.open(url)
    except Exception:
        pass

def browser_url(host: str, port: int) -> str:
    if host in ("", "0.0.0.0", "::"):
        host = "127.0.0.1"
    if ":" in host and not host.startswith("["):
        host = f"[{host}]"
    return f"http://{host}:{port}/"

def serve(php: str, host: str, port: int, open_browser: bool) -> int:
    url = browser_url(host, port)
    command = [
        php,
        "-S",
        f"{host}:{port}",
        "-t",
        str(PUBLIC_DIR),
        str(ROUTER),
    ]

    print(f"Distrothèque sur {url}  (Ctrl+C pour arrêter)", flush=True)
    print(f"Administration : {url}admin", flush=True)
    if not (ROOT / "data" / "admin.json").is_file():
        print(
            "  Compte admin non configuré : php web/cli/admin.php set-password --username admin",
            flush=True,
        )
    if host in ("0.0.0.0", "::"):
        print(f"Écoute sur toutes les interfaces, port {port}.", flush=True)

    process = subprocess.Popen(command, cwd=str(ROOT))

    if open_browser:
        threading.Thread(
            target=open_browser_later, args=(url,), daemon=True
        ).start()

    try:
        return process.wait()
    except KeyboardInterrupt:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
        return 130

def main() -> int:
    configure_stdio()

    parser = argparse.ArgumentParser(
        description="Lance la Distrothèque en local sous Linux."
    )
    parser.add_argument(
        "--host",
        default=os.environ.get("DISTROTHEQUE_HOST", "127.0.0.1"),
        help="Adresse d'écoute (défaut : 127.0.0.1)",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=env_int("DISTROTHEQUE_PORT", 8080),
        help="Port d'écoute (défaut : 8080)",
    )
    parser.add_argument(
        "--no-browser",
        action="store_true",
        help="Ne pas ouvrir le navigateur",
    )
    parser.add_argument(
        "--rebuild",
        action="store_true",
        help="Reconstruire la base depuis distros.json avant de démarrer",
    )
    parser.add_argument(
        "--no-build",
        action="store_true",
        help="Ne pas construire la base même si elle est absente",
    )
    args = parser.parse_args()

    try:
        if not 1 <= args.port <= 65535:
            raise StartError("Le port doit être compris entre 1 et 65535.")

        if not ROUTER.is_file():
            raise StartError(f"Routeur PHP introuvable : {ROUTER}")

        php = find_php()
        version = php_version(php)
        if version < MIN_PHP:
            raise StartError(
                f"PHP {'.'.join(map(str, version))} détecté, mais PHP 8.1+ est requis."
            )

        check_php_modules(php)

        if not args.no_build:
            ensure_database(args.rebuild)
        elif not DB_PATH.is_file():
            print(
                "Attention : la base est absente et --no-build a été demandé.",
                file=sys.stderr,
            )

        port = choose_port(args.host, args.port)
        if port != args.port:
            print(
                f"Port {args.port} déjà utilisé, bascule sur {port}.", flush=True
            )

        return serve(php, args.host, port, not args.no_browser)

    except StartError as error:
        print(f"ERREUR : {error}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        return 130

if __name__ == "__main__":
    raise SystemExit(main())
