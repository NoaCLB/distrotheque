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
DEFAULT_MANUAL = ROOT / "data" / "manual_distros.json"
DEFAULT_OVERRIDES = ROOT / "data" / "distro_overrides.json"
DEFAULT_DELETIONS = ROOT / "data" / "distro_deletions.json"
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
    "sparc": "sparc",
    "sparc64": "sparc64",
    "sparcv9": "sparc64",
    "sparc-v9": "sparc64",
}

ARCH_ORDER = [
    "x86_64", "x86", "arm64", "arm", "ppc64le", "ppc64",
    "s390x", "riscv64", "loongarch64", "mips", "sparc64", "sparc",
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

def external_url(value: str) -> str:
    text = (value or "").strip()
    if not text or re.search(r"[\x00-\x20\x7f]", text):
        return ""
    parsed = urlparse(text)
    if parsed.scheme.lower() not in ("http", "https") or not parsed.netloc:
        return ""
    return text

def clean_url(value: str) -> str:
    text = re.sub(r"\s*\(not active\)\s*", "", value or "", flags=re.IGNORECASE).strip()
    if not text:
        return ""
    if re.match(r"^[A-Za-z][A-Za-z0-9+.-]*:", text):
        return external_url(text)
    return external_url("https://" + text)

def unlink_quiet(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass
    except PermissionError:
        pass

def download_key(item: dict) -> tuple:
    """Clé d'une variante de téléchargement.

    L'URL seule ne suffit pas : une même page peut proposer plusieurs
    variantes (libellé, version, architecture, bureau…). Seules les lignes
    strictement identiques sont considérées comme des doublons.
    """
    url = external_url(item.get("url") or "")
    return (
        url,
        str(item.get("label") or "").strip(),
        str(item.get("group") or "").strip(),
        str(item.get("version") or "").strip(),
        str(item.get("architecture") or "").strip(),
        str(item.get("desktop") or "").strip(),
        str(item.get("purpose") or "").strip(),
        str(item.get("status") or "").strip(),
        str(item.get("health") or "").strip(),
    )

def unique_downloads(downloads) -> list[dict]:
    seen = set()
    cleaned = []
    for item in downloads or []:
        key = download_key(item)
        if not key[0] or key in seen:
            continue
        seen.add(key)
        cleaned.append({**item, "url": key[0]})
    return cleaned

def merge_manual_entries(entries: list[dict], manual: list[dict]) -> list[dict]:
    """Fusionne les ajouts manuels dans la liste scrapée.

    Une fiche manuelle qui porte le même nom normalisé qu'une fiche scrapée
    complète celle-ci au lieu de créer un doublon. Les autres sont ajoutées.
    """
    if not manual:
        return entries

    indexes: dict[str, int] = {}
    for index, entry in enumerate(entries):
        key = name_key(entry.get("name", ""))
        if key:
            indexes.setdefault(key, index)

    for item in manual:
        if not isinstance(item, dict):
            continue
        name = str(item.get("name") or "").strip()
        if not name:
            continue

        key = name_key(name)
        if key not in indexes:
            if not str(item.get("source") or "").strip():
                item = {**item, "source": "manual"}
            indexes[key] = len(entries)
            entries.append(item)
            continue

        target = entries[indexes[key]]

        for field in ("version", "release", "updated", "href", "rank", "architectures"):
            if not str(target.get(field) or "").strip() and str(item.get(field) or "").strip():
                target[field] = item[field]

        if not str(target.get("description") or "").strip() and str(item.get("description") or "").strip():
            target["description"] = item["description"]

        requirements = target.get("requirements")
        if not isinstance(requirements, dict):
            requirements = {}
            target["requirements"] = requirements
        manual_requirements = item.get("requirements")
        if isinstance(manual_requirements, dict):
            for key_name, value in manual_requirements.items():
                if not str(requirements.get(key_name) or "").strip() and str(value or "").strip():
                    requirements[key_name] = value

        archiveos = target.get("archiveos")
        if not isinstance(archiveos, dict):
            archiveos = {}
            target["archiveos"] = archiveos
        manual_archiveos = item.get("archiveos")
        if isinstance(manual_archiveos, dict):
            for key_name, value in manual_archiveos.items():
                if not archiveos.get(key_name) and value:
                    archiveos[key_name] = value

        existing = {
            download_key(download)
            for download in target.get("downloads") or []
            if isinstance(download, dict)
        }
        for download in item.get("downloads") or []:
            if not isinstance(download, dict):
                continue
            key = download_key(download)
            if key[0] and key not in existing:
                target.setdefault("downloads", []).append(download)
                existing.add(key)

    return entries

def apply_overrides(entries: list[dict], overrides: list[dict]) -> int:
    """Applique les modifications manuelles aux fiches existantes.

    Une surcharge cible une fiche par son nom normalisé d'origine (clé stable
    même si le nom affiché est modifié). Les champs et les téléchargements
    fournis remplacent ceux de la fiche scrapée.
    """
    if not overrides:
        return 0

    indexes: dict[str, dict] = {}
    for entry in entries:
        key = name_key(entry.get("name", ""))
        if key:
            indexes.setdefault(key, entry)

    applied = 0
    for override in overrides:
        if not isinstance(override, dict):
            continue

        target_key = str(override.get("target_name_norm") or "").strip()
        data = override.get("data")
        target = indexes.get(target_key)
        if target is None or not isinstance(data, dict):
            continue

        for field in ("name", "version", "release", "updated", "architectures", "description", "href", "rank"):
            target[field] = data.get(field, "")

        requirements = target.get("requirements")
        if not isinstance(requirements, dict):
            requirements = {}
            target["requirements"] = requirements
        override_requirements = data.get("requirements")
        if isinstance(override_requirements, dict):
            for key_name in ("architectures", "official_url", "memory", "storage", "firmware", "note"):
                requirements[key_name] = override_requirements.get(key_name, "")

        archiveos = target.get("archiveos")
        if not isinstance(archiveos, dict):
            archiveos = {}
            target["archiveos"] = archiveos
        override_archiveos = data.get("archiveos")
        if isinstance(override_archiveos, dict) and "website" in override_archiveos:
            archiveos["website"] = override_archiveos["website"]

        override_downloads = data.get("downloads")
        if isinstance(override_downloads, list):
            target["downloads"] = override_downloads

        applied += 1

    return applied

def apply_deletions(entries: list[dict], deletions: list[dict]) -> int:
    """Retire les fiches supprimées depuis l'administration.

    Une suppression vise le slug stable de la fiche ; le nom normalisé sert de
    repli si la fiche a été renommée entre-temps. Les fiches sont filtrées
    après les surcharges pour que le slug reste celui d'origine.
    """
    if not deletions:
        return 0

    slugs = set()
    names = set()
    for record in deletions:
        if not isinstance(record, dict):
            continue
        slug = str(record.get("target_slug") or "").strip()
        name = str(record.get("target_name_norm") or "").strip()
        if slug:
            slugs.add(slug)
        if name:
            names.add(name)

    if not slugs and not names:
        return 0

    kept = []
    removed = 0
    for entry in entries:
        slug = str(entry.get("_slug") or "").strip()
        key = name_key(entry.get("name", ""))
        if (slug and slug in slugs) or (key and key in names):
            removed += 1
            continue
        kept.append(entry)

    entries[:] = kept
    return removed

def build(entries: list[dict], db_path: Path, json_path: Path, manual_count: int = 0, override_count: int = 0, deletion_count: int = 0) -> dict:
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
                description_norm, search_norm, keywords, updated_raw, updated_at,
                popularity, memory, storage, firmware, requirements_note,
                archiveos_json, iso_count
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
            if not downloads:
                continue
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

            slug = str(entry.get("_slug") or "").strip()
            if not slug:
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
                    external_url(entry.get("href") or ""),
                    website,
                    external_url(requirements.get("official_url") or ""),
                    description,
                    description_norm,
                    f"{name_norm} {description_norm}".strip(),
                    "unix" if entry.get("unix") else "",
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
            "manual_count": manual_count,
            "override_count": override_count,
            "deletion_count": deletion_count,
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

    if stats["distro_count"] == 0:
        raise ValueError("Aucune distribution avec ISO : la base n'a pas été remplacée.")

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
    parser.add_argument(
        "--manual",
        default=str(DEFAULT_MANUAL),
        help="Ajouts manuels (JSON) fusionnés à la source",
    )
    parser.add_argument(
        "--overrides",
        default=str(DEFAULT_OVERRIDES),
        help="Surcharges manuelles des fiches existantes (JSON)",
    )
    parser.add_argument(
        "--deletions",
        default=str(DEFAULT_DELETIONS),
        help="Fiches supprimées depuis l'administration (JSON)",
    )
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

    manual_entries = []
    manual_path = Path(args.manual)
    if manual_path.is_file():
        try:
            manual_data = json.loads(manual_path.read_text(encoding="utf-8"))
            if isinstance(manual_data, list):
                manual_entries = [item for item in manual_data if isinstance(item, dict)]
            else:
                print(
                    f"Ajouts manuels ignorés ({manual_path}) : liste attendue.",
                    file=sys.stderr,
                )
        except (OSError, json.JSONDecodeError) as error:
            print(
                f"Ajouts manuels ignorés ({manual_path}) : {error}",
                file=sys.stderr,
            )

    entries = merge_manual_entries(entries, manual_entries)

    used_slugs: dict[str, int] = {}
    for entry in entries:
        entry["_slug"] = distro_slug(entry, used_slugs)

    override_entries = []
    overrides_path = Path(args.overrides)
    if overrides_path.is_file():
        try:
            override_data = json.loads(overrides_path.read_text(encoding="utf-8"))
            if isinstance(override_data, list):
                override_entries = [item for item in override_data if isinstance(item, dict)]
            else:
                print(
                    f"Surcharges ignorées ({overrides_path}) : liste attendue.",
                    file=sys.stderr,
                )
        except (OSError, json.JSONDecodeError) as error:
            print(
                f"Surcharges ignorées ({overrides_path}) : {error}",
                file=sys.stderr,
            )

    override_count = apply_overrides(entries, override_entries)

    deletion_entries = []
    deletions_path = Path(args.deletions)
    if deletions_path.is_file():
        try:
            deletion_data = json.loads(deletions_path.read_text(encoding="utf-8"))
            if isinstance(deletion_data, list):
                deletion_entries = [item for item in deletion_data if isinstance(item, dict)]
            else:
                print(
                    f"Suppressions ignorées ({deletions_path}) : liste attendue.",
                    file=sys.stderr,
                )
        except (OSError, json.JSONDecodeError) as error:
            print(
                f"Suppressions ignorées ({deletions_path}) : {error}",
                file=sys.stderr,
            )

    deleted_count = apply_deletions(entries, deletion_entries)

    try:
        stats = build(
            entries,
            Path(args.db),
            json_path,
            manual_count=len(manual_entries),
            override_count=override_count,
            deletion_count=deleted_count,
        )
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 1
    size = stats["db_size"] / 1024
    print(
        f"{stats['distro_count']} distributions, "
        f"{stats['download_count']} téléchargements "
        f"(dont {stats['manual_count']} ajout(s) manuel(s), "
        f"{stats['override_count']} surcharge(s), "
        f"{stats['deletion_count']} suppression(s)) -> {stats['db_path']} "
        f"({size:.0f} Ko)"
    )
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
