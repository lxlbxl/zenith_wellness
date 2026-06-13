-- ===========================================
-- Phase 1 Schema Migration
-- Payment quotes, discount codes, and user/lead extensions
-- ===========================================

-- ===========================================
-- 1. Discount Codes
-- ===========================================
CREATE TABLE IF NOT EXISTS discount_codes (
    id VARCHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(10,2) NOT NULL,
    max_uses INTEGER DEFAULT NULL,
    used_count INTEGER DEFAULT 0,
    cohort_id VARCHAR(50) DEFAULT NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_cohort (cohort_id)
) ENGINE=InnoDB;

-- ===========================================
-- 2. Payment Quotes
-- ===========================================
CREATE TABLE IF NOT EXISTS payment_quotes (
    id VARCHAR(36) PRIMARY KEY,
    cohort_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(36) DEFAULT NULL,
    amount INTEGER NOT NULL COMMENT 'Base price in USD cents',
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    converted_amount INTEGER NOT NULL COMMENT 'Final amount in target currency cents',
    discount_code VARCHAR(50) DEFAULT NULL,
    order_bump_amount INTEGER DEFAULT 0,
    signature VARCHAR(64) NOT NULL COMMENT 'HMAC-SHA256 quote signature',
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_cohort (cohort_id)
) ENGINE=InnoDB;

-- ===========================================
-- 3. ALTER TABLE users — Add quiz/onboarding columns
-- ===========================================
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS quiz_profile VARCHAR(30) NULL AFTER persona,
    ADD COLUMN IF NOT EXISTS quiz_answers JSON NULL AFTER quiz_profile,
    ADD COLUMN IF NOT EXISTS quiz_lead_id VARCHAR(36) NULL AFTER quiz_answers,
    ADD COLUMN IF NOT EXISTS onboarding_completed_at TIMESTAMP NULL AFTER quiz_lead_id,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(60) DEFAULT 'UTC' AFTER onboarding_completed_at,
    ADD COLUMN IF NOT EXISTS completed_cohorts INTEGER DEFAULT 0 AFTER timezone;

-- ===========================================
-- 4. ALTER TABLE leads — Add quiz/checkout columns
-- ===========================================
ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS quiz_profile VARCHAR(30) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS quiz_answers JSON NULL AFTER quiz_profile,
    ADD COLUMN IF NOT EXISTS checkout_opened_at TIMESTAMP NULL AFTER quiz_answers,
    ADD COLUMN IF NOT EXISTS checkout_recovery_enrolled TINYINT(1) DEFAULT 0 AFTER checkout_opened_at;

SELECT 'Phase 1 schema migration completed successfully!' AS status;
