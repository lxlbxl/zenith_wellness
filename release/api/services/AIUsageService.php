<?php
/**
 * AI Usage Service
 * 
 * Handles token tracking, rate limiting, usage logging, and fallback responses.
 */

class AIUsageService
{
    private $db;
    private $fallbackResponses = [
        'coach_sara' => "I'm experiencing a brief pause in my thinking. Let's try again in a moment. In the meantime, remember: you're already making progress just by showing up. 💪",
        'nutrition_iq' => '{"meal_name": "Analysis Unavailable", "summary": "I couldn\'t analyze this meal right now. Please try again in a moment.", "wellness_score": null}',
        'companion' => "I'm here with you, even in moments of quiet. Take a breath, and let's try connecting again shortly. 🌸",
        'analyst' => '{"summary": "Analysis temporarily unavailable", "recommendation": "Please try again in a moment."}',
        'content_studio' => "Content generation is momentarily paused. Your personalized briefing will be ready soon.",
        'default' => "I'm taking a brief moment to gather my thoughts. Please try again shortly. Your wellness journey continues! ✨"
    ];

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Log AI usage with token counts
     */
    public function logUsage($userId, $agentName, $provider, $model, $inputTokens, $outputTokens, $responseTimeMs, $status = 'success', $errorMessage = null)
    {
        try {
            $totalTokens = $inputTokens + $outputTokens;

            $stmt = $this->db->prepare("
                INSERT INTO ai_usage_logs (
                    user_id, agent_name, provider, model, 
                    input_tokens, output_tokens, total_tokens, 
                    response_time_ms, status, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $agentName,
                $provider,
                $model,
                $inputTokens,
                $outputTokens,
                $totalTokens,
                $responseTimeMs,
                $status,
                $errorMessage
            ]);

            // Update daily count
            $this->incrementDailyCount($userId);

            return true;
        } catch (Exception $e) {
            error_log("Failed to log AI usage: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user is within their daily limit
     */
    public function checkRateLimit($userId)
    {
        $this->resetDailyCountIfNeeded($userId);

        $stmt = $this->db->prepare("
            SELECT daily_limit, daily_count, is_premium 
            FROM ai_usage_limits 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $limits = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$limits) {
            // Create default limits for new user
            $this->createDefaultLimits($userId);
            return ['allowed' => true, 'remaining' => 50, 'limit' => 50];
        }

        $remaining = max(0, $limits['daily_limit'] - $limits['daily_count']);
        $allowed = $remaining > 0;

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'limit' => $limits['daily_limit'],
            'is_premium' => (bool) $limits['is_premium']
        ];
    }

    /**
     * Reset daily count if it's a new day
     */
    private function resetDailyCountIfNeeded($userId)
    {
        $stmt = $this->db->prepare("
            UPDATE ai_usage_limits 
            SET daily_count = 0, last_reset_date = CURRENT_DATE
            WHERE user_id = ? 
            AND last_reset_date < CURRENT_DATE
        ");
        $stmt->execute([$userId]);
    }

    /**
     * Increment daily usage count
     */
    private function incrementDailyCount($userId)
    {
        $this->resetDailyCountIfNeeded($userId);

        $stmt = $this->db->prepare("
            INSERT INTO ai_usage_limits (user_id, daily_count, last_reset_date)
            VALUES (?, 1, CURRENT_DATE)
            ON CONFLICT(user_id) DO UPDATE SET daily_count = daily_count + 1
        ");
        $stmt->execute([$userId]);
    }

    /**
     * Create default limits for new user
     */
    private function createDefaultLimits($userId)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO ai_usage_limits (user_id, daily_limit, daily_count, last_reset_date, is_premium)
                VALUES (?, 50, 0, CURRENT_DATE, 0)
            ");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("Failed to create AI limits: " . $e->getMessage());
        }
    }

    /**
     * Set premium status (increases limits to 200/day)
     */
    public function setPremiumStatus($userId, $isPremium)
    {
        $dailyLimit = $isPremium ? 200 : 50;

        $stmt = $this->db->prepare("
            INSERT INTO ai_usage_limits (user_id, daily_limit, is_premium)
            VALUES (?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET daily_limit = ?, is_premium = ?
        ");
        $stmt->execute([$userId, $dailyLimit, (int) $isPremium, $dailyLimit, (int) $isPremium]);
    }

    /**
     * Get fallback response for when AI fails
     */
    public function getFallbackResponse($agentName)
    {
        return $this->fallbackResponses[$agentName] ?? $this->fallbackResponses['default'];
    }

    /**
     * Get usage statistics for a user
     */
    public function getUserStats($userId, $days = 30)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(total_tokens) as total_tokens,
                AVG(response_time_ms) as avg_response_time,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                agent_name,
                COUNT(*) as agent_requests
            FROM ai_usage_logs 
            WHERE user_id = ? 
            AND created_at > datetime('now', '-' || ? || ' days')
            GROUP BY agent_name
        ");
        $stmt->execute([$userId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get system-wide AI usage for admin dashboard
     */
    public function getSystemStats($days = 7)
    {
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as requests,
                SUM(total_tokens) as tokens,
                AVG(response_time_ms) as avg_response_time,
                provider,
                model
            FROM ai_usage_logs 
            WHERE created_at > datetime('now', '-' || ? || ' days')
            GROUP BY DATE(created_at), provider, model
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Estimate token count from text (rough approximation)
     */
    public static function estimateTokens($text)
    {
        // Rough estimate: ~4 characters per token for English
        return (int) ceil(strlen($text) / 4);
    }
}
