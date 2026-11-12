import argparse
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

from bs4 import BeautifulSoup

DEFAULT_URL = "https://isoslinux.com/"
DEFAULT_OUTPUT = "isoslinux.json"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
}

def fetch(url, retries=3, timeout=30):
    last_error = None
    for attempt in range(1, retries + 1):
        try:
            request = urllib.request.Request(url, headers=HEADERS)
            with urllib.request.urlopen(request, timeout=timeout) as response:
                charset = response.headers.get_content_charset() or "utf-8"
                return response.read().decode(charset, errors="replace")
        except (urllib.error.URLError, TimeoutError) as error:
            last_error = error
            if attempt < retries:
                time.sleep(2 * attempt)
    raise RuntimeError(f"Impossible de télécharger {url} : {last_error}")

def cell_text(row, css_class):
    cell = row.find("td", class_=css_class)
    return cell.get_text(" ", strip=True) if cell else ""

def parse_rows(html, base_url=DEFAULT_URL):
    soup = BeautifulSoup(html, "html.parser")
    entries = []

    for row in soup.find_all("tr"):
        if not row.get("data-name"):
            continue

        link = row.select_one("a.catalog-entry")
        href = link.get("href", "") if link else ""
        if href:
            href = urllib.parse.urljoin(base_url, href)

        title = row.select_one("span.catalog-title")
        description = row.select_one("span.catalog-description")
        name = (
            title.get_text(" ", strip=True) if title else ""
        ) or row.get("data-name", "").strip()
        if name and name == name.lower():
            name = name.title()

        entries.append(
            {
                "name": name,
                "architectures": row.get("data-architectures", "").strip(),
                "release": row.get("data-release", "").strip(),
                "updated": row.get("data-updated", "").strip(),
                "rank": cell_text(row, "rank"),
                "version": cell_text(row, "version"),
                "href": href,
                "description": (
                    description.get_text(" ", strip=True) if description else ""
                ),
            }
        )

    return entries

def extract_download_url(href):
    query = urllib.parse.parse_qs(urllib.parse.urlparse(href).query)
    return query.get("url", [""])[0]

def parse_variant(item, group):
    label_element = item.find("strong", recursive=False)
    meta_element = None
    for span in item.find_all("span", recursive=False):
        if "download-health" not in (span.get("class") or []):
            meta_element = span
            break

    meta = meta_element.get_text(" ", strip=True) if meta_element else ""
    parts = [part.strip() for part in meta.split("·", 2)]
    version = parts[0] if len(parts) > 0 else ""
    architecture = parts[1] if len(parts) > 1 else ""
    desktop = parts[2] if len(parts) > 2 else ""

    purpose = item.select_one(".download-variant-purpose")
    health = item.select_one(".download-health")
    status = health.find("strong") if health else None
    health_detail = health.find("span") if health else None

    href = item.get("href", "")

    return {
        "label": label_element.get_text(" ", strip=True) if label_element else "",
        "group": group,
        "version": version,
        "architecture": architecture,
        "desktop": desktop,
        "purpose": purpose.get_text(" ", strip=True) if purpose else "",
        "status": status.get_text(" ", strip=True) if status else "",
        "health": health_detail.get_text(" ", strip=True) if health_detail else "",
        "url": extract_download_url(href),
    }

def parse_downloads(soup, fallback_version=""):
    downloads = []
    seen = set()

    variants = soup.select("a.download-variant-item")
    for item in variants:
        group_element = item.find_parent("div", class_="download-variant-group")
        heading = group_element.find("h3") if group_element else None
        download = parse_variant(item, heading.get_text(strip=True) if heading else "")
        key = (download["url"], download["version"], download["architecture"])
        if download["url"] and key not in seen:
            seen.add(key)
            downloads.append(download)

    if downloads:
        return downloads

    for item in soup.select(".dp-downloads a.btn-download"):
        container = item.find_parent("div", class_="dp-download")
        label_element = (
            container.select_one(".dp-download-text strong") if container else None
        )
        purpose_element = (
            container.select_one(".dp-download-text span") if container else None
        )
        href = item.get("href", "")
        download = {
            "label": label_element.get_text(" ", strip=True) if label_element else "",
            "group": "",
            "version": fallback_version,
            "architecture": "",
            "desktop": "",
            "purpose": (
                purpose_element.get_text(" ", strip=True) if purpose_element else ""
            ),
            "status": "",
            "health": "",
            "url": extract_download_url(href),
        }
        if download["url"] and download["url"] not in seen:
            seen.add(download["url"])
            downloads.append(download)

    return downloads

def parse_requirements(soup):
    section = soup.select_one("section.requirements-card")
    if not section:
        return None

    requirements = {
        "architectures": [],
        "memory": "",
        "storage": "",
        "firmware": "",
        "note": "",
        "official_url": "",
    }

    for block in section.select("dl.requirements-list > div"):
        term = block.find("dt")
        value = block.find("dd")
        if not term or not value:
            continue
        key = term.get_text(strip=True).lower()
        if key == "architectures":
            badges = value.select(".architecture-badges span")
            requirements["architectures"] = (
                [badge.get_text(strip=True) for badge in badges]
                if badges
                else [value.get_text(" ", strip=True)]
            )
        elif key in ("memory", "storage", "firmware"):
            requirements[key] = value.get_text(" ", strip=True)

    note = section.select_one(".requirements-note")
    requirements["note"] = note.get_text(" ", strip=True) if note else ""

    link = section.select_one("a[href]")
    requirements["official_url"] = link.get("href", "") if link else ""

    return requirements

def scrape_details(entries, workers=6):
    def enrich(entry):
        soup = BeautifulSoup(fetch(entry["href"]), "html.parser")
        entry["downloads"] = parse_downloads(soup, entry.get("version", ""))
        entry["requirements"] = parse_requirements(soup)
        return entry

    total = len(entries)
    done = 0

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(enrich, entry): entry for entry in entries}
        for future in as_completed(futures):
            entry = futures[future]
            try:
                future.result()
            except Exception as error:
                entry["downloads"] = []
                entry["requirements"] = None
                entry["scrape_error"] = str(error)
            done += 1
            print(f"  [{done}/{total}] {entry['name']}", file=sys.stderr)

    return entries

def main():
    parser = argparse.ArgumentParser(
        description="Scrape le catalogue isoslinux.com vers un JSON."
    )
    parser.add_argument("-u", "--url", default=DEFAULT_URL, help="URL de la page")
    parser.add_argument(
        "-o", "--output", default=DEFAULT_OUTPUT, help="Fichier JSON de sortie"
    )
    parser.add_argument(
        "--workers", type=int, default=6, help="Téléchargements simultanés"
    )
    parser.add_argument(
        "--no-details",
        action="store_true",
        help="Ne pas ouvrir les pages de détail (pas de liens ISO ni de prérequis)",
    )
    args = parser.parse_args()

    try:
        html = fetch(args.url)
    except RuntimeError as error:
        sys.exit(f"Erreur : {error}")

    entries = parse_rows(html, args.url)
    if not entries:
        sys.exit("Aucune ligne trouvée : la structure de la page a peut-être changé.")

    if not args.no_details:
        print(f"Lecture des pages de détail ({len(entries)})...", file=sys.stderr)
        entries = scrape_details(entries, max(1, args.workers))

    with open(args.output, "w", encoding="utf-8") as file:
        json.dump(entries, file, ensure_ascii=False, indent=2)
        file.write("\n")

    downloads = sum(len(entry.get("downloads") or []) for entry in entries)
    print(f"{len(entries)} distributions, {downloads} liens ISO -> {args.output}")

if __name__ == "__main__":
    main()
