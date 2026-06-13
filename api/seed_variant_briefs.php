<?php
// api/seed_variant_briefs.php
// ===========================================
// B.2 — Seed variant_briefs rows per surface
// Run AFTER variant_generation_schema.sql
// ===========================================

include_once __DIR__ . '/config/Database.php';

header("Content-Type: application/json");

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["message" => "Database connection failed"]);
    exit();
}

// ---- shared strings ----

$brandVoice = <<<'VOICE'
Warm, direct, science-grounded. We speak to women who are tired of generic health advice and ready for a protocol that respects their biology.

**Do:**
- Use "we" and "you" — collaborative, not prescriptive
- Cite biological mechanisms simply ("Your cells need…" not "Mitochondrial dysfunction…")
- Normalize struggle + celebrate progress
- Lead with empathy, follow with science

**Don't:**
- Shame or guilt ("Stop ignoring your body")
- Use fear-based urgency ("This is your last chance")
- Generic wellness platitudes ("Eat clean, live well")
- Medicalize everyday experiences

**Vocabulary:**
- "Cycle-synced" not "hormone-optimized"
- "Protocol" not "program"
- "Metabolic flexibility" not "weight loss"
- "Nourish" not "restrict"
- "Rebalance" not "fix"
- "PCOS-friendly" not "PCOS cure"
VOICE;

$sharedValueProps = [
    [
        'key' => 'cycle_synced',
        'headline' => 'Cycle-Synced Protocols',
        'body' => 'Every recommendation adapts to your menstrual phase — follicular, ovulatory, luteal, menstrual. What works in week one won\'t work in week three, and we honour that.',
        'weight' => 1.0
    ],
    [
        'key' => 'metabolic_reset_21',
        'headline' => '21-Day Metabolic Reset',
        'body' => 'A structured, evidence-based protocol to rebuild metabolic flexibility. Not a quick fix — a rewire.',
        'weight' => 0.9
    ],
    [
        'key' => 'community',
        'headline' => 'Community That Gets It',
        'body' => 'Small cohorts of women on the same protocol. Shared wins, shared struggles, real accountability.',
        'weight' => 0.8
    ],
    [
        'key' => 'guarantee',
        'headline' => '30-Day Money-Back Guarantee',
        'body' => 'If you don\'t feel the difference in 30 days, we refund every penny. No forms, no fuss.',
        'weight' => 0.7
    ],
    [
        'key' => 'ai_coach',
        'headline' => 'AI Health Coach (Sara)',
        'body' => 'Your personal coach, available 24/7. Sara knows your cycle phase, your energy levels, your goals. She adapts in real time.',
        'weight' => 0.85
    ]
];

$forbiddenClaims = [
    'CURES, TREATS, OR PREVENTS any medical condition (PCOS, diabetes, thyroid disorders, etc.)',
    'FDA, EMA, or MHRA approval or clearance',
    'Guaranteed weight-loss amount or timeline ("Lose 10 lbs in 7 days")',
    'Comparable to or a replacement for prescription medication (metformin, Ozempic, spironolactone, etc.)',
    'Replaces professional medical advice, diagnosis, or treatment',
    'PCOS reversal or cure',
    'Specific hormone level changes ("Increase progesterone by X%")',
    'Claims about pregnancy, fertility treatment, or conception rates',
    'Clinical trial results that do not exist or are not correctly attributed',
    'Testimonials presented as typical results without being clearly identifiable as individual experiences'
];

$targetPersonas = ['hormonal_warrior', 'metabolic_reset', 'newbie', 'cycle_tracker'];

// ---- per-surface seed data ----

$briefs = [];

// --- 1. sales_trust ---
$briefs['sales_trust'] = [
    'surface'         => 'sales_trust',
    'brand_voice'     => $brandVoice,
    'value_props'     => json_encode($sharedValueProps, JSON_PRETTY_PRINT),
    'forbidden_claims' => json_encode($forbiddenClaims, JSON_PRETTY_PRINT),
    'target_personas'  => json_encode($targetPersonas),
    'config_schema'    => json_encode([
        'type' => 'object',
        'required' => ['headline', 'subheadline', 'trust_signals', 'testimonial_style', 'cta_text'],
        'properties' => [
            'headline' => [
                'type' => 'string',
                'maxLength' => 80,
                'description' => 'Above-fold hero headline'
            ],
            'subheadline' => [
                'type' => 'string',
                'maxLength' => 160,
                'description' => 'Supporting subheadline'
            ],
            'trust_signals' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 6,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'icon' => ['type' => 'string'],
                        'label' => ['type' => 'string']
                    ]
                ],
                'description' => 'Trust-building indicators (credentials, media features, stats)'
            ],
            'testimonial_style' => [
                'type' => 'string',
                'enum' => ['carousel', 'spotlight_static', 'grid'],
                'description' => 'How testimonials are displayed'
            ],
            'cta_text' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Primary CTA button text'
            ],
            'proof_points' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Key proof/statistic points'
            ]
        ]
    ], JSON_PRETTY_PRINT),
    'reference_winners' => json_encode([
        [
            'surface' => 'sales_trust',
            'variant' => [
                'headline' => 'Wellness That Finally Respects Your Cycle',
                'subheadline' => 'Science-backed protocols that adapt to every phase of your menstrual cycle. Not generic advice — precision care.',
                'trust_signals' => [
                    ['icon' => 'flask', 'label' => 'Built with PhD researchers'],
                    ['icon' => 'users', 'label' => '12,000+ women in 14 countries'],
                    ['icon' => 'newspaper', 'label' => 'Featured in Vogue & Women\'s Health']
                ],
                'testimonial_style' => 'spotlight_static',
                'cta_text' => 'Start Your Protocol',
                'proof_points' => [
                    '94% report improved energy within 2 weeks',
                    '87% see metabolic improvements by day 21'
                ]
            ],
            'rationale' => 'Hero headline directly names the frustration (generic wellness) and promises a solution (cycle-respect). Subheadline bridges science + care. Trust signals are specific and verifiable.',
            'conversion_rate' => 0.042
        ],
        [
            'surface' => 'sales_trust',
            'variant' => [
                'headline' => 'The 21-Day Protocol Built for Your Biology',
                'subheadline' => 'Not another "eat less, move more" plan. A metabolic reset that works with your hormones, not against them.',
                'trust_signals' => [
                    ['icon' => 'chart-line', 'label' => '92% completion rate across all cohorts'],
                    ['icon' => 'clock', 'label' => '7+ years of women\'s health research'],
                    ['icon' => 'star', 'label' => '4.8/5 from 3,200+ reviews']
                ],
                'testimonial_style' => 'grid',
                'cta_text' => 'Check Your Eligibility',
                'proof_points' => [
                    '73% reduction in sugar cravings by day 14',
                    'Active community in 30+ countries'
                ]
            ],
            'rationale' => 'Frames the protocol as a direct counter to failed generic plans. Completion rate as social proof is powerful.',
            'conversion_rate' => 0.038
        ]
    ], JSON_PRETTY_PRINT)
];

// --- 2. quiz_landing ---
$briefs['quiz_landing'] = [
    'surface'         => 'quiz_landing',
    'brand_voice'     => $brandVoice,
    'value_props'     => json_encode($sharedValueProps, JSON_PRETTY_PRINT),
    'forbidden_claims' => json_encode($forbiddenClaims, JSON_PRETTY_PRINT),
    'target_personas'  => json_encode($targetPersonas),
    'config_schema'    => json_encode([
        'type' => 'object',
        'required' => ['headline', 'subheadline', 'quiz_question', 'benefit_preview', 'cta_text'],
        'properties' => [
            'headline' => [
                'type' => 'string',
                'maxLength' => 70,
                'description' => 'Hook that makes the quiz feel personal'
            ],
            'subheadline' => [
                'type' => 'string',
                'maxLength' => 140,
                'description' => 'Why take this quiz'
            ],
            'quiz_question' => [
                'type' => 'string',
                'maxLength' => 120,
                'description' => 'First question text (the micro-commitment hook)'
            ],
            'benefit_preview' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'What they get after completing the quiz'
            ],
            'cta_text' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Button text to start the quiz'
            ],
            'commitment_framing' => [
                'type' => 'string',
                'enum' => ['micro', 'curiosity', 'diagnostic'],
                'description' => 'Psychological framing for starting the quiz'
            ]
        ]
    ], JSON_PRETTY_PRINT),
    'reference_winners' => json_encode([
        [
            'surface' => 'quiz_landing',
            'variant' => [
                'headline' => 'Your Hormones Are Trying to Tell You Something',
                'subheadline' => 'Answer 6 quick questions and get a personalised metabolic blueprint — free.',
                'quiz_question' => 'How would you describe your energy levels right now?',
                'benefit_preview' => [
                    'Personalised protocol match',
                    'Cycle phase insights',
                    'First 3 days of your protocol free'
                ],
                'cta_text' => 'Start the Quiz →',
                'commitment_framing' => 'curiosity'
            ],
            'rationale' => 'Curiosity gap headline draws in. "6 quick questions" lowers barrier. Free first 3 days is powerful lead magnet.',
            'conversion_rate' => 0.067
        ]
    ], JSON_PRETTY_PRINT)
];

// --- 3. checkout ---
$briefs['checkout'] = [
    'surface'         => 'checkout',
    'brand_voice'     => $brandVoice,
    'value_props'     => json_encode($sharedValueProps, JSON_PRETTY_PRINT),
    'forbidden_claims' => json_encode($forbiddenClaims, JSON_PRETTY_PRINT),
    'target_personas'  => json_encode($targetPersonas),
    'config_schema'    => json_encode([
        'type' => 'object',
        'required' => ['headline', 'urgency_elements', 'guarantee_style', 'cta_text'],
        'properties' => [
            'headline' => [
                'type' => 'string',
                'maxLength' => 80,
                'description' => 'Checkout page headline (reassurance + value framing)'
            ],
            'urgency_elements' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['timer', 'social_proof', 'scarcity']],
                        'text' => ['type' => 'string']
                    ]
                ],
                'description' => 'Ethical urgency signals'
            ],
            'guarantee_style' => [
                'type' => 'string',
                'enum' => ['prominent_banner', 'inline_text', 'cta_adjacent'],
                'description' => 'Risk-reversal placement'
            ],
            'cta_text' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Purchase CTA text'
            ],
            'risk_reversal' => [
                'type' => 'string',
                'maxLength' => 120,
                'description' => 'The guarantee / what-if-it-doesnt-work statement'
            ]
        ]
    ], JSON_PRETTY_PRINT),
    'reference_winners' => json_encode([
        [
            'surface' => 'checkout',
            'variant' => [
                'headline' => 'You\'re One Step Away From a Body That Finally Feels Like Yours',
                'urgency_elements' => [
                    ['type' => 'social_proof', 'text' => '47 people started their protocol today'],
                    ['type' => 'scarcity', 'text' => 'Next cohort closes in 3 days']
                ],
                'guarantee_style' => 'prominent_banner',
                'cta_text' => 'Join the Protocol',
                'risk_reversal' => 'If you don\'t feel the difference in 30 days, we\'ll refund every penny. No questions, no hassle.'
            ],
            'rationale' => 'Reframes purchase as investment in self. Ethical social proof + cohort scarcity (genuine). No fake timers.',
            'conversion_rate' => 0.053
        ]
    ], JSON_PRETTY_PRINT)
];

// --- 4. pricing ---
$briefs['pricing'] = [
    'surface'         => 'pricing',
    'brand_voice'     => $brandVoice,
    'value_props'     => json_encode($sharedValueProps, JSON_PRETTY_PRINT),
    'forbidden_claims' => json_encode($forbiddenClaims, JSON_PRETTY_PRINT),
    'target_personas'  => json_encode($targetPersonas),
    'config_schema'    => json_encode([
        'type' => 'object',
        'required' => ['headline', 'plan_comparison_framing', 'featured_plan', 'cta_text'],
        'properties' => [
            'headline' => [
                'type' => 'string',
                'maxLength' => 80,
                'description' => 'Pricing page headline'
            ],
            'plan_comparison_framing' => [
                'type' => 'string',
                'enum' => ['side_by_side', 'stacked', 'toggle'],
                'description' => 'Layout style for plan comparison'
            ],
            'featured_plan' => [
                'type' => 'string',
                'description' => 'Which plan is highlighted as best value'
            ],
            'cta_text' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Primary CTA text'
            ],
            'price_anchoring' => [
                'type' => 'string',
                'maxLength' => 100,
                'description' => 'Comparative anchor text (e.g. "Less than /day")'
            ]
        ]
    ], JSON_PRETTY_PRINT),
    'reference_winners' => json_encode([
        [
            'surface' => 'pricing',
            'variant' => [
                'headline' => 'Invest in Yourself — For Less Than a Coffee a Day',
                'plan_comparison_framing' => 'side_by_side',
                'featured_plan' => 'Metabolic Reset (21-day)',
                'cta_text' => 'Start Your Transformation',
                'price_anchoring' => 'Less than £3/day — cancels anytime'
            ],
            'rationale' => 'Price anchoring to daily coffee is relatable and low-stress. Side-by-side comparison helps justify the mid-tier choice.',
            'conversion_rate' => 0.047
        ]
    ], JSON_PRETTY_PRINT)
];

// --- 5. signup ---
$briefs['signup'] = [
    'surface'         => 'signup',
    'brand_voice'     => $brandVoice,
    'value_props'     => json_encode($sharedValueProps, JSON_PRETTY_PRINT),
    'forbidden_claims' => json_encode($forbiddenClaims, JSON_PRETTY_PRINT),
    'target_personas'  => json_encode($targetPersonas),
    'config_schema'    => json_encode([
        'type' => 'object',
        'required' => ['headline', 'subheadline', 'social_proof_hook', 'barrier_reduction', 'cta_text'],
        'properties' => [
            'headline' => [
                'type' => 'string',
                'maxLength' => 70,
                'description' => 'Signup headline (low-commitment framing)'
            ],
            'subheadline' => [
                'type' => 'string',
                'maxLength' => 140,
                'description' => 'Value reminder for creating an account'
            ],
            'social_proof_hook' => [
                'type' => 'string',
                'maxLength' => 100,
                'description' => 'One-liner social proof near the form'
            ],
            'barrier_reduction' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Reasons this is an easy commitment'
            ],
            'cta_text' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Signup button text'
            ]
        ]
    ], JSON_PRETTY_PRINT),
    'reference_winners' => json_encode([
        [
            'surface' => 'signup',
            'variant' => [
                'headline' => 'See What Your Body\'s Been Trying to Tell You',
                'subheadline' => 'Free personalised insights based on your cycle phase. No credit card required.',
                'social_proof_hook' => 'Join 12,000+ women already on the protocol',
                'barrier_reduction' => [
                    'Free personalised hormone snapshot',
                    'No credit card required',
                    'Takes 2 minutes'
                ],
                'cta_text' => 'Get Your Free Snapshot'
            ],
            'rationale' => 'Leads with curiosity and self-discovery. "No credit card" and "2 minutes" crush signup friction. Free snapshot is a low-risk entry point.',
            'conversion_rate' => 0.089
        ]
    ], JSON_PRETTY_PRINT)
];

// ---- batch insert ----

try {
    $stmt = $db->prepare("
        INSERT INTO variant_briefs
            (id, surface, brand_voice, value_props, forbidden_claims, target_personas, reference_winners, config_schema)
        VALUES
            (:id, :surface, :brand_voice, :value_props, :forbidden_claims, :target_personas, :reference_winners, :config_schema)
        ON DUPLICATE KEY UPDATE
            brand_voice        = VALUES(brand_voice),
            value_props        = VALUES(value_props),
            forbidden_claims   = VALUES(forbidden_claims),
            target_personas    = VALUES(target_personas),
            reference_winners  = VALUES(reference_winners),
            config_schema      = VALUES(config_schema)
    ");

    $count = 0;
    foreach ($briefs as $key => $brief) {
        $stmt->execute([
            ':id'               => 'b_' . $brief['surface'],
            ':surface'          => $brief['surface'],
            ':brand_voice'      => $brief['brand_voice'],
            ':value_props'      => $brief['value_props'],
            ':forbidden_claims' => $brief['forbidden_claims'],
            ':target_personas'  => $brief['target_personas'],
            ':reference_winners'=> $brief['reference_winners'],
            ':config_schema'    => $brief['config_schema']
        ]);
        $count++;
    }

    echo json_encode([
        "message" => "Variant briefs seeded successfully",
        "surfaces_seeded" => $count,
        "surfaces" => array_keys($briefs)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "message" => "Error seeding variant briefs",
        "error" => $e->getMessage()
    ]);
}
