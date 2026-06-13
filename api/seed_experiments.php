<?php
/**
 * seed_experiments.php — Seed first A/B experiments as drafts.
 *
 * A.10 — Pre-creates experiments with variants for initial test surfaces.
 * No code deploy needed. Run after experiment_engine_schema.sql.
 *
 * Usage:
 *   php api/seed_experiments.php
 *
 * Safe to re-run: uses key_slug uniqueness to skip existing experiments.
 */

require_once __DIR__ . '/config/Database.php';

// ─── Helpers ───────────────────────────────────────────────────

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function variantSlug(string $prefix, string $label): string
{
    return $prefix . '__' . preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
}

// ─── Bootstrap DB ─────────────────────────────────────────────

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

// ─── Experiment Definitions ───────────────────────────────────

$experiments = [];

// ── 1. trust_hero_v1 ─────────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'trust_hero_v1',
    'name'             => 'Trust Hero — Sales Trust Surface',
    'description'      => 'Test headline framing on trust-building. Outcome-first shows results upfront; question headline creates curiosity gap.',
    'surface'          => 'sales_trust',
    'primary_goal'     => 'payment_success',
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Warm Empathy',
            'is_control' => 1,
            'config'   => [
                'headline'    => 'Wellness That Finally Respects Your Cycle',
                'subheadline' => 'Science-backed protocols that adapt to every phase of your menstrual cycle. Not generic advice — precision care.',
                'trust_signals' => [
                    ['icon' => 'flask', 'label' => 'Built with PhD researchers'],
                    ['icon' => 'users', 'label' => '12,000+ women in 14 countries'],
                ],
                'testimonial_style' => 'spotlight_static',
                'cta_text' => 'Start Your Protocol',
            ],
        ],
        [
            'key_slug' => 'outcome_first',
            'name'     => 'Outcome-First — Results Driven',
            'is_control' => 0,
            'config'   => [
                'headline'    => '94% Reported Better Energy in 2 Weeks. Here\'s How.',
                'subheadline' => 'A metabolic reset built for women — proven across 12,000+ participants. Your results start here.',
                'trust_signals' => [
                    ['icon' => 'chart-line', 'label' => '94% report improved energy'],
                    ['icon' => 'users', 'label' => '12,000+ women enrolled'],
                    ['icon' => 'star', 'label' => '4.8/5 from 3,200+ reviews'],
                ],
                'testimonial_style' => 'grid',
                'cta_text' => 'See What\'s Possible',
            ],
        ],
        [
            'key_slug' => 'question_headline',
            'name'     => 'Question Headline — Curiosity Hook',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'What If Your Wellness Protocol Worked With Your Cycle, Not Against It?',
                'subheadline' => 'Most plans ignore your hormonal reality. Ours adapts to every phase — follicular, ovulatory, luteal, menstrual.',
                'trust_signals' => [
                    ['icon' => 'flask', 'label' => 'Built with PhD researchers'],
                    ['icon' => 'clock', 'label' => '7+ years of women\'s health research'],
                ],
                'testimonial_style' => 'carousel',
                'cta_text' => 'Find Out How',
            ],
        ],
    ],
];

// ── 2. quiz_email_gate_v1 ─────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'quiz_email_gate_v1',
    'name'             => 'Quiz Email Gate — Quiz Landing Surface',
    'description'      => 'Test quiz entry point framing. Primary conversion is email capture (lead_captured).',
    'surface'          => 'quiz_landing',
    'primary_goal'     => 'lead_captured',
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Curiosity Driven',
            'is_control' => 1,
            'config'   => [
                'headline'    => 'Your Hormones Are Trying to Tell You Something',
                'subheadline' => 'Answer 6 quick questions and get a personalised metabolic blueprint — free.',
                'quiz_question' => 'How would you describe your energy levels right now?',
                'benefit_preview' => [
                    'Personalised protocol match',
                    'Cycle phase insights',
                    'First 3 days free',
                ],
                'cta_text' => 'Start the Quiz →',
            ],
        ],
        [
            'key_slug' => 'diagnostic_framing',
            'name'     => 'Diagnostic Framing — Assessment Hook',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Is Your Metabolism Out of Sync With Your Cycle? Take the 2-Minute Assessment.',
                'subheadline' => 'Discover where your metabolic health stands — and get a custom protocol recommendation based on your results.',
                'quiz_question' => 'Which describes your energy pattern this week?',
                'benefit_preview' => [
                    'Metabolic health score',
                    'Personalised protocol match',
                    'Free 3-day trial',
                ],
                'cta_text' => 'Get Your Assessment →',
            ],
        ],
        [
            'key_slug' => 'value_first',
            'name'     => 'Value-First — Lead With the Reward',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Get Your Free Personalised Wellness Blueprint',
                'subheadline' => 'No fluff, no generic advice — just a science-backed protocol tailored to your cycle, goals, and lifestyle.',
                'quiz_question' => 'What\'s your primary wellness goal?',
                'benefit_preview' => [
                    'Free personalised blueprint',
                    'Cycle-synced protocol match',
                    'Instant access — no credit card',
                ],
                'cta_text' => 'Claim Your Free Blueprint',
            ],
        ],
    ],
];

// ── 3. checkout_layout_v1 ─────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'checkout_layout_v1',
    'name'             => 'Checkout Layout — Checkout Surface',
    'description'      => 'Test checkout page layout and urgency/guarantee placement for conversion impact.',
    'surface'          => 'checkout',
    'primary_goal'     => 'payment_success',
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Standard Layout',
            'is_control' => 1,
            'config'   => [
                'headline'    => 'You\'re One Step Away From a Body That Finally Feels Like Yours',
                'urgency_elements' => [
                    ['type' => 'social_proof', 'text' => '47 people started their protocol today'],
                    ['type' => 'scarcity', 'text' => 'Next cohort closes in 3 days'],
                ],
                'guarantee_style' => 'prominent_banner',
                'cta_text'   => 'Join the Protocol',
                'risk_reversal' => 'If you don\'t feel the difference in 30 days, we\'ll refund every penny. No questions, no hassle.',
            ],
        ],
        [
            'key_slug' => 'guarantee_first',
            'name'     => 'Guarantee-First — Risk Reversal Above Fold',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Start Risk-Free — 30-Day Full Refund, No Questions Asked',
                'urgency_elements' => [
                    ['type' => 'social_proof', 'text' => '12,000+ women have already started'],
                    ['type' => 'scarcity', 'text' => 'Cohort size limited to 50'],
                ],
                'guarantee_style' => 'cta_adjacent',
                'cta_text'   => 'Start Risk-Free Today',
                'risk_reversal' => 'Try the full protocol for 30 days. If you don\'t feel the difference, you get every penny back.',
            ],
        ],
    ],
];

// ── 4. pricing_anchor_v1 ──────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'pricing_anchor_v1',
    'name'             => 'Pricing Anchor — Pricing Surface',
    'description'      => 'Test pricing page framing: daily anchor, plan comparison format, and CTA positioning.',
    'surface'          => 'pricing',
    'primary_goal'     => 'payment_success',
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Daily Anchor',
            'is_control' => 1,
            'config'   => [
                'headline'    => 'Invest in Yourself — For Less Than a Coffee a Day',
                'plan_comparison_framing' => 'side_by_side',
                'featured_plan' => 'Metabolic Reset (21-day)',
                'cta_text'   => 'Start Your Transformation',
                'price_anchoring' => 'Less than £3/day — cancels anytime',
            ],
        ],
        [
            'key_slug' => 'value_stacked',
            'name'     => 'Value-Stacked — Total Worth Framing',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'What Would You Invest in a Body That Finally Works With You?',
                'plan_comparison_framing' => 'side_by_side',
                'featured_plan' => 'Metabolic Reset (21-day)',
                'cta_text'   => 'Claim Your Protocol',
                'price_anchoring' => 'Value breakdown: AI coach + protocol + community — less than £3/day',
            ],
        ],
        [
            'key_slug' => 'savings_highlight',
            'name'     => 'Savings Highlight — Annual Value Hook',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Full Access for Less Than a Haircut — Seriously.',
                'plan_comparison_framing' => 'toggle',
                'featured_plan' => 'Annual Premium (best value)',
                'cta_text'   => 'Go Premium — Save 40%',
                'price_anchoring' => 'Just £1.80/day on annual — save £438 vs monthly',
            ],
        ],
    ],
];

// ── 5. order_bump_copy_v1 ─────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'order_bump_copy_v1',
    'name'             => 'Order Bump Copy — Checkout Surface',
    'description'      => 'Test order bump copy and urgency framing. Guardrail: revenue per visitor must not degrade.',
    'surface'          => 'checkout',
    'primary_goal'     => 'payment_success',
    'guardrail_events' => ['revenue_per_visitor'],
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Standard Order Bump',
            'is_control' => 1,
            'config'   => [
                'headline'    => 'Complete Your Protocol — Add the Daily Essentials Pack',
                'urgency_elements' => [
                    ['type' => 'social_proof', 'text' => '8/10 members add this pack'],
                ],
                'guarantee_style' => 'inline_text',
                'cta_text'   => 'Add to Order — £17',
                'risk_reversal' => 'Cancel anytime. Pairs perfectly with your protocol.',
            ],
        ],
        [
            'key_slug' => 'urgency_bump',
            'name'     => 'Urgency Bump — Scarcity-Driven',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Most Members Add This — But Stock Runs Fast',
                'urgency_elements' => [
                    ['type' => 'timer', 'text' => 'Low stock — order within 00:15:00 to guarantee dispatch'],
                    ['type' => 'scarcity', 'text' => 'Only 12 left in this batch'],
                ],
                'guarantee_style' => 'inline_text',
                'cta_text'   => 'Secure Yours — £17',
                'risk_reversal' => 'Adds premium shipping. Cancel or modify within 1 hour.',
            ],
        ],
        [
            'key_slug' => 'value_bump',
            'name'     => 'Value Bump — Savings Framing',
            'is_control' => 0,
            'config'   => [
                'headline'    => 'Add the Essentials Pack for 40% Less Than Buying Separately',
                'urgency_elements' => [
                    ['type' => 'social_proof', 'text' => '84% of members on day 21 recommend this bundle'],
                ],
                'guarantee_style' => 'cta_adjacent',
                'cta_text'   => 'Add Bundle — Save 40%',
                'risk_reversal' => 'Full 30-day refund includes the add-on. No questions asked.',
            ],
        ],
    ],
];

// ── 6. signup_steps_v1 ────────────────────────────────────────
$experiments[] = [
    'key_slug'         => 'signup_steps_v1',
    'name'             => 'Signup Steps — Signup Surface',
    'description'      => 'Test signup page friction reduction and value framing for account creation.',
    'surface'          => 'signup',
    'primary_goal'     => 'onboarding_complete',
    'allocation_mode'  => 'bandit',
    'exploration_floor'=> 0.05,
    'auto_promote'     => 0,
    'variants'         => [
        [
            'key_slug' => 'control',
            'name'     => 'Control — Free Snapshot Hook',
            'is_control' => 1,
            'config'   => [
                'headline'   => 'See What Your Body\'s Been Trying to Tell You',
                'subheadline' => 'Free personalised insights based on your cycle phase. No credit card required.',
                'social_proof_hook' => 'Join 12,000+ women already on the protocol',
                'barrier_reduction' => [
                    'Free personalised hormone snapshot',
                    'No credit card required',
                    'Takes 2 minutes',
                ],
                'cta_text' => 'Get Your Free Snapshot',
            ],
        ],
        [
            'key_slug' => 'progress_preview',
            'name'     => 'Progress Preview — Outcome Visibility',
            'is_control' => 0,
            'config'   => [
                'headline'   => 'Your Personalised Protocol Is Ready — Create Your Free Account to Unlock It',
                'subheadline' => 'We\'ve already built your metabolic profile based on your quiz answers. Your custom plan is waiting.',
                'social_proof_hook' => '94% of members see measurable progress in 14 days',
                'barrier_reduction' => [
                    'Your quiz results are ready',
                    'Custom protocol built for your cycle phase',
                    'Free account — full access for 3 days',
                ],
                'cta_text' => 'Unlock My Protocol',
            ],
        ],
    ],
];

// ─── Insert (cross-DB: MySQL, PostgreSQL, SQLite) ─────────────

$checkExp = $db->prepare("SELECT id FROM experiments WHERE key_slug = ?");
$checkVar = $db->prepare("SELECT id FROM experiment_variants WHERE experiment_id = ? AND key_slug = ?");

$upsertExp = $db->prepare("
    UPDATE experiments SET
        name = ?, description = ?, allocation_mode = ?,
        exploration_floor = ?, auto_promote = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

$upsertVar = $db->prepare("
    UPDATE experiment_variants SET
        name = ?, config = ?, is_active = 1
    WHERE id = ?
");

$insertExp = $db->prepare("
    INSERT INTO experiments
        (id, key_slug, name, description, surface, status, allocation_mode,
         primary_goal_event, guardrail_events, exploration_floor,
         min_samples_per_variant, auto_promote, confidence_threshold, holdout)
    VALUES
        (?, ?, ?, ?, ?, 'draft', ?,
         ?, ?, ?,
         300, ?, 0.95, 0)
");

$insertVar = $db->prepare("
    INSERT INTO experiment_variants
        (id, experiment_id, key_slug, name, is_control, config, is_active)
    VALUES
        (?, ?, ?, ?, ?, ?, 1)
");

$seeded = 0;

foreach ($experiments as $exp) {
    $guardrailJson = isset($exp['guardrail_events'])
        ? json_encode($exp['guardrail_events'])
        : null;

    // Upsert experiment: check if exists
    $checkExp->execute([$exp['key_slug']]);
    $existingExp = $checkExp->fetch(PDO::FETCH_ASSOC);

    if ($existingExp) {
        $expId = $existingExp['id'];
        $upsertExp->execute([
            $exp['name'], $exp['description'], $exp['allocation_mode'],
            $exp['exploration_floor'], $exp['auto_promote'], $expId,
        ]);
    } else {
        $expId = uuid();
        $insertExp->execute([
            $expId, $exp['key_slug'], $exp['name'], $exp['description'],
            $exp['surface'], $exp['allocation_mode'], $exp['primary_goal'],
            $guardrailJson, $exp['exploration_floor'], $exp['auto_promote'],
        ]);
    }

    foreach ($exp['variants'] as $v) {
        // Upsert variant: check if exists
        $checkVar->execute([$expId, $v['key_slug']]);
        $existingVar = $checkVar->fetch(PDO::FETCH_ASSOC);

        $configJson = json_encode($v['config']);

        if ($existingVar) {
            $upsertVar->execute([$v['name'], $configJson, $existingVar['id']]);
        } else {
            $insertVar->execute([
                uuid(), $expId, $v['key_slug'], $v['name'],
                $v['is_control'], $configJson,
            ]);
        }
    }

    $seeded++;
    echo "  OK  {$exp['key_slug']} — {$exp['name']} (" . count($exp['variants']) . " variants)\n";
}

// ─── Summary ──────────────────────────────────────────────────

echo "\nSeed complete: {$seeded} experiments created/updated.\n";

$check = $db->query("SELECT COUNT(*) FROM experiments WHERE status = 'draft'");
$draftCount = $check->fetchColumn();
$check = $db->query("SELECT COUNT(*) FROM experiment_variants");
$variantCount = $check->fetchColumn();

echo "Experiments (draft): {$draftCount}\n";
echo "Total variants:      {$variantCount}\n";
