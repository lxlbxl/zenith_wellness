-- SQLite-compatible experiment engine schema (for local dev/testing)

CREATE TABLE IF NOT EXISTS experiments (
    id TEXT PRIMARY KEY,
    key_slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    surface TEXT NOT NULL,
    status TEXT DEFAULT 'draft',
    allocation_mode TEXT DEFAULT 'bandit',
    primary_goal_event TEXT NOT NULL,
    guardrail_events TEXT,
    targeting TEXT,
    exploration_floor REAL DEFAULT 0.05,
    min_samples_per_variant INTEGER DEFAULT 300,
    auto_promote INTEGER DEFAULT 0,
    confidence_threshold REAL DEFAULT 0.95,
    holdout INTEGER DEFAULT 0,
    started_at TEXT,
    ended_at TEXT,
    created_by TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS experiment_variants (
    id TEXT PRIMARY KEY,
    experiment_id TEXT NOT NULL,
    key_slug TEXT NOT NULL,
    name TEXT NOT NULL,
    is_control INTEGER DEFAULT 0,
    config TEXT,
    fixed_weight REAL,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE (experiment_id, key_slug),
    FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS experiment_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id TEXT NOT NULL,
    variant_id TEXT NOT NULL,
    visitor_id TEXT NOT NULL,
    user_id TEXT,
    segment_key TEXT,
    context TEXT,
    assigned_at TEXT DEFAULT (datetime('now')),
    UNIQUE (experiment_id, visitor_id),
    FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES experiment_variants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id TEXT NOT NULL,
    variant_id TEXT NOT NULL,
    visitor_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    is_goal INTEGER DEFAULT 0,
    is_guardrail INTEGER DEFAULT 0,
    value_cents INTEGER DEFAULT 0,
    segment_key TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS experiment_segment_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id TEXT NOT NULL,
    variant_id TEXT NOT NULL,
    segment_key TEXT DEFAULT 'global',
    exposures INTEGER DEFAULT 0,
    conversions INTEGER DEFAULT 0,
    revenue_cents INTEGER DEFAULT 0,
    funnel_counts TEXT,
    alpha REAL DEFAULT 1,
    beta REAL DEFAULT 1,
    prob_best REAL DEFAULT 0,
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE (experiment_id, variant_id, segment_key)
);

CREATE TABLE IF NOT EXISTS experiment_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id TEXT NOT NULL,
    decision_type TEXT NOT NULL,
    winning_variant_id TEXT,
    actor TEXT,
    rationale TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_exp_status ON experiments(status);
CREATE INDEX IF NOT EXISTS idx_exp_surface ON experiments(surface);
CREATE INDEX IF NOT EXISTS idx_assignments_visitor ON experiment_assignments(visitor_id);
CREATE INDEX IF NOT EXISTS idx_assignments_variant ON experiment_assignments(variant_id);
CREATE INDEX IF NOT EXISTS idx_assignments_user ON experiment_assignments(user_id);
CREATE INDEX IF NOT EXISTS idx_events_exp_type ON experiment_events(experiment_id, variant_id, event_type);
CREATE INDEX IF NOT EXISTS idx_events_visitor ON experiment_events(visitor_id);
CREATE INDEX IF NOT EXISTS idx_events_created ON experiment_events(created_at);
