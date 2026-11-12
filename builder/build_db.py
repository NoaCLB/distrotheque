#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import re
import sqlite3
import sys
import unicodedata
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import unquote, urlparse

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_JSON = ROOT / "distros.json"
DEFAULT_DB = ROOT / "data" / "distrotheque.db"
SCHEMA_FILE = Path(__file__).with_name("schema.sql")

MONTHS = {
    "january": 1, "february": 2, "march": 3, "april": 4,
    "may": 5, "june": 6, "july": 7, "august": 8,
    "september": 9, "october": 10, "november": 11, "december": 12,
}

ARCH_ALIASES = {
    "x86": "x86",
    "i386": "x86",
    "i486": "x86",
    "i586": "x86",
    "i686": "x86",
    "386": "x86",
    "32-bit": "x86",
    "32bit": "x86",
    "x86_64": "x86_64",
    "x86-64": "x86_64",
    "amd64": "x86_64",
    "x64": "x86_64",
    "64-bit": "x86_64",
    "64bit": "x86_64",
    "arm64": "arm64",
    "aarch64": "arm64",
    "arm": "arm",
    "armhf": "arm",
    "armel": "arm",
    "armv7": "arm",
    "armv7l": "arm",
    "armv6": "arm",
    "ppc64le": "ppc64le",
    "ppc64el": "ppc64le",
    "ppc64": "ppc64",
    "s390x": "s390x",
    "riscv64": "riscv64",
    "loongarch64": "loongarch64",
    "mips": "mips",
    "mipsel": "mips",
}

ARCH_ORDER = [
    "x86_64", "x86", "arm64", "arm", "ppc64le", "ppc64",
    "s390x", "riscv64", "loongarch64", "mips",
]

def strip_accents(value: str) -> str:
    text = unicodedata.normalize("NFKD", value or "")
    return "".join(char for char in text if not unicodedata.combining(char))

def name_key(value: str) -> str:
    text = strip_accents(value or "").lower()
    text = text.replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()

def text_key(value: str) -> str:
    text = strip_accents(value or "").lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()

def slugify(value: str) -> str:
    slug = name_key(value).replace(" ", "-")
    return slug or "distro"

def parse_updated(raw: str) -> str | None:
    text = (raw or "").strip()
    if not text:
        return None

    match = re.match(r"^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?", text)
    if match:
        year, month, day, hour, minute, second = match.groups()
        return f"{year}-{month}-{day} {hour}:{minute}:{second or '00'}"

    match = re.match(
        r"^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\s+(\d{4})"
        r"(?:,?\s*(\d{1,2}):(\d{2})\s*(am|pm)?)?",
        text,
    )
    if match:
        day, month_name, year, hour, minute, meridiem = match.groups()
        month = MONTHS.get(month_name.lower())
        if month:
            hour_value = int(hour) if hour else 0
            if meridiem:
                if meridiem.lower() == "pm" and hour_value < 12:
                    hour_value += 12
                if meridiem.lower() == "am" and hour_value == 12:
                    hour_value = 0
            return (
                f"{int(year):04d}-{month:02d}-{int(day):02d} "
                f"{hour_value:02d}:{int(minute) if minute else 0:02d}:00"
            )

    match = re.match(r"^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$", text)
    if match:
        month_name, day, year = match.groups()
        month = MONTHS.get(month_name.lower())
        if month:
            return f"{int(year):04d}-{month:02d}-{int(day):02d} 00:00:00"

    match = re.match(r"^(\d{4})-(\d{2})-(\d{2})$", text)
    if match:
        return f"{match.group(1)}-{match.group(2)}-{match.group(3)} 00:00:00"

    return None

def arch_tokens(value) -> list[str]:
    if isinstance(value, (list, tuple)):
        chunks = []
        for item in value:
            chunks.extend(arch_tokens(item))
        return chunks

    text = str(value or "").strip()
    if not text:
        return []

    found = []
    for part in re.split(r"[,/;+|()\[\]]+|\bet\b|\band\b", text):
        token = part.strip().lower().replace(" ", "")
        if not token:
            continue
        arch = ARCH_ALIASES.get(token)
        if not arch:
            for alias, target in ARCH_ALIASES.items():
                if token.startswith(alias):
                    arch = target
                    break
        if arch and arch not in found:
            found.append(arch)
    return found

def sort_archs(archs) -> list[str]:
    def key(arch: str):
        if arch in ARCH_ORDER:
            return (ARCH_ORDER.index(arch), "")
        return (len(ARCH_ORDER), arch)

    return sorted(set(archs), key=key)

def distro_slug(entry: dict, used: dict[str, int]) -> str:
    archiveos = entry.get("archiveos") or {}
    candidates = [
        archiveos.get("slug") if isinstance(archiveos, dict) else "",
        urlparse(entry.get("href") or "").path.rstrip("/").rsplit("/", 1)[-1],
        slugify(entry.get("name", "")),
    ]

    base = ""
    for candidate in candidates:
        slug = slugify(unquote(str(candidate or "")))
        if slug and slug != "distro":
            base = slug
            break
    base = base or "distro"

    count = used.get(base, 0) + 1
    used[base] = count
    return base if count == 1 else f"{base}-{count}"

def download_host(url: str) -> str:
    try:
        return urlparse(url).netloc.lower()
    except Exception:
        return ""

def clean_url(value: str) -> str:
    text = re.sub(r"\s*\(not active\)\s*", "", value or "", flags=re.IGNORECASE).strip()
    if not text:
        return ""
    if not text.startswith(("http://", "https://")):
        text = "https://" + text
    return text if urlparse(text).netloc else ""

def unlink_quiet(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass
    except PermissionError:
        pass

def unique_downloads(downloads) -> list[dict]:
    seen = set()
    cleaned = []
    for item in downloads or []:
        url = (item.get("url") or "").strip()
        if not url or url in seen:
            continue
        seen.add(url)
        cleaned.append(item)
    return cleaned

def build(entries: list[dict], db_path: Path, json_path: Path) -> dict:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = db_path.with_name(db_path.name + ".tmp")
    for suffix in ("", "-wal", "-shm"):
        unlink_quiet(Path(str(temp_path) + suffix))

    connection = sqlite3.connect(temp_path)
    try:
        connection.executescript(SCHEMA_FILE.read_text(encoding="utf-8"))
        connection.execute("PRAGMA journal_mode = DELETE")
        connection.execute("PRAGMA synchronous = OFF")

        used_slugs: dict[str, int] = {}
        iso_total = 0
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        insert_distro = """
            INSERT INTO distros (
                slug, name, name_norm, version, release_name, architectures,
                source, href, website, official_url, description,
                description_norm, search_norm, updated_raw, updated_at,
                popularity, memory, storage, firmware, requirements_note,
                archiveos_json, iso_count
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        """
        insert_download = """
            INSERT OR IGNORE INTO downloads (
                distro_id, label, variant_group, version, architecture,
                desktop, purpose, status, health, url, host
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
        """
        insert_arch = (
            "INSERT OR IGNORE INTO distro_architectures (distro_id, arch) VALUES (?,?)"
        )

        for entry in entries:
            name = (entry.get("name") or "").strip()
            if not name:
                continue

            requirements = entry.get("requirements") or {}
            archiveos = entry.get("archiveos") or {}
            if not isinstance(archiveos, dict):
                archiveos = {}

            downloads = unique_downloads(entry.get("downloads"))
            iso_total += len(downloads)

            archs = sort_archs(
                arch_tokens(entry.get("architectures"))
                + arch_tokens(requirements.get("architectures"))
                + arch_tokens([item.get("architecture") for item in downloads])
            )

            description = (entry.get("description") or "").strip()
            description_norm = text_key(description)
            name_norm = name_key(name)

            website = clean_url(archiveos.get("website") or "")

            popularity = None
            raw_rank = str(entry.get("rank") or "").strip()
            if raw_rank.isdigit():
                popularity = int(raw_rank)

            slug = distro_slug(entry, used_slugs)
            cursor = connection.execute(
                insert_distro,
                (
                    slug,
                    name,
                    name_norm,
                    (entry.get("version") or "").strip(),
                    (entry.get("release") or "").strip(),
                    ", ".join(archs),
                    (entry.get("source") or "").strip(),
                    (entry.get("href") or "").strip(),
                    website,
                    (requirements.get("official_url") or "").strip(),
                    description,
                    description_norm,
                    f"{name_norm} {description_norm}".strip(),
                    (entry.get("updated") or "").strip(),
                    parse_updated(entry.get("updated") or "")
                    or parse_updated(entry.get("release") or ""),
                    popularity,
                    (requirements.get("memory") or "").strip(),
                    (requirements.get("storage") or "").strip(),
                    (requirements.get("firmware") or "").strip(),
                    (requirements.get("note") or "").strip(),
                    json.dumps(archiveos, ensure_ascii=False),
                    len(downloads),
                ),
            )
            distro_id = cursor.lastrowid

            if archs:
                connection.executemany(
                    insert_arch, [(distro_id, arch) for arch in archs]
                )

            if downloads:
                connection.executemany(
                    insert_download,
                    [
                        (
                            distro_id,
                            (item.get("label") or "").strip(),
                            (item.get("group") or "").strip(),
                            (item.get("version") or "").strip(),
                            (item.get("architecture") or "").strip(),
                            (item.get("desktop") or "").strip(),
                            (item.get("purpose") or "").strip(),
                            (item.get("status") or "").strip(),
                            (item.get("health") or "").strip(),
                            (item.get("url") or "").strip(),
                            download_host(item.get("url") or ""),
                        )
                        for item in downloads
                    ],
                )

        stats = {
            "built_at": now,
            "distro_count": connection.execute(
                "SELECT COUNT(*) FROM distros"
            ).fetchone()[0],
            "download_count": connection.execute(
                "SELECT COUNT(*) FROM downloads"
            ).fetchone()[0],
            "json_source": str(json_path),
            "json_mtime": datetime.fromtimestamp(
                json_path.stat().st_mtime, timezone.utc
            ).strftime("%Y-%m-%d %H:%M:%S"),
        }
        connection.executemany(
            "INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)",
            [(key, str(value)) for key, value in stats.items()],
        )
        connection.commit()
        connection.execute("VACUUM")
        connection.commit()
    finally:
        connection.close()

    temp_path.replace(db_path)
    for suffix in ("-wal", "-shm"):
        unlink_quiet(Path(str(db_path) + suffix))

    stats["db_path"] = str(db_path)
    stats["db_size"] = db_path.stat().st_size
    return stats

def main() -> int:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(errors="replace")
        except Exception:
            pass

    parser = argparse.ArgumentParser(
        description="Construit la base SQLite de la Distrothèque depuis distros.json."
    )
    parser.add_argument("--json", default=str(DEFAULT_JSON), help="Fichier source")
    parser.add_argument("--db", default=str(DEFAULT_DB), help="Base SQLite de sortie")
    args = parser.parse_args()

    json_path = Path(args.json)
    if not json_path.is_file():
        print(f"Fichier introuvable : {json_path}", file=sys.stderr)
        return 1

    try:
        entries = json.loads(json_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as error:
        print(f"JSON invalide ({json_path}) : {error}", file=sys.stderr)
        return 1

    if not isinstance(entries, list):
        print("Le JSON doit contenir une liste de distributions.", file=sys.stderr)
        return 1

    stats = build(entries, Path(args.db), json_path)
    size = stats["db_size"] / 1024
    print(
        f"{stats['distro_count']} distributions, "
        f"{stats['download_count']} téléchargements -> {stats['db_path']} "
        f"({size:.0f} Ko)"
    )
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
