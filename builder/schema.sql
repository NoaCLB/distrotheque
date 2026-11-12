DROP TABLE IF EXISTS distros;
DROP TABLE IF EXISTS downloads;
DROP TABLE IF EXISTS distro_architectures;
DROP TABLE IF EXISTS meta;

CREATE TABLE distros (
    id             INTEGER PRIMARY KEY,
    slug           TEXT    NOT NULL UNIQUE,
    name           TEXT    NOT NULL,
    name_norm      TEXT    NOT NULL,
    version        TEXT    NOT NULL DEFAULT '',
    release_name   TEXT    NOT NULL DEFAULT '',
    architectures  TEXT    NOT NULL DEFAULT '',
    source         TEXT    NOT NULL DEFAULT '',
    href           TEXT    NOT NULL DEFAULT '',
    website        TEXT    NOT NULL DEFAULT '',
    official_url   TEXT    NOT NULL DEFAULT '',
    description    TEXT    NOT NULL DEFAULT '',
    description_norm TEXT  NOT NULL DEFAULT '',
    search_norm    TEXT    NOT NULL DEFAULT '',
    keywords       TEXT    NOT NULL DEFAULT '',
    updated_raw    TEXT    NOT NULL DEFAULT '',
    updated_at     TEXT,
    popularity     INTEGER,
    memory         TEXT    NOT NULL DEFAULT '',
    storage        TEXT    NOT NULL DEFAULT '',
    firmware       TEXT    NOT NULL DEFAULT '',
    requirements_note TEXT NOT NULL DEFAULT '',
    archiveos_json TEXT    NOT NULL DEFAULT '{}',
    iso_count      INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_distros_name_norm ON distros(name_norm);
CREATE INDEX idx_distros_updated   ON distros(updated_at DESC);
CREATE INDEX idx_distros_iso_count ON distros(iso_count DESC);
CREATE INDEX idx_distros_source    ON distros(source);

CREATE TABLE downloads (
    id           INTEGER PRIMARY KEY,
    distro_id    INTEGER NOT NULL REFERENCES distros(id) ON DELETE CASCADE,
    label        TEXT NOT NULL DEFAULT '',
    variant_group TEXT NOT NULL DEFAULT '',
    version      TEXT NOT NULL DEFAULT '',
    architecture TEXT NOT NULL DEFAULT '',
    desktop      TEXT NOT NULL DEFAULT '',
    purpose      TEXT NOT NULL DEFAULT '',
    status       TEXT NOT NULL DEFAULT '',
    health       TEXT NOT NULL DEFAULT '',
    url          TEXT NOT NULL,
    host         TEXT NOT NULL DEFAULT ''
);

CREATE INDEX idx_downloads_distro ON downloads(distro_id);
CREATE UNIQUE INDEX idx_downloads_unique ON downloads(distro_id, url);

CREATE TABLE distro_architectures (
    distro_id INTEGER NOT NULL REFERENCES distros(id) ON DELETE CASCADE,
    arch      TEXT NOT NULL,
    PRIMARY KEY (distro_id, arch)
);

CREATE INDEX idx_architectures_arch ON distro_architectures(arch);

CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
