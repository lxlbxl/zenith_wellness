-- ===========================================
-- AI Variant Generation Schema (B.1)
-- Run after experiment_engine_schema.sql
-- ===========================================

USE zenith_wellness;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================================
-- 1. Variant Briefs
-- Brand/voice/constraint brief per surface — the
-- "system knowledge" the generator uses.
-- ===========================================
DROP TABLE IF EXISTS variant_briefs;
CREATE TABLE variant_briefs (
    id VARCHAR(36) PRIMARY KEY,
    surface VARCHAR(80) NOT NULL,                   -- 'sales_trust','quiz_landing','checkout','pricing','signup'
    brand_voice TEXT NOT NULL,                      -- tone, do's/don'ts, vocabulary
    value_props JSON NOT NULL,                      -- core promises the copy may lean on
    forbidden_claims JSON NOT NULL,                 -- medical/legal lines the AI must never cross
    target_personas JSON NULL,                      -- ['hormonal_warrior','metabolic_reset',...]
    reference_winners JSON NULL,                    -- exemplar winning copy snippets (seed)
    config_schema JSON NOT NULL,                    -- the exact fields a variant config must contain
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_surface (surface)
) ENGINE=InnoDB;

-- ===========================================
-- 2. Variant Generations
-- Every AI generation run (for audit + cost
-- tracking + reproducibility).
-- ===========================================
DROP TABLE IF EXISTS variant_generations;
CREATE TABLE variant_generations (
    id VARCHAR(36) PRIMARY KEY,
    experiment_id VARCHAR(36) NULL,                 -- null if generating for a brand-new experiment
    surface VARCHAR(80) NOT NULL,
    segment_key VARCHAR(120) NULL,                  -- generated specifically for this segment if set
    brief_id VARCHAR(36) NOT NULL,
    prompt_used TEXT NOT NULL,                      -- exact prompt sent (reproducibility)
    insights_injected JSON NULL,                    -- which learnings were fed in
    model VARCHAR(60) NOT NULL,
    raw_response TEXT NULL,
    candidate_count INTEGER DEFAULT 0,
    tokens_used INTEGER DEFAULT 0,
    actor VARCHAR(36) NULL,                          -- admin who triggered, or 'engine'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_surface (surface),
    INDEX idx_experiment (experiment_id)
) ENGINE=InnoDB;

-- ===========================================
-- 3. Variant Candidates
-- Individual AI-generated candidate variants
-- awaiting admin review.
-- ===========================================
DROP TABLE IF EXISTS variant_candidates;
CREATE TABLE variant_candidates (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL,
    experiment_id VARCHAR(36) NULL,
    config JSON NOT NULL,                            -- the generated variant payload
    rationale TEXT NULL,                             -- why the AI thinks this will convert
    pattern_tags JSON NULL,                         -- ['question_headline','loss_framed','short','emoji']
    safety_flag ENUM('clean','needs_review','rejected') DEFAULT 'needs_review',
    safety_notes TEXT NULL,                          -- output of the automated claims check
    review_status ENUM('pending','approved','rejected','edited') DEFAULT 'pending',
    edited_config JSON NULL,                         -- admin's edited version if changed before approving
    promoted_variant_id VARCHAR(36) NULL,           -- the experiment_variants.id once approved into a test
    reviewed_by VARCHAR(36) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_generation (generation_id),
    INDEX idx_review (review_status),
    FOREIGN KEY (generation_id) REFERENCES variant_generations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 4. Variant Insights
-- The learning memory: structured insights
-- extracted from completed experiments.
-- ===========================================
DROP TABLE IF EXISTS variant_insights;
CREATE TABLE variant_insights (
    id VARCHAR(36) PRIMARY KEY,
    surface VARCHAR(80) NOT NULL,
    segment_key VARCHAR(120) NULL,                  -- insight may be segment-specific
    pattern_tag VARCHAR(60) NOT NULL,               -- 'question_headline','loss_framed','payment_plan_anchor'
    direction ENUM('lifts','hurts','neutral') NOT NULL,
    effect_size DECIMAL(6,3) NULL,                  -- observed relative lift/drop (e.g., 0.14 = +14%)
    confidence DECIMAL(4,3) NULL,                   -- prob-best/credibility behind this insight
    sample_size INTEGER NULL,
    source_experiment_id VARCHAR(36) NULL,
    summary TEXT NOT NULL,                           -- human + AI readable summary
    weight DECIMAL(4,3) DEFAULT 1.0,                -- decays over time; recent insights weighted higher
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_surface_seg (surface, segment_key),
    INDEX idx_pattern (pattern_tag)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Variant generation schema created successfully!' AS status;
