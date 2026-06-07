<?php
/**
 * ReferralService — Referral program: codes, tracking, rewards.
 * Phase 4.3: Referral Program
 */

class ReferralService
{
    private $db;

    // Reward configuration
    private const REFERRER_REWARD_POINTS = 50;
    private const REFERRED_REWARD_POINTS = 25;
    private const REFERRAL_DISCOUNT_PERCENT = 10;

    // Level multipliers for multi-tier referrals
    private const LEVEL_MULTIPLIERS = [1 => 1.0, 2 => 0.5, 3 => 0.25];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate or retrieve a user's referral code.
     */
    public function getReferralCode(string $userId): string
    {
        $stmt = $this->db->prepare("SELECT referral_code FROM user_referrals WHERE user_id = ?");
        $stmt->execute([$userId]);
        $code = $stmt->fetchColumn();

        if ($code) {
            return $code;
        }

        return $this->generateCode($userId);
    }

    private function generateCode(string $userId): string
    {
        // Generate a friendly, memorable code
        $nameStmt = $this->db->prepare("SELECT name FROM users WHERE id = ?");
        $nameStmt->execute([$userId]);
        $name = $nameStmt->fetchColumn() ?: 'ZEN';

        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 4));
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $code = $prefix . $suffix;

        // Ensure uniqueness
        while ($this->codeExists($code)) {
            $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            $code = $prefix . $suffix;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO user_referrals (id, user_id, referral_code, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$this->uuid(), $userId, $code]);

        return $code;
    }

    /**
     * Apply a referral when a new user registers.
     */
    public function applyReferral(string $newUserId, string $referralCode): array
    {
        // Find the referrer
        $stmt = $this->db->prepare(
            "SELECT ur.user_id, u.name as referrer_name 
             FROM user_referrals ur 
             JOIN users u ON ur.user_id = u.id 
             WHERE ur.referral_code = ?"
        );
        $stmt->execute([$referralCode]);
        $referrer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referrer) {
            return ['success' => false, 'message' => 'Invalid referral code'];
        }

        $referrerId = $referrer['user_id'];

        // Prevent self-referral
        if ($referrerId === $newUserId) {
            return ['success' => false, 'message' => 'Cannot refer yourself'];
        }

        // Check if this user was already referred
        $stmt = $this->db->prepare("SELECT id FROM referral_links WHERE referred_user_id = ?");
        $stmt->execute([$newUserId]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Already referred'];
        }

        // Record the referral link
        $linkId = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO referral_links (id, referrer_user_id, referred_user_id, referral_code, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([$linkId, $referrerId, $newUserId, $referralCode]);

        // Award pending rewards (activated when user completes onboarding)
        $this->awardPendingRewards($referrerId, $newUserId);

        return [
            'success' => true,
            'referrer' => $referrer['referrer_name'],
            'message' => 'Referral applied successfully',
        ];
    }

    /**
     * Activate referral rewards when referred user completes key actions.
     */
    public function activateReferralRewards(string $referredUserId): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM referral_links WHERE referred_user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$referredUserId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$link)
            return;

        // Award referrer
        $this->awardPoints($link['referrer_user_id'], self::REFERRER_REWARD_POINTS, 'referral_bonus');
        $this->createReferralDiscount($link['referrer_user_id']);

        // Award referred user
        $this->awardPoints($referredUserId, self::REFERRED_REWARD_POINTS, 'referred_signup_bonus');

        // Mark as activated
        $this->db->prepare("UPDATE referral_links SET status = 'activated', activated_at = NOW() WHERE id = ?")
            ->execute([$link['id']]);

        // Check parent referral for multi-level rewards
        $this->processMultiLevelReward($link['referrer_user_id'], 2);
    }

    /**
     * Get referral stats for a user.
     */
    public function getReferralStats(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total_invites,
                SUM(CASE WHEN status = 'activated' THEN 1 ELSE 0 END) as successful_referrals,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_referrals
             FROM referral_links WHERE referrer_user_id = ?"
        );
        $stmt->execute([$userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Points earned from referrals
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM points_history 
             WHERE user_id = ? AND reason = 'referral_bonus'"
        );
        $stmt->execute([$userId]);
        $stats['points_earned'] = (int) $stmt->fetchColumn();

        // Current referral code
        $stats['referral_code'] = $this->getReferralCode($userId);

        return $stats;
    }

    /**
     * Get leaderboard of top referrers.
     */
    public function getLeaderboard(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.avatar_url,
                    COUNT(rl.id) as successful_referrals,
                    COALESCE(SUM(ph.points), 0) as total_points_earned
             FROM referral_links rl
             JOIN users u ON rl.referrer_user_id = u.id
             LEFT JOIN points_history ph ON ph.user_id = u.id AND ph.reason = 'referral_bonus'
             WHERE rl.status = 'activated'
             GROUP BY u.id, u.name, u.avatar_url
             ORDER BY successful_referrals DESC, total_points_earned DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate a shareable referral link.
     */
    public function getShareLink(string $userId): string
    {
        $code = $this->getReferralCode($userId);
        $baseUrl = defined('APP_URL') ? APP_URL : getenv('APP_URL') ?: 'https://zenithwellness.com';
        return $baseUrl . '/register?ref=' . urlencode($code);
    }

    // ─── Multi-Level Rewards ────────────────────────────

    private function processMultiLevelReward(string $userId, int $level): void
    {
        if ($level > 3)
            return; // Max 3 levels
        if (!isset(self::LEVEL_MULTIPLIERS[$level]))
            return;

        $multiplier = self::LEVEL_MULTIPLIERS[$level];
        $points = (int) round(self::REFERRER_REWARD_POINTS * $multiplier);

        if ($points > 0) {
            $this->awardPoints($userId, $points, "referral_level_{$level}");
        }

        // Find parent referrer
        $stmt = $this->db->prepare(
            "SELECT rl.referrer_user_id FROM referral_links rl 
             WHERE rl.referred_user_id = ? AND rl.status = 'activated'"
        );
        $stmt->execute([$userId]);
        $parentReferrer = $stmt->fetchColumn();

        if ($parentReferrer) {
            $this->processMultiLevelReward($parentReferrer, $level + 1);
        }
    }

    // ─── Discounts ──────────────────────────────────────

    private function createReferralDiscount(string $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO discounts (id, user_id, discount_percent, reason, expires_at, is_used, created_at)
             VALUES (?, ?, ?, 'referral', DATE_ADD(NOW(), INTERVAL 90 DAY), FALSE, NOW())"
        );
        $stmt->execute([$this->uuid(), $userId, self::REFERRAL_DISCOUNT_PERCENT]);
    }

    // ─── Helpers ────────────────────────────────────────

    private function awardPoints(string $userId, int $points, string $reason): void
    {
        // Ensure points record exists
        $this->db->prepare(
            "INSERT IGNORE INTO user_points (id, user_id, total_points) VALUES (?, ?, 0)"
        )->execute([$this->uuid(), $userId]);

        $this->db->prepare(
            "INSERT INTO points_history (id, user_id, points, reason, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$this->uuid(), $userId, $points, $reason]);

        $this->db->prepare(
            "UPDATE user_points SET total_points = total_points + ?, updated_at = NOW() WHERE user_id = ?"
        )->execute([$points, $userId]);
    }

    private function awardPendingRewards(string $referrerId, string $referredUserId): void
    {
        // Small instant reward for referral signup
        $this->awardPoints($referrerId, 10, 'referral_signup_bonus');
        $this->awardPoints($referredUserId, 5, 'referred_signup_bonus');
    }

    private function codeExists(string $code): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_referrals WHERE referral_code = ?");
        $stmt->execute([$code]);
        return $stmt->fetchColumn() > 0;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}