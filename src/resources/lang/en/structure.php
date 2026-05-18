<?php

/**
 * Structure Manager — labels for fuel-event classification (Tier 1) and
 * suspect-narrowing forensics (Tier 2), shipped in v2.0.0.
 *
 * Keys map to constants in:
 *   - StructureManager\Models\StructureFuelHistory          (fuel_event_*)
 *   - StructureManager\Models\StructureFuelEventCandidate   (fuel_signal_*, fuel_confidence_*)
 */
return [
    // ============================================================
    // Fuel event types (Tier 1 — FuelEventClassifier output)
    // ============================================================
    'fuel_event_unclassified'        => 'Unclassified',
    'fuel_event_consumption_normal'  => 'Normal Consumption',
    'fuel_event_consumption_anomaly' => 'Consumption Anomaly',
    'fuel_event_refuel_internal'     => 'Refuel (from Reserves)',
    'fuel_event_refuel_external'     => 'Refuel (from Outside)',
    'fuel_event_withdrawal_bay'      => 'Withdrawal from Bay',
    'fuel_event_withdrawal_reserves' => 'Withdrawal from Reserves',
    'fuel_event_unexplained_gain'    => 'Unexplained Gain',

    // ============================================================
    // Fuel event descriptions (tooltip on the badges)
    // ============================================================
    'fuel_event_desc_unclassified'        => 'First snapshot or no baseline data available yet.',
    'fuel_event_desc_consumption_normal'  => 'Bay burned fuel at the expected rate for active services.',
    'fuel_event_desc_consumption_anomaly' => 'Bay burned 15-50% more than expected. A service likely activated or low-power lifted.',
    'fuel_event_desc_refuel_internal'     => 'Fuel was moved from a CorpSAG reserve hangar into the bay.',
    'fuel_event_desc_refuel_external'     => 'Fuel was added to the bay from outside the structure (haul or market buy).',
    'fuel_event_desc_withdrawal_bay'      => 'Bay lost more than 1.5x expected consumption. Likely someone yanked fuel from the bay.',
    'fuel_event_desc_withdrawal_reserves' => 'CorpSAG reserves dropped significantly without matching bay gain. Fuel may have left the corp.',
    'fuel_event_desc_unexplained_gain'    => 'Bay gained fuel but the reserve change does not match expected refuel patterns.',

    // ============================================================
    // Forensic signals (Tier 2 — WithdrawalForensicsService matches)
    // ============================================================
    'fuel_signal_online_during_window' => 'Online during event',
    'fuel_signal_asset_gain_match'     => 'Personal hangar gain matches',
    'fuel_signal_has_role'             => 'Has corp title',
    'fuel_signal_wallet_sale_match'    => 'Sold matching fuel on market',

    // ============================================================
    // Confidence buckets
    // ============================================================
    'fuel_confidence_HIGH'   => 'High',
    'fuel_confidence_MEDIUM' => 'Medium',
    'fuel_confidence_LOW'    => 'Low',

    // ============================================================
    // UI strings for the forensics surface
    // ============================================================
    'forensics_panel_title'        => 'Candidate Handlers',
    'forensics_panel_disclaimer'   => 'ESI does not expose who moved this fuel. These candidates are inferred from collateral SeAT data (online status, personal hangar holdings, corp titles, market sales). False positives are inevitable.',
    'forensics_panel_no_matches'   => 'No candidate handlers identified for this event.',
    'forensics_panel_loading'      => 'Forensic analysis in progress…',
    'forensics_column_character'   => 'Character',
    'forensics_column_confidence'  => 'Confidence',
    'forensics_column_score'       => 'Score',
    'forensics_column_signals'     => 'Matched Signals',
];
