-- HotFetched schema (documentation only — the app creates/migrates schema in bootstrap.php)

CREATE TABLE IF NOT EXISTS projects (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL UNIQUE,
    firmware     TEXT NOT NULL CHECK(firmware IN ('marlin','klipper','reprap')),
    board_id     TEXT NOT NULL,                  -- e.g. 'btt_skr_v3_0'
    mcu_variant  TEXT,                           -- e.g. 'STM32H743VI' | 'STM32H723VG'
    source_type  TEXT CHECK(source_type IN ('github','zip')),
    source_ref   TEXT,                           -- github url#ref, or original zip filename
    source_state TEXT NOT NULL DEFAULT 'none'
                 CHECK(source_state IN ('none','fetching','ready','error')),
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS config_values (
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    field_key   TEXT NOT NULL,                   -- 'MOTHERBOARD', 'X_BED_SIZE', ...
    field_value TEXT NOT NULL,
    updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (project_id, field_key)
);

CREATE TABLE IF NOT EXISTS builds (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id    INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    status        TEXT NOT NULL DEFAULT 'queued'
                  CHECK(status IN ('queued','validating','building','success','failed')),
    confidence    INTEGER,                       -- 0-100 gate score
    gate_json     TEXT,                          -- per-gate pass/fail detail
    log_path      TEXT,
    artifact_path TEXT,
    started_at    TEXT,
    finished_at   TEXT
);

CREATE INDEX IF NOT EXISTS idx_builds_project ON builds(project_id, id DESC);
