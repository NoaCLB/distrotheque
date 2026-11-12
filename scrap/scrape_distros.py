import argparse
import json
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

from bs4 import BeautifulSoup

ARCHIVEOS_BASE_URL = "https://archiveos.org/"
UNIX_TAG_URL = "https://archiveos.org/tag/unix/"
ISOSLINUX_BASE_URL = "https://isoslinux.com/"
DEFAULT_OUTPUT = "distros.json"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
}
OS_CATEGORIES = {"Linux", "BSD"}

DOWNLOAD_FIELDS = (
    "label",
    "group",
    "version",
    "architecture",
    "desktop",
    "purpose",
    "status",
    "health",
    "url",
)

ARCHIVEOS_META_FIELDS = (
    "slug",
    "url",
    "published",
    "author",
    "categories",
    "image",
    "last_updated",
    "website",
    "origin",
    "category",
    "desktop_environment",
    "architecture",
    "based_on",
    "wikipedia",
    "media",
    "last_version",
    "released",
    "description",
    "extra",
    "download_page",
    "download_url",
    "download_urls",
    "excerpt",
    "error",
)

NAME_STOPWORDS = {
    "a",
    "de",
    "des",
    "desktop",
    "distro",
    "distribution",
    "du",
    "edition",
    "gnu",
    "la",
    "le",
    "les",
    "linux",
    "live",
    "os",
    "project",
    "system",
    "the",
}
VERSION_TOKEN = re.compile(r"^(?:v|version)?\d+(?:[._-]\d+)*(?:[a-z]\d*)?$", re.I)

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

def archiveos_page_url(number):
    if number <= 1:
        return ARCHIVEOS_BASE_URL
    return urllib.parse.urljoin(ARCHIVEOS_BASE_URL, f"page/{number}/")

def discover_last_page(first_html):
    numbers = [
        int(match.group(1))
        for match in re.finditer(r"/page/(\d+)/", first_html)
        if match.group(1).isdigit()
    ]
    return max(numbers) if numbers else None

def unix_tag_page_url(number):
    if number <= 1:
        return UNIX_TAG_URL
    return urllib.parse.urljoin(UNIX_TAG_URL, f"page/{number}/")

def scrape_unix_urls(workers):
    try:
        first_html = fetch(UNIX_TAG_URL)
    except Exception as error:
        print(f"Tag unix inaccessible : {error}", file=sys.stderr)
        return set()

    last_page = discover_last_page(first_html) or 1

    def load(number):
        url = unix_tag_page_url(number)
        try:
            return parse_listing(fetch(url), url)
        except Exception as error:
            print(f"  tag unix page {number} ignorée : {error}", file=sys.stderr)
            return []

    urls = set()
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for posts in pool.map(load, range(1, last_page + 1)):
            urls.update(post["url"] for post in posts)

    print(f"{len(urls)} fiches taguées unix", file=sys.stderr)
    return urls

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
            html = fetch(archiveos_page_url(number))
        except NotFound:
            return number, []
        return number, parse_listing(html, archiveos_page_url(number))

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

    page_url = urllib.parse.urljoin(ARCHIVEOS_BASE_URL, match.group(1))

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

def resolve_freebsd(url):
    parsed = urllib.parse.urlparse(url)
    if has_image_suffix(parsed.path):
        return [url]

    try:
        page = fetch(url, retries=2, timeout=20)
    except Exception:
        return [url]

    soup = BeautifulSoup(page, "html.parser")
    directories = []
    for link in soup.select("a[href]"):
        href = urllib.parse.urljoin(url, link.get("href", "").strip())
        if "/ISO-IMAGES/" in href and href not in directories:
            directories.append(href)

    found = []
    for directory in directories[:3]:
        try:
            listing = fetch(directory, retries=2, timeout=20)
        except Exception:
            continue
        for link in BeautifulSoup(listing, "html.parser").select("a[href]"):
            href = urllib.parse.urljoin(directory, link.get("href", "").strip())
            if has_image_suffix(urllib.parse.urlparse(href).path) and href not in found:
                found.append(href)

    return found or [url]

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
        if host.endswith("freebsd.org"):
            return resolve_freebsd(url)
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

def scrape_archiveos_details(entries, workers):
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

def cell_text(row, css_class):
    cell = row.find("td", class_=css_class)
    return cell.get_text(" ", strip=True) if cell else ""

def parse_rows(html, base_url=ISOSLINUX_BASE_URL):
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

def scrape_isoslinux_details(entries, workers=6):
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

def name_tokens(name):
    text = unicodedata.normalize("NFKD", name or "")
    text = "".join(char for char in text if not unicodedata.combining(char))
    text = text.lower().replace("&", " and ")
    text = re.sub(r"\([^)]*\)", " ", text)
    text = re.sub(r"\[[^\]]*\]", " ", text)

    tokens = []
    for part in re.split(r"[^a-z0-9]+", text):
        if not part or part in NAME_STOPWORDS or VERSION_TOKEN.match(part):
            continue
        tokens.append(part)
    return tokens

def name_keys(name):
    tokens = name_tokens(name)
    keys = []
    if tokens:
        keys.append("t:" + " ".join(tokens))
        keys.append("c:" + "".join(tokens))
    return keys

def normalized_downloads(downloads):
    cleaned = []
    for download in downloads or []:
        item = {field: download.get(field) or "" for field in DOWNLOAD_FIELDS}
        if item["url"] and item["url"] not in {entry["url"] for entry in cleaned}:
            cleaned.append(item)
    return cleaned

def archiveos_downloads(entry):
    urls = []
    for url in [entry.get("download_url")] + list(entry.get("download_urls") or []):
        if url and url not in urls:
            urls.append(url)

    downloads = []
    for url in urls:
        filename = urllib.parse.unquote(
            urllib.parse.urlparse(url).path.rstrip("/").rsplit("/", 1)[-1]
        )
        downloads.append(
            {
                "label": filename,
                "group": "",
                "version": entry.get("last_version", ""),
                "architecture": entry.get("architecture", ""),
                "desktop": entry.get("desktop_environment", ""),
                "purpose": "",
                "status": "",
                "health": "",
                "url": url,
            }
        )
    return downloads

def archiveos_requirements(entry):
    architectures = [
        part.strip()
        for part in re.split(r"[,/]", entry.get("architecture", ""))
        if part.strip()
    ]
    website = entry.get("website", "")
    official_url = website if website.startswith("http") else ""
    if not architectures and not official_url:
        return None
    return {
        "architectures": architectures,
        "memory": "",
        "storage": "",
        "firmware": "",
        "note": "",
        "official_url": official_url,
    }

def archiveos_metadata(entry):
    return {
        field: entry[field]
        for field in ARCHIVEOS_META_FIELDS
        if field in entry
    }

def normalize_isoslinux_entry(entry):
    return {
        "name": entry.get("name", ""),
        "architectures": entry.get("architectures", ""),
        "release": entry.get("release", ""),
        "updated": entry.get("updated", ""),
        "rank": entry.get("rank", ""),
        "version": entry.get("version", ""),
        "href": entry.get("href", ""),
        "description": entry.get("description", ""),
        "downloads": normalized_downloads(entry.get("downloads")),
        "requirements": entry.get("requirements"),
        "source": "isoslinux",
    }

def normalize_archiveos_entry(entry):
    normalized = {
        "name": entry.get("name", ""),
        "architectures": entry.get("architecture", ""),
        "release": entry.get("released", ""),
        "updated": entry.get("last_updated", ""),
        "rank": "",
        "version": entry.get("last_version", ""),
        "href": entry.get("url", ""),
        "description": entry.get("description", ""),
        "downloads": normalized_downloads(archiveos_downloads(entry)),
        "requirements": archiveos_requirements(entry),
        "source": "archiveos",
        "unix": bool(entry.get("unix")),
        "archiveos": archiveos_metadata(entry),
    }
    if entry.get("error"):
        normalized["error"] = entry["error"]
    return normalized

def name_identity(name):
    text = unicodedata.normalize("NFKD", name or "")
    text = "".join(char for char in text if not unicodedata.combining(char))
    text = text.lower().replace("&", " and ")
    text = re.sub(r"\([^)]*\)", " ", text)
    text = re.sub(r"\[[^\]]*\]", " ", text)
    return " ".join(re.split(r"[^a-z0-9]+", text)).strip()

def merge_archiveos_into(target, archiveos_entry):
    target["source"] = "both"
    target["archiveos"] = archiveos_entry.get("archiveos", {})

    for field in ("architectures", "release", "updated", "version", "description"):
        if not target.get(field) and archiveos_entry.get(field):
            target[field] = archiveos_entry[field]

    if not target.get("downloads") and archiveos_entry.get("downloads"):
        target["downloads"] = archiveos_entry["downloads"]
    if not target.get("requirements") and archiveos_entry.get("requirements"):
        target["requirements"] = archiveos_entry["requirements"]
    if archiveos_entry.get("unix"):
        target["unix"] = True
    if archiveos_entry.get("error"):
        target["archiveos_error"] = archiveos_entry["error"]

def merge_distros(isoslinux_entries, archiveos_entries):
    merged = []
    index = {}

    def find(name):
        for key in name_keys(name):
            if key in index:
                return index[key]
        return None

    for raw in isoslinux_entries:
        entry = normalize_isoslinux_entry(raw)
        existing = find(entry["name"])
        if existing:
            continue
        merged.append(entry)
        for key in name_keys(entry["name"]):
            index.setdefault(key, entry)

    duplicates = 0
    for raw in archiveos_entries:
        entry = normalize_archiveos_entry(raw)
        existing = find(entry["name"])
        if (
            existing
            and entry.get("unix")
            and name_identity(existing["name"]) != name_identity(entry["name"])
        ):
            existing = None
        if existing:
            merge_archiveos_into(existing, entry)
            duplicates += 1
            continue
        merged.append(entry)

    return merged, duplicates

def scrape_isoslinux(url, workers, no_details):
    try:
        html = fetch(url)
    except RuntimeError as error:
        sys.exit(f"Erreur isoslinux : {error}")

    entries = parse_rows(html, url)
    if not entries:
        sys.exit(
            "Aucune ligne isoslinux trouvée : la structure de la page a peut-être changé."
        )

    if not no_details:
        print(
            f"Lecture des pages de détail isoslinux ({len(entries)})...",
            file=sys.stderr,
        )
        entries = scrape_isoslinux_details(entries, workers)
    return entries

def scrape_archiveos(args, workers):
    try:
        first_html = fetch(archiveos_page_url(args.start_page))
    except NotFound:
        sys.exit(f"Page archiveos introuvable : {archiveos_page_url(args.start_page)}")
    except RuntimeError as error:
        sys.exit(f"Erreur archiveos : {error}")

    last_page = discover_last_page(first_html)
    if not last_page:
        sys.exit("Impossible de détecter la dernière page d'archiveos.")

    if args.max_pages:
        last_page = min(last_page, args.start_page + args.max_pages - 1)

    print(
        f"Pages archiveos {args.start_page} à {last_page} "
        f"({last_page - args.start_page + 1})",
        file=sys.stderr,
    )
    entries = scrape_listing(args.start_page, last_page, workers)

    unique = []
    seen = set()
    for entry in entries:
        if entry["url"] in seen:
            continue
        seen.add(entry["url"])
        unique.append(entry)
    entries = unique

    unix_urls = set()
    if not args.all_categories:
        print("Lecture du tag unix archiveos...", file=sys.stderr)
        unix_urls = scrape_unix_urls(workers)

        before = len(entries)
        entries = [
            entry
            for entry in entries
            if OS_CATEGORIES & set(entry.get("categories") or [])
            or entry.get("url") in unix_urls
        ]
        print(
            f"{before - len(entries)} distributions archiveos "
            "hors Linux/BSD/Unix exclues",
            file=sys.stderr,
        )

    if args.limit:
        entries = entries[: args.limit]

    print(f"{len(entries)} distributions archiveos détectées", file=sys.stderr)

    if not args.no_details:
        entries = scrape_archiveos_details(entries, workers)
        entries = resolve_entry_links(entries, workers)

    for entry in entries:
        categories = set(entry.get("categories") or [])
        entry["unix"] = (
            entry.get("url") in unix_urls
            and not (OS_CATEGORIES & categories)
        )

    if not args.include_articles:
        before = len(entries)
        entries = [
            entry
            for entry in entries
            if not ({"News", "Articles"} & set(entry.get("categories") or []))
        ]
        print(f"{before - len(entries)} articles archiveos exclus", file=sys.stderr)

    if not args.no_details:
        before = len(entries)
        entries = [
            entry
            for entry in entries
            if entry.get("download_url")
        ]
        print(
            f"{before - len(entries)} distributions archiveos sans lien "
            "de téléchargement exclues",
            file=sys.stderr,
        )

    return entries

def main():
    parser = argparse.ArgumentParser(
        description=(
            "Scrape archiveos.org et isoslinux.com puis fusionne le résultat "
            "dans un seul JSON au format isoslinux."
        )
    )
    parser.add_argument("-o", "--output", default=DEFAULT_OUTPUT, help="Fichier JSON")
    parser.add_argument(
        "--workers", type=int, default=8, help="Téléchargements simultanés"
    )
    parser.add_argument(
        "--isos-url", default=ISOSLINUX_BASE_URL, help="URL du catalogue isoslinux"
    )
    parser.add_argument(
        "--start-page", type=int, default=1, help="Première page archiveos"
    )
    parser.add_argument(
        "--max-pages",
        type=int,
        default=0,
        help="Limiter le nombre de pages archiveos (0 = tout)",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Limiter le nombre de distributions archiveos",
    )
    parser.add_argument(
        "--no-details",
        action="store_true",
        help="Ne pas ouvrir les fiches (pas de métadonnées ni de liens ISO)",
    )
    parser.add_argument(
        "--include-articles",
        action="store_true",
        help="Inclure les articles et actualités archiveos",
    )
    parser.add_argument(
        "--all-categories",
        action="store_true",
        help="Inclure aussi les distributions archiveos hors Linux/BSD/Unix",
    )
    parser.add_argument(
        "--skip-isoslinux",
        action="store_true",
        help="Ne pas scraper isoslinux.com",
    )
    parser.add_argument(
        "--skip-archiveos",
        action="store_true",
        help="Ne pas scraper archiveos.org",
    )
    args = parser.parse_args()

    workers = max(1, args.workers)
    isoslinux_entries = []
    archiveos_entries = []

    if not args.skip_isoslinux:
        print("Scraping isoslinux.com...", file=sys.stderr)
        isoslinux_entries = scrape_isoslinux(args.isos_url, workers, args.no_details)
        print(f"{len(isoslinux_entries)} distributions isoslinux", file=sys.stderr)

    if not args.skip_archiveos:
        print("Scraping archiveos.org...", file=sys.stderr)
        archiveos_entries = scrape_archiveos(args, workers)

    merged, duplicates = merge_distros(isoslinux_entries, archiveos_entries)

    before = len(merged)
    if not args.no_details:
        merged = [entry for entry in merged if entry.get("downloads")]
    if before != len(merged):
        print(
            f"{before - len(merged)} distributions sans ISO exclues",
            file=sys.stderr,
        )

    with open(args.output, "w", encoding="utf-8") as file:
        json.dump(merged, file, ensure_ascii=False, indent=2)
        file.write("\n")

    total_downloads = sum(len(entry.get("downloads") or []) for entry in merged)
    print(
        f"{len(merged)} distributions ({duplicates} doublons fusionnés), "
        f"{total_downloads} liens ISO -> {args.output}"
    )

if __name__ == "__main__":
    main()
