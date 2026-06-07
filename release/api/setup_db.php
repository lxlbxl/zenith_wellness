<?php
// Include configuration to load environment variables
require_once __DIR__ . '/config.php';
include_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Initializing Database...\n";

try {
    // 1. Users Table
    $sql_users = "CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(36) PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        persona VARCHAR(20) DEFAULT 'newbie',
        role VARCHAR(20) DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_users);
    echo "Users table created.\n";

    // 2. User Stats
    $sql_stats = "CREATE TABLE IF NOT EXISTS user_stats (
        user_id VARCHAR(36) PRIMARY KEY,
        focus_minutes INTEGER DEFAULT 0,
        mood_history TEXT, -- JSON
        macros TEXT, -- JSON
        goals TEXT, -- JSON
        purchased_programs TEXT, -- JSON array of program IDs
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_stats);
    echo "UserStats table created.\n";

    // 3. Cycle Log
    $sql_cycles = "CREATE TABLE IF NOT EXISTS cycle_logs (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE,
        symptoms TEXT, -- JSON
        flow_intensity VARCHAR(20),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_cycles);
    echo "CycleLogs table created.\n";

    // 4. Cohorts (Programs)
    $sql_cohorts = "CREATE TABLE IF NOT EXISTS cohorts (
        id VARCHAR(50) PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        start_date DATE,
        category VARCHAR(50),
        image_url VARCHAR(255),
        objectives TEXT -- JSON
    )";
    $db->exec($sql_cohorts);
    echo "Cohorts table created.\n";

    // 5. Cohort Progress (User tracking)
    $sql_progress = "CREATE TABLE IF NOT EXISTS cohort_progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT, -- SQLite specific, might need SERIAL for PG
        user_id VARCHAR(36) NOT NULL,
        program_id VARCHAR(50) NOT NULL,
        current_day INTEGER DEFAULT 1,
        tasks TEXT, -- JSON
        performance_score INTEGER DEFAULT 0,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    // Adjust for Postgres/MySQL if needed
    if (getenv('DATABASE_URL')) {
        // Postgres
        $sql_progress = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "SERIAL PRIMARY KEY", $sql_progress);
    } elseif (getenv('DB_HOST')) {
        // MySQL
        $sql_progress = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "INTEGER PRIMARY KEY AUTO_INCREMENT", $sql_progress);
    }
    $db->exec($sql_progress);
    echo "CohortProgress table created.\n";

    // 6. Cohort Messages (Chat)
    $sql_messages = "CREATE TABLE IF NOT EXISTS cohort_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id VARCHAR(50) NOT NULL,
        user_id VARCHAR(36) NOT NULL,
        user_name VARCHAR(100),
        user_rank VARCHAR(20),
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    if (getenv('DATABASE_URL')) {
        $sql_messages = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "SERIAL PRIMARY KEY", $sql_messages);
    } elseif (getenv('DB_HOST')) {
        $sql_messages = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "INTEGER PRIMARY KEY AUTO_INCREMENT", $sql_messages);
    }
    $db->exec($sql_messages);
    echo "CohortMessages table created.\n";

    // 7. Meal Logs (NEW)
    $sql_meals = "CREATE TABLE IF NOT EXISTS meal_logs (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        food_items TEXT,
        macros TEXT,
        wellness_score INTEGER DEFAULT 0,
        summary TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_meals);
    echo "MealLogs table created.\n";

    // 8. Chat Sessions (NEW)
    $sql_chat = "CREATE TABLE IF NOT EXISTS chat_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(36) NOT NULL,
        messages TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    if (getenv('DATABASE_URL')) {
        $sql_chat = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "SERIAL PRIMARY KEY", $sql_chat);
    } elseif (getenv('DB_HOST')) {
        $sql_chat = str_replace("INTEGER PRIMARY KEY AUTOINCREMENT", "INTEGER PRIMARY KEY AUTO_INCREMENT", $sql_chat);
    }
    $db->exec($sql_chat);
    echo "ChatSessions table created.\n";

    // 9. Password Resets Table
    $sql_password_resets = "CREATE TABLE IF NOT EXISTS password_resets (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_password_resets);
    echo "PasswordResets table created.\n";

    // 10. Routine Templates Table (system routines)
    $sql_routine_templates = "CREATE TABLE IF NOT EXISTS routine_templates (
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
    )";
    $db->exec($sql_routine_templates);
    echo "RoutineTemplates table created.\n";

    // 11. Routine Template Items
    $sql_routine_template_items = "CREATE TABLE IF NOT EXISTS routine_template_items (
        id VARCHAR(36) PRIMARY KEY,
        template_id VARCHAR(36) NOT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        duration_minutes INTEGER,
        order_index INTEGER NOT NULL,
        is_optional INTEGER DEFAULT 0,
        icon VARCHAR(50),
        FOREIGN KEY (template_id) REFERENCES routine_templates(id) ON DELETE CASCADE
    )";
    $db->exec($sql_routine_template_items);
    echo "RoutineTemplateItems table created.\n";

    // 12. User Routines (instances assigned to users)
    $sql_user_routines = "CREATE TABLE IF NOT EXISTS user_routines (
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
    )";
    $db->exec($sql_user_routines);
    echo "UserRoutines table created.\n";

    // 13. User Routine Items
    $sql_user_routine_items = "CREATE TABLE IF NOT EXISTS user_routine_items (
        id VARCHAR(36) PRIMARY KEY,
        routine_id VARCHAR(36) NOT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        duration_minutes INTEGER,
        order_index INTEGER NOT NULL,
        icon VARCHAR(50),
        FOREIGN KEY (routine_id) REFERENCES user_routines(id) ON DELETE CASCADE
    )";
    $db->exec($sql_user_routine_items);
    echo "UserRoutineItems table created.\n";

    // 14. Routine Completions
    $sql_routine_completions = "CREATE TABLE IF NOT EXISTS routine_completions (
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
    )";
    $db->exec($sql_routine_completions);
    echo "RoutineCompletions table created.\n";

    // 15. Habit Templates
    $sql_habit_templates = "CREATE TABLE IF NOT EXISTS habit_templates (
        id VARCHAR(36) PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        category VARCHAR(50),
        icon VARCHAR(50),
        recommended_frequency VARCHAR(20) DEFAULT 'daily',
        is_system INTEGER DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_habit_templates);
    echo "HabitTemplates table created.\n";

    // 16. User Habits
    $sql_user_habits = "CREATE TABLE IF NOT EXISTS user_habits (
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
    )";
    $db->exec($sql_user_habits);
    echo "UserHabits table created.\n";

    // 17. Habit Logs (daily tracking)
    $sql_habit_logs = "CREATE TABLE IF NOT EXISTS habit_logs (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        habit_id VARCHAR(36) NOT NULL,
        log_date DATE NOT NULL,
        completed INTEGER DEFAULT 1,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (habit_id) REFERENCES user_habits(id) ON DELETE CASCADE,
        UNIQUE(habit_id, log_date)
    )";
    $db->exec($sql_habit_logs);
    echo "HabitLogs table created.\n";

    // 18. Daily Wellness Logs (water, sleep, energy, stress)
    $sql_wellness_logs = "CREATE TABLE IF NOT EXISTS daily_wellness_logs (
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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, log_date)
    )";
    $db->exec($sql_wellness_logs);
    echo "DailyWellnessLogs table created.\n";

    // 18b. Daily Symptom Logs (Quick Symptom Log feature)
    $sql_symptom_logs = "CREATE TABLE IF NOT EXISTS daily_symptom_logs (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        log_date DATE NOT NULL,
        symptoms TEXT, -- JSON array of symptom IDs
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, log_date)
    )";
    $db->exec($sql_symptom_logs);
    echo "DailySymptomLogs table created.\n";

    // 19. Exercise Library (system-defined exercises)
    $sql_exercises = "CREATE TABLE IF NOT EXISTS exercise_library (
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
    )";
    $db->exec($sql_exercises);
    echo "ExerciseLibrary table created.\n";

    // 20. Workout Logs
    $sql_workout_logs = "CREATE TABLE IF NOT EXISTS workout_logs (
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
    )";
    $db->exec($sql_workout_logs);
    echo "WorkoutLogs table created.\n";

    // 21. Personal Goals
    $sql_goals = "CREATE TABLE IF NOT EXISTS personal_goals (
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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_goals);
    echo "PersonalGoals table created.\n";

    // 22. Goal Milestones
    $sql_milestones = "CREATE TABLE IF NOT EXISTS goal_milestones (
        id VARCHAR(36) PRIMARY KEY,
        goal_id VARCHAR(36) NOT NULL,
        title VARCHAR(100) NOT NULL,
        target_value DECIMAL(10,2) NOT NULL,
        achieved INTEGER DEFAULT 0,
        achieved_at TIMESTAMP,
        FOREIGN KEY (goal_id) REFERENCES personal_goals(id) ON DELETE CASCADE
    )";
    $db->exec($sql_milestones);
    echo "GoalMilestones table created.\n";

    // 23. Goal Progress Logs
    $sql_goal_progress = "CREATE TABLE IF NOT EXISTS goal_progress_logs (
        id VARCHAR(36) PRIMARY KEY,
        goal_id VARCHAR(36) NOT NULL,
        value DECIMAL(10,2) NOT NULL,
        notes TEXT,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (goal_id) REFERENCES personal_goals(id) ON DELETE CASCADE
    )";
    $db->exec($sql_goal_progress);
    echo "GoalProgressLogs table created.\n";

    // 24. Journal Entries
    $sql_journal = "CREATE TABLE IF NOT EXISTS journal_entries (
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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_journal);
    echo "JournalEntries table created.\n";

    // 25. Reflection Prompts
    $sql_prompts = "CREATE TABLE IF NOT EXISTS reflection_prompts (
        id VARCHAR(36) PRIMARY KEY,
        prompt_text TEXT NOT NULL,
        category VARCHAR(50),
        is_system INTEGER DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_prompts);
    echo "ReflectionPrompts table created.\n";

    // 26. Weekly Reflections
    $sql_weekly = "CREATE TABLE IF NOT EXISTS weekly_reflections (
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
    )";
    $db->exec($sql_weekly);
    echo "WeeklyReflections table created.\n";

    // 27. Achievements
    $sql_achievements = "CREATE TABLE IF NOT EXISTS achievements (
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
    )";
    $db->exec($sql_achievements);
    echo "Achievements table created.\n";

    // 28. User Achievements
    $sql_user_achievements = "CREATE TABLE IF NOT EXISTS user_achievements (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        achievement_id VARCHAR(36) NOT NULL,
        earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        progress INTEGER DEFAULT 0,
        is_viewed INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
        UNIQUE(user_id, achievement_id)
    )";
    $db->exec($sql_user_achievements);
    echo "UserAchievements table created.\n";

    // 29. User Points
    $sql_user_points = "CREATE TABLE IF NOT EXISTS user_points (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        points INTEGER DEFAULT 0,
        level INTEGER DEFAULT 1,
        points_to_next_level INTEGER DEFAULT 100,
        total_points_earned INTEGER DEFAULT 0,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id)
    )";
    $db->exec($sql_user_points);
    echo "UserPoints table created.\n";

    // 30. Points History
    $sql_points_history = "CREATE TABLE IF NOT EXISTS points_history (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        points INTEGER NOT NULL,
        reason VARCHAR(200),
        source_type VARCHAR(50),
        source_id VARCHAR(36),
        earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_points_history);
    echo "PointsHistory table created.\n";

    // 31. Notifications
    $sql_notifications = "CREATE TABLE IF NOT EXISTS notifications (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT,
        action_link VARCHAR(255),
        is_read INTEGER DEFAULT 0,
        priority VARCHAR(20) DEFAULT 'normal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_notifications);
    echo "Notifications table created.\n";

    // 32. Notification Preferences
    $sql_notif_prefs = "CREATE TABLE IF NOT EXISTS notification_preferences (
        user_id VARCHAR(36) NOT NULL,
        notification_type VARCHAR(50) NOT NULL,
        in_app_enabled INTEGER DEFAULT 1,
        email_enabled INTEGER DEFAULT 0,
        push_enabled INTEGER DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, notification_type),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_notif_prefs);
    echo "NotificationPreferences table created.\n";

    // 33. Admin Logs
    $sql_admin_logs = "CREATE TABLE IF NOT EXISTS admin_logs (
        id VARCHAR(36) PRIMARY KEY,
        admin_id VARCHAR(36) NOT NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(50),
        target_id VARCHAR(36),
        details TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES users(id)
    )";
    $db->exec($sql_admin_logs);
    echo "AdminLogs table created.\n";

    // 34. System Metrics
    $sql_metrics = "CREATE TABLE IF NOT EXISTS system_metrics (
        id VARCHAR(36) PRIMARY KEY,
        metric_name VARCHAR(100) NOT NULL,
        metric_value REAL,
        dimension VARCHAR(50),
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_metrics);
    echo "SystemMetrics table created.\n";

    // 35. Payments
    $sql_payments = "CREATE TABLE IF NOT EXISTS payments (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        amount INTEGER NOT NULL, -- in smallest unit (cents)
        currency VARCHAR(3) DEFAULT 'USD',
        status VARCHAR(20) DEFAULT 'pending',
        gateway VARCHAR(20),
        gateway_reference VARCHAR(100),
        description VARCHAR(255),
        metadata TEXT,
        gateway_payment_id VARCHAR(100),
        refund_id VARCHAR(100),
        refunded_at TIMESTAMP,
        refund_reason VARCHAR(255),
        cohort_id VARCHAR(50),
        access_expires_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql_payments);
    echo "Payments table created.\n";

    // 36. Leads
    $sql_leads = "CREATE TABLE IF NOT EXISTS leads (
        id VARCHAR(36) PRIMARY KEY,
        name VARCHAR(100),
        email VARCHAR(100) NOT NULL,
        source VARCHAR(50),
        status VARCHAR(20) DEFAULT 'new', -- new, contacted, qualified, converted, lost
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        converted_user_id VARCHAR(36) -- if they became a user
    )";
    $db->exec($sql_leads);
    echo "Leads table created.\n";

    // 37. System Settings
    $sql_settings = "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        setting_group VARCHAR(50), -- smtp, payment, general, ai
        description VARCHAR(255),
        is_encrypted INTEGER DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_settings);
    echo "SystemSettings table created.\n";

    // 38. AI Prompts
    $sql_prompts_config = "CREATE TABLE IF NOT EXISTS ai_prompts (
        id VARCHAR(36) PRIMARY KEY,
        agent_name VARCHAR(50) UNIQUE NOT NULL, -- e.g., 'coach_sara', 'data_analyst'
        system_prompt TEXT,
        model_config TEXT, -- JSON for temperature, model version etc
        is_active INTEGER DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_prompts_config);
    echo "AIPrompts table created.\n";

    // 39. Cohort Enrollments (NEW for Payment System)
    $sql_enrollments = "CREATE TABLE IF NOT EXISTS cohort_enrollments (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        cohort_id VARCHAR(50) NOT NULL,
        payment_id VARCHAR(36),
        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        access_starts_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        access_expires_at TIMESTAMP NOT NULL,
        status VARCHAR(20) DEFAULT 'active', -- active, expired, revoked, refunded
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
        FOREIGN KEY (payment_id) REFERENCES payments(id),
        UNIQUE(user_id, cohort_id)
    )";
    $db->exec($sql_enrollments);
    echo "CohortEnrollments table created.\n";

    // Seed Default Settings
    $check_settings = $db->query("SELECT count(*) FROM system_settings")->fetchColumn();
    if ($check_settings == 0) {
        $settings = [
            // General
            ['site_name', 'Zenith Wellness', 'general', 'Platform Name', 0],
            ['support_email', 'support@zenith.com', 'general', 'Support Contact Email', 0],
            ['maintenance_mode', '0', 'general', '1 to enable maintenance mode', 0],

            // SMTP
            ['smtp_host', 'smtp.mailtrap.io', 'smtp', 'SMTP Server Host', 0],
            ['smtp_port', '2525', 'smtp', 'SMTP Port', 0],
            ['smtp_user', 'user', 'smtp', 'SMTP Username', 0],
            ['smtp_pass', 'pass', 'smtp', 'SMTP Password', 1],
            ['smtp_secure', 'tls', 'smtp', 'Encryption (tls/ssl)', 0],

            // Payment
            ['payment_currency', 'USD', 'payment', 'Default Currency', 0],
            ['stripe_public_key', 'pk_test_...', 'payment', 'Stripe Public Key', 0],
            ['stripe_secret_key', 'sk_test_...', 'payment', 'Stripe Secret Key', 1],
            ['paystack_public_key', 'pk_test_...', 'payment', 'Paystack Public Key', 0],
            ['paystack_secret_key', 'sk_test_...', 'payment', 'Paystack Secret Key', 1],

            // AI
            ['ai_provider', 'gemini', 'ai', 'Default AI Provider (gemini, openai, openrouter)', 0],
            ['ai_default_model', 'gemini-pro', 'ai', 'Model Name (e.g., gpt-4, gemini-pro)', 0],
            ['openai_api_key', '', 'ai', 'OpenAI API Key', 1],
            ['gemini_api_key', '', 'ai', 'Gemini API Key', 1],
            ['gemini_api_key', '', 'ai', 'Gemini API Key', 1],
            ['openrouter_api_key', '', 'ai', 'OpenRouter API Key', 1],

            // Payment - Additional Gateways
            ['active_payment_gateway', 'stripe', 'payment', 'Active gateway: stripe, paystack, or flutterwave', 0],
            ['flutterwave_public_key', 'FLWPUBK_TEST-xxx', 'payment', 'Flutterwave Public Key', 0],
            ['flutterwave_secret_key', 'FLWSECK_TEST-xxx', 'payment', 'Flutterwave Secret Key', 1],
            ['stripe_webhook_secret', 'whsec_xxx', 'payment', 'Stripe Webhook Secret', 1],
            ['paystack_webhook_secret', '', 'payment', 'Paystack Webhook Secret', 1],
            ['flutterwave_webhook_secret', '', 'payment', 'Flutterwave Webhook Secret', 1],
        ];

        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted) VALUES (?,?,?,?,?)");
        foreach ($settings as $s) {
            $stmt->execute($s);
        }
        echo "Default Settings seeded.\n";
    }

    // Seed AI Prompts - World-Class Agent Architecture
    $check_ai_prompts = $db->query("SELECT count(*) FROM ai_prompts")->fetchColumn();
    if ($check_ai_prompts == 0) {
        $ai_prompts = [
            // ========================================
            // AGENT 1: COACH SARA - Primary Wellness Coach
            // ========================================
            [
                'coach_sara',
                'coach_sara',
                'You are **Sara**, the lead wellness coach at Zenith. You are warm, wise, and deeply committed to each member\'s transformation. Think of yourself as that brilliant friend who also happens to have a PhD in metabolic health.

## Your Personality
- **Warm but direct**: You care deeply, so you tell the truth with kindness
- **Scientific yet accessible**: You explain WHY things work, not just what to do
- **Celebratory**: You notice and praise every small win
- **Personalized**: You reference their specific data, never generic advice

## User Context (Injected Variables)
- Name: {{user_name}}
- Persona: {{persona}} (newbie/intermediate/advanced)
- Current Streak: {{streak_days}} days
- Today\'s Stats: {{focus_minutes}} mins focus, {{calories}} kcal ({{protein}}g P, {{carbs}}g C, {{fats}}g F)
- Mood: {{mood}}, Energy: {{energy}}/10
- Cycle Phase: {{cycle_phase}}
- Active Cohort: {{active_cohort}} (Day {{cohort_day}})

## Response Guidelines
1. **Open with recognition**: Acknowledge their current state or recent progress
2. **Be specific**: Reference their actual numbers, not generic advice
3. **Give ONE clear action**: End with a specific micro-task they can do TODAY
4. **Keep it brief**: 2-3 short paragraphs max, unless they asked a detailed question
5. **Use their name**: Make it personal

## Metabolic Health Expertise
- PCOS management and insulin sensitivity
- Cycle syncing nutrition and exercise
- Blood sugar optimization
- Stress-cortisol-weight connection
- Sleep architecture for hormonal health

## Example Response Style
"{{user_name}}, I see you hit {{focus_minutes}} minutes of focus today - that\'s your third day above target! 🎯 

With your cycle in {{cycle_phase}}, your body is primed for [specific advice]. Your protein is solid at {{protein}}g, but I\'d love to see you add one more handful of greens today.

**Your micro-mission**: Before your next meal, take 5 deep belly breaths. This activates your parasympathetic nervous system and improves nutrient absorption. Small thing, big impact."',
                json_encode(['temperature' => 0.7, 'model' => 'gemini-1.5-flash', 'maxTokens' => 1024])
            ],

            // ========================================
            // AGENT 2: NUTRITION IQ - Meal Analysis Expert
            // ========================================
            [
                'nutrition_iq',
                'nutrition_iq',
                'You are **Nutrition IQ**, Zenith\'s visual nutrition analysis system. You analyze meal photos with the precision of a registered dietitian and the warmth of a supportive coach.

## Your Expertise
- Visual macro estimation (±15% accuracy target)
- PCOS and insulin-impact assessment
- Inflammatory food identification
- Micronutrient gap detection
- Smart swap suggestions

## User Context (Injected Variables)
- Name: {{user_name}}
- Persona: {{persona}}
- Daily Goals: {{daily_calorie_goal}} kcal, {{daily_protein_goal}}g protein
- Cycle Phase: {{cycle_phase}}
- Dietary Restrictions: {{dietary_restrictions}}
- Meals Logged Today: {{meals_today}}
- Running Totals: {{running_calories}} kcal, {{running_protein}}g protein

## Response Format (STRICT JSON)
Always respond with valid JSON in this exact structure:
```json
{
  "meal_name": "Descriptive name of the meal",
  "macros": {
    "calories": 450,
    "protein_g": 32,
    "carbs_g": 35,
    "fats_g": 18,
    "fiber_g": 8
  },
  "wellness_score": 8.5,
  "insulin_impact": "low|moderate|high",
  "pcos_friendly": true,
  "highlights": ["High protein", "Good fiber"],
  "concerns": ["Could use more vegetables"],
  "smart_swaps": [
    {"current": "white rice", "suggested": "cauliflower rice", "benefit": "-25g carbs, +3g fiber"}
  ],
  "summary": "One encouraging sentence about this meal.",
  "remaining_today": {
    "calories": 1050,
    "protein_g": 68
  }
}
```

## Analysis Guidelines
1. **Be encouraging first**: Lead with what\'s good about the meal
2. **PCOS lens**: Always assess insulin impact for hormonal health
3. **Context-aware**: Factor in their cycle phase for recommendations
4. **Practical swaps**: Suggestions must be realistic, not aspirational
5. **Running totals**: Help them understand where they stand for the day',
                json_encode(['temperature' => 0.3, 'model' => 'gemini-1.5-flash', 'maxTokens' => 800])
            ],

            // ========================================
            // AGENT 3: COMPANION - Emotional Intelligence
            // ========================================
            [
                'companion',
                'companion',
                'You are **Companion**, Zenith\'s emotional intelligence engine. You read between the lines of journal entries and provide gentle, insightful reflections that help users understand themselves better.

## Your Essence
- **A wise, gentle mirror**: You reflect back what they might not see
- **Non-prescriptive**: You don\'t tell them what to do; you help them discover
- **Trauma-informed**: You never push; you hold space
- **Pattern-aware**: You gently illuminate recurring themes

## User Context (Injected Variables)
- Name: {{user_name}}
- Today\'s Mood: {{mood_today}}
- Energy Level: {{energy_level}}/10
- Journal Streak: {{journal_streak}} days
- Dominant Mood This Week: {{dominant_mood_week}}
- Recent Achievements: {{recent_achievements}}
- Entry Word Count: {{word_count}}

## Response Guidelines
1. **Acknowledge first**: Begin by validating their emotional state
2. **Reflect, don\'t advise**: "I notice..." not "You should..."
3. **Illuminate patterns**: "This feels connected to what you wrote about..."
4. **Offer a gentle question**: End with an optional reflection prompt
5. **Keep it intimate**: 3-5 sentences, like a thoughtful friend

## Tone Examples
✅ "There\'s a quiet strength in what you wrote today, {{user_name}}. Even naming this feeling is a form of progress."
✅ "I notice you keep returning to the theme of control. I wonder what it might feel like to release just one small thing?"
❌ "You seem stressed. Try meditation." (Too prescriptive)
❌ "Great journal entry!" (Too shallow)

## Emotional Patterns to Notice
- Recurring themes or words
- Shifts in energy or tone from previous entries
- Unspoken feelings beneath the surface
- Growth or regression signals
- Self-compassion vs. self-criticism ratio

## Response Format
Respond with a warm, brief reflection (3-5 sentences). Optionally include:
- A gentle insight about patterns you notice
- A single reflective question (not a task)
- Acknowledgment of their consistency if relevant',
                json_encode(['temperature' => 0.8, 'model' => 'gemini-1.5-flash', 'maxTokens' => 600])
            ],

            // ========================================
            // AGENT 4: ANALYST - Performance & Insights
            // ========================================
            [
                'analyst',
                'analyst',
                'You are **Analyst**, Zenith\'s data intelligence system. You transform raw wellness data into actionable insights that feel like having a personal health researcher on your team.

## Your Expertise
- Behavioral pattern recognition
- Correlation analysis (sleep↔energy, nutrition↔mood, etc.)
- Progress trajectory modeling
- Cohort benchmarking
- Predictive insights

## User Context (Injected Variables)
- Name: {{user_name}}
- Cohort: {{cohort_name}} (Day {{cohort_day}} of {{cohort_total_days}})
- Completion Rate: {{completion_rate}}%
- Current Streak: {{streak}} days
- Points: {{points}}
- Weekly Averages: Sleep {{sleep_avg}}h, Focus {{focus_avg}} mins, Energy {{energy_trend}}/10
- Habit Completion: {{habit_completion_rates}} (JSON)
- Compared to Cohort Average: {{vs_cohort_avg}}

## Analysis Types

### Morning Briefing
Quick, motivating summary with today\'s focus areas.

### Weekly Deep Dive
Comprehensive analysis with:
- Top 3 wins
- 1 area for improvement
- Key correlations discovered
- Prediction for next week

### Cohort Performance
How they stack up (encouragingly) against cohort averages.

## Response Format (for Deep Analysis)
```json
{
  "summary": "One-line headline insight",
  "wins": ["Win 1", "Win 2", "Win 3"],
  "focus_area": "One specific thing to improve",
  "correlation_insight": "We noticed that when you X, your Y improves by Z%",
  "prediction": "If you maintain this trajectory...",
  "cohort_rank": "Top 25%",
  "recommendation": "Specific action based on data"
}
```

## Tone Guidelines
- **Data-driven but human**: Numbers tell stories
- **Encouraging**: Frame improvements as opportunities
- **Specific**: Never generic; always reference their actual data
- **Forward-looking**: Focus on what they can control next',
                json_encode(['temperature' => 0.4, 'model' => 'gemini-1.5-flash', 'maxTokens' => 1200])
            ],

            // ========================================
            // AGENT 5: CONTENT STUDIO - Personalized Content
            // ========================================
            [
                'content_studio',
                'content_studio',
                'You are **Content Studio**, Zenith\'s personalized content generation engine. You create tailored wellness content that feels handcrafted for each user.

## Content Types You Generate

### 1. Morning Briefings
Daily motivation + today\'s focus based on:
- Where they are in their program
- Yesterday\'s performance
- Upcoming challenges
- Cycle phase considerations

### 2. Prep Guides
7-day preparation guides for new program enrollees:
- Day-by-day action items
- Pantry cleanup lists
- Mindset preparation
- What to expect

### 3. Workshop Curriculum
Detailed curriculum for wellness workshops with:
- Learning objectives
- Module breakdown
- Exercises and activities
- Key takeaways

### 4. Smart Recommendations
Personalized resource suggestions based on their gaps.

## User Context (Injected Variables)
- Name: {{user_name}}
- Persona: {{persona}}
- Cohort: {{cohort_name}}
- Program Week: {{current_week}} of {{total_weeks}}
- Days Remaining: {{days_remaining}}
- Recent Wins: {{recent_wins}}
- Areas to Improve: {{areas_to_improve}}
- Objectives: {{cohort_objectives}} (array)
- Yesterday\'s Summary: {{yesterday_summary}}

## Morning Briefing Format
```
🌅 Good morning, {{user_name}}!

**Day {{cohort_day}} of {{cohort_name}}**

[One personalized, encouraging opener based on their recent performance]

📋 **Today\'s Focus**
1. [Priority 1 - specific to their goals]
2. [Priority 2 - based on what they\'re working on]
3. [Optional Priority 3]

💡 **Sara\'s Tip**: [One cycle-aware or metabolic tip]

🔥 **Your streak**: {{streak}} days strong

You\'ve got this. Let\'s make today count.
```

## Tone Guidelines
- **Energizing but not overwhelming**: Morning content should inspire, not stress
- **Personalized**: Reference their specific journey, not generic motivation
- **Actionable**: Every piece of content should have clear next steps
- **On-brand**: Warm, scientific, empowering - the Zenith voice',
                json_encode(['temperature' => 0.6, 'model' => 'gemini-1.5-flash', 'maxTokens' => 1500])
            ]
        ];

        $promptStmt = $db->prepare("INSERT INTO ai_prompts (id, agent_name, system_prompt, model_config, is_active) VALUES (?, ?, ?, ?, 1)");
        foreach ($ai_prompts as $prompt) {
            $promptStmt->execute($prompt);
        }
        echo "AI Prompts seeded (5 world-class agents).\n";
    }

    // --- ADD MISSING COLUMNS (safe to run multiple times) ---

    // Add program_id and is_premium to routine_templates
    try {
        $db->exec("ALTER TABLE routine_templates ADD COLUMN program_id VARCHAR(50)");
        $db->exec("ALTER TABLE routine_templates ADD COLUMN is_premium INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE routine_templates ADD COLUMN ai_generated INTEGER DEFAULT 0"); // identifying AI templates
        echo "Added program_id, is_premium, ai_generated to routine_templates.\n";
    } catch (Exception $e) {
    }

    // Add generated_metadata to user_routines
    try {
        $db->exec("ALTER TABLE user_routines ADD COLUMN is_generated INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE user_routines ADD COLUMN generation_date DATE");
        echo "Added is_generated, generation_date to user_routines.\n";
    } catch (Exception $e) {
    }

    // Add missing columns to user_stats
    try {
        $db->exec("ALTER TABLE user_stats ADD COLUMN daily_streak INTEGER DEFAULT 0");
        echo "Added daily_streak column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE user_stats ADD COLUMN settings TEXT");
        echo "Added settings column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE user_stats ADD COLUMN completed_tasks INTEGER DEFAULT 0");
        echo "Added completed_tasks column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE user_stats ADD COLUMN cohort_progress TEXT");
        echo "Added cohort_progress column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add missing columns to cohorts
    try {
        $db->exec("ALTER TABLE cohorts ADD COLUMN price INTEGER DEFAULT 8900");
        echo "Added price column to cohorts.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE cohorts ADD COLUMN duration_days INTEGER DEFAULT 21");
        echo "Added duration_days column to cohorts.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add missing columns to cycle_logs for calculations
    try {
        $db->exec("ALTER TABLE cycle_logs ADD COLUMN cycle_length INTEGER");
        echo "Added cycle_length column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE cycle_logs ADD COLUMN period_length INTEGER");
        echo "Added period_length column.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add updated_at to cohort_progress if missing
    try {
        $db->exec("ALTER TABLE cohort_progress ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added updated_at column to cohort_progress.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Add deadline notification tracking to personal_goals
    try {
        $db->exec("ALTER TABLE personal_goals ADD COLUMN deadline_notified INTEGER DEFAULT 0");
        echo "Added deadline_notified column to personal_goals.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    try {
        $db->exec("ALTER TABLE personal_goals ADD COLUMN overdue_notified INTEGER DEFAULT 0");
        echo "Added overdue_notified column to personal_goals.\n";
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // --- SEED DATA ---

    // Seed Programs
    $check_programs = $db->query("SELECT count(*) FROM cohorts")->fetchColumn();
    if ($check_programs == 0) {
        $programs = [
            [
                'prod_1',
                'Productivity Power',
                'Master deep work protocols.',
                '2023-01-01',
                'productivity',
                'https://picsum.photos/seed/prod/400/300',
                json_encode(['Deep Work Flow', 'Email Management'])
            ],
            [
                'pcos_1',
                'PCOS Protocol',
                'Regulate hormones with targeted nutrition.',
                '2025-06-01',
                'pcos',
                'https://picsum.photos/seed/pcos/400/300',
                json_encode(['Insulin Sensitivity', 'Anti-Inflammatory Nutrition'])
            ],
            [
                'weight_1',
                'Metabolic Reset',
                'Next cohort starts in 12 days.',
                date('Y-m-d', strtotime('+12 days')),
                'wellness',
                'https://picsum.photos/seed/weight/400/300',
                json_encode(['Basal Metabolic Rate', 'Fat Oxidation'])
            ]
        ];

        $stmt = $db->prepare("INSERT INTO cohorts (id, title, description, start_date, category, image_url, objectives) VALUES (?,?,?,?,?,?,?)");
        foreach ($programs as $p) {
            $stmt->execute($p);
        }
        echo "Cohorts seeded.\n";
    }

    // Seed Test User
    $check_user = $db->query("SELECT count(*) FROM users WHERE email='test@zenith.com'")->fetchColumn();
    if ($check_user == 0) {
        $test_id = '1';
        $pass_hash = password_hash('password', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (id, name, email, password_hash, persona, role) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$test_id, 'Alex Johnson', 'test@zenith.com', $pass_hash, 'active', 'admin']);

        // Seed Stats
        $stmt = $db->prepare("INSERT INTO user_stats (user_id, focus_minutes, mood_history, macros) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $test_id,
            120,
            json_encode([['date' => date('c'), 'mood' => 'happy']]),
            json_encode(['protein' => 45, 'carbs' => 120, 'fats' => 30, 'calories' => 1250])
        ]);
        echo "Test user seeded.\n";
    }

    // Seed Routine Templates
    $check_routines = $db->query("SELECT count(*) FROM routine_templates")->fetchColumn();
    if ($check_routines == 0) {
        $templates = [
            [
                'id' => 'rt_morning_energy',
                'title' => 'Energizing Morning',
                'description' => 'Start your day with energy and focus. Best for follicular and ovulation phases.',
                'type' => 'daily',
                'cycle_phase' => 'follicular',
                'category' => 'morning',
                'duration_minutes' => 15,
                'items' => [
                    ['title' => 'Glass of lemon water', 'duration_minutes' => 2, 'icon' => 'fa-droplet'],
                    ['title' => '5 minute stretching', 'duration_minutes' => 5, 'icon' => 'fa-person-walking'],
                    ['title' => 'Gratitude journaling (3 things)', 'duration_minutes' => 3, 'icon' => 'fa-heart'],
                    ['title' => 'Plan 3 priorities for today', 'duration_minutes' => 5, 'icon' => 'fa-list-check']
                ]
            ],
            [
                'id' => 'rt_morning_gentle',
                'title' => 'Gentle Morning',
                'description' => 'A nurturing start for lower energy days. Ideal for menstrual and luteal phases.',
                'type' => 'daily',
                'cycle_phase' => 'menstrual',
                'category' => 'morning',
                'duration_minutes' => 20,
                'items' => [
                    ['title' => 'Warm water with ginger', 'duration_minutes' => 2, 'icon' => 'fa-mug-hot'],
                    ['title' => 'Gentle yoga flow', 'duration_minutes' => 10, 'icon' => 'fa-spa'],
                    ['title' => 'Deep breathing (4-7-8)', 'duration_minutes' => 3, 'icon' => 'fa-wind'],
                    ['title' => 'Light breakfast prep', 'duration_minutes' => 5, 'icon' => 'fa-bowl-food']
                ]
            ],
            [
                'id' => 'rt_evening_winddown',
                'title' => 'Wind Down Protocol',
                'description' => 'Prepare your body and mind for restful sleep.',
                'type' => 'daily',
                'cycle_phase' => null,
                'category' => 'evening',
                'duration_minutes' => 24,
                'items' => [
                    ['title' => 'Put away screens', 'duration_minutes' => 1, 'icon' => 'fa-mobile-screen'],
                    ['title' => 'Herbal tea preparation', 'duration_minutes' => 5, 'icon' => 'fa-mug-saucer'],
                    ['title' => 'Skincare routine', 'duration_minutes' => 10, 'icon' => 'fa-sparkles'],
                    ['title' => 'Gratitude review', 'duration_minutes' => 3, 'icon' => 'fa-heart'],
                    ['title' => 'Set tomorrow intentions', 'duration_minutes' => 5, 'icon' => 'fa-calendar-check']
                ]
            ],
            [
                'id' => 'rt_period_care',
                'title' => 'Period Care Ritual',
                'description' => 'Self-care focused routine for your menstrual phase.',
                'type' => 'daily',
                'cycle_phase' => 'menstrual',
                'category' => 'self_care',
                'duration_minutes' => 35,
                'items' => [
                    ['title' => 'Heat therapy (heating pad)', 'duration_minutes' => 10, 'icon' => 'fa-fire-flame-simple'],
                    ['title' => 'Gentle stretches for cramps', 'duration_minutes' => 5, 'icon' => 'fa-person'],
                    ['title' => 'Iron-rich snack', 'duration_minutes' => 5, 'icon' => 'fa-apple-whole'],
                    ['title' => 'Rest or light nap', 'duration_minutes' => 15, 'icon' => 'fa-bed']
                ]
            ],
            [
                'id' => 'rt_hydration',
                'title' => 'Hydration Check-ins',
                'description' => 'Stay hydrated throughout the day with scheduled water breaks.',
                'type' => 'daily',
                'cycle_phase' => null,
                'category' => 'nutrition',
                'duration_minutes' => 10,
                'items' => [
                    ['title' => 'Morning hydration (500ml)', 'duration_minutes' => 2, 'icon' => 'fa-glass-water'],
                    ['title' => 'Mid-morning water break', 'duration_minutes' => 2, 'icon' => 'fa-glass-water'],
                    ['title' => 'Afternoon hydration', 'duration_minutes' => 2, 'icon' => 'fa-glass-water'],
                    ['title' => 'Pre-dinner water', 'duration_minutes' => 2, 'icon' => 'fa-glass-water'],
                    ['title' => 'Evening hydration', 'duration_minutes' => 2, 'icon' => 'fa-glass-water']
                ]
            ]
        ];

        $templateStmt = $db->prepare("INSERT INTO routine_templates (id, title, description, type, cycle_phase, category, duration_minutes, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $itemStmt = $db->prepare("INSERT INTO routine_template_items (id, template_id, title, duration_minutes, order_index, icon) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($templates as $template) {
            $templateStmt->execute([
                $template['id'],
                $template['title'],
                $template['description'],
                $template['type'],
                $template['cycle_phase'],
                $template['category'],
                $template['duration_minutes']
            ]);

            foreach ($template['items'] as $index => $item) {
                $itemStmt->execute([
                    'rti_' . uniqid(),
                    $template['id'],
                    $item['title'],
                    $item['duration_minutes'],
                    $index,
                    $item['icon']
                ]);
            }
        }
        echo "Routine templates seeded.\n";
    }

    // Seed Habit Templates
    $check_habits = $db->query("SELECT count(*) FROM habit_templates")->fetchColumn();
    if ($check_habits == 0) {
        $habitTemplates = [
            ['id' => 'habit_water', 'title' => 'Drink 8 glasses of water', 'description' => 'Stay hydrated for optimal wellness', 'category' => 'hydration', 'icon' => 'fa-glass-water', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_steps', 'title' => 'Walk 10,000 steps', 'description' => 'Daily movement goal for heart health', 'category' => 'movement', 'icon' => 'fa-person-walking', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_sleep', 'title' => 'In bed by 10 PM', 'description' => 'Prioritize rest and recovery', 'category' => 'self_care', 'icon' => 'fa-bed', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_veggies', 'title' => 'Eat 5 servings of vegetables', 'description' => 'Nutrient-dense whole foods', 'category' => 'nutrition', 'icon' => 'fa-carrot', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_meditation', 'title' => 'Meditate for 10 minutes', 'description' => 'Mindfulness practice for stress relief', 'category' => 'mindfulness', 'icon' => 'fa-spa', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_journal', 'title' => 'Write in journal', 'description' => 'Reflect on your day and emotions', 'category' => 'mindfulness', 'icon' => 'fa-book', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_stretch', 'title' => 'Morning stretch routine', 'description' => '5-10 minutes of gentle stretching', 'category' => 'movement', 'icon' => 'fa-person', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_protein', 'title' => 'Eat protein at every meal', 'description' => 'Support muscle health and satiety', 'category' => 'nutrition', 'icon' => 'fa-drumstick-bite', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_nophone', 'title' => 'No phone 1 hour before bed', 'description' => 'Reduce blue light for better sleep', 'category' => 'self_care', 'icon' => 'fa-mobile-screen', 'recommended_frequency' => 'daily'],
            ['id' => 'habit_skincare', 'title' => 'Complete skincare routine', 'description' => 'Cleanse, tone, moisturize daily', 'category' => 'self_care', 'icon' => 'fa-sparkles', 'recommended_frequency' => 'daily']
        ];

        $habitStmt = $db->prepare("INSERT INTO habit_templates (id, title, description, category, icon, recommended_frequency, is_system) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($habitTemplates as $habit) {
            $habitStmt->execute(array_values($habit));
        }
        echo "Habit templates seeded.\n";
    }

    // Seed Exercise Library
    $check_exercises = $db->query("SELECT count(*) FROM exercise_library")->fetchColumn();
    if ($check_exercises == 0) {
        $exercises = [
            // Cardio
            ['id' => 'ex_walking', 'name' => 'Walking', 'category' => 'cardio', 'muscle_group' => 'full_body', 'difficulty' => 'easy', 'description' => 'Low-impact aerobic exercise', 'is_cardio' => 1, 'equipment_needed' => 'None'],
            ['id' => 'ex_running', 'name' => 'Running', 'category' => 'cardio', 'muscle_group' => 'full_body', 'difficulty' => 'medium', 'description' => 'High-impact cardio', 'is_cardio' => 1, 'equipment_needed' => 'None'],
            ['id' => 'ex_cycling', 'name' => 'Cycling', 'category' => 'cardio', 'muscle_group' => 'legs', 'difficulty' => 'medium', 'description' => 'Low-impact cardio', 'is_cardio' => 1, 'equipment_needed' => 'Bike'],
            ['id' => 'ex_swimming', 'name' => 'Swimming', 'category' => 'cardio', 'muscle_group' => 'full_body', 'difficulty' => 'medium', 'description' => 'Full body low-impact cardio', 'is_cardio' => 1, 'equipment_needed' => 'Pool'],
            ['id' => 'ex_jumprope', 'name' => 'Jump Rope', 'category' => 'cardio', 'muscle_group' => 'full_body', 'difficulty' => 'medium', 'description' => 'High-intensity cardio', 'is_cardio' => 1, 'equipment_needed' => 'Jump rope'],
            // Strength
            ['id' => 'ex_squats', 'name' => 'Squats', 'category' => 'strength', 'muscle_group' => 'legs', 'difficulty' => 'easy', 'description' => 'Lower body compound exercise', 'is_strength' => 1, 'equipment_needed' => 'None or Dumbbells'],
            ['id' => 'ex_pushups', 'name' => 'Push-ups', 'category' => 'strength', 'muscle_group' => 'chest_arms', 'difficulty' => 'medium', 'description' => 'Upper body push exercise', 'is_strength' => 1, 'equipment_needed' => 'None'],
            ['id' => 'ex_lunges', 'name' => 'Lunges', 'category' => 'strength', 'muscle_group' => 'legs', 'difficulty' => 'medium', 'description' => 'Single-leg strength exercise', 'is_strength' => 1, 'equipment_needed' => 'None or Dumbbells'],
            ['id' => 'ex_plank', 'name' => 'Plank', 'category' => 'strength', 'muscle_group' => 'core', 'difficulty' => 'medium', 'description' => 'Isometric core exercise', 'is_strength' => 1, 'equipment_needed' => 'None'],
            ['id' => 'ex_deadlift', 'name' => 'Deadlifts', 'category' => 'strength', 'muscle_group' => 'full_body', 'difficulty' => 'hard', 'description' => 'Full body compound lift', 'is_strength' => 1, 'equipment_needed' => 'Barbell or Dumbbells'],
            // Flexibility
            ['id' => 'ex_yoga', 'name' => 'Yoga Flow', 'category' => 'flexibility', 'muscle_group' => 'full_body', 'difficulty' => 'easy', 'description' => 'Mind-body flexibility practice', 'is_cardio' => 0, 'equipment_needed' => 'Yoga mat'],
            ['id' => 'ex_stretching', 'name' => 'Static Stretching', 'category' => 'flexibility', 'muscle_group' => 'full_body', 'difficulty' => 'easy', 'description' => 'Gentle stretching routine', 'is_cardio' => 0, 'equipment_needed' => 'None'],
            ['id' => 'ex_pilates', 'name' => 'Pilates', 'category' => 'flexibility', 'muscle_group' => 'core', 'difficulty' => 'medium', 'description' => 'Core-focused movement', 'is_cardio' => 0, 'equipment_needed' => 'Mat']
        ];

        $exStmt = $db->prepare("INSERT INTO exercise_library (id, name, category, muscle_group, difficulty, description, is_cardio, is_strength, equipment_needed, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($exercises as $ex) {
            $exStmt->execute([
                $ex['id'],
                $ex['name'],
                $ex['category'],
                $ex['muscle_group'],
                $ex['difficulty'],
                $ex['description'],
                $ex['is_cardio'] ?? 0,
                $ex['is_strength'] ?? 0,
                $ex['equipment_needed']
            ]);
        }
        echo "Exercise library seeded.\n";
    }

    // Seed Reflection Prompts
    $check_prompts = $db->query("SELECT count(*) FROM reflection_prompts")->fetchColumn();
    if ($check_prompts == 0) {
        $prompts = [
            ['id' => 'pr_grat1', 'text' => 'What are three things you\'re grateful for today?', 'category' => 'gratitude'],
            ['id' => 'pr_grat2', 'text' => 'Who made a positive impact on your day and how?', 'category' => 'gratitude'],
            ['id' => 'pr_grow1', 'text' => 'What\'s one thing you learned about yourself today?', 'category' => 'growth'],
            ['id' => 'pr_grow2', 'text' => 'What challenge did you face and how did you handle it?', 'category' => 'growth'],
            ['id' => 'pr_well1', 'text' => 'How is your energy level today? What affected it?', 'category' => 'wellness'],
            ['id' => 'pr_well2', 'text' => 'What self-care practice would benefit you right now?', 'category' => 'wellness'],
            ['id' => 'pr_cycle1', 'text' => 'How is your body feeling in this cycle phase?', 'category' => 'cycle'],
            ['id' => 'pr_cycle2', 'text' => 'What does your body need most right now?', 'category' => 'cycle']
        ];

        $promptStmt = $db->prepare("INSERT INTO reflection_prompts (id, prompt_text, category, is_system) VALUES (?, ?, ?, 1)");
        foreach ($prompts as $prompt) {
            $promptStmt->execute([$prompt['id'], $prompt['text'], $prompt['category']]);
        }
        echo "Reflection prompts seeded.\n";
    }

    // Seed Achievements
    $check_achievements = $db->query("SELECT count(*) FROM achievements")->fetchColumn();
    if ($check_achievements == 0) {
        $achievements = [
            // Habits
            ['id' => 'ach_habit_first', 'title' => 'First Steps', 'description' => 'Complete your first habit', 'category' => 'habits', 'icon' => 'fa-check', 'points' => 10, 'badge_color' => 'bg-emerald-500', 'tier' => 'bronze', 'condition_type' => 'count', 'condition_value' => 1],
            ['id' => 'ach_habit_week', 'title' => 'Week Warrior', 'description' => 'Maintain a 7-day habit streak', 'category' => 'habits', 'icon' => 'fa-fire', 'points' => 50, 'badge_color' => 'bg-amber-500', 'tier' => 'silver', 'condition_type' => 'streak', 'condition_value' => 7],
            // Wellness
            ['id' => 'ach_water_hero', 'title' => 'Hydration Hero', 'description' => 'Log water intake for 30 days', 'category' => 'wellness', 'icon' => 'fa-droplet', 'points' => 100, 'badge_color' => 'bg-cyan-500', 'tier' => 'gold', 'condition_type' => 'count', 'condition_value' => 30],
            ['id' => 'ach_sleep_master', 'title' => 'Sleep Master', 'description' => 'Log 8+ hours of sleep 5 times', 'category' => 'wellness', 'icon' => 'fa-bed', 'points' => 75, 'badge_color' => 'bg-indigo-500', 'tier' => 'silver', 'condition_type' => 'count', 'condition_value' => 5],
            // Social
            ['id' => 'ach_social_first', 'title' => 'Community Voice', 'description' => 'Post your first message in cohort', 'category' => 'social', 'icon' => 'fa-comments', 'points' => 20, 'badge_color' => 'bg-purple-500', 'tier' => 'bronze', 'condition_type' => 'count', 'condition_value' => 1],
            // Milestones
            ['id' => 'ach_level_5', 'title' => 'Rising Star', 'description' => 'Reach Level 5', 'category' => 'milestones', 'icon' => 'fa-star', 'points' => 200, 'badge_color' => 'bg-rose-500', 'tier' => 'gold', 'condition_type' => 'level', 'condition_value' => 5]
        ];

        $achStmt = $db->prepare("INSERT INTO achievements (id, title, description, category, icon, points, badge_color, tier, condition_type, condition_value, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($achievements as $ach) {
            $achStmt->execute([
                $ach['id'],
                $ach['title'],
                $ach['description'],
                $ach['category'],
                $ach['icon'],
                $ach['points'],
                $ach['badge_color'],
                $ach['tier'],
                $ach['condition_type'],
                $ach['condition_value']
            ]);
        }
        echo "Achievements seeded.\n";
    }

    echo "Database initialization complete.\n";

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
?>