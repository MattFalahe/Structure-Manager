<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Services\FuelEconomicsService;

/**
 * Fuel Economics page controller.
 *
 * Phase A: shows weekly / monthly / quarterly / yearly fuel ISK across the
 * user's corporations, with a per-system table. Per-structure breakdown,
 * services-offline detection, trend chart, and type pie are wired in
 * subsequent commits — this controller's `index()` already passes the full
 * payload; the view just renders progressively as each commit lands.
 *
 * Permission: `structure-manager.economics`. Hidden from sidebar when
 * Manager Core's pricing infrastructure isn't installed (sidebar config
 * does the class_exists guard).
 *
 * Corp scoping: matches the rest of SM. Admin = unrestricted, view-only
 * users see only the corps their linked characters belong to.
 */
class EconomicsController extends Controller
{
    /**
     * Render the Economics page.
     */
    public function index(Request $request, FuelEconomicsService $service)
    {
        if (!ManagerCoreIntegration::isPricingAvailable()) {
            return view('structure-manager::economics.mc-required');
        }

        // Operator has explicitly opted out via SM Settings > Economics.
        // Render a notice with a deeplink to the settings tab rather than
        // returning the empty page. Distinct from MC-absent because the
        // operator made an active choice we should reflect.
        if (ManagerCoreIntegration::economicsPricingMode() === ManagerCoreIntegration::ECONOMICS_MODE_DISABLED) {
            return view('structure-manager::economics.disabled');
        }

        $forceRefresh = (bool) $request->input('refresh', false);
        $periodDays   = $this->resolvePeriod($request);
        $corpScope    = $this->resolveCorpScope();

        // Cache key includes the period + the corp scope (so admin's view
        // and a per-corp user's view don't collide). 5-minute TTL matches
        // the Diagnostic page's per-section cache convention.
        $cacheKey = $this->buildCacheKey($periodDays, $corpScope);

        $payload = $this->cached($cacheKey, 300, $forceRefresh, function () use ($service, $periodDays, $corpScope) {
            return $service->buildEconomics($periodDays, $corpScope);
        });

        return view('structure-manager::economics.index', [
            'payload'      => $payload,
            'periodDays'   => $periodDays,
            'periods'      => FuelEconomicsService::PERIODS_DAYS,
            'isAdmin'      => $corpScope === null,
        ]);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    /**
     * Resolve the look-back period from the request, defaulting to 180.
     * Validates against FuelEconomicsService::PERIODS_DAYS so a crafted
     * URL can't pass an arbitrary day count.
     */
    private function resolvePeriod(Request $request): int
    {
        $period = (int) $request->input('period', FuelEconomicsService::DEFAULT_PERIOD_DAYS);
        if (!in_array($period, FuelEconomicsService::PERIODS_DAYS, true)) {
            return FuelEconomicsService::DEFAULT_PERIOD_DAYS;
        }
        return $period;
    }

    /**
     * Resolve which corp_ids the current user can see.
     *
     * Returns null when the user has structure-manager.admin (= full
     * cross-corp access). Otherwise returns the array of corp_ids the
     * user's linked characters belong to (may be empty for a user with
     * no characters; the service treats empty as "no work to do").
     */
    private function resolveCorpScope(): ?array
    {
        $user = auth()->user();
        if ($user && $user->can('structure-manager.admin')) {
            return null;
        }

        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', auth()->id())
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Build a cache key that's stable per (period, corp_scope, user_admin_flag).
     * Admin and a regular user with the same corp_scope still get separate keys
     * because admin's view ignores scope.
     */
    private function buildCacheKey(int $periodDays, ?array $corpScope): string
    {
        if ($corpScope === null) {
            return "sm:economics:admin:{$periodDays}";
        }
        sort($corpScope);
        $hash = substr(md5(implode(',', $corpScope)), 0, 12);
        return "sm:economics:scope:{$periodDays}:{$hash}";
    }

    /**
     * Cache wrapper with forceRefresh and defensive try/catch.
     * Mirrors the cached() helper in DiagnosticController.
     */
    private function cached(string $key, int $ttl, bool $forceRefresh, callable $compute)
    {
        try {
            if ($forceRefresh) {
                Cache::forget($key);
            }
            return Cache::remember($key, $ttl, $compute);
        } catch (\Throwable $e) {
            Log::warning("[SM] Economics cache failed for {$key}: " . $e->getMessage());
            return $compute();
        }
    }
}
