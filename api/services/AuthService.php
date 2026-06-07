<?php
/**
 * AuthService — JWT token generation, refresh rotation, server-side logout.
 * Phase 1.2: Authentication Overhaul
 */

class AuthService
{
    private $db;
    private string $jwtSecret;
    private int $accessTokenTTL = 3600;       // 1 hour
    private int $refreshTokenTTL = 2592000;   // 30 days

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : getenv('JWT_SECRET');
    }

    // ─── Token Generation ───────────────────────────────

    /**
     * Issue access + refresh token pair for a user.
     * Returns: ['access_token' => string, 'refresh_token' => string, 'expires_in' => int]
     */
    public function issueTokenPair(array $user): array
    {
        $now = time();
        $accessTokenId = $this->uuid();
        $refreshTokenId = $this->uuid();
        $refreshToken = bin2hex(random_bytes(64));

        // Access token payload
        $accessPayload = [
            'iss' => 'zenith-wellness',
            'sub' => $user['id'],
            'jti' => $accessTokenId,
            'iat' => $now,
            'exp' => $now + $this->accessTokenTTL,
            'type' => 'access',
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? 'user',
        ];

        $accessToken = $this->encodeJWT($accessPayload);

        // Store refresh token hash in DB
        $refreshHash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare(
            "INSERT INTO refresh_tokens (id, user_id, token_hash, jti, expires_at, created_at) 
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())"
        );
        $stmt->execute([$refreshTokenId, $user['id'], $refreshHash, $accessTokenId]);

        // Clean old tokens for this user (keep last 5 refresh tokens)
        $this->db->prepare(
            "DELETE FROM refresh_tokens WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM refresh_tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
                ) AS kept
            )"
        )->execute([$user['id'], $user['id']]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTTL,
        ];
    }

    // ─── Token Verification ─────────────────────────────

    /**
     * Decode and verify an access token. Returns payload array or throws.
     */
    public function verifyAccessToken(string $token): ?array
    {
        $payload = $this->decodeJWT($token);

        if (!$payload) {
            return null;
        }

        // Check type
        if (($payload['type'] ?? '') !== 'access') {
            return null;
        }

        // Check if blacklisted
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM token_blacklist WHERE jti = ?");
        $stmt->execute([$payload['jti'] ?? '']);
        if ($stmt->fetchColumn() > 0) {
            return null;
        }

        return $payload;
    }

    /**
     * Refresh an access token using a refresh token.
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $refreshHash = hash('sha256', $refreshToken);

        // Find matching refresh token
        $stmt = $this->db->prepare(
            "SELECT rt.*, u.id as user_id, u.email, u.role, u.name 
             FROM refresh_tokens rt 
             JOIN users u ON rt.user_id = u.id 
             WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND rt.revoked_at IS NULL"
        );
        $stmt->execute([$refreshHash]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRow) {
            return null;
        }

        // Revoke old refresh token (rotation)
        $this->db->prepare("UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?")
            ->execute([$tokenRow['id']]);

        // Issue new pair
        return $this->issueTokenPair([
            'id' => $tokenRow['user_id'],
            'email' => $tokenRow['email'],
            'role' => $tokenRow['role'],
        ]);
    }

    // ─── Logout ─────────────────────────────────────────

    /**
     * Server-side logout: blacklist current access token + revoke all refresh tokens.
     */
    public function logout(string $accessToken): bool
    {
        $payload = $this->decodeJWT($accessToken);
        if (!$payload) {
            return false;
        }

        $userId = $payload['sub'] ?? null;
        $jti = $payload['jti'] ?? null;

        if (!$userId || !$jti) {
            return false;
        }

        // Blacklist the current access token
        $expiresAt = date('Y-m-d H:i:s', $payload['exp'] ?? time() + 3600);
        $stmt = $this->db->prepare("INSERT IGNORE INTO token_blacklist (id, jti, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$this->uuid(), $jti, $expiresAt]);

        // Revoke all refresh tokens for this user
        $this->db->prepare("UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
            ->execute([$userId]);

        return true;
    }

    /**
     * Blacklist a specific JWT by jti.
     */
    public function blacklistToken(string $jti, int $expiresAt): void
    {
        $stmt = $this->db->prepare("INSERT IGNORE INTO token_blacklist (id, jti, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$this->uuid(), $jti, date('Y-m-d H:i:s', $expiresAt)]);
    }

    // ─── Password Reset ─────────────────────────────────

    /**
     * Generate a secure password reset token.
     */
    public function createPasswordReset(string $email): ?string
    {
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Don't reveal if email exists
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        // Invalidate old tokens
        $this->db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$user['id']]);

        // Create new token
        $stmt = $this->db->prepare(
            "INSERT INTO password_resets (id, user_id, token, expires_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->uuid(), $user['id'], hash('sha256', $token), $expiresAt]);

        return $token . '.' . $user['id']; // Encode user ID with token for lookup
    }

    /**
     * Verify and consume a password reset token.
     */
    public function verifyPasswordReset(string $encodedToken): ?array
    {
        $parts = explode('.', $encodedToken);
        if (count($parts) !== 2) {
            return null;
        }

        [$rawToken, $userId] = $parts;
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare(
            "SELECT pr.*, u.email FROM password_resets pr 
             JOIN users u ON pr.user_id = u.id 
             WHERE pr.user_id = ? AND pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL"
        );
        $stmt->execute([$userId, $tokenHash]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            return null;
        }

        // Mark as used
        $this->db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
            ->execute([$reset['id']]);

        return [
            'user_id' => $userId,
            'email' => $reset['email'],
        ];
    }

    /**
     * Reset a user's password.
     */
    public function resetPassword(string $userId, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        // Invalidate all existing sessions
        $this->db->prepare("UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
            ->execute([$userId]);

        return true;
    }

    // ─── Helper: User Lookup from Token ─────────────────

    /**
     * Get the authenticated user from a valid access token.
     */
    public function getUserFromToken(string $token): ?array
    {
        $payload = $this->verifyAccessToken($token);
        if (!$payload) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, name, email, persona, role FROM users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Rate Limiting ──────────────────────────────────

    /**
     * Check if a key (e.g., IP, email) has exceeded rate limit.
     * Returns ['allowed' => bool, 'retry_after' => int, 'remaining' => int]
     */
    public function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): array
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        // Clean old entries
        $this->db->prepare("DELETE FROM rate_limits WHERE window_start < ?")
            ->execute([$windowStart]);

        // Count requests in current window
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND window_start >= ?"
        );
        $stmt->execute([$key, $windowStart]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxRequests) {
            // Find earliest request to calculate retry_after
            $stmt = $this->db->prepare(
                "SELECT created_at FROM rate_limits WHERE rate_key = ? AND window_start >= ? ORDER BY created_at ASC LIMIT 1"
            );
            $stmt->execute([$key, $windowStart]);
            $first = $stmt->fetch(PDO::FETCH_ASSOC);
            $retryAfter = $first ? (strtotime($first['created_at']) + $windowSeconds - time()) : $windowSeconds;

            return ['allowed' => false, 'retry_after' => max(1, $retryAfter), 'remaining' => 0];
        }

        // Record this request
        $stmt = $this->db->prepare(
            "INSERT INTO rate_limits (id, rate_key, window_start) VALUES (?, ?, ?)"
        );
        $stmt->execute([$this->uuid(), $key, $windowStart]);

        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxRequests - $count - 1];
    }

    // ─── JWT Encode / Decode (no external library) ──────

    private function encodeJWT(array $payload): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", $this->jwtSecret, true)
        );

        return "$header.$payloadEncoded.$signature";
    }

    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $this->jwtSecret, true)
        );

        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);

        // Check expiration
        if (($data['exp'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // ─── Utility ────────────────────────────────────────

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}