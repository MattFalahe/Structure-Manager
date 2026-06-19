<?php

namespace StructureManager\Http\Controllers\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corp access + picker helpers for the Doctrine Compliance pages. Mirrors
 * StructureManagerController::getUserCorporations: admins see every corp,
 * everyone else only the corps their linked characters belong to.
 */
trait ScopesStructureCorps
{
    /**
     * Corp IDs the current user may see. null = full access (admin); otherwise
     * the corps the user's linked characters belong to (may be empty).
     */
    protected function accessibleStructureCorpIds(): ?array
    {
        if (auth()->user() && auth()->user()->can('structure-manager.admin')) {
            return null;
        }

        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', auth()->id())
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()->filter()->values()->map(fn ($c) => (int) $c)->all();
    }

    /**
     * Corporations the user may pick from: every corp on the install for an
     * admin, otherwise only their own. Each row: {corporation_id, name, ticker}.
     */
    protected function corpOptions()
    {
        if (!Schema::hasTable('corporation_infos')) {
            return collect();
        }

        $allowed = $this->accessibleStructureCorpIds();
        $query = DB::table('corporation_infos')->select('corporation_id', 'name', 'ticker');
        if ($allowed !== null) {
            $query->whereIn('corporation_id', $allowed ?: [0]);
        }
        return $query->orderBy('name')->get();
    }

    /**
     * The corp to show: the requested one when allowed, else the first option.
     */
    protected function resolveStructureCorp($request, $options): int
    {
        $ids = $options->pluck('corporation_id')->map(fn ($c) => (int) $c)->all();
        $requested = (int) $request->input('corporation_id', 0);
        if ($requested > 0 && in_array($requested, $ids, true)) {
            return $requested;
        }
        // Default to the viewer's OWN corp (even for admins, whose picker lists
        // every corp) so the page opens on something relevant; the dropdown is
        // still free to switch to another corp.
        $own = $this->defaultOwnCorpId();
        if ($own > 0 && in_array($own, $ids, true)) {
            return $own;
        }
        return (int) ($options->first()->corporation_id ?? 0);
    }

    /**
     * The viewer's own corporation: their main character's corp, falling back to
     * the first corp any of their linked characters belong to. 0 if unknown.
     */
    protected function defaultOwnCorpId(): int
    {
        $user = auth()->user();
        if (!$user) {
            return 0;
        }

        $mainId = (int) ($user->main_character_id ?? 0);
        if ($mainId > 0) {
            $corp = DB::table('character_affiliations')->where('character_id', $mainId)->value('corporation_id');
            if ($corp) {
                return (int) $corp;
            }
        }

        $corp = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->value('character_affiliations.corporation_id');

        return (int) ($corp ?? 0);
    }

    /**
     * Guard: a non-admin may only act on a corp they belong to.
     */
    protected function assertStructureCorpAccess(int $corporationId): void
    {
        $allowed = $this->accessibleStructureCorpIds();
        if ($allowed !== null && !in_array($corporationId, $allowed, true)) {
            abort(403);
        }
    }
}
