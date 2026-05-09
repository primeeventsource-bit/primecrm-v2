<?php

declare(strict_types=1);

/*
 * Lead module tuning. Per-tenant overrides live in tenants.settings (JSONB);
 * this file is the global default. Resolution order: tenant.settings.leads.* →
 * this file. The LeadScoringService and LeadAssignmentService read both.
 */
return [
    /*
     * Scoring weights — additive contributions to a lead's score.
     * The ceiling is enforced in LeadScoringService (default 1000).
     */
    'scoring' => [
        'max_score' => 1000,

        'priority_weights' => [
            'low' => 0,
            'normal' => 50,
            'high' => 200,
            'hot' => 500,
        ],

        'has_express_consent_bonus' => 75,
        'resort_interest_known_bonus' => 40,
        'phone_e164_bonus' => 20,
        'email_present_bonus' => 15,

        // Per-source base scores. Unknown sources default to 'unknown'.
        'source_weights' => [
            'facebook' => 30,
            'google' => 50,
            'tiktok' => 25,
            'referral' => 80,
            'partner_api' => 60,
            'csv_import' => 10,
            'cold_list' => 5,
            'inbound_call' => 100,
            'web_form' => 40,
            'unknown' => 0,
        ],

        // Linear contribution: estimated_value / divisor, capped.
        'estimated_value' => [
            'divisor' => 200,
            'cap' => 100,
        ],

        // Penalties — subtracted before clamping.
        'attempt_penalty_per_call' => 10,
        'age_penalty_per_day' => 2,
        'max_age_penalty' => 80,
    ],

    /*
     * Lead assignment.
     *
     * Modes:
     *   round_robin — simple cycle through eligible agents (configurable).
     *   performance — weighted random selection from top-N by AgentScore.
     *   skill_based — filters by required skills, then performance-weighted.
     *
     * AgentScore = (conversion_rate * 0.4)
     *            + (revenue_normalized * 0.3)
     *            + (call_speed_normalized * 0.2)
     *            + (qa_score * 0.1)
     *
     * The assignment service explains its decision in audit_logs.
     */
    'assignment' => [
        'default_mode' => env('LEAD_ASSIGNMENT_MODE', 'performance'),

        'eligible_roles' => ['agent', 'fronter', 'closer'],

        // Recompute per-agent metrics every N seconds (cached in Redis).
        'metrics_cache_ttl_seconds' => 300,

        // Performance window. 30d matches our commission lookback.
        'metrics_window_days' => 30,

        'score_weights' => [
            'conversion_rate' => 0.4,
            'revenue' => 0.3,
            'call_speed' => 0.2,
            'qa_score' => 0.1,
        ],

        // Top-N pool to draw from in performance/skill_based modes. Setting
        // this above 1 prevents starving everyone but the top agent and
        // gives high performers ~weighted probability rather than 100%.
        'performance_top_n' => 5,

        // Hot leads short-circuit normal routing and go to top performer.
        'hot_lead_skip_pool' => true,

        // Reassign leads idle this long.
        'stale_assignment_minutes' => 10,

        // Hard cap on per-agent open lead pile.
        'max_open_leads_per_agent' => 200,
    ],

    /*
     * CSV import.
     */
    'import' => [
        // Hard maximum rows in a single upload. Larger files should be split.
        'max_rows_per_file' => 500_000,
        'chunk_size' => 1_000,
        'sample_error_cap' => 100, // store first N errors in lead_imports.errors
    ],

    /*
     * Dedup engine thresholds. Phone match is exact (hash equality);
     * email match is exact lowercase; fuzzy name match is Levenshtein
     * AND requires a structural co-signal (postal_code OR city+state)
     * — name alone is not enough to fold records.
     */
    'dedup' => [
        'fuzzy_name_max_distance' => 2,
    ],
];
