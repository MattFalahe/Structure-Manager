<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use StructureManager\Http\Controllers\Traits\ScopesStructureCorps;
use StructureManager\Services\StructureComplianceService;

/**
 * Doctrine Compliance page: compares a corp's Upwell structures against the
 * recommended fits (StructureDoctrine). Ported from HR Manager's Corp Health
 * "Structure Compliance" tab into a standalone Structure Manager nav page.
 * Read-only; reads SeAT core only (no ESI). View-tier.
 */
class ComplianceController extends Controller
{
    use ScopesStructureCorps;

    public function index(Request $request)
    {
        $corporations  = $this->corpOptions();
        $corporationId = $this->resolveStructureCorp($request, $corporations);

        if ($corporationId > 0) {
            $this->assertStructureCorpAccess($corporationId);
            $compliance = app(StructureComplianceService::class)->forCorporation($corporationId);
        } else {
            $compliance = ['available' => false];
        }

        return view('structure-manager::compliance.index', compact(
            'corporations', 'corporationId', 'compliance'
        ));
    }
}
