<?php

return [
    'title' => 'Structure Doctrines',
    'intro' => 'Define the recommended fit for each Upwell structure type, per security band. The Doctrine Compliance page then checks your structures against these.',
    'corporation' => 'Corporation',
    'back_compliance' => 'Back to compliance',

    // Settings
    'settings_heading' => 'Compliance settings',
    'scope_label' => 'Doctrine scope',
    'scope_corp' => 'Per corporation',
    'scope_alliance' => 'Per alliance (shared)',
    'scope_help' => 'Per-corp keeps each corporation\'s doctrines separate. Per-alliance shares one set across every corp in the same alliance.',
    'offline_strict_label' => 'A fitted-but-offline service module counts as non-compliant',
    'save_settings' => 'Save settings',

    // List
    'list_heading' => 'Doctrines',
    'add' => 'Add doctrine',
    'none' => 'No doctrines defined yet. Add one to start checking compliance.',
    'alliance_missing' => 'This corporation has no alliance on record, so per-alliance doctrines cannot be keyed. Switch to per-corp scope, or sync the corp\'s alliance.',
    'inactive' => 'Inactive',
    'col_structure' => 'Structure',
    'col_band' => 'Band',
    'col_name' => 'Doctrine',
    'col_required' => 'Required modules',
    'col_gates' => 'Gates',
    'gate_fighters' => 'Fighters',
    'gate_ammo' => 'Ammo',
    'confirm_delete' => 'Delete this doctrine?',

    // Form
    'back' => 'Back to doctrines',
    'add_heading' => 'New structure doctrine',
    'edit_heading' => 'Edit structure doctrine',
    'form_help' => 'Paste the recommended fit in EFT format. The first line names the hull ([Astrahus, ...]); rigs and service modules are the required spine. The structure type and security band are how this doctrine is matched to your structures.',
    'f_name' => 'Doctrine name',
    'f_name_ph' => 'e.g. Staging Fortizar',
    'f_band' => 'Security band',
    'f_eft' => 'Recommended fit (EFT)',
    'f_eft_ph' => '[Astrahus, Staging] then one module per line, rigs + service modules included',
    'f_eft_help' => 'Module names must match in-game names exactly. A higher tier than required (T2 where T1 is listed) or an extra rig still counts as compliant (badged "upgraded").',
    'f_require_fighters' => 'Require fighters present (any type)',
    'f_require_ammo' => 'Require ammo present (any type)',
    'f_active' => 'Active',
    'f_gates_help' => 'Fighters/ammo gates only check the bay is non-empty. The specific type is the player\'s choice.',
    'f_save' => 'Save doctrine',

    // Flashes
    'saved' => 'Doctrine saved.',
    'deleted' => 'Doctrine deleted.',
    'settings_saved' => 'Compliance settings saved.',

    // Errors
    'err_hull_unresolved' => 'The hull ":name" was not found in the SDE. Check the spelling on the first [Hull, Name] line.',
    'err_unresolved' => 'These names were not found in the SDE: :names. Fix the spelling (they must match in-game names exactly) and save again.',
    'err_no_alliance' => 'Per-alliance scope is on but this corporation has no alliance on record. Switch to per-corp scope, or sync the alliance.',
    'err_duplicate' => 'A doctrine already exists for this structure type + security band in this scope. Edit that one instead.',
    'err_save' => 'Could not save the doctrine. Check the log and try again.',
];
