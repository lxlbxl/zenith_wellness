-- ===========================================
-- Variant Safety & Insights Schema (B.4, B.5)
-- Run after variant_generation_schema.sql
-- ===========================================

USE zenith_wellness;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================================
-- 1. Variant Safety Log
-- Records every safety check run (deterministic + AI)
-- for audit trail and tuning.
-- ===========================================
DROP TABLE IF EXISTS variant_safety_log;
CREATE TABLE variant_safety_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    candidate_id VARCHAR(36) NOT NULL,
    check_type ENUM('deterministic', 'ai_review') NOT NULL,
    verdict ENUM('clean', 'needs_review', 'reject') NOT NULL,
    details JSON NULL,                               -- matched forbidden claims, flagged phrases
    model_used VARCHAR(60) NULL,                     -- for ai_review checks
    latency_ms INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_candidate (candidate_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (candidate_id) REFERENCES variant_candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Variant safety & insights schema created successfully!' AS status;
