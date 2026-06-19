<?php

return [
    'page_title'   => 'Structure Compliance',
    'banner_title' => 'Structure doctrine compliance',
    'banner_body'  => 'Compares each Upwell structure your corp owns against the recommended fit (rigs and service modules) for its type and security band. Read-only; Structure Manager changes nothing in-game.',
    'corporation'  => 'Corporation',
    'manage'       => 'Manage doctrines',

    'scope_corp'     => 'Per-corp doctrines',
    'scope_alliance' => 'Per-alliance doctrines',
    'doctrines_n'    => ':n doctrine(s) defined',
    'offline_strict' => 'offline services count against compliance',

    'unavailable'   => 'Structure data is unavailable on this install (corporation_structures not present).',
    'no_assets'     => 'No corporation assets are synced for this corp, so fitted rigs and service modules cannot be read. Register a director/accountant token with the corp-assets scope. Structures show as "No data" until then.',
    'no_structures' => 'This corporation owns no Upwell structures (or none are synced yet).',

    'compliant'          => 'Compliant',
    'compliant_upgraded' => 'Compliant + upgraded',
    'partial'            => 'Partial',
    'non_compliant'      => 'Non-compliant',
    'no_doctrine'        => 'No doctrine',
    'no_data'            => 'No data',

    'band_highsec'  => 'High-sec',
    'band_lowsec'   => 'Low-sec',
    'band_nullsec'  => 'Null-sec',
    'band_wormhole' => 'Wormhole',

    'reason_no_fighters'      => 'Fighter bay empty',
    'reason_no_ammo'          => 'Ammo hold empty',
    'reason_offline_services' => 'Service module offline',

    'col_current'  => 'Current',
    'col_required' => 'Required',
    'col_diff'     => 'Diff',
    'empty_slot'   => '(empty)',

    'diff_upgraded' => 'upgraded',
    'diff_lower'    => 'lower tier',
    'diff_mismatch' => 'wrong module',
    'diff_missing'  => 'missing',
    'diff_extra'    => 'extra',

    'section_high'     => 'High slots',
    'section_med'      => 'Med slots',
    'section_low'      => 'Low slots',
    'section_rig'      => 'Rigs',
    'section_service'  => 'Service modules',
    'section_other'    => 'Other',
    'section_optional' => 'Cargo / fighters (optional)',
    'optional_tag'     => 'optional, not checked',

    'diag_band'     => 'Band mismatch: this structure is in :band, but your doctrine(s) for this type are for other bands. Add a :band doctrine.',
    'diag_scope_id' => 'Different corporation/alliance: a doctrine for this type exists, but it was saved for a different corp/alliance than this structure. Re-save it under this structure\'s corp (or switch to per-alliance scope if these share an alliance).',
    'diag_found'    => 'Doctrines found for this structure type:',

    'no_doctrine_hint' => 'No doctrine is defined for this structure type and security band. Add one via Manage doctrines.',
    'no_data_hint'     => 'No corp asset data, so fitted modules cannot be read. Register a director/accountant token with the corp-assets scope.',

    'fit_current'     => 'Current fit',
    'fit_recommended' => 'Recommended fit',
    'fit_missing'     => 'Missing',
    'btn_copy'        => 'Copy',
    'btn_appraise'    => 'Appraise',
    'copied'          => 'Copied',
];
