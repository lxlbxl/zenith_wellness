-- Migration: Add user_id to experiment_events for visitor identification
-- This enables event tracking to authenticated users after login/signup

ALTER TABLE experiment_events ADD COLUMN user_id VARCHAR(36) NULL;

-- Index for user-based event queries (e.g., timeline per user)
CREATE INDEX IF NOT EXISTS idx_events_user ON experiment_events (user_id);

SELECT 'Migration 003: user_id added to experiment_events' AS status;
