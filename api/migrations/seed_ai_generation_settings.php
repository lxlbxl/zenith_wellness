<?php
/**
 * Seed AI variant generation system settings.
 *
 * Run AFTER variant_generation_schema.sql and the main schema migration.
 * Adds the kill switch and cost ceiling settings used by propose_challengers.php.
 */

include_once __DIR__ . '/../config/Database.php';

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["message" => "Database connection failed"]);
    exit();
}

$settings = [
    [
        'key' => 'EXPERIMENT_AI_GENERATION_ENABLED',
        'value' => '1',
        'group' => 'ai',
        'description' => 'Master kill switch for AI variant generation (1=enabled, 0=disabled)',
        'encrypted' => 0,
    ],
    [
        'key' => 'AI_USAGE_COST_CEILING',
        'value' => '50.00',
        'group' => 'ai',
        'description' => 'Monthly AI generation cost ceiling in USD',
        'encrypted' => 0,
    ],
    [
        'key' => 'ai_provider',
        'value' => 'gemini',
        'group' => 'ai',
        'description' => 'AI provider for variant generation (gemini, openrouter)',
        'encrypted' => 0,
    ],
    [
        'key' => 'ai_default_model',
        'value' => 'gemini-1.5-flash',
        'group' => 'ai',
        'description' => 'Default AI model for variant generation',
        'encrypted' => 0,
    ],
];

try {
    $stmt = $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_encrypted)
        VALUES (:key, :value, :group, :desc, :enc)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            description = VALUES(description)
    ");

    $count = 0;
    foreach ($settings as $s) {
        $stmt->execute([
            ':key' => $s['key'],
            ':value' => $s['value'],
            ':group' => $s['group'],
            ':desc' => $s['description'],
            ':enc' => $s['encrypted'],
        ]);
        $count++;
    }

    echo json_encode([
        "message" => "AI generation settings seeded",
        "count" => $count,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "message" => "Error seeding settings",
        "error" => $e->getMessage(),
    ]);
}
