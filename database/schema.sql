CREATE TABLE IF NOT EXISTS pulse_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    value REAL NOT NULL,
    tags TEXT NOT NULL DEFAULT '{}',
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_spans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    duration_ms REAL NOT NULL,
    memory_bytes INTEGER NOT NULL DEFAULT 0,
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    trace_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class TEXT NOT NULL DEFAULT '',
    fingerprint TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    file TEXT NOT NULL,
    line INTEGER NOT NULL,
    trace TEXT NOT NULL,
    method TEXT NOT NULL DEFAULT '',
    unhandled INTEGER NOT NULL DEFAULT 0,
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    trace_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL,
    method TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    duration_ms REAL NOT NULL,
    memory_bytes INTEGER NOT NULL,
    ip TEXT,
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    trace_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_catalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hash TEXT NOT NULL UNIQUE,
    normalized_sql TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_queries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    catalog_id INTEGER NOT NULL,
    duration_ms REAL NOT NULL,
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    trace_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (catalog_id) REFERENCES pulse_catalog(id)
);

CREATE TABLE IF NOT EXISTS pulse_outbound_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service TEXT NOT NULL DEFAULT 'default',
    route TEXT NOT NULL DEFAULT '/',
    method TEXT NOT NULL,
    status_code INTEGER NOT NULL DEFAULT 0,
    url TEXT NOT NULL,
    duration_ms REAL NOT NULL,
    trace_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pulse_alert_state (
    rule TEXT PRIMARY KEY,
    last_triggered_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pulse_metrics_name_created_at
    ON pulse_metrics(name, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_queries_catalog_id
    ON pulse_queries(catalog_id);
CREATE INDEX IF NOT EXISTS idx_pulse_requests_service_route_created_at
    ON pulse_requests(service, route, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_metrics_service_route_created_at
    ON pulse_metrics(service, route, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_spans_service_route_created_at
    ON pulse_spans(service, route, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_exceptions_service_route_created_at
    ON pulse_exceptions(service, route, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_queries_service_route_created_at
    ON pulse_queries(service, route, created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_outbound_service_route_created_at
    ON pulse_outbound_requests(service, route, created_at);

-- Retention: pruning deletes by created_at only.
CREATE INDEX IF NOT EXISTS idx_pulse_metrics_created_at ON pulse_metrics(created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_spans_created_at ON pulse_spans(created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_exceptions_created_at ON pulse_exceptions(created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_requests_created_at ON pulse_requests(created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_queries_created_at ON pulse_queries(created_at);
CREATE INDEX IF NOT EXISTS idx_pulse_outbound_created_at ON pulse_outbound_requests(created_at);

-- Trace correlation (request detail page and N+1 detection).
CREATE INDEX IF NOT EXISTS idx_pulse_requests_trace_id ON pulse_requests(trace_id);
CREATE INDEX IF NOT EXISTS idx_pulse_spans_trace_id ON pulse_spans(trace_id);
CREATE INDEX IF NOT EXISTS idx_pulse_exceptions_trace_id ON pulse_exceptions(trace_id);
CREATE INDEX IF NOT EXISTS idx_pulse_queries_trace_id_catalog_id ON pulse_queries(trace_id, catalog_id);
CREATE INDEX IF NOT EXISTS idx_pulse_outbound_trace_id ON pulse_outbound_requests(trace_id);

-- Exception grouping.
CREATE INDEX IF NOT EXISTS idx_pulse_exceptions_fingerprint_created_at
    ON pulse_exceptions(fingerprint, created_at);
