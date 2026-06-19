<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recommended Upwell structure fit, scoped per corp or per alliance and
 * keyed by structure type + security band. Ported from HR Manager. See the
 * migration docblock + StructureComplianceService for the compliance model.
 */
class StructureDoctrine extends Model
{
    protected $table = 'structure_manager_doctrines';

    protected $fillable = [
        'scope_type', 'scope_id', 'structure_type_id', 'structure_type_name',
        'security_band', 'name', 'eft_raw', 'parsed',
        'require_fighters', 'require_ammo', 'is_active', 'created_by',
    ];

    protected $casts = [
        'parsed'           => 'array',
        'require_fighters' => 'boolean',
        'require_ammo'     => 'boolean',
        'is_active'        => 'boolean',
    ];

    public const BANDS = ['highsec', 'lowsec', 'nullsec', 'wormhole'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
