-- ===========================================
-- Experiment Engine Schema (A.1)
-- A/B Testing & Conversion Optimization Engine
-- Run after Phase 2-4 schema
-- ===========================================

-- ===========================================
-- 1. Experiments
-- ===========================================
CREATE TABLE IF NOT EXISTS experiments (
    id VARCHAR(36) PRIMARY KEY,
    key_slug VARCHAR(80) UNIQUE NOT NULL,          -- referenced in code: 'trust_hero_v1'
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    surface VARCHAR(80) NOT NULL,                  -- 'sales_trust','quiz_landing','checkout','pricing','signup'
    status ENUM('draft','running','paused','shipped','archived') DEFAULT 'draft',
    allocation_mode ENUM('bandit','fixed') DEFAULT 'bandit',
    primary_goal_event VARCHAR(50) NOT NULL,       -- analytics event = conversion, e.g. 'payment_success'
    guardrail_events JSON NULL,                    -- ["payment_success"] when primary is upstream
    targeting JSON NULL,                           -- {sources:[],devices:[],countries:[],quiz_profiles:[]}
    exploration_floor DECIMAL(4,3) DEFAULT 0.05,  -- min traffic share per variant
    min_samples_per_variant INTEGER DEFAULT 300,  -- gate before auto-decisions
    auto_promote TINYINT(1) DEFAULT 0,            -- ship winner automatically when confident
    confidence_threshold DECIMAL(4,3) DEFAULT 0.95,-- prob-to-best needed to declare winner
    holdout TINYINT(1) DEFAULT 0,                  -- reserve global holdout (no exposure)
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    created_by VARCHAR(36) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_surface (surface)
) ENGINE=InnoDB;

-- ===========================================
-- 2. Experiment Variants
-- ===========================================
CREATE TABLE IF NOT EXISTS experiment_variants (
    id VARCHAR(36) PRIMARY KEY,
    experiment_id VARCHAR(36) NOT NULL,
    key_slug VARCHAR(80) NOT NULL,                 -- 'control','headline_b'
    name VARCHAR(150) NOT NULL,
    is_control TINYINT(1) DEFAULT 0,
    config JSON NULL,                               -- variant payload: {headline, cta_text, price, etc.}
    fixed_weight DECIMAL(5,4) NULL,                -- used only when allocation_mode='fixed'
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exp_variant (experiment_id, key_slug),
    FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 3. Experiment Assignments (sticky visitor→variant)
-- ===========================================
CREATE TABLE IF NOT EXISTS experiment_assignments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    experiment_id VARCHAR(36) NOT NULL,
    variant_id VARCHAR(36) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,               -- zen_vid cookie value
    user_id VARCHAR(36) NULL,                       -- backfilled on login/signup
    segment_key VARCHAR(120) NULL,                  -- 'src:meta|dev:mobile' for contextual stats
    context JSON NULL,                              -- raw context at assignment time
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_visitor_exp (experiment_id, visitor_id),
    INDEX idx_visitor (visitor_id),
    INDEX idx_variant (variant_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES experiment_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 4. Experiment Events (raw exposures & conversions)
-- ===========================================
CREATE TABLE IF NOT EXISTS experiment_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    experiment_id VARCHAR(36) NOT NULL,
    variant_id VARCHAR(36) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(50) NOT NULL,               -- 'exposure' or the analytics event name
    is_goal TINYINT(1) DEFAULT 0,                   -- 1 if this event = the experiment's primary goal
    is_guardrail TINYINT(1) DEFAULT 0,
    value_cents INTEGER DEFAULT 0,                  -- revenue for payment_success events
    segment_key VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exp_variant_type (experiment_id, variant_id, event_type),
    INDEX idx_visitor (visitor_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ===========================================
-- 5. Experiment Segment Stats (pre-aggregated)
-- ===========================================
CREATE TABLE IF NOT EXISTS experiment_segment_stats (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    experiment_id VARCHAR(36) NOT NULL,
    variant_id VARCHAR(36) NOT NULL,
    segment_key VARCHAR(120) NOT NULL DEFAULT 'global',
    exposures INTEGER DEFAULT 0,
    conversions INTEGER DEFAULT 0,
    revenue_cents BIGINT DEFAULT 0,
    funnel_counts JSON NULL,                       -- {quiz_start:N, lead_captured:N, checkout_open:N, payment_success:N}
    alpha DECIMAL(12,4) DEFAULT 1,                  -- Beta posterior α (cached)
    beta DECIMAL(12,4) DEFAULT 1,                   -- Beta posterior β (cached)
    prob_best DECIMAL(6,5) DEFAULT 0,               -- probability this variant is best (in segment)
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exp_var_seg (experiment_id, variant_id, segment_key),
    INDEX idx_exp_seg (experiment_id, segment_key)
) ENGINE=InnoDB;

-- ===========================================
-- 6. Experiment Decisions (audit log)
-- ===========================================
CREATE TABLE IF NOT EXISTS experiment_decisions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    experiment_id VARCHAR(36) NOT NULL,
    decision_type ENUM('started','paused','resumed','shipped','rolled_back','srm_alert','auto_promote_suggested') NOT NULL,
    winning_variant_id VARCHAR(36) NULL,
    actor VARCHAR(36) NULL,                          -- admin user_id or 'engine'
    rationale JSON NULL,                            -- {prob_best, uplift, samples, p_value}
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exp (experiment_id)
) ENGINE=InnoDB;

SELECT 'Experiment engine schema migration completed successfully!' AS status;
