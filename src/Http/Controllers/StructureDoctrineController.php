<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use StructureManager\Http\Controllers\Traits\ScopesStructureCorps;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Models\StructureDoctrine;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Services\EftParser;
use StructureManager\Services\StructureComplianceService;

/**
 * Manage alliance/corp structure doctrines (the "specify alliance fittings"
 * page) + the two global compliance settings. Ported from HR Manager.
 * Admin-tier.
 */
class StructureDoctrineController extends Controller
{
    use ScopesStructureCorps;

    public function index(Request $request)
    {
        $corporations  = $this->corpOptions();
        $corporationId = $this->resolveStructureCorp($request, $corporations);
        $this->assertStructureCorpAccess($corporationId);

        $svc           = app(StructureComplianceService::class);
        $scopeMode     = $svc->scopeMode();
        $offlineStrict = $svc->offlineStrict();
        $scopeId       = $this->scopeIdFor($corporationId, $scopeMode);

        $doctrines       = collect();
        $allianceMissing = false;
        if ($scopeMode === 'alliance' && !$scopeId) {
            $allianceMissing = true;
        } elseif ($scopeId) {
            $doctrines = StructureDoctrine::where('scope_type', $scopeMode)
                ->where('scope_id', $scopeId)
                ->orderBy('structure_type_name')
                ->orderBy('security_band')
                ->get();
        }

        return view('structure-manager::doctrines.index', compact(
            'doctrines', 'corporationId', 'corporations',
            'scopeMode', 'offlineStrict', 'allianceMissing'
        ));
    }

    public function create(Request $request)
    {
        $corporationId = (int) $request->input('corporation_id', 0);
        $this->assertStructureCorpAccess($corporationId);

        $doctrine = null;
        $bands    = StructureDoctrine::BANDS;

        return view('structure-manager::doctrines.form', compact('doctrine', 'corporationId', 'bands'));
    }

    public function edit(Request $request, $id)
    {
        $corporationId = (int) $request->input('corporation_id', 0);
        $this->assertStructureCorpAccess($corporationId);

        $doctrine = StructureDoctrine::findOrFail((int) $id);
        $bands    = StructureDoctrine::BANDS;

        return view('structure-manager::doctrines.form', compact('doctrine', 'corporationId', 'bands'));
    }

    public function store(Request $request)
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, $id)
    {
        return $this->persist($request, StructureDoctrine::findOrFail((int) $id));
    }

    private function persist(Request $request, ?StructureDoctrine $doctrine)
    {
        $corporationId = (int) $request->input('corporation_id');
        $this->assertStructureCorpAccess($corporationId);

        $data = $request->validate([
            'name'             => 'required|string|max:120',
            'security_band'    => 'required|in:highsec,lowsec,nullsec,wormhole',
            'eft'              => 'required|string|max:20000',
            'require_fighters' => 'nullable|boolean',
            'require_ammo'     => 'nullable|boolean',
            'is_active'        => 'nullable|boolean',
        ]);

        $parsed = app(EftParser::class)->parse($data['eft']);
        if ($parsed['error']) {
            return back()->withInput()->with('error', $parsed['error']);
        }
        if (!$parsed['hull_type_id']) {
            return back()->withInput()->with('error', trans('structure-manager::doctrines.err_hull_unresolved', ['name' => $parsed['hull_type_name']]));
        }
        if (!empty($parsed['unresolved'])) {
            return back()->withInput()->with('error', trans('structure-manager::doctrines.err_unresolved', ['names' => implode(', ', $parsed['unresolved'])]));
        }

        $scopeMode = app(StructureComplianceService::class)->scopeMode();
        $scopeId   = $this->scopeIdFor($corporationId, $scopeMode);
        if (!$scopeId) {
            return back()->withInput()->with('error', trans('structure-manager::doctrines.err_no_alliance'));
        }

        // Enforce (scope, type, band) uniqueness with a friendly message.
        $clash = StructureDoctrine::where('scope_type', $scopeMode)
            ->where('scope_id', $scopeId)
            ->where('structure_type_id', $parsed['hull_type_id'])
            ->where('security_band', $data['security_band']);
        if ($doctrine) {
            $clash->where('id', '!=', $doctrine->id);
        }
        if ($clash->exists()) {
            return back()->withInput()->with('error', trans('structure-manager::doctrines.err_duplicate'));
        }

        $payload = [
            'scope_type'          => $scopeMode,
            'scope_id'            => (int) $scopeId,
            'structure_type_id'   => $parsed['hull_type_id'],
            'structure_type_name' => $parsed['hull_type_name'],
            'security_band'       => $data['security_band'],
            'name'                => $data['name'],
            'eft_raw'             => $data['eft'],
            'parsed'              => [
                'hull_type_id'   => $parsed['hull_type_id'],
                'hull_type_name' => $parsed['hull_type_name'],
                'required'       => $parsed['required'],
                'optional'       => $parsed['optional'],
                'slot_counts'    => $parsed['slot_counts'] ?? [],
            ],
            'require_fighters'    => $request->boolean('require_fighters'),
            'require_ammo'        => $request->boolean('require_ammo'),
            'is_active'           => $doctrine ? $request->boolean('is_active') : true,
            'created_by'          => $doctrine->created_by ?? auth()->id(),
        ];

        try {
            if ($doctrine) {
                $doctrine->update($payload);
            } else {
                StructureDoctrine::create($payload);
            }
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] structure doctrine save failed: ' . $e->getMessage());
            return back()->withInput()->with('error', trans('structure-manager::doctrines.err_save'));
        }

        // Tell consumers (HR Manager) the compliance picture changed.
        ManagerCoreIntegration::publishDoctrineChanged([
            'corporation_id'    => $corporationId,
            'scope_type'        => $scopeMode,
            'scope_id'          => (int) $scopeId,
            'structure_type_id' => (int) $parsed['hull_type_id'],
            'security_band'     => $data['security_band'],
            'action'            => $doctrine ? 'updated' : 'created',
        ]);

        return redirect()->route('structure-manager.doctrines.index', ['corporation_id' => $corporationId])
            ->with('success', trans('structure-manager::doctrines.saved'));
    }

    public function destroy(Request $request, $id)
    {
        $doctrine      = StructureDoctrine::findOrFail((int) $id);
        $corporationId = (int) $request->input('corporation_id');

        $snapshot = [
            'corporation_id'    => $corporationId,
            'scope_type'        => $doctrine->scope_type,
            'scope_id'          => (int) $doctrine->scope_id,
            'structure_type_id' => (int) $doctrine->structure_type_id,
            'security_band'     => $doctrine->security_band,
            'action'            => 'deleted',
        ];
        $doctrine->delete();
        ManagerCoreIntegration::publishDoctrineChanged($snapshot);

        return redirect()->route('structure-manager.doctrines.index', ['corporation_id' => $corporationId])
            ->with('success', trans('structure-manager::doctrines.deleted'));
    }

    public function saveSettings(Request $request)
    {
        $request->validate([
            'structure_doctrine_scope'       => 'required|in:corp,alliance',
            'structure_offline_noncompliant' => 'nullable|boolean',
        ]);

        StructureManagerSettings::set(StructureComplianceService::SETTING_SCOPE, $request->input('structure_doctrine_scope'), 'string');
        StructureManagerSettings::set(StructureComplianceService::SETTING_OFFLINE_STRICT, $request->boolean('structure_offline_noncompliant'), 'boolean');

        $corporationId = (int) $request->input('corporation_id');

        // Scope/offline changes shift every structure's verdict — tell consumers.
        ManagerCoreIntegration::publishDoctrineChanged([
            'corporation_id' => $corporationId,
            'action'         => 'settings',
        ]);

        return redirect()->route('structure-manager.doctrines.index', ['corporation_id' => $corporationId])
            ->with('success', trans('structure-manager::doctrines.settings_saved'));
    }

    private function scopeIdFor(int $corporationId, string $mode): ?int
    {
        if ($mode === 'corp') {
            return $corporationId;
        }
        $allianceId = optional(CorporationInfo::find($corporationId))->alliance_id;
        return $allianceId ? (int) $allianceId : null;
    }
}
