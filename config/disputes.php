<?php

/*
|--------------------------------------------------------------------------
| Advanced Disputes (Phase 2)
|--------------------------------------------------------------------------
|
| Escalation tiers, refund limits, response targets and appeals, to the
| OQ-21 recommendation of 16 Oct 2026 (not yet client-confirmed):
|
|   Tier 1  support          every new dispute except safety
|   Tier 2  support_lead     escalations, appeals, missed deadlines
|   Tier 3  super_admin      final; larger refunds, legal and press cases
|   Safety  safety_operator  safety concerns, skipping tier 1
|
| A super admin can act at any tier, and a higher tier can act on a lower
| tier's case. Super admins can override every value at runtime with
| PUT /api/v1/admin/settings/disputes; read them through
| AppSetting::disputes(), not config(), so overrides apply.
|
*/

return [

    // Which admin role works each tier. Not a runtime setting: changing it
    // changes who can see and act on cases.
    'tiers' => [
        1 => 'support',
        2 => 'support_lead',
        3 => 'super_admin',
    ],

    // Most one admin can refund on one dispute without a second approver,
    // in NGN and counting refunds already given on it. Above the limit the
    // refund waits for an admin whose limit covers it. Super admins have no
    // limit.
    'tier_1_refund_limit' => (float) env('DISPUTES_TIER_1_REFUND_LIMIT', 5000),
    'tier_2_refund_limit' => (float) env('DISPUTES_TIER_2_REFUND_LIMIT', 50000),
    'safety_refund_limit' => (float) env('DISPUTES_SAFETY_REFUND_LIMIT', 50000),

    // Response targets. First response: someone takes the case or moves it
    // to under review. The clock restarts when a case changes tier.
    'safety_first_response_minutes' => (int) env('DISPUTES_SAFETY_FIRST_RESPONSE_MINUTES', 60),
    'safety_resolution_hours' => (int) env('DISPUTES_SAFETY_RESOLUTION_HOURS', 24),
    'tier_1_first_response_hours' => (int) env('DISPUTES_TIER_1_FIRST_RESPONSE_HOURS', 24),
    'tier_1_resolution_hours' => (int) env('DISPUTES_TIER_1_RESOLUTION_HOURS', 72),
    'tier_2_first_response_hours' => (int) env('DISPUTES_TIER_2_FIRST_RESPONSE_HOURS', 24),
    'tier_2_resolution_hours' => (int) env('DISPUTES_TIER_2_RESOLUTION_HOURS', 120),

    // Move a tier 1 case that misses a target to tier 2. Off: it stays at
    // tier 1 and only shows as overdue.
    'auto_escalate_tier_1' => (bool) env('DISPUTES_AUTO_ESCALATE_TIER_1', true),

    // Days after the decision in which the reporter can appeal, once.
    'appeal_window_days' => (int) env('DISPUTES_APPEAL_WINDOW_DAYS', 7),

    // Evidence files (photos, audio, documents) one dispute can hold.
    'max_evidence_files' => (int) env('DISPUTES_MAX_EVIDENCE_FILES', 10),

];
