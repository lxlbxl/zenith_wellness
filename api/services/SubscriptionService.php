<?php
/**
 * SubscriptionService — Manages user subscriptions, plans, free trials, and billing.
 * Phase 2.1 / 2.2: Subscription Billing + Pricing Restructure
 */

class SubscriptionService
{
    private $db;

    // Plan definitions with pricing tiers
    public const PLANS = [
        'free' => [
            'name' => 'Free Tier',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'features' => ['habit_tracking', 'meal_logging', 'mood_tracking', '1_cohort'],
            'max_cohorts' => 1,
            'ai_messages_per_day' => 5,
            'trial_days' => 0,
        ],
        'starter' => [
            'name' => 'Starter',
            'price_monthly' => 4.99,
            'price_yearly' => 49.99,
            'features' => ['habit_tracking', 'meal_logging', 'mood_tracking', 'all_cohorts', 'ai_coach', 'journal'],
            'max_cohorts' => 3,
            'ai_messages_per_day' => 50,
            'trial_days' => 7,
        ],
        'pro' => [
            'name' => 'Pro',
            'price_monthly' => 9.99,
            'price_yearly' => 99.99,
            'features' => ['all_features', 'unlimited_cohorts', 'ai_coach', 'journal', 'focus_timer', 'cycle_tracker', 'gamification', 'goal_manager'],
            'max_cohorts' => 999,
            'ai_messages_per_day' => 200,
            'trial_days' => 14,
        ],
    ];

    // Supported billing intervals
    public const INTERVALS = ['monthly', 'yearly'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── Subscription Management ────────────────────────

    /**
     * Create or update a user's subscription.
     */
    public function subscribe(string $userId, string $plan, string $interval = 'monthly', ?string $paymentRef = null): array
    {
        if (!isset(self::PLANS[$plan])) {
            throw new \InvalidArgumentException("Invalid plan: $plan");
        }
        if (!in_array($interval, self::INTERVALS)) {
            throw new \InvalidArgumentException("Invalid interval: $interval");
        }

        $planDef = self::PLANS[$plan];
        $price = $interval === 'yearly' ? $planDef['price_yearly'] : $planDef['price_monthly'];

        $now = new \DateTime();
        $endDate = (clone $now)->modify($interval === 'yearly' ? '+1 year' : '+1 month');

        // Check for existing active subscription
        $stmt = $this->db->prepare(
            "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW()"
        );
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Extend existing subscription
            $stmt = $this->db->prepare(
                "UPDATE subscriptions SET plan = ?, interval_type = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$plan, $interval, $existing['id']]);
            $subId = $existing['id'];
        } else {
            // Create new subscription
            $subId = $this->uuid();
            $stmt = $this->db->prepare(
                "INSERT INTO subscriptions (id, user_id, plan, interval_type, status, starts_at, ends_at, trial_ends_at, auto_renew)
                 VALUES (?, ?, ?, ?, 'active', NOW(), ?, NULL, TRUE)"
            );
            $stmt->execute([$subId, $userId, $plan, $interval, $endDate->format('Y-m-d H:i:s')]);
        }

        // Record payment if provided
        if ($paymentRef) {
            $stmt = $this->db->prepare(
                "INSERT INTO payments (id, user_id, subscription_id, amount, currency, status, gateway_ref, gateway, created_at)
                 VALUES (?, ?, ?, ?, 'USD', 'completed', ?, ?, NOW())"
            );
            $stmt->execute([$this->uuid(), $userId, $subId, $price, $paymentRef, ACTIVE_PAYMENT_GATEWAY]);
        }

        return [
            'subscription_id' => $subId,
            'plan' => $plan,
            'interval' => $interval,
            'price' => $price,
            'ends_at' => $endDate->format('Y-m-d H:i:s'),
            'features' => $planDef['features'],
        ];
    }

    /**
     * Start a free trial for a user.
     */
    public function startTrial(string $userId, string $plan): array
    {
        if (!isset(self::PLANS[$plan])) {
            throw new \InvalidArgumentException("Invalid plan: $plan");
        }

        $planDef = self::PLANS[$plan];
        $trialDays = $planDef['trial_days'];

        if ($trialDays <= 0) {
            throw new \InvalidArgumentException("$plan plan does not offer a free trial");
        }

        // Check if user has already used trial
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM subscriptions WHERE user_id = ? AND trial_ends_at IS NOT NULL"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() > 0) {
            throw new \RuntimeException("You have already used your free trial");
        }

        $now = new \DateTime();
        $trialEnd = (clone $now)->modify("+{$trialDays} days");

        $subId = $this->uuid();
        $stmt = $this->db->prepare(
            "INSERT INTO subscriptions (id, user_id, plan, interval_type, status, starts_at, ends_at, trial_ends_at, auto_renew)
             VALUES (?, ?, ?, 'monthly', 'trialing', NOW(), ?, ?, TRUE)"
        );
        $stmt->execute([$subId, $userId, $plan, $trialEnd->format('Y-m-d H:i:s'), $trialEnd->format('Y-m-d H:i:s')]);

        return [
            'subscription_id' => $subId,
            'plan' => $plan,
            'trial_ends_at' => $trialEnd->format('Y-m-d H:i:s'),
            'trial_days_remaining' => $trialDays,
            'features' => $planDef['features'],
        ];
    }

    /**
     * Get a user's current active subscription.
     */
    public function getCurrentSubscription(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, 
                (SELECT COUNT(*) FROM payments p WHERE p.subscription_id = s.id AND p.status = 'completed') as payments_count
             FROM subscriptions s 
             WHERE s.user_id = ? AND s.status IN ('active', 'trialing') AND s.ends_at > NOW()
             ORDER BY s.created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            return null;
        }

        $planDef = self::PLANS[$sub['plan']] ?? self::PLANS['free'];
        $sub['features'] = $planDef['features'];
        $sub['plan_name'] = $planDef['name'];
        $sub['price'] = $sub['interval_type'] === 'yearly' ? $planDef['price_yearly'] : $planDef['price_monthly'];
        $sub['is_trialing'] = $sub['status'] === 'trialing';

        // Calculate days remaining
        $endsAt = new \DateTime($sub['ends_at']);
        $now = new \DateTime();
        $sub['days_remaining'] = max(0, (int) $now->diff($endsAt)->format('%r%a'));

        if ($sub['trial_ends_at']) {
            $trialEnd = new \DateTime($sub['trial_ends_at']);
            $sub['trial_days_remaining'] = max(0, (int) $now->diff($trialEnd)->format('%r%a'));
        }

        return $sub;
    }

    /**
     * Cancel a subscription (at period end).
     */
    public function cancelSubscription(string $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions SET auto_renew = FALSE, updated_at = NOW() 
             WHERE user_id = ? AND status IN ('active', 'trialing') AND ends_at > NOW()"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate auto-renew on a subscription.
     */
    public function reactivateSubscription(string $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE subscriptions SET auto_renew = TRUE, updated_at = NOW() 
             WHERE user_id = ? AND status = 'active' AND ends_at > NOW()"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if user has access to a feature.
     */
    public function hasFeature(string $userId, string $feature): bool
    {
        $sub = $this->getCurrentSubscription($userId);

        // Default to free tier if no subscription
        $features = $sub['features'] ?? self::PLANS['free']['features'];

        // 'all_features' grants everything
        if (in_array('all_features', $features)) {
            return true;
        }

        return in_array($feature, $features);
    }

    /**
     * Check if user can enroll in another cohort.
     */
    public function canEnrollInMoreCohorts(string $userId): bool
    {
        $sub = $this->getCurrentSubscription($userId);
        $planDef = self::PLANS[$sub['plan'] ?? 'free'];
        $maxCohorts = $planDef['max_cohorts'];

        if ($maxCohorts >= 999)
            return true; // unlimited

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cohort_enrollments WHERE user_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId]);
        $currentCount = (int) $stmt->fetchColumn();

        return $currentCount < $maxCohorts;
    }

    /**
     * Get remaining AI messages for today.
     */
    public function getRemainingAIMessages(string $userId): int
    {
        $sub = $this->getCurrentSubscription($userId);
        $planDef = self::PLANS[$sub['plan'] ?? 'free'];
        $maxPerDay = $planDef['ai_messages_per_day'];

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_usage_logs WHERE user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$userId]);
        $usedToday = (int) $stmt->fetchColumn();

        return max(0, $maxPerDay - $usedToday);
    }

    /**
     * Record AI usage.
     */
    public function recordAIUsage(string $userId, string $model, int $tokensUsed): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ai_usage_logs (id, user_id, model, tokens_used, created_at) 
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$this->uuid(), $userId, $model, $tokensUsed]);
    }

    // ─── Payment Processing ─────────────────────────────

    /**
     * Get active payment gateway configuration.
     */
    public function getActiveGateway(): array
    {
        $gateway = defined('ACTIVE_PAYMENT_GATEWAY') ? ACTIVE_PAYMENT_GATEWAY : getenv('ACTIVE_PAYMENT_GATEWAY') ?: 'stripe';

        $configs = [
            'stripe' => [
                'public_key' => defined('STRIPE_PUBLIC_KEY') ? STRIPE_PUBLIC_KEY : getenv('STRIPE_PUBLIC_KEY'),
                'secret_key' => defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : getenv('STRIPE_SECRET_KEY'),
            ],
            'paystack' => [
                'public_key' => defined('PAYSTACK_PUBLIC_KEY') ? PAYSTACK_PUBLIC_KEY : getenv('PAYSTACK_PUBLIC_KEY'),
                'secret_key' => defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : getenv('PAYSTACK_SECRET_KEY'),
            ],
            'flutterwave' => [
                'public_key' => defined('FLUTTERWAVE_PUBLIC_KEY') ? FLUTTERWAVE_PUBLIC_KEY : getenv('FLUTTERWAVE_PUBLIC_KEY'),
                'secret_key' => defined('FLUTTERWAVE_SECRET_KEY') ? FLUTTERWAVE_SECRET_KEY : getenv('FLUTTERWAVE_SECRET_KEY'),
            ],
        ];

        return array_merge(['gateway' => $gateway], $configs[$gateway] ?? $configs['stripe']);
    }

    /**
     * Generate payment intent / initialize transaction with active gateway.
     */
    public function initializePayment(string $userId, string $plan, string $interval, string $email): array
    {
        $planDef = self::PLANS[$plan] ?? null;
        if (!$planDef) {
            throw new \InvalidArgumentException("Invalid plan: $plan");
        }

        $amount = $interval === 'yearly' ? $planDef['price_yearly'] : $planDef['price_monthly'];
        $gateway = $this->getActiveGateway();
        $currency = defined('PAYMENT_CURRENCY') ? PAYMENT_CURRENCY : getenv('PAYMENT_CURRENCY') ?: 'USD';

        $reference = 'ZEN-' . strtoupper(bin2hex(random_bytes(8)));

        $result = [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'plan' => $plan,
            'interval' => $interval,
            'gateway' => $gateway['gateway'],
            'public_key' => $gateway['public_key'],
        ];

        switch ($gateway['gateway']) {
            case 'paystack':
                $result['paystack'] = $this->initPaystack($email, $amount, $currency, $reference);
                break;
            case 'flutterwave':
                $result['flutterwave'] = $this->initFlutterwave($email, $amount, $currency, $reference, $plan);
                break;
            case 'stripe':
            default:
                $result['stripe'] = $this->initStripe($amount, $currency, $reference);
                break;
        }

        // Record pending payment
        $stmt = $this->db->prepare(
            "INSERT INTO payments (id, user_id, amount, currency, status, gateway, gateway_ref, plan, interval_type, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$this->uuid(), $userId, $amount, $currency, $gateway['gateway'], $reference, $plan, $interval]);

        return $result;
    }

    /**
     * Verify a payment with the gateway and activate subscription.
     */
    public function verifyAndActivate(string $gateway, string $reference): array
    {
        $verified = false;
        $gatewayResponse = null;

        switch ($gateway) {
            case 'paystack':
                $result = $this->verifyPaystack($reference);
                $verified = $result['verified'];
                $gatewayResponse = $result;
                break;
            case 'flutterwave':
                $result = $this->verifyFlutterwave($reference);
                $verified = $result['verified'];
                $gatewayResponse = $result;
                break;
            case 'stripe':
            default:
                $result = $this->verifyStripe($reference);
                $verified = $result['verified'];
                $gatewayResponse = $result;
                break;
        }

        if (!$verified) {
            return ['success' => false, 'message' => 'Payment verification failed'];
        }

        // Get pending payment
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE gateway_ref = ? AND status = 'pending'");
        $stmt->execute([$reference]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment record not found'];
        }

        // Mark payment as completed
        $stmt = $this->db->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
        $stmt->execute([$payment['id']]);

        // Activate subscription
        $sub = $this->subscribe($payment['user_id'], $payment['plan'], $payment['interval_type'], $reference);

        return [
            'success' => true,
            'subscription' => $sub,
            'payment' => $payment,
        ];
    }

    // ─── Gateway Implementations ────────────────────────

    private function initStripe(float $amount, string $currency, string $reference): array
    {
        // Client-side Stripe integration: return config for stripe.js
        return [
            'client_secret' => null, // Generated client-side with Elements
            'amount' => (int) ($amount * 100), // cents
            'currency' => strtolower($currency),
        ];
    }

    private function verifyStripe(string $sessionId): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY') ?: STRIPE_SECRET_KEY;
        if (empty($secretKey)) {
            return ['verified' => false, 'error' => 'Stripe not configured'];
        }

        $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/$sessionId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $secretKey"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['verified' => false, 'error' => 'Stripe verification failed'];
        }

        $data = json_decode($response, true);
        return ['verified' => ($data['payment_status'] ?? '') === 'paid', 'data' => $data];
    }

    private function initPaystack(string $email, float $amount, string $currency, string $reference): array
    {
        $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: PAYSTACK_SECRET_KEY;

        $body = json_encode([
            'email' => $email,
            'amount' => (int) ($amount * 100), // kobo/cents
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => APP_URL . '/payment/verify',
        ]);

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $secretKey",
                "Content-Type: application/json",
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['data'] ?? ['error' => 'Paystack initialization failed'];
    }

    private function verifyPaystack(string $reference): array
    {
        $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: PAYSTACK_SECRET_KEY;

        $ch = curl_init("https://api.paystack.co/transaction/verify/$reference");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $secretKey"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $status = $data['data']['status'] ?? '';
        return ['verified' => $status === 'success', 'data' => $data['data'] ?? null];
    }

    private function initFlutterwave(string $email, float $amount, string $currency, string $reference, string $plan): array
    {
        $secretKey = getenv('FLUTTERWAVE_SECRET_KEY') ?: FLUTTERWAVE_SECRET_KEY;

        $body = json_encode([
            'tx_ref' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'email' => $email,
            'redirect_url' => APP_URL . '/payment/verify',
            'payment_options' => 'card,banktransfer,ussd',
            'meta' => ['plan' => $plan],
        ]);

        $ch = curl_init('https://api.flutterwave.com/v3/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $secretKey",
                "Content-Type: application/json",
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['data'] ?? ['error' => 'Flutterwave initialization failed'];
    }

    private function verifyFlutterwave(string $txRef): array
    {
        $secretKey = getenv('FLUTTERWAVE_SECRET_KEY') ?: FLUTTERWAVE_SECRET_KEY;

        $ch = curl_init("https://api.flutterwave.com/v3/transactions/verify_by_tx_ref?tx_ref=$txRef");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $secretKey"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $status = $data['data']['status'] ?? '';
        return ['verified' => $status === 'successful', 'data' => $data['data'] ?? null];
    }

    // ─── Cohort Pricing ─────────────────────────────────

    /**
     * Get or set cohort price (for premium/paid cohorts).
     */
    public function getCohortPrice(string $cohortId): ?float
    {
        $stmt = $this->db->prepare("SELECT price FROM cohort_prices WHERE cohort_id = ? AND is_active = TRUE LIMIT 1");
        $stmt->execute([$cohortId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Get all available plans for display.
     */
    public function getAllPlans(): array
    {
        $plans = [];
        foreach (self::PLANS as $key => $plan) {
            $plans[$key] = [
                'id' => $key,
                'name' => $plan['name'],
                'price_monthly' => $plan['price_monthly'],
                'price_yearly' => $plan['price_yearly'],
                'features' => $plan['features'],
                'max_cohorts' => $plan['max_cohorts'],
                'ai_messages_per_day' => $plan['ai_messages_per_day'],
                'trial_days' => $plan['trial_days'],
                'yearly_savings_percent' => $plan['price_monthly'] > 0
                    ? round((1 - ($plan['price_yearly'] / ($plan['price_monthly'] * 12))) * 100)
                    : 0,
            ];
        }
        return $plans;
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