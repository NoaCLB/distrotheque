import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

from bs4 import BeautifulSoup

BASE_URL = "https://archiveos.org/"
DEFAULT_OUTPUT = "archiveos.json"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
}

class NotFound(Exception):
    pass

def fetch(url, retries=4, timeout=30, headers=None):
    last_error = None
    request_headers = dict(HEADERS)
    if headers:
        request_headers.update(headers)
    for attempt in range(1, retries + 1):
        try:
            request = urllib.request.Request(url, headers=request_headers)
            with urllib.request.urlopen(request, timeout=timeout) as response:
                charset = response.headers.get_content_charset() or "utf-8"
                return response.read().decode(charset, errors="replace")
        except urllib.error.HTTPError as error:
            if error.code == 404:
                raise NotFound(url)
            last_error = error
        except (urllib.error.URLError, TimeoutError) as error:
            last_error = error
        if attempt < retries:
            time.sleep(2 * attempt)
    raise RuntimeError(f"Impossible de télécharger {url} : {last_error}")

def page_url(number):
    if number <= 1:
        return BASE_URL
    return urllib.parse.urljoin(BASE_URL, f"page/{number}/")

def discover_last_page(first_html):
    numbers = [
        int(match.group(1))
        for match in re.finditer(r"/page/(\d+)/", first_html)
        if match.group(1).isdigit()
    ]
    return max(numbers) if numbers else None

def parse_listing(html, base_url):
    soup = BeautifulSoup(html, "html.parser")
    posts = []

    for article in soup.select("article"):
        link = article.select_one("h2.entry-title a") or article.select_one("h2 a")
        if not link:
            continue

        time_element = article.select_one("time")
        excerpt = article.select_one(".entry-content")
        image = article.select_one(".post-thumbnail img") or article.select_one(
            "img.wp-post-image"
        )

        posts.append(
            {
                "name": link.get_text(" ", strip=True),
                "url": urllib.parse.urljoin(base_url, link.get("href", "")),
                "published": (
                    time_element.get("datetime", "") if time_element else ""
                ).strip(),
                "categories": [
                    category.get_text(" ", strip=True)
                    for category in article.select(".post-categories a")
                ],
                "excerpt": excerpt.get_text(" ", strip=True) if excerpt else "",
                "image": (image.get("src", "") if image else "").strip(),
            }
        )

    return posts

def scrape_listing(start_page, last_page, workers):
    def load(number):
        try:
            html = fetch(page_url(number))
        except NotFound:
            return number, []
        return number, parse_listing(html, page_url(number))

    pages = list(range(start_page, last_page + 1))
    results = {}
    completed = 0
    total = len(pages)

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(load, number): number for number in pages}
        for future in as_completed(futures):
            number, posts = future.result()
            results[number] = posts
            completed += 1
            if completed % 25 == 0 or completed == total:
                print(f"  pages {completed}/{total}", file=sys.stderr)

    ordered = []
    for number in pages:
        for post in results.get(number, []):
            ordered.append(post)
    return ordered

def split_segments(html):
    return re.split(r"<br\s*/?>", html)

def parse_metadata(content):
    meta = {}
    last_updated = ""

    for paragraph in content.find_all("p", recursive=False):
        classes = paragraph.get("class") or []
        if "last-updt-div" in classes:
            text = paragraph.get_text(" ", strip=True)
            last_updated = re.sub(
                r"^Last Updated on\s*:?\s*", "", text, flags=re.IGNORECASE
            ).strip()
            continue
        if paragraph.find("button") or paragraph.find("script"):
            continue

        for segment in split_segments(str(paragraph)):
            fragment = BeautifulSoup(segment, "html.parser")
            label_element = fragment.find(["span", "strong", "b"])
            if not label_element:
                continue
            label = label_element.get_text(" ", strip=True)
            if not label.endswith(":"):
                continue
            label_element.extract()
            value = fragment.get_text(" ", strip=True).strip(" ,")
            key = label.rstrip(":").strip()
            if key:
                meta.setdefault(key, []).append(value)

    cleaned = {}
    for key, values in meta.items():
        unique = list(dict.fromkeys(value for value in values if value))
        cleaned[key] = ", ".join(unique)
    return cleaned, last_updated

def pick(meta, *keys):
    for key in keys:
        for name, value in meta.items():
            if name.lower() == key.lower() and value:
                return value
    return ""

def pick_prefix(meta, prefix):
    for name, value in meta.items():
        if name.lower().startswith(prefix.lower()) and value:
            return value
    return ""

def parse_version(meta):
    value = pick(meta, "The last version | Released")
    if not value:
        return "", ""
    version, separator, released = value.partition("|")
    if not separator:
        return value.strip(), ""
    return version.strip(), released.strip()

def first_element(paragraph):
    for child in paragraph.children:
        if getattr(child, "name", None):
            return child
    return None

def is_metadata_paragraph(paragraph):
    element = first_element(paragraph)
    return (
        element is not None
        and element.name in ("span", "strong", "b")
        and element.get_text(" ", strip=True).endswith(":")
    )

def parse_description(content):
    paragraphs = []
    for paragraph in content.find_all("p", recursive=False):
        classes = paragraph.get("class") or []
        if "last-updt-div" in classes:
            continue
        if paragraph.find("button") or paragraph.find("script"):
            continue
        if any("yarpp" in str(item) for item in classes):
            continue
        if is_metadata_paragraph(paragraph):
            continue
        text = paragraph.get_text(" ", strip=True)
        if text:
            paragraphs.append(text)
    return "\n\n".join(paragraphs)

def parse_detail(html, base_url):
    soup = BeautifulSoup(html, "html.parser")
    article = soup.select_one("article") or soup

    title = article.select_one(".entry-title") or soup.select_one("h1")
    time_element = article.select_one("time")
    author = article.select_one(".author.vcard a") or article.select_one(".author")
    image = article.select_one(".post-thumbnail img") or article.select_one(
        "img.wp-post-image"
    )
    content = article.select_one(".entry-content")
    script = article.select_one("script[src*='/get/pak/download-']") or article.select_one(
        "script[src*='download-']"
    )
    download_script = (
        urllib.parse.urljoin(base_url, script.get("src", "")) if script else ""
    )

    meta = {}
    last_updated = ""
    description = ""
    if content:
        meta, last_updated = parse_metadata(content)
        description = parse_description(content)

    version, released = parse_version(meta)
    known = {
        "web site",
        "website",
        "origin",
        "category",
        "desktop environment",
        "architecture",
        "based on",
        "media",
        "the last version | released",
        "last updated on",
    }
    extra = {
        name: value
        for name, value in meta.items()
        if name.lower() not in known and not name.lower().startswith("wikipedia")
    }

    return {
        "name": title.get_text(" ", strip=True) if title else "",
        "slug": urllib.parse.urlparse(base_url).path.strip("/"),
        "url": base_url,
        "published": (time_element.get("datetime", "") if time_element else "").strip(),
        "author": author.get_text(" ", strip=True) if author else "",
        "categories": [
            category.get_text(" ", strip=True)
            for category in article.select(".post-categories a")
        ],
        "image": (image.get("src", "") if image else "").strip(),
        "last_updated": last_updated,
        "website": pick(meta, "Web site", "Website"),
        "origin": pick(meta, "Origin"),
        "category": pick(meta, "Category"),
        "desktop_environment": pick(meta, "Desktop environment"),
        "architecture": pick(meta, "Architecture"),
        "based_on": pick(meta, "Based on"),
        "wikipedia": pick_prefix(meta, "Wikipedia"),
        "media": pick(meta, "Media"),
        "last_version": version,
        "released": released,
        "download_script": download_script,
        "description": description,
        "extra": extra,
    }

def resolve_download(script_url):
    if not script_url:
        return "", [], "", ""

    try:
        script = fetch(script_url)
    except Exception as error:
        return "", [], f"script : {error}", ""

    match = re.search(r"open\(\s*['\"](/get/[^'\"]+\.html)['\"]", script)
    if not match:
        match = re.search(r"['\"](/get/download-[^'\"]+\.html)['\"]", script)
    if not match:
        return "", [], "aucune page de téléchargement dans le script", ""

    page_url = urllib.parse.urljoin(BASE_URL, match.group(1))

    try:
        page = fetch(page_url)
    except Exception as error:
        return "", [], f"page : {error}", page_url

    soup = BeautifulSoup(page, "html.parser")
    links = []
    for link in soup.select("a[href]"):
        href = link.get("href", "").strip()
        if not href.startswith("http"):
            continue
        if "archiveos.org" in href:
            continue
        if href not in links:
            links.append(href)

    primary = next(
        (href for href in links if "archive.org/download/" in href),
        links[0] if links else "",
    )
    return primary, links, "", page_url

IMAGE_SUFFIXES = (
    ".iso",
    ".img",
    ".ova",
    ".raw",
    ".dmg",
    ".vmdk",
    ".vdi",
    ".qcow2",
    ".bin",
)

def has_image_suffix(path):
    cleaned = urllib.parse.unquote(path).lower().rstrip("/")
    for suffix in IMAGE_SUFFIXES:
        if cleaned.endswith(suffix):
            return True
        if cleaned.endswith(suffix + ".gz"):
            return True
        if cleaned.endswith(suffix + ".xz"):
            return True
        if cleaned.endswith(suffix + ".bz2"):
            return True
        if cleaned.endswith(suffix + ".zip"):
            return True
    return False

def resolve_archive(url):
    parsed = urllib.parse.urlparse(url)
    parts = [part for part in parsed.path.split("/") if part]
    if len(parts) < 2 or parts[0] not in ("download", "details"):
        return [url]

    if len(parts) > 2 and has_image_suffix(parts[2]):
        return [url]

    item = urllib.parse.unquote(parts[1])
    try:
        data = json.loads(fetch(f"https://archive.org/metadata/{item}"))
    except Exception:
        return [url]

    files = [
        entry.get("name", "")
        for entry in data.get("files", [])
        if has_image_suffix(entry.get("name", ""))
    ]
    if not files:
        return [url]

    return [
        "https://archive.org/download/"
        + item
        + "/"
        + urllib.parse.quote(name, safe="/")
        for name in files
    ]

def resolve_sourceforge(url):
    parsed = urllib.parse.urlparse(url)
    path = parsed.path.rstrip("/")
    if path.endswith("/download"):
        return [url]
    if has_image_suffix(path):
        return [url + "/download"]
    return [url]

def resolve_github(url):
    parsed = urllib.parse.urlparse(url)
    if "/releases/download/" in parsed.path or has_image_suffix(parsed.path):
        return [url]

    parts = [part for part in parsed.path.split("/") if part]
    if len(parts) < 2:
        return [url]

    try:
        data = json.loads(
            fetch(
                f"https://api.github.com/repos/{parts[0]}/{parts[1]}/releases",
                headers={"Accept": "application/vnd.github+json"},
            )
        )
    except Exception:
        return [url]

    found = []
    for release in data if isinstance(data, list) else []:
        for asset in release.get("assets") or []:
            link = asset.get("browser_download_url", "")
            if link and has_image_suffix(asset.get("name", "")):
                if link not in found:
                    found.append(link)
        if found:
            break
    return found or [url]

def resolve_gitlab(url):
    parsed = urllib.parse.urlparse(url)
    if "/-/blob/" in parsed.path:
        return [url.replace("/-/blob/", "/-/raw/", 1)]
    return [url]

def resolve_generic(url):
    parsed = urllib.parse.urlparse(url)
    if has_image_suffix(parsed.path):
        return [url]

    try:
        page = fetch(url, retries=2, timeout=20)
    except Exception:
        return [url]

    soup = BeautifulSoup(page, "html.parser")
    found = []
    for link in soup.select("a[href]"):
        href = urllib.parse.urljoin(url, link.get("href", "").strip())
        if has_image_suffix(urllib.parse.urlparse(href).path) and href not in found:
            found.append(href)
        if len(found) >= 40:
            break
    return found or [url]

def is_direct_link(url):
    path = urllib.parse.unquote(urllib.parse.urlparse(url).path).lower().rstrip("/")
    if path.endswith("/download"):
        path = path[: -len("/download")]
    return has_image_suffix(path)

def resolve_link(url):
    host = urllib.parse.urlparse(url).netloc.lower()
    try:
        if host == "archive.org":
            return resolve_archive(url)
        if host.endswith("sourceforge.net"):
            return resolve_sourceforge(url)
        if host == "github.com":
            return resolve_github(url)
        if host == "gitlab.com":
            return resolve_gitlab(url)
        return resolve_generic(url)
    except Exception:
        return [url]

def choose_primary(links):
    if not links:
        return ""

    def score(url):
        path = urllib.parse.unquote(url).lower()
        if path.endswith(".iso"):
            return 0
        for suffix in IMAGE_SUFFIXES[1:]:
            if path.endswith(suffix):
                return 1
        for suffix in IMAGE_SUFFIXES:
            if path.endswith(suffix + ".gz") or path.endswith(suffix + ".xz"):
                return 2
            if path.endswith(suffix + ".bz2") or path.endswith(suffix + ".zip"):
                return 2
        return 3

    return min(links, key=score)

def resolve_entry_links(entries, workers):
    urls = sorted(
        {url for entry in entries for url in (entry.get("download_urls") or [])}
    )
    total = len(urls)
    done = 0
    cache = {}

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(resolve_link, url): url for url in urls}
        for future in as_completed(futures):
            url = futures[future]
            try:
                cache[url] = future.result()
            except Exception:
                cache[url] = [url]
            done += 1
            if done % 50 == 0 or done == total:
                print(f"  liens {done}/{total}", file=sys.stderr)

    for entry in entries:
        resolved = []
        for url in entry.get("download_urls") or []:
            for link in cache.get(url, [url]):
                if is_direct_link(link) and link not in resolved:
                    resolved.append(link)
        entry["download_urls"] = resolved
        entry["download_url"] = choose_primary(resolved)

    return entries

def scrape_details(entries, workers):
    def enrich(entry):
        try:
            html = fetch(entry["url"])
        except NotFound:
            entry["error"] = "page introuvable"
            return entry
        detail = parse_detail(html, entry["url"])
        download_script = detail.pop("download_script", "")
        primary, links, error, page_url = resolve_download(download_script)
        detail["download_page"] = page_url
        detail["download_url"] = primary
        detail["download_urls"] = links
        if error:
            detail["download_error"] = error
        detail["excerpt"] = entry.get("excerpt", "")
        if not detail["name"]:
            detail["name"] = entry.get("name", "")
        if not detail["published"]:
            detail["published"] = entry.get("published", "")
        if not detail["categories"]:
            detail["categories"] = entry.get("categories", [])
        if not detail["image"]:
            detail["image"] = entry.get("image", "")
        return detail

    total = len(entries)
    completed = 0
    enriched = [None] * total

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {
            pool.submit(enrich, entry): index for index, entry in enumerate(entries)
        }
        for future in as_completed(futures):
            index = futures[future]
            try:
                enriched[index] = future.result()
            except Exception as error:
                entry = dict(entries[index])
                entry["error"] = str(error)
                enriched[index] = entry
            completed += 1
            if completed % 50 == 0 or completed == total:
                print(f"  fiches {completed}/{total}", file=sys.stderr)

    return enriched

def main():
    parser = argparse.ArgumentParser(
        description="Scrape toutes les distributions de archiveos.org vers un JSON."
    )
    parser.add_argument("-o", "--output", default=DEFAULT_OUTPUT, help="Fichier JSON")
    parser.add_argument(
        "--workers", type=int, default=8, help="Téléchargements simultanés"
    )
    parser.add_argument(
        "--start-page", type=int, default=1, help="Première page de liste"
    )
    parser.add_argument(
        "--max-pages", type=int, default=0, help="Limiter le nombre de pages (0 = tout)"
    )
    parser.add_argument(
        "--limit", type=int, default=0, help="Limiter le nombre de distributions"
    )
    parser.add_argument(
        "--no-details",
        action="store_true",
        help="Ne pas ouvrir les fiches (pas de métadonnées détaillées)",
    )
    parser.add_argument(
        "--include-articles",
        action="store_true",
        help="Inclure les articles et actualités en plus des distributions",
    )
    parser.add_argument(
        "--all-categories",
        action="store_true",
        help="Inclure aussi les distributions hors catégorie Linux",
    )
    args = parser.parse_args()

    try:
        first_html = fetch(page_url(args.start_page))
    except NotFound:
        sys.exit(f"Page introuvable : {page_url(args.start_page)}")
    except RuntimeError as error:
        sys.exit(f"Erreur : {error}")

    last_page = discover_last_page(first_html)
    if not last_page:
        sys.exit("Impossible de détecter la dernière page.")

    if args.max_pages:
        last_page = min(last_page, args.start_page + args.max_pages - 1)

    print(
        f"Pages {args.start_page} à {last_page} ({last_page - args.start_page + 1})",
        file=sys.stderr,
    )
    entries = scrape_listing(args.start_page, last_page, max(1, args.workers))

    unique = []
    seen = set()
    for entry in entries:
        if entry["url"] in seen:
            continue
        seen.add(entry["url"])
        unique.append(entry)
    entries = unique

    if not args.all_categories:
        before = len(entries)
        entries = [
            entry for entry in entries if "Linux" in (entry.get("categories") or [])
        ]
        print(f"{before - len(entries)} distributions hors Linux exclues", file=sys.stderr)

    if args.limit:
        entries = entries[: args.limit]

    print(f"{len(entries)} distributions détectées", file=sys.stderr)

    if not args.no_details:
        entries = scrape_details(entries, max(1, args.workers))
        entries = resolve_entry_links(entries, max(1, args.workers))

    if not args.include_articles:
        before = len(entries)
        entries = [
            entry
            for entry in entries
            if not ({"News", "Articles"} & set(entry.get("categories") or []))
        ]
        print(f"{before - len(entries)} articles exclus", file=sys.stderr)

    if not args.no_details:
        before = len(entries)
        entries = [entry for entry in entries if entry.get("download_url")]
        print(
            f"{before - len(entries)} distributions sans lien de téléchargement exclues",
            file=sys.stderr,
        )

    with open(args.output, "w", encoding="utf-8") as file:
        json.dump(entries, file, ensure_ascii=False, indent=2)
        file.write("\n")

    errors = sum(1 for entry in entries if entry.get("error"))
    print(f"{len(entries)} distributions, {errors} erreurs -> {args.output}")

if __name__ == "__main__":
    main()
