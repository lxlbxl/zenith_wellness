<?php
// api/services/VariantGeneratorService.php
// ===========================================
// B.3 — AI Variant Generator
// Loads brief + active insights + prior attempts,
// builds the B.3a prompt, calls AI via OpenRouter/Gemini,
// parses and validates against config_schema,
// returns candidates.
// ===========================================

class VariantGeneratorService
{
    private PDO $db;
    private array $providerCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ===========================================
    // PUBLIC API
    // ===========================================

    /**
     * Generate variant candidates for a given surface.
     *
     * @param string      $surface       One of: sales_trust, quiz_landing, checkout, pricing, signup
     * @param string|null $experimentId  If generating for an existing experiment
     * @param string|null $segmentKey    Segment to target (e.g. 'src:meta|dev:mobile')
     * @param int         $candidateCount How many candidates to request
     *
     * @return array {
     *   success: bool,
     *   candidates: array,
     *   generationId: string|null,
     *   error?: string
     * }
     */
    public function generate(
        string  $surface,
        ?string $experimentId = null,
        ?string $segmentKey   = null,
        int     $candidateCount = 3
    ): array {
        try {
            // 1. Load brief
            $brief = $this->loadBrief($surface);
            if (!$brief) {
                return [
                    'success' => false,
                    'candidates' => [],
                    'generationId' => null,
                    'error' => "No variant brief found for surface: {$surface}"
                ];
            }

            $configSchema = json_decode($brief['config_schema'], true);
            if (!$configSchema) {
                return [
                    'success' => false,
                    'candidates' => [],
                    'generationId' => null,
                    'error' => "Invalid config_schema for surface: {$surface}"
                ];
            }

            // 2. Load active insights
            $insights = $this->loadActiveInsights($surface, $segmentKey);

            // 3. Load prior attempts (ALREADY TESTED)
            $priorAttempts = $this->loadPriorAttempts($surface, 5);

            // 4. Build the B.3a prompt
            $prompt = $this->buildPrompt($brief, $insights, $priorAttempts, $candidateCount);

            // 5. Call AI
            $aiResponse = $this->callAI($prompt);

            // 6. Parse and validate
            $parsed = $this->parseAIResponse($aiResponse);
            if (!$parsed['success']) {
                return [
                    'success' => false,
                    'candidates' => [],
                    'generationId' => null,
                    'error' => $parsed['error'] ?? 'Failed to parse AI response'
                ];
            }

            // 7. Validate each candidate against config_schema
            $validCandidates = $this->validateCandidates($parsed['candidates'], $configSchema);

            // 8. Record generation
            $generationId = $this->recordGeneration(
                $brief, $prompt, $insights, $experimentId, $segmentKey,
                $aiResponse, $validCandidates
            );

            // 9. Insert candidates
            $this->insertCandidates($generationId, $validCandidates, $experimentId);

            return [
                'success' => true,
                'candidates' => $validCandidates,
                'generationId' => $generationId,
                'insights_used' => count($insights),
                'prior_attempts_consulted' => count($priorAttempts)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'candidates' => [],
                'generationId' => null,
                'error' => 'Generation failed: ' . $e->getMessage()
            ];
        }
    }

    // ===========================================
    // 1. LOAD BRIEF
    // ===========================================

    private function loadBrief(string $surface): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, surface, brand_voice, value_props, forbidden_claims,
                   target_personas, reference_winners, config_schema
            FROM variant_briefs
            WHERE surface = :surface
            LIMIT 1
        ");
        $stmt->execute([':surface' => $surface]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ===========================================
    // 2. LOAD ACTIVE INSIGHTS
    // ===========================================

    private function loadActiveInsights(string $surface, ?string $segmentKey): array
    {
        $sql = "
            SELECT pattern_tag, direction, effect_size, confidence, summary, weight
            FROM variant_insights
            WHERE surface = :surface
              AND is_active = 1
        ";
        $params = [':surface' => $surface];

        if ($segmentKey) {
            $sql .= " AND (segment_key = :seg OR segment_key IS NULL)";
            $params[':seg'] = $segmentKey;
        }

        $sql .= " ORDER BY weight DESC, confidence DESC LIMIT 20";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ===========================================
    // 3. LOAD PRIOR ATTEMPTS
    // ===========================================

    private function loadPriorAttempts(string $surface, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT vg.id, vg.prompt_used, vg.insights_injected,
                   vc.id AS candidate_id, vc.config, vc.rationale,
                   vc.safety_flag, vc.review_status,
                   vc.promoted_variant_id
            FROM variant_generations vg
            LEFT JOIN variant_candidates vc ON vc.generation_id = vg.id
            WHERE vg.surface = :surface
            ORDER BY vg.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':surface', $surface, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ===========================================
    // 4. BUILD B.3a PROMPT
    // ===========================================

    private function buildPrompt(array $brief, array $insights, array $priorAttempts, int $candidateCount): string
    {
        $surface = $brief['surface'];
        $surfaceGoalMap = [
            'sales_trust'  => 'Build trust and credibility so the visitor feels safe proceeding to the quiz.',
            'quiz_landing' => 'Drive quiz starts by making the visitor curious about their personal results.',
            'checkout'     => 'Overcome last-minute purchase anxiety and convert the visitor to a paying member.',
            'pricing'      => 'Present value so clearly that the mid-tier or premium plan feels like the obvious choice.',
            'signup'       => 'Minimise account-creation friction and get the visitor to register for free.',
        ];
        $goal = $surfaceGoalMap[$surface] ?? 'Convert the visitor.';

        $valueProps = json_decode($brief['value_props'], true) ?? [];
        $forbiddenClaims = json_decode($brief['forbidden_claims'], true) ?? [];
        $refWinners = json_decode($brief['reference_winners'], true) ?? [];

        $prompt = "# B.3a — Variant Generation Prompt\n\n";

        // --- ROLE ---
        $prompt .= "## ROLE\n";
        $prompt .= "You are Zenith's senior conversion copywriter. You specialise in women's health, cycle-synced wellness, and metabolic optimisation. ";
        $prompt .= "You write copy that converts without betraying trust. You NEVER use fear, shame, or medical misinformation.\n\n";

        // --- BRAND VOICE ---
        $prompt .= "## BRAND VOICE\n";
        $prompt .= trim($brief['brand_voice']) . "\n\n";

        // --- TRUE VALUE PROPS ---
        $prompt .= "## TRUE VALUE PROPS\n";
        $prompt .= "These are the genuine, provable value propositions you may reference. Do NOT invent new ones:\n";
        foreach ($valueProps as $vp) {
            $prompt .= "- **{$vp['headline']}**: {$vp['body']}\n";
        }
        $prompt .= "\n";

        // --- FORBIDDEN CLAIMS ---
        $prompt .= "## FORBIDDEN CLAIMS (NEVER CROSS THESE LINES)\n";
        $prompt .= "These are strictly prohibited. Violating any will get the variant rejected:\n";
        foreach ($forbiddenClaims as $i => $claim) {
            $prompt .= ($i + 1) . ". {$claim}\n";
        }
        $prompt .= "\n";

        // --- SURFACE & GOAL ---
        $prompt .= "## SURFACE & GOAL\n";
        $prompt .= "**Surface:** {$surface}\n";
        $prompt .= "**Conversion goal:** {$goal}\n\n";

        // --- WHAT WE HAVE LEARNED ---
        if (!empty($insights)) {
            $prompt .= "## WHAT WE HAVE LEARNED (from completed experiments)\n";
            $prompt .= "These are data-backed insights. Weight them by confidence and effect size:\n";
            foreach ($insights as $insight) {
                $dir = strtoupper($insight['direction']);
                $effect = $insight['effect_size'] ? sprintf(' effect: %+.1f%%', $insight['effect_size'] * 100) : '';
                $conf = $insight['confidence'] ? sprintf(' confidence: %.0f%%', $insight['confidence'] * 100) : '';
                $weight = $insight['weight'] ? sprintf(' weight: %.2f', $insight['weight']) : '';
                $prompt .= "- [{$dir}] pattern:{$insight['pattern_tag']}{$effect}{$conf}{$weight}\n";
                if ($insight['summary']) {
                    $prompt .= "  → {$insight['summary']}\n";
                }
            }
            $prompt .= "\n";
        }

        // --- ALREADY TESTED ---
        $seenConfigs = [];
        if (!empty($priorAttempts)) {
            $prompt .= "## ALREADY TESTED (avoid repeating these approaches)\n";
            foreach ($priorAttempts as $attempt) {
                if ($attempt['config'] && !in_array($attempt['config'], $seenConfigs, true)) {
                    $config = json_decode($attempt['config'], true);
                    $status = $attempt['promoted_variant_id'] ? ' [WON — do not reuse]' : ' [' . ($attempt['review_status'] ?? 'unknown') . ']';
                    $prompt .= "- Config: " . json_encode($config) . "{$status}\n";
                    if ($attempt['rationale']) {
                        $prompt .= "  Rationale: {$attempt['rationale']}\n";
                    }
                    $seenConfigs[] = $attempt['config'];
                }
            }
            $prompt .= "\n";
        } else {
            $prompt .= "## ALREADY TESTED\n";
            $prompt .= "No prior variants have been tested for this surface. You are generating the first set.\n\n";
        }

        // --- REFERENCE WINNERS ---
        $prompt .= "## REFERENCE EXAMPLES (high-converting past variants)\n";
        $prompt .= "Study these for tone, structure, and what works — but do NOT copy:\n";
        foreach ($refWinners as $winner) {
            if (($winner['surface'] ?? '') === $surface) {
                $prompt .= "- CVR {$winner['conversion_rate']}: " . json_encode($winner['variant']) . "\n";
                $prompt .= "  Rationale: {$winner['rationale']}\n";
            }
        }
        $prompt .= "\n";

        // --- OUTPUT ---
        $prompt .= "## OUTPUT INSTRUCTIONS\n";
        $prompt .= "Generate **{$candidateCount} distinct variants** for the {$surface} surface.\n\n";
        $prompt .= "Return a JSON object with this exact structure:\n";
        $prompt .= '```json' . "\n";
        $prompt .= "{\n";
        $prompt .= "  \"candidates\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"config\": {<surface-specific fields described below>},\n";
        $prompt .= "      \"rationale\": \"Why this variant will convert\",\n";
        $prompt .= "      \"pattern_tags\": [\"tag1\", \"tag2\"]\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= '```' . "\n\n";
        $prompt .= "Each candidate's `config` object MUST conform to this schema:\n";
        $prompt .= json_encode($configSchema = json_decode($brief['config_schema'], true), JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Required fields: " . implode(', ', $configSchema['required'] ?? []) . "\n\n";
        $prompt .= "Pattern tags describe the approach (choose from): question_headline, loss_framed, gain_framed, short, long_form, social_proof_heavy, urgency, curiosity_gap, personalisation, authority, comparison, how_to, listicle, testimonial_led, benefit_focused, feature_focused, minimalist, conversational, bold_statement\n\n";
        $prompt .= "IMPORTANT:\n";
        $prompt .= "- Each variant MUST be meaningfully different from the others\n";
        $prompt .= "- Variants MUST differ from the ALREADY TESTED section above\n";
        $prompt .= "- Stay within maxLength limits specified in the schema\n";
        $prompt .= "- NEVER include forbidden claims\n";
        $prompt .= "- Use brand voice: warm, direct, science-grounded\n";
        $prompt .= "- Output valid JSON only — no additional text before or after";

        return $prompt;
    }

    // ===========================================
    // 5. CALL AI (OpenRouter with Gemini fallback)
    // ===========================================

    private function callAI(string $prompt): string
    {
        $provider = $this->getSetting('ai_provider') ?: 'openrouter';
        $model = $this->getSetting('ai_default_model') ?: 'gemini-1.5-flash';

        switch ($provider) {
            case 'openrouter':
                $apiKey = $this->getSetting('openrouter_api_key') ?: (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
                if ($apiKey) {
                    return $this->callOpenRouter($prompt, $apiKey, $model);
                }
                // fall through to gemini
            default:
                $apiKey = $this->getSetting('gemini_api_key') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
                if (!$apiKey) {
                    throw new RuntimeException('No AI API key configured. Set gemini_api_key or openrouter_api_key in system_settings.');
                }
                return $this->callGemini($prompt, $apiKey, $model);
        }
    }

    private function callOpenRouter(string $prompt, string $apiKey, string $model): string
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.8,
            'max_tokens' => 4096,
            'response_format' => ['type' => 'json_object']
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://zenithwellness.app',
            'X-Title: Zenith Wellness'
        ];

        return $this->makeRequest($url, $data, $headers);
    }

    private function callGemini(string $prompt, string $apiKey, string $model): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json'
            ]
        ];

        return $this->makeRequest($url, $data);
    }

    private function makeRequest(string $url, array $data, array $customHeaders = []): string
    {
        $headers = "Content-Type: application/json\r\n";
        foreach ($customHeaders as $header) {
            $headers .= $header . "\r\n";
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($data),
                'timeout' => 60,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        return $result;
    }

    // ===========================================
    // 6. PARSE AI RESPONSE
    // ===========================================

    private function parseAIResponse(string $rawResponse): array
    {
        $parsed = json_decode($rawResponse, true);
        if (!$parsed) {
            return [
                'success' => false,
                'error' => 'AI response was not valid JSON',
                'raw' => substr($rawResponse, 0, 500)
            ];
        }

        // Handle Gemini format
        $text = null;
        if (isset($parsed['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $parsed['candidates'][0]['content']['parts'][0]['text'];
        } elseif (isset($parsed['choices'][0]['message']['content'])) {
            $text = $parsed['choices'][0]['message']['content'];
        } elseif (isset($parsed['candidates'])) {
            // Already parsed as our expected JSON candidate array
            $text = json_encode($parsed);
        }

        if (!$text) {
            return [
                'success' => false,
                'error' => 'Could not extract content from AI response',
                'raw' => substr(json_encode($parsed), 0, 500)
            ];
        }

        // Strip markdown fences
        $clean = preg_replace('/```(?:json)?\s*\n?|\n?\s*```/', '', $text);
        $clean = trim($clean);

        $result = json_decode($clean, true);
        if (!$result) {
            return [
                'success' => false,
                'error' => 'AI response content was not valid JSON',
                'raw' => substr($clean, 0, 500)
            ];
        }

        $candidates = $result['candidates'] ?? $result;
        if (isset($candidates[0]['config'])) {
            // Already in our expected format
            return ['success' => true, 'candidates' => $candidates];
        }

        // Wrap single or flat candidate arrays
        if (isset($candidates[0]) && is_array($candidates[0])) {
            $wrapped = [];
            foreach ($candidates as $c) {
                $wrapped[] = [
                    'config' => $c,
                    'rationale' => $c['rationale'] ?? '',
                    'pattern_tags' => $c['pattern_tags'] ?? []
                ];
            }
            return ['success' => true, 'candidates' => $wrapped];
        }

        return [
            'success' => false,
            'error' => 'Unexpected AI response structure',
            'raw' => substr($clean, 0, 500)
        ];
    }

    // ===========================================
    // 7. VALIDATE CANDIDATES AGAINST SCHEMA
    // ===========================================

    private function validateCandidates(array $candidates, array $schema): array
    {
        $required = $schema['required'] ?? [];
        $props = $schema['properties'] ?? [];
        $valid = [];

        foreach ($candidates as $candidate) {
            $config = $candidate['config'] ?? $candidate;
            if (!is_array($config)) {
                continue;
            }

            // Check required fields
            $missing = [];
            foreach ($required as $field) {
                if (!isset($config[$field]) || (is_string($config[$field]) && trim($config[$field]) === '')) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                continue;
            }

            // Check maxLength constraints
            $violation = false;
            foreach ($props as $field => $constraints) {
                if (isset($config[$field], $constraints['maxLength']) && is_string($config[$field])) {
                    if (mb_strlen($config[$field]) > $constraints['maxLength']) {
                        $violation = true;
                        break;
                    }
                }
                // Check enum constraints
                if (isset($config[$field], $constraints['enum']) && is_string($config[$field])) {
                    if (!in_array($config[$field], $constraints['enum'], true)) {
                        $violation = true;
                        break;
                    }
                }
            }
            if ($violation) {
                continue;
            }

            // Validate arrays
            foreach ($props as $field => $constraints) {
                if (isset($config[$field], $constraints['minItems']) && is_array($config[$field])) {
                    if (count($config[$field]) < $constraints['minItems']) {
                        $violation = true;
                        break;
                    }
                }
                if (isset($config[$field], $constraints['maxItems']) && is_array($config[$field])) {
                    if (count($config[$field]) > $constraints['maxItems']) {
                        $violation = true;
                        break;
                    }
                }
            }
            if ($violation) {
                continue;
            }

            $valid[] = [
                'config' => $config,
                'rationale' => $candidate['rationale'] ?? '',
                'pattern_tags' => $candidate['pattern_tags'] ?? []
            ];
        }

        return $valid;
    }

    // ===========================================
    // 8. RECORD GENERATION
    // ===========================================

    private function recordGeneration(
        array  $brief,
        string $prompt,
        array  $insights,
        ?string $experimentId,
        ?string $segmentKey,
        string $aiResponse,
        array  $validCandidates
    ): string {
        $id = $this->uuid();
        $tokensUsed = $this->estimateTokenUsage($prompt, $aiResponse);

        $stmt = $this->db->prepare("
            INSERT INTO variant_generations
                (id, experiment_id, surface, segment_key, brief_id,
                 prompt_used, insights_injected, model, raw_response,
                 candidate_count, tokens_used, actor)
            VALUES
                (:id, :experimentId, :surface, :segmentKey, :briefId,
                 :promptUsed, :insightsInjected, :model, :rawResponse,
                 :candidateCount, :tokensUsed, :actor)
        ");

        $stmt->execute([
            ':id'               => $id,
            ':experimentId'     => $experimentId,
            ':surface'          => $brief['surface'],
            ':segmentKey'       => $segmentKey,
            ':briefId'          => $brief['id'],
            ':promptUsed'       => $prompt,
            ':insightsInjected' => json_encode(array_map(function ($i) {
                return ['pattern_tag' => $i['pattern_tag'], 'direction' => $i['direction']];
            }, $insights)),
            ':model'            => $this->getSetting('ai_default_model') ?: 'gemini-1.5-flash',
            ':rawResponse'      => $aiResponse,
            ':candidateCount'   => count($validCandidates),
            ':tokensUsed'       => $tokensUsed,
            ':actor'            => 'engine'
        ]);

        return $id;
    }

    // ===========================================
    // 9. INSERT CANDIDATES
    // ===========================================

    private function insertCandidates(string $generationId, array $candidates, ?string $experimentId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO variant_candidates
                (id, generation_id, experiment_id, config, rationale,
                 pattern_tags, safety_flag)
            VALUES
                (:id, :generationId, :experimentId, :config, :rationale,
                 :patternTags, :safetyFlag)
        ");

        foreach ($candidates as $candidate) {
            $stmt->execute([
                ':id'           => $this->uuid(),
                ':generationId' => $generationId,
                ':experimentId' => $experimentId,
                ':config'       => json_encode($candidate['config']),
                ':rationale'    => $candidate['rationale'] ?? null,
                ':patternTags'  => json_encode($candidate['pattern_tags'] ?? []),
                ':safetyFlag'   => 'needs_review'
            ]);
        }
    }

    // ===========================================
    // HELPERS
    // ===========================================

    private function getSetting(string $key): ?string
    {
        if (isset($this->providerCache[$key])) {
            return $this->providerCache[$key];
        }

        $stmt = $this->db->prepare("SELECT setting_value, is_encrypted FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $constName = strtoupper($key);
            $value = defined($constName) ? constant($constName) : null;
            $this->providerCache[$key] = $value;
            return $value;
        }

        $value = $row['is_encrypted'] ? $this->decrypt($row['setting_value']) : $row['setting_value'];
        $this->providerCache[$key] = $value;
        return $value;
    }

    private function decrypt(string $value): string
    {
        if (empty($value)) {
            return $value;
        }
        try {
            $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'zenith_default_key_change_me_32ch';
            $parts = explode('::', base64_decode($value), 2);
            if (count($parts) !== 2) {
                return $value;
            }
            [$iv, $encrypted] = $parts;
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
            return $decrypted !== false ? $decrypted : $value;
        } catch (Exception $e) {
            return $value;
        }
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Rough token estimation (chars / 4). Not exact, good enough for record-keeping.
     */
    private function estimateTokenUsage(string $prompt, string $response): int
    {
        $promptTokens = (int) ceil(mb_strlen($prompt) / 4);
        $responseTokens = (int) ceil(mb_strlen($response) / 4);
        return $promptTokens + $responseTokens;
    }

    // ===========================================
    // SURFACE HELPERS
    // ===========================================

    /**
     * List all available surfaces that have briefs.
     */
    public function listSurfaces(): array
    {
        $stmt = $this->db->query("SELECT surface, updated_at FROM variant_briefs ORDER BY surface");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the brief for a surface (includes full content).
     */
    public function getBrief(string $surface): ?array
    {
        return $this->loadBrief($surface);
    }
}
