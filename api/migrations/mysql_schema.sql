-- ===========================================
-- Zenith Wellness MySQL 8.0 Schema
-- Run: mysql -u root -p < api/migrations/mysql_schema.sql
-- ===========================================

CREATE DATABASE IF NOT EXISTS zenith_wellness CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zenith_wellness;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================================
-- 1. Users Table
-- ===========================================
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    persona VARCHAR(20) DEFAULT 'newbie',
    role VARCHAR(20) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_persona (persona)
) ENGINE=InnoDB;

-- ===========================================
-- 2. User Stats
-- ===========================================
DROP TABLE IF EXISTS user_stats;
CREATE TABLE user_stats (
    user_id VARCHAR(36) PRIMARY KEY,
    focus_minutes INTEGER DEFAULT 0,
    mood_history TEXT,
    macros TEXT,
    goals TEXT,
    purchased_programs TEXT,
    display_currency VARCHAR(3) DEFAULT 'USD',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 3. Cycle Logs
-- ===========================================
DROP TABLE IF EXISTS cycle_logs;
CREATE TABLE cycle_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    symptoms TEXT,
    flow_intensity VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 4. Cohorts (Programs)
-- ===========================================
DROP TABLE IF EXISTS cohorts;
CREATE TABLE cohorts (
    id VARCHAR(50) PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    start_date DATE,
    category VARCHAR(50),
    image_url VARCHAR(255),
    objectives TEXT,
    max_participants INTEGER DEFAULT NULL,
    status ENUM('upcoming','active','completed','cancelled') DEFAULT 'upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 5. Cohort Progress (User tracking)
-- ===========================================
DROP TABLE IF EXISTS cohort_progress;
CREATE TABLE cohort_progress (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    program_id VARCHAR(50) NOT NULL,
    current_day INTEGER DEFAULT 1,
    tasks TEXT,
    performance_score INTEGER DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 6. Cohort Messages (Chat)
-- ===========================================
DROP TABLE IF EXISTS cohort_messages;
CREATE TABLE cohort_messages (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    program_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    user_name VARCHAR(100),
    user_rank VARCHAR(20),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 7. Meal Logs
-- ===========================================
DROP TABLE IF EXISTS meal_logs;
CREATE TABLE meal_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    food_items TEXT,
    macros TEXT,
    wellness_score INTEGER DEFAULT 0,
    summary TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 8. Chat Sessions
-- ===========================================
DROP TABLE IF EXISTS chat_sessions;
CREATE TABLE chat_sessions (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    messages TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 9. Password Resets
-- ===========================================
DROP TABLE IF EXISTS password_resets;
CREATE TABLE password_resets (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ===========================================
-- 10. Routine Templates
-- ===========================================
DROP TABLE IF EXISTS routine_templates;
CREATE TABLE routine_templates (
    id VARCHAR(36) PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    type VARCHAR(20) DEFAULT 'daily',
    cycle_phase VARCHAR(20),
    category VARCHAR(50),
    duration_minutes INTEGER,
    is_system INTEGER DEFAULT 1,
    created_by VARCHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 11. Routine Template Items
-- ===========================================
DROP TABLE IF EXISTS routine_template_items;
CREATE TABLE routine_template_items (
    id VARCHAR(36) PRIMARY KEY,
    template_id VARCHAR(36) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    duration_minutes INTEGER,
    order_index INTEGER NOT NULL,
    is_optional INTEGER DEFAULT 0,
    icon VARCHAR(50),
    FOREIGN KEY (template_id) REFERENCES routine_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 12. User Routines
-- ===========================================
DROP TABLE IF EXISTS user_routines;
CREATE TABLE user_routines (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    template_id VARCHAR(36),
    title VARCHAR(100) NOT NULL,
    type VARCHAR(20) DEFAULT 'daily',
    schedule_days TEXT,
    cycle_phase VARCHAR(20),
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 13. User Routine Items
-- ===========================================
DROP TABLE IF EXISTS user_routine_items;
CREATE TABLE user_routine_items (
    id VARCHAR(36) PRIMARY KEY,
    routine_id VARCHAR(36) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    duration_minutes INTEGER,
    order_index INTEGER NOT NULL,
    icon VARCHAR(50),
    FOREIGN KEY (routine_id) REFERENCES user_routines(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 14. Routine Completions
-- ===========================================
DROP TABLE IF EXISTS routine_completions;
CREATE TABLE routine_completions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    routine_id VARCHAR(36) NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    items_completed TEXT,
    total_items INTEGER,
    completion_percent INTEGER,
    duration_actual INTEGER,
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 15. Habit Templates
-- ===========================================
DROP TABLE IF EXISTS habit_templates;
CREATE TABLE habit_templates (
    id VARCHAR(36) PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    icon VARCHAR(50),
    recommended_frequency VARCHAR(20) DEFAULT 'daily',
    is_system INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 16. User Habits
-- ===========================================
DROP TABLE IF EXISTS user_habits;
CREATE TABLE user_habits (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    template_id VARCHAR(36),
    title VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    icon VARCHAR(50),
    frequency VARCHAR(20) DEFAULT 'daily',
    target_days TEXT,
    is_active INTEGER DEFAULT 1,
    current_streak INTEGER DEFAULT 0,
    longest_streak INTEGER DEFAULT 0,
    total_completions INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 17. Habit Logs
-- ===========================================
DROP TABLE IF EXISTS habit_logs;
CREATE TABLE habit_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    habit_id VARCHAR(36) NOT NULL,
    log_date DATE NOT NULL,
    completed INTEGER DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (habit_id) REFERENCES user_habits(id) ON DELETE CASCADE,
    UNIQUE(habit_id, log_date),
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB;

-- ===========================================
-- 18. Daily Wellness Logs
-- ===========================================
DROP TABLE IF EXISTS daily_wellness_logs;
CREATE TABLE daily_wellness_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    log_date DATE NOT NULL,
    water_glasses INTEGER DEFAULT 0,
    sleep_hours DECIMAL(3,1) DEFAULT 0,
    sleep_quality VARCHAR(20),
    energy_level INTEGER,
    stress_level INTEGER,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, log_date),
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB;

-- ===========================================
-- 18b. Daily Symptom Logs
-- ===========================================
DROP TABLE IF EXISTS daily_symptom_logs;
CREATE TABLE daily_symptom_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    log_date DATE NOT NULL,
    symptoms TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, log_date)
) ENGINE=InnoDB;

-- ===========================================
-- 19. Exercise Library
-- ===========================================
DROP TABLE IF EXISTS exercise_library;
CREATE TABLE exercise_library (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    muscle_group VARCHAR(50),
    difficulty VARCHAR(20),
    description TEXT,
    is_cardio INTEGER DEFAULT 0,
    is_strength INTEGER DEFAULT 0,
    equipment_needed TEXT,
    instructions TEXT,
    is_system INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 20. Workout Logs
-- ===========================================
DROP TABLE IF EXISTS workout_logs;
CREATE TABLE workout_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    exercise_id VARCHAR(36),
    exercise_name VARCHAR(100) NOT NULL,
    workout_type VARCHAR(50),
    duration_minutes INTEGER,
    calories_burned INTEGER,
    sets INTEGER,
    reps INTEGER,
    weight_kg DECIMAL(5,2),
    distance_km DECIMAL(5,2),
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    workout_date DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 21. Personal Goals
-- ===========================================
DROP TABLE IF EXISTS personal_goals;
CREATE TABLE personal_goals (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    target_value DECIMAL(10,2),
    current_value DECIMAL(10,2) DEFAULT 0,
    unit VARCHAR(20),
    start_date DATE NOT NULL,
    target_date DATE,
    status VARCHAR(20) DEFAULT 'active',
    priority VARCHAR(20) DEFAULT 'medium',
    is_public INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 22. Goal Milestones
-- ===========================================
DROP TABLE IF EXISTS goal_milestones;
CREATE TABLE goal_milestones (
    id VARCHAR(36) PRIMARY KEY,
    goal_id VARCHAR(36) NOT NULL,
    title VARCHAR(100) NOT NULL,
    target_value DECIMAL(10,2) NOT NULL,
    achieved INTEGER DEFAULT 0,
    achieved_at TIMESTAMP NULL,
    FOREIGN KEY (goal_id) REFERENCES personal_goals(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 23. Goal Progress Logs
-- ===========================================
DROP TABLE IF EXISTS goal_progress_logs;
CREATE TABLE goal_progress_logs (
    id VARCHAR(36) PRIMARY KEY,
    goal_id VARCHAR(36) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id) REFERENCES personal_goals(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 24. Journal Entries
-- ===========================================
DROP TABLE IF EXISTS journal_entries;
CREATE TABLE journal_entries (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    title VARCHAR(200),
    content TEXT NOT NULL,
    mood VARCHAR(20),
    energy_level INTEGER,
    tags TEXT,
    is_favorite INTEGER DEFAULT 0,
    is_private INTEGER DEFAULT 1,
    word_count INTEGER DEFAULT 0,
    entry_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 25. Reflection Prompts
-- ===========================================
DROP TABLE IF EXISTS reflection_prompts;
CREATE TABLE reflection_prompts (
    id VARCHAR(36) PRIMARY KEY,
    prompt_text TEXT NOT NULL,
    category VARCHAR(50),
    is_system INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 26. Weekly Reflections
-- ===========================================
DROP TABLE IF EXISTS weekly_reflections;
CREATE TABLE weekly_reflections (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    week_start_date DATE NOT NULL,
    highlights TEXT,
    challenges TEXT,
    lessons_learned TEXT,
    next_week_intentions TEXT,
    mood_summary VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 27. Achievements
-- ===========================================
DROP TABLE IF EXISTS achievements;
CREATE TABLE achievements (
    id VARCHAR(36) PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    icon VARCHAR(50),
    points INTEGER DEFAULT 0,
    badge_color VARCHAR(20),
    tier VARCHAR(20) DEFAULT 'bronze',
    condition_type VARCHAR(50),
    condition_value INTEGER,
    is_system INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 28. User Achievements
-- ===========================================
DROP TABLE IF EXISTS user_achievements;
CREATE TABLE user_achievements (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    achievement_id VARCHAR(36) NOT NULL,
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    progress INTEGER DEFAULT 0,
    is_viewed INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
    UNIQUE(user_id, achievement_id)
) ENGINE=InnoDB;

-- ===========================================
-- 29. User Points
-- ===========================================
DROP TABLE IF EXISTS user_points;
CREATE TABLE user_points (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    points INTEGER DEFAULT 0,
    level INTEGER DEFAULT 1,
    points_to_next_level INTEGER DEFAULT 100,
    total_points_earned INTEGER DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id)
) ENGINE=InnoDB;

-- ===========================================
-- 30. Points History
-- ===========================================
DROP TABLE IF EXISTS points_history;
CREATE TABLE points_history (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    points INTEGER NOT NULL,
    reason VARCHAR(200),
    source_type VARCHAR(50),
    source_id VARCHAR(36),
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 31. Notifications
-- ===========================================
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT,
    action_link VARCHAR(255),
    is_read INTEGER DEFAULT 0,
    priority VARCHAR(20) DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read, created_at)
) ENGINE=InnoDB;

-- ===========================================
-- 32. Notification Preferences
-- ===========================================
DROP TABLE IF EXISTS notification_preferences;
CREATE TABLE notification_preferences (
    user_id VARCHAR(36) NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    in_app_enabled INTEGER DEFAULT 1,
    email_enabled INTEGER DEFAULT 0,
    push_enabled INTEGER DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 33. Admin Logs
-- ===========================================
DROP TABLE IF EXISTS admin_logs;
CREATE TABLE admin_logs (
    id VARCHAR(36) PRIMARY KEY,
    admin_id VARCHAR(36) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id VARCHAR(36),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===========================================
-- 34. System Metrics
-- ===========================================
DROP TABLE IF EXISTS system_metrics;
CREATE TABLE system_metrics (
    id VARCHAR(36) PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DOUBLE,
    dimension VARCHAR(50),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 35. Payments
-- ===========================================
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    amount INTEGER NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    status VARCHAR(20) DEFAULT 'pending',
    gateway VARCHAR(20),
    gateway_reference VARCHAR(100),
    description VARCHAR(255),
    metadata TEXT,
    gateway_payment_id VARCHAR(100),
    refund_id VARCHAR(100),
    refunded_at TIMESTAMP NULL,
    refund_reason VARCHAR(255),
    cohort_id VARCHAR(50),
    access_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created (created_at),
    INDEX idx_gateway (gateway),
    INDEX idx_currency (currency)
) ENGINE=InnoDB;

-- ===========================================
-- 36. Leads
-- ===========================================
DROP TABLE IF EXISTS leads;
CREATE TABLE leads (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100) NOT NULL,
    source VARCHAR(50),
    status VARCHAR(20) DEFAULT 'new',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    converted_user_id VARCHAR(36)
) ENGINE=InnoDB;

-- ===========================================
-- 37. System Settings
-- ===========================================
DROP TABLE IF EXISTS system_settings;
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    setting_group VARCHAR(50),
    description VARCHAR(255),
    is_encrypted INTEGER DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 38. AI Prompts
-- ===========================================
DROP TABLE IF EXISTS ai_prompts;
CREATE TABLE ai_prompts (
    id VARCHAR(36) PRIMARY KEY,
    agent_name VARCHAR(50) UNIQUE NOT NULL,
    system_prompt TEXT,
    model_config TEXT,
    is_active INTEGER DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 39. Cohort Enrollments
-- ===========================================
DROP TABLE IF EXISTS cohort_enrollments;
CREATE TABLE cohort_enrollments (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    cohort_id VARCHAR(50) NOT NULL,
    payment_id VARCHAR(36),
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_starts_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_expires_at TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    UNIQUE(user_id, cohort_id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_cohort_status (cohort_id, status),
    INDEX idx_expires (access_expires_at)
) ENGINE=InnoDB;

-- ===========================================
-- 40. Exchange Rates (Multi-Currency)
-- ===========================================
DROP TABLE IF EXISTS exchange_rates;
CREATE TABLE exchange_rates (
    id VARCHAR(36) PRIMARY KEY,
    base_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    target_currency VARCHAR(3) NOT NULL,
    rate DECIMAL(12,6) NOT NULL,
    source VARCHAR(50) DEFAULT 'manual',
    valid_from TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    valid_until TIMESTAMP NULL,
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(base_currency, target_currency, valid_from),
    INDEX idx_active (target_currency, is_active, valid_from)
) ENGINE=InnoDB;

-- ===========================================
-- 41. Cohort Prices (Multi-Currency)
-- ===========================================
DROP TABLE IF EXISTS cohort_prices;
CREATE TABLE cohort_prices (
    id VARCHAR(36) PRIMARY KEY,
    cohort_id VARCHAR(50) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    amount INTEGER NOT NULL,
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    UNIQUE(cohort_id, currency),
    INDEX idx_currency (currency, is_active)
) ENGINE=InnoDB;

-- ===========================================
-- 42. Email Logs
-- ===========================================
DROP TABLE IF EXISTS email_logs;
CREATE TABLE email_logs (
    id VARCHAR(36) PRIMARY KEY,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255),
    status VARCHAR(20) DEFAULT 'sent',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 43. Cohort Completions
-- ===========================================
DROP TABLE IF EXISTS cohort_completions;
CREATE TABLE cohort_completions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    cohort_id VARCHAR(50) NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completion_stats TEXT,
    certificate_downloaded INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id)
) ENGINE=InnoDB;

-- ===========================================
-- 44. User Activity Logs
-- ===========================================
DROP TABLE IF EXISTS user_activity_logs;
CREATE TABLE user_activity_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    action_type VARCHAR(50),
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 45. AI Usage Logs
-- ===========================================
DROP TABLE IF EXISTS ai_usage_logs;
CREATE TABLE ai_usage_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    agent_name VARCHAR(50),
    tokens_used INTEGER,
    cost_estimate DECIMAL(10,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB;

-- ===========================================
-- 46. Request Logs
-- ===========================================
DROP TABLE IF EXISTS request_logs;
CREATE TABLE request_logs (
    id VARCHAR(36) PRIMARY KEY,
    ip_address VARCHAR(45),
    endpoint VARCHAR(255),
    method VARCHAR(10),
    status_code INTEGER,
    response_time_ms INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint (ip_address, endpoint, created_at)
) ENGINE=InnoDB;

-- ===========================================
-- 47. Token Blacklist (JWT Revocation)
-- ===========================================
DROP TABLE IF EXISTS token_blacklist;
CREATE TABLE token_blacklist (
    id VARCHAR(36) PRIMARY KEY,
    jti VARCHAR(100) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(jti),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- ===========================================
-- 48. Error Logs
-- ===========================================
DROP TABLE IF EXISTS error_logs;
CREATE TABLE error_logs (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    message TEXT NOT NULL,
    file VARCHAR(255),
    line INTEGER,
    trace TEXT,
    severity VARCHAR(20) DEFAULT 'error',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 49. Compliance Requests (GDPR)
-- ===========================================
DROP TABLE IF EXISTS compliance_requests;
CREATE TABLE compliance_requests (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    request_type VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===========================================
-- 50. User Consents
-- ===========================================
DROP TABLE IF EXISTS user_consents;
CREATE TABLE user_consents (
    user_id VARCHAR(36) NOT NULL,
    consent_type VARCHAR(50) NOT NULL,
    granted INTEGER DEFAULT 1,
    ip_address VARCHAR(45),
    consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, consent_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 51. AI Conversations
-- ===========================================
DROP TABLE IF EXISTS ai_conversations;
CREATE TABLE ai_conversations (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    agent_name VARCHAR(50),
    messages TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 52. AI Usage Limits
-- ===========================================
DROP TABLE IF EXISTS ai_usage_limits;
CREATE TABLE ai_usage_limits (
    user_id VARCHAR(36) PRIMARY KEY,
    daily_limit INTEGER DEFAULT 50,
    daily_used INTEGER DEFAULT 0,
    last_reset DATE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================
-- 53. Expiry Reminders Sent
-- ===========================================
DROP TABLE IF EXISTS expiry_reminders_sent;
CREATE TABLE expiry_reminders_sent (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    enrollment_id VARCHAR(36) NOT NULL,
    reminder_type VARCHAR(20),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===========================================
-- 54. Cohort Modules (Content)
-- ===========================================
DROP TABLE IF EXISTS cohort_modules;
CREATE TABLE cohort_modules (
    id VARCHAR(36) PRIMARY KEY,
    cohort_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    content_type VARCHAR(20) DEFAULT 'article',
    content_url TEXT,
    duration_minutes INT,
    unlock_day INT NOT NULL DEFAULT 0,
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    INDEX idx_cohort_order (cohort_id, sort_order)
) ENGINE=InnoDB;

-- ===========================================
-- 55. User Module Progress
-- ===========================================
DROP TABLE IF EXISTS user_module_progress;
CREATE TABLE user_module_progress (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    cohort_id VARCHAR(36) NOT NULL,
    module_id VARCHAR(36) NOT NULL,
    completed BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    score INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES cohort_modules(id) ON DELETE CASCADE,
    UNIQUE(user_id, module_id),
    INDEX idx_user_cohort (user_id, cohort_id)
) ENGINE=InnoDB;

-- ===========================================
-- 56. Event Sourcing Log
-- ===========================================
DROP TABLE IF EXISTS event_log;
CREATE TABLE event_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    aggregate_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    version INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aggregate (aggregate_id, version),
    INDEX idx_event_type (event_type, created_at)
) ENGINE=InnoDB;

-- ===========================================
-- Seed Default System Settings
-- ===========================================
INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted) VALUES
('site_name', 'Zenith Wellness', 'general', 'Platform Name', 0),
('support_email', 'support@zenithwellness.com', 'general', 'Support Contact Email', 0),
('maintenance_mode', '0', 'general', 'Enable maintenance mode (1/0)', 0),
('smtp_host', '', 'smtp', 'SMTP Server Host', 0),
('smtp_port', '587', 'smtp', 'SMTP Port', 0),
('smtp_user', '', 'smtp', 'SMTP Username', 0),
('smtp_pass', '', 'smtp', 'SMTP Password', 1),
('smtp_secure', 'tls', 'smtp', 'Encryption (tls/ssl)', 0),
('smtp_from_email', '', 'smtp', 'From Email Address', 0),
('smtp_from_name', 'Zenith Wellness', 'smtp', 'From Display Name', 0),
('payment_currency', 'USD', 'payment', 'Default Currency', 0),
('active_payment_gateway', 'flutterwave', 'payment', 'Active gateway', 0),
('stripe_public_key', '', 'payment', 'Stripe Public Key', 0),
('stripe_secret_key', '', 'payment', 'Stripe Secret Key', 1),
('stripe_webhook_secret', '', 'payment', 'Stripe Webhook Secret', 1),
('paystack_public_key', '', 'payment', 'Paystack Public Key', 0),
('paystack_secret_key', '', 'payment', 'Paystack Secret Key', 1),
('paystack_webhook_secret', '', 'payment', 'Paystack Webhook Secret', 1),
('flutterwave_public_key', '', 'payment', 'Flutterwave Public Key', 0),
('flutterwave_secret_key', '', 'payment', 'Flutterwave Secret Key', 1),
('flutterwave_webhook_secret', '', 'payment', 'Flutterwave Webhook Secret', 1),
('ai_provider', 'gemini', 'ai', 'Default AI Provider', 0),
('ai_default_model', 'gemini-pro', 'ai', 'Default AI Model', 0),
('openai_api_key', '', 'ai', 'OpenAI API Key', 1),
('gemini_api_key', '', 'ai', 'Gemini API Key', 1),
('openrouter_api_key', '', 'ai', 'OpenRouter API Key', 1)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'MySQL schema created successfully!' AS status;