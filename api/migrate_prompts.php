<?php
include_once 'config/Database.php';

$database = new Database();
$db = $database->getConnection();

echo "Migrating AI Prompts...\n";

try {
    $sql_prompts = "CREATE TABLE IF NOT EXISTS ai_prompts (
        prompt_key VARCHAR(50) PRIMARY KEY,
        prompt_text TEXT NOT NULL,
        description VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql_prompts);
    echo "AIPrompts table (referenced in setup_db.php) created/verified.\n";

    // Seed if empty
    $check = $db->query("SELECT count(*) FROM ai_prompts")->fetchColumn();
    if ($check == 0) {
        $prompts = [
            [
                'coach_system',
                "You are Zenith, a high-performance Metabolic & PCOS Accountability Coach. \nYour mission: Help users optimize their metabolic health and manage PCOS symptoms through precision data analysis and behavioral coaching.\n\nUser Profile:\n- Name: {{name}}\n- Persona: {{persona}}\n\nCurrent Metrics:\n- Focus: {{focus}} mins.\n- Macros: {{calories}}kcal ({{protein}}g P, {{carbs}}g C, {{fats}}g F).\n- Last Mood: {{mood}}.\n\nCoaching Style:\n1. Direct & Scientific: Use metabolic terms (e.g., insulin sensitivity, muscle synthesis, ketogenic threshold).\n2. Accountability First: If they are off-track on macros or focus, call it out firmly but supportively.\n3. Actionable: Every response must end with one specific metabolic or focus task.\n4. Conciseness: Maximum 3 short paragraphs.",
                "System prompt for the main coaching chat."
            ],
            [
                'json_system',
                "You are a JSON generator. Output ONLY JSON.",
                "System prompt for JSON output tasks."
            ],
            [
                'meal_analysis',
                "Analyze this meal photo for a PCOS/Metabolic Reset protocol.\n1. Identify all food items.\n2. Estimate Macros (Protein, Carbs, Fats, Calories).\n3. Provide a 'PCOS Score' (1-10) based on glycemic load and anti-inflammatory properties.\n4. Give a 'Metabolic Verdict' (Is this safe for the current reset phase?).\nReturn the analysis strictly in JSON format as specified.",
                "Prompt for vision analysis."
            ]
        ];

        $stmt = $db->prepare("INSERT INTO ai_prompts (prompt_key, prompt_text, description) VALUES (?, ?, ?)");
        foreach ($prompts as $p) {
            $stmt->execute($p);
        }
        echo "Prompts seeded.\n";
    } else {
        echo "Prompts already exist.\n";
    }

} catch (PDOException $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
?>