<?php
// api/services/VariantSafetyService.php
// ===========================================
// B.4 — Two-Pass Variant Safety Check
// Determinstic forbidden-claims scan first,
// then AI compliance review, before any
// candidate reaches the human approval queue.
// ===========================================

class VariantSafetyService
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
     * Run both safety passes on a candidate variant.
     *
     * @param string $candidateId  The variant_candidates.id
     * @return array {
     *   candidate_id: string,
     *   passes: array,            // per-pass results
     *   overall_verdict: string,  // clean | needs_review | reject
     *   safety_notes: array       // aggregated notes
     * }
     */
    public function check(string $candidateId): array
    {
        // 1. Load candidate + brief
        $candidate = $this->loadCandidate($candidateId);
        if (!$candidate) {
            return [
                'candidate_id' => $candidateId,
                'success' => false,
                'error' => 'Candidate not found',
            ];
        }

        $brief = $this->loadBriefForCandidate($candidate);
        $forbiddenClaims = $brief ? (json_decode($brief['forbidden_claims'], true) ?? []) : [];
        $surface = $brief ? $brief['surface'] : ($candidate['surface'] ?? 'unknown');

        // 2. Pass 1 — Deterministic scan
        $pass1 = $this->deterministicScan($candidate, $forbiddenClaims);

        // Record pass 1
        $this->logCheck($candidateId, 'deterministic', $pass1['verdict'], [
            'matches' => $pass1['matches'],
            'surface' => $surface,
        ], null, $pass1['latency_ms']);

        // Short-circuit on hard reject from deterministic pass
        if ($pass1['verdict'] === 'reject') {
            $this->updateCandidateSafety($candidateId, 'rejected', $pass1['notes']);
            return $this->buildResult($candidateId, [$pass1], $pass1['verdict'], $pass1['notes']);
        }

        // 3. Pass 2 — AI claims-review
        $pass2 = $this->aiReview($candidate, $forbiddenClaims, $surface);

        // Record pass 2
        $this->logCheck($candidateId, 'ai_review', $pass2['verdict'], [
            'flagged_phrases' => $pass2['flagged_phrases'] ?? [],
            'review_rationale' => $pass2['rationale'] ?? '',
        ], $pass2['model_used'] ?? null, $pass2['latency_ms'] ?? null);

        // 4. Combine verdicts (most severe wins)
        $overall = $this->combineVerdicts($pass1['verdict'], $pass2['verdict']);
        $allNotes = array_merge($pass1['notes'], $pass2['notes']);

        $this->updateCandidateSafety($candidateId, $overall, $allNotes);

        return $this->buildResult($candidateId, [$pass1, $pass2], $overall, $allNotes);
    }

    /**
     * Run only the deterministic scan for bulk screening.
     */
    public function deterministicOnly(string $candidateId): array
    {
        $candidate = $this->loadCandidate($candidateId);
        if (!$candidate) {
            return ['candidate_id' => $candidateId, 'success' => false, 'error' => 'Candidate not found'];
        }

        $brief = $this->loadBriefForCandidate($candidate);
        $forbiddenClaims = $brief ? (json_decode($brief['forbidden_claims'], true) ?? []) : [];

        $result = $this->deterministicScan($candidate, $forbiddenClaims);

        $this->logCheck($candidateId, 'deterministic', $result['verdict'], [
            'matches' => $result['matches'],
        ], null, $result['latency_ms']);

        return $result;
    }

    // ===========================================
    // PASS 1 — DETERMINISTIC SCAN
    // ===========================================

    /**
     * Scan candidate copy for forbidden claims using case-insensitive,
     * stemmed keyword and phrase matching.
     */
    private function deterministicScan(array $candidate, array $forbiddenClaims): array
    {
        $start = microtime(true);
        $matches = [];
        $texts = $this->flattenConfigText($candidate);

        foreach ($forbiddenClaims as $claim) {
            $claim = trim($claim);
            if (empty($claim)) {
                continue;
            }

            $matchedFields = [];

            foreach ($texts as $field => $text) {
                if ($this->matchesForbidden($text, $claim)) {
                    $matchedFields[] = $field;
                }
            }

            if (!empty($matchedFields)) {
                $matches[] = [
                    'claim' => $claim,
                    'matched_fields' => $matchedFields,
                ];
            }
        }

        $latency = (int) ((microtime(true) - $start) * 1000);

        if (!empty($matches)) {
            $notes = [];
            foreach ($matches as $m) {
                $notes[] = "Forbidden claim detected: \"{$m['claim']}\" in " . implode(', ', $m['matched_fields']);
            }
            return [
                'pass' => 'deterministic',
                'verdict' => 'reject',
                'matches' => $matches,
                'notes' => $notes,
                'latency_ms' => $latency,
            ];
        }

        return [
            'pass' => 'deterministic',
            'verdict' => 'clean',
            'matches' => [],
            'notes' => [],
            'latency_ms' => $latency,
        ];
    }

    /**
     * Check if a text contains a forbidden claim, using stemming.
     */
    private function matchesForbidden(string $text, string $claim): bool
    {
        $textLower = mb_strtolower($text);
        $claimLower = mb_strtolower($claim);

        // Direct substring match first (fast path)
        if (str_contains($textLower, $claimLower)) {
            return true;
        }

        // Stemmed keyword match: break claim into words and check each
        $claimWords = preg_split('/\s+/', $claimLower);
        $textWords = preg_split('/\s+/', $textLower);

        // Multi-word claim: check if all stemmed words appear in proximity
        if (count($claimWords) > 1) {
            return $this->matchStemmedPhrase($textWords, $claimWords);
        }

        // Single word: check stemmed match
        $stemmedClaim = $this->stem($claimWords[0]);
        foreach ($textWords as $tw) {
            if ($this->stem($tw) === $stemmedClaim) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all stemmed claim words appear in order within the text.
     */
    private function matchStemmedPhrase(array $textWords, array $claimWords): bool
    {
        $stemmedText = array_map(fn($w) => $this->stem($w), $textWords);
        $stemmedClaim = array_map(fn($w) => $this->stem($w), $claimWords);

        $tc = count($stemmedClaim);
        $tt = count($stemmedText);

        for ($i = 0; $i <= $tt - $tc; $i++) {
            $match = true;
            for ($j = 0; $j < $tc; $j++) {
                if ($stemmedText[$i + $j] !== $stemmedClaim[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple lightweight stemmer — strips common English suffixes.
     * Not linguistically perfect, sufficient for compliance keyword matching.
     */
    private function stem(string $word): string
    {
        $word = trim($word);
        $word = preg_replace('/[^a-z0-9]/', '', $word);
        if (strlen($word) < 3) {
            return $word;
        }

        // Step 1: plurals and past participles
        if (str_ends_with($word, 'ies') && strlen($word) > 4) {
            $word = substr($word, 0, -3) . 'i';
        } elseif (str_ends_with($word, 'ving') && strlen($word) > 5) {
            $word = substr($word, 0, -3);
        } elseif (str_ends_with($word, 'ned') && strlen($word) > 4) {
            $word = substr($word, 0, -3) . 'n';
        } elseif (str_ends_with($word, 'ing') && strlen($word) > 5) {
            $word = substr($word, 0, -3);
        } elseif (str_ends_with($word, 'ed') && strlen($word) > 4) {
            $word = substr($word, 0, -2);
        } elseif (str_ends_with($word, 'ly') && strlen($word) > 4) {
            $word = substr($word, 0, -2);
        } elseif (str_ends_with($word, 'es') && strlen($word) > 4) {
            $word = substr($word, 0, -2);
        } elseif (str_ends_with($word, 's') && !str_ends_with($word, 'ss') && strlen($word) > 4) {
            $word = substr($word, 0, -1);
        }

        return $word;
    }

    // ===========================================
    // PASS 2 — AI CLAIMS REVIEW
    // ===========================================

    /**
     * Run a cheap AI call to review the candidate copy for compliance issues.
     */
    private function aiReview(array $candidate, array $forbiddenClaims, string $surface): array
    {
        $start = microtime(true);
        $texts = $this->flattenConfigText($candidate);
        $config = $candidate['config'] ?? '{}';
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        $prompt = $this->buildCompliancePrompt($texts, $forbiddenClaims, $surface);

        try {
            $response = $this->callAI($prompt);
            $parsed = json_decode($response, true);

            $latency = (int) ((microtime(true) - $start) * 1000);
            $model = $this->getSetting('safety_review_model') ?: 'gemini-1.5-flash';

            if (!$parsed || !isset($parsed['verdict'])) {
                // Fallback: if AI fails, flag for human review
                return [
                    'pass' => 'ai_review',
                    'verdict' => 'needs_review',
                    'flagged_phrases' => [],
                    'rationale' => 'AI review could not parse response; flagging for human review.',
                    'notes' => ['AI review parsing failure — needs human review'],
                    'model_used' => $model,
                    'latency_ms' => $latency,
                ];
            }

            $verdict = $parsed['verdict']; // clean, needs_review, reject
            $flagged = $parsed['flagged_phrases'] ?? [];
            $rationale = $parsed['rationale'] ?? '';

            $notes = [];
            if ($verdict === 'reject' || $verdict === 'needs_review') {
                foreach ($flagged as $f) {
                    $notes[] = "AI flagged: {$f}";
                }
                if (!empty($rationale)) {
                    $notes[] = $rationale;
                }
            }

            return [
                'pass' => 'ai_review',
                'verdict' => $verdict,
                'flagged_phrases' => $flagged,
                'rationale' => $rationale,
                'notes' => $notes,
                'model_used' => $model,
                'latency_ms' => $latency,
            ];
        } catch (Exception $e) {
            $latency = (int) ((microtime(true) - $start) * 1000);
            return [
                'pass' => 'ai_review',
                'verdict' => 'needs_review',
                'flagged_phrases' => [],
                'rationale' => 'AI review call failed: ' . $e->getMessage(),
                'notes' => ['AI review error — needs human review'],
                'model_used' => 'error',
                'latency_ms' => $latency,
            ];
        }
    }

    /**
     * Build the compliance review prompt for the AI pass.
     */
    private function buildCompliancePrompt(array $texts, array $forbiddenClaims, string $surface): string
    {
        $prompt = "# Compliance Review\n\n";
        $prompt .= "Review the following variant copy for {$surface} surface.\n\n";
        $prompt .= "## Strictly Forbidden Claims (MUST reject if present, even implicitly)\n";
        foreach ($forbiddenClaims as $i => $claim) {
            $prompt .= ($i + 1) . ". {$claim}\n";
        }
        $prompt .= "\n## Copy to Review\n";
        foreach ($texts as $field => $text) {
            $prompt .= "### {$field}\n{$text}\n\n";
        }

        $prompt .= "## Instructions\n";
        $prompt .= "Check for:\n";
        $prompt .= "1. Any forbidden claims (listed above) — even subtle rephrasings\n";
        $prompt .= "2. Medical claims not supported by the brand's actual value props\n";
        $prompt .= "3. Overpromising or creating unrealistic expectations\n";
        $prompt .= "4. Fear-mongering or shame-based messaging\n";
        $prompt .= "5. Regulatory compliance issues (EU AI Act, GDPR, medical advertising rules)\n\n";
        $prompt .= "## Output Format\n";
        $prompt .= "Return a JSON object:\n";
        $prompt .= '{"verdict": "clean|needs_review|reject", "flagged_phrases": ["phrase1", "phrase2"], "rationale": "brief explanation"}';
        $prompt .= "\n\n- **clean**: No issues found, passes compliance\n";
        $prompt .= "- **needs_review**: Minor concerns, human should review before approving\n";
        $prompt .= "- **reject**: Clear violation — do NOT approve this variant\n";
        $prompt .= "\nOutput valid JSON only.";

        return $prompt;
    }

    // ===========================================
    // HELPERS
    // ===========================================

    /**
     * Flatten a candidate's config into a key-value array of text fields for scanning.
     */
    private function flattenConfigText(array $candidate): array
    {
        $config = $candidate['config'] ?? '{}';
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }
        if (!is_array($config)) {
            return [];
        }

        $texts = [];
        foreach ($config as $key => $value) {
            if (is_string($value) && !empty($value)) {
                $texts[$key] = $value;
            } elseif (is_array($value)) {
                // Flatten nested arrays (e.g., bullet_points, features)
                $this->flattenArray($value, $key, $texts);
            }
        }

        // Also scan rationale and pattern_tags
        if (!empty($candidate['rationale'])) {
            $texts['rationale'] = $candidate['rationale'];
        }

        return $texts;
    }

    private function flattenArray(array $arr, string $prefix, array &$result): void
    {
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? "{$prefix}.{$k}" : $prefix;
            if (is_string($v) && !empty($v)) {
                $result[$key] = $v;
            } elseif (is_array($v)) {
                $this->flattenArray($v, $key, $result);
            }
        }
    }

    /**
     * Combine two verdicts, taking the most severe.
     */
    private function combineVerdicts(string $v1, string $v2): string
    {
        $severity = ['clean' => 0, 'needs_review' => 1, 'reject' => 2];
        $s1 = $severity[$v1] ?? 0;
        $s2 = $severity[$v2] ?? 0;
        return $s1 >= $s2 ? $v1 : $v2;
    }

    /**
     * Build the standard result envelope.
     */
    private function buildResult(string $candidateId, array $passes, string $overall, array $notes): array
    {
        return [
            'candidate_id' => $candidateId,
            'success' => true,
            'passes' => $passes,
            'overall_verdict' => $overall,
            'safety_notes' => $notes,
        ];
    }

    // ===========================================
    // DATA ACCESS
    // ===========================================

    private function loadCandidate(string $candidateId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT vc.*, vg.surface
            FROM variant_candidates vc
            JOIN variant_generations vg ON vg.id = vc.generation_id
            WHERE vc.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $candidateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadBriefForCandidate(array $candidate): ?array
    {
        $surface = $candidate['surface'] ?? null;
        if (!$surface) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT surface, forbidden_claims
            FROM variant_briefs
            WHERE surface = :surface
            LIMIT 1
        ");
        $stmt->execute([':surface' => $surface]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function updateCandidateSafety(string $candidateId, string $flag, array $notes): void
    {
        $stmt = $this->db->prepare("
            UPDATE variant_candidates
            SET safety_flag = :flag,
                safety_notes = :notes,
                review_status = CASE
                    WHEN :flag = 'rejected' THEN 'rejected'
                    ELSE review_status
                END
            WHERE id = :id
        ");
        $stmt->execute([
            ':flag' => $flag,
            ':notes' => json_encode($notes),
            ':id' => $candidateId,
        ]);
    }

    private function logCheck(
        string $candidateId,
        string $checkType,
        string $verdict,
        array $details,
        ?string $modelUsed,
        ?int $latencyMs
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO variant_safety_log
                (candidate_id, check_type, verdict, details, model_used, latency_ms)
            VALUES
                (:candidateId, :checkType, :verdict, :details, :modelUsed, :latencyMs)
        ");
        $stmt->execute([
            ':candidateId' => $candidateId,
            ':checkType' => $checkType,
            ':verdict' => $verdict,
            ':details' => json_encode($details),
            ':modelUsed' => $modelUsed,
            ':latencyMs' => $latencyMs,
        ]);
    }

    // ===========================================
    // AI PROVIDER (shared with VariantGeneratorService pattern)
    // ===========================================

    private function callAI(string $prompt): string
    {
        $provider = $this->getSetting('safety_ai_provider') ?: $this->getSetting('ai_provider') ?: 'openrouter';
        $model = $this->getSetting('safety_review_model') ?: $this->getSetting('cheap_ai_model') ?: 'gemini-1.5-flash';

        switch ($provider) {
            case 'openrouter':
                $apiKey = $this->getSetting('openrouter_api_key') ?: (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
                if ($apiKey) {
                    return $this->callOpenRouter($prompt, $apiKey, $model);
                }
                // fall through
            default:
                $apiKey = $this->getSetting('gemini_api_key') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
                if (!$apiKey) {
                    throw new RuntimeException('No AI API key configured for safety review.');
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
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://zenithwellness.app',
            'X-Title: Zenith Wellness',
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
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 1024,
                'responseMimeType' => 'application/json',
            ],
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
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        return $result;
    }

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
}
