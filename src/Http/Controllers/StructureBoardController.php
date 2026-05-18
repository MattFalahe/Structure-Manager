<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\Timer;

/**
 * Structure Board — v2 Phase 1 controller.
 *
 * Renders the timeline view, lists corps the current user can filter by,
 * and handles CRUD for manual-entry timers (hostile / defense ops).
 *
 * Visibility is enforced in Timer::scopeVisibleTo(). Admin bypass is the
 * 'structure-manager.admin' or 'structure-manager.command-board.admin'
 * permission.
 */
class StructureBoardController extends Controller
{
    /**
     * GET /command-board
     * Renders the timeline with active timers within the look-ahead window.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Admin status — drives corp dropdown + visibility bypass
        $isBoardAdmin = $user->can('structure-manager.admin')
            || $user->can('structure-manager.command-board.admin')
            || $user->isAdmin();

        // Look-ahead window (default 7 days, configurable in settings)
        $defaultDays = (int) StructureManagerSettings::get('command_board_default_window_days', 7);
        $days = (int) ($request->input('days', $defaultDays));
        if ($days < 1 || $days > 365) {
            $days = $defaultDays;
        }

        // Build base query — eager-load tags so per-row chip rendering
        // doesn't N+1.
        $query = Timer::active()
            ->visibleTo($user)
            ->withinWindow($days)
            ->with('tags')
            ->orderBy('eve_time', 'asc');

        // Optional corp filter (from URL param — client will re-POST on change)
        $corpFilter = $request->input('corp');
        if ($corpFilter === 'all_mine' || $corpFilter === null) {
            // No extra filter — visibleTo already scopes to user's corps
        } elseif ($corpFilter === 'all_tracked' && $isBoardAdmin) {
            // Admin-only: disable corp filter entirely (already bypassed by visibleTo for admins)
        } elseif (is_numeric($corpFilter)) {
            $query->where('corporation_id', (int) $corpFilter);
        }

        $timers = $query->get();

        // Group by day for timeline rendering
        $grouped = $timers->groupBy(function ($timer) {
            return $timer->eve_time->format('Y-m-d');
        });

        // User's corps (for corp dropdown)
        $userCorpIds = Timer::getUserCorpIds($user);
        $userCorps = DB::table('corporation_infos')
            ->whereIn('corporation_id', $userCorpIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        // Admin-only: list of ALL corps that have at least one timer (for the
        // "Show all tracked corps" selector)
        $allTrackedCorps = collect();
        if ($isBoardAdmin) {
            $allTrackedCorps = DB::table('corporation_infos as ci')
                ->join(
                    DB::raw('(SELECT DISTINCT corporation_id FROM structure_manager_timers WHERE corporation_id IS NOT NULL) t'),
                    'ci.corporation_id', '=', 't.corporation_id'
                )
                ->select('ci.corporation_id', 'ci.name', 'ci.ticker')
                ->orderBy('ci.name')
                ->get();
        }

        // SeAT roles for the role-gate picker (admins only, used on create form)
        $allRoles = collect();
        if ($user->can('structure-manager.command-board.create') || $isBoardAdmin) {
            $allRoles = \Seat\Web\Models\Acl\Role::orderBy('title')->get();
        }

        // Defaults for the create form
        $defaultOpsecRoleId = (int) StructureManagerSettings::get('command_board_default_opsec_role_id', 0) ?: null;

        // Build the Structure Type dropdown options once in PHP land so the
        // blade view only renders. Avoids the @php block inside the blade
        // that caused a "unexpected token class" view-compilation error on
        // some PHP versions (the class-constant access inside @php blocks
        // appears to trip Blade's compiler under certain conditions).
        $structureTypeOptions = $this->buildStructureTypeOptions();

        return view('structure-manager::command-board.index', [
            'grouped'              => $grouped,
            'timers'               => $timers,
            'days'                 => $days,
            'userCorps'            => $userCorps,
            'allTrackedCorps'      => $allTrackedCorps,
            'allRoles'             => $allRoles,
            'corpFilter'           => $corpFilter ?? 'all_mine',
            'isBoardAdmin'         => $isBoardAdmin,
            'canCreate'            => $user->can('structure-manager.command-board.create') || $isBoardAdmin,
            'canViewSensitive'     => $user->can('structure-manager.command-board.view-sensitive') || $isBoardAdmin,
            'defaultOpsecRoleId'   => $defaultOpsecRoleId,
            'defaultWindowDays'    => $defaultDays,
            'structureTypeOptions' => $structureTypeOptions,
        ]);
    }

    /**
     * Build the Structure Type dropdown groups for the Manual Op Timer modal.
     *
     * Returns a nested array keyed by category label, each value being a
     * type_id => display_name map. The blade renders this as an
     * <optgroup>-grouped <select>. Computed once per request in PHP land
     * (not in a blade @php block) because class-constant access inside
     * blade @php caused a view-compilation parse error on some PHP
     * installations.
     *
     * @return array<string, array<int, string>>
     */
    private function buildStructureTypeOptions(): array
    {
        $categoryLabels = [
            'citadel'     => 'Citadels',
            'engineering' => 'Engineering Complexes',
            'refinery'    => 'Refineries',
            'navigation'  => 'Navigation (FLEX)',
            'orbital'     => 'Orbital (Equinox)',
            'deployable'  => 'Deployable',
        ];

        $grouped = [];
        foreach (\StructureManager\Helpers\TypeIdRegistry::UPWELL_TYPE_IDS as $typeId => $meta) {
            $catKey = $meta['category'] ?? 'other';
            $catLabel = $categoryLabels[$catKey] ?? ucfirst($catKey);
            $grouped[$catLabel][$typeId] = $meta['name'];
        }

        // POS towers as a separate optgroup. Three sizes is enough — operators
        // rarely need to specify faction/officer variants for a timer.
        $grouped['POS Towers'] = [
            20059 => 'POS — Medium (T1)',
            12235 => 'POS — Large (T1)',
            20060 => 'POS — Small (T1)',
        ];

        return $grouped;
    }

    /**
     * GET /command-board/grid
     *
     * Monthly calendar grid view — alternative to the timeline. Same data,
     * same visibility scope. Useful for operators planning around a long
     * window (multi-week ops, anchor cycles). Filters by ?month=YYYY-MM
     * (defaults to current month) and ?corp=N (same as the timeline view).
     *
     * Renders a 6-week grid (always 42 cells = 6 rows × 7 days) starting
     * from the Monday on or before the 1st of the requested month, so the
     * grid stays a consistent shape across months. Days outside the
     * requested month are dimmed.
     */
    public function calendarGrid(Request $request)
    {
        $user = auth()->user();

        $isBoardAdmin = $user->can('structure-manager.admin')
            || $user->can('structure-manager.command-board.admin')
            || $user->isAdmin();

        // Resolve target month (?month=YYYY-MM); fall back to current month.
        $monthInput = $request->input('month');
        try {
            $monthStart = $monthInput
                ? Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth()
                : Carbon::now()->startOfMonth();
        } catch (\Throwable $e) {
            $monthStart = Carbon::now()->startOfMonth();
        }
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Grid window: snap to Monday on/before month start, run 42 days
        // forward. Always 6 rows x 7 cols regardless of which day-of-week
        // the 1st falls on, so the grid template is uniform.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd   = $gridStart->copy()->addDays(42);

        $query = Timer::active()
            ->visibleTo($user)
            ->whereBetween('eve_time', [$gridStart, $gridEnd])
            ->with('tags')
            ->orderBy('eve_time', 'asc');

        $corpFilter = $request->input('corp');
        if ($corpFilter === 'all_mine' || $corpFilter === null) {
            // No extra filter — visibleTo already scopes
        } elseif ($corpFilter === 'all_tracked' && $isBoardAdmin) {
            // Admin: see everything
        } elseif (is_numeric($corpFilter)) {
            $query->where('corporation_id', (int) $corpFilter);
        }

        $timers = $query->get();

        // Group timers by day-of-month-key for cell rendering
        $byDay = $timers->groupBy(function ($timer) {
            return $timer->eve_time->format('Y-m-d');
        });

        // Build the 42-day grid as a flat array of [date => Carbon] entries
        $gridDays = [];
        for ($i = 0; $i < 42; $i++) {
            $d = $gridStart->copy()->addDays($i);
            $gridDays[] = $d;
        }

        // Corp dropdown data — same as timeline view
        $userCorpIds = Timer::getUserCorpIds($user);
        $userCorps = DB::table('corporation_infos')
            ->whereIn('corporation_id', $userCorpIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('structure-manager::command-board.grid', [
            'monthStart'    => $monthStart,
            'monthEnd'      => $monthEnd,
            'gridStart'     => $gridStart,
            'gridDays'      => $gridDays,
            'byDay'         => $byDay,
            'timers'        => $timers,
            'userCorps'     => $userCorps,
            'corpFilter'    => $corpFilter ?? 'all_mine',
            'isBoardAdmin'  => $isBoardAdmin,
            'prevMonthIso'  => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonthIso'  => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /**
     * POST /command-board/op
     * Create a manual hostile/defense op timer.
     */
    public function storeManualOp(Request $request)
    {
        $user = auth()->user();

        $isBoardAdmin = $user->can('structure-manager.admin')
            || $user->can('structure-manager.command-board.admin')
            || $user->isAdmin();

        if (!($user->can('structure-manager.command-board.create') || $isBoardAdmin)) {
            abort(403);
        }

        $data = $request->validate([
            'event_type'                => 'required|in:hostile_op,defense_op',
            'structure_name'            => 'nullable|string|max:255',
            // Structure type now comes in as an EVE type_id from the dropdown.
            // We resolve the human-readable name from TypeIdRegistry below.
            'structure_type_id'         => 'nullable|integer',
            'structure_type'            => 'nullable|string|max:128',
            'system_name'               => 'nullable|string|max:128',
            'owner_corporation_name'    => 'nullable|string|max:255',
            'attacker_corporation_name' => 'nullable|string|max:255',
            'eve_time'                  => 'required|date',
            'notes'                     => 'nullable|string|max:2000',
            'visibility_scope'          => 'required|in:my_corp,all_my_corps,specific,global',
            'specific_corp_id'          => 'nullable|integer',
            'role_id'                   => 'nullable|integer',
            'severity'                  => 'nullable|in:info,warning,critical',
            'tags'                      => 'nullable|string|max:1024',  // comma-separated, normalized in syncTags
        ]);

        // Resolve human-readable structure type from the dropdown type_id.
        // The board's icon renderer keys on structure_type_id for the
        // images.evetech.net URL; the display label on the row + in Discord
        // embeds uses structure_type. We populate both from one selection.
        $structureTypeId = !empty($data['structure_type_id']) ? (int) $data['structure_type_id'] : null;
        $structureType = $data['structure_type'] ?? null;
        if ($structureTypeId !== null) {
            $upwellMeta = \StructureManager\Helpers\TypeIdRegistry::UPWELL_TYPE_IDS[$structureTypeId] ?? null;
            if ($upwellMeta) {
                $structureType = $upwellMeta['name'];
            } else {
                $posMeta = \StructureManager\Helpers\TypeIdRegistry::posTower($structureTypeId);
                if ($posMeta) {
                    $structureType = $posMeta['name'];
                }
            }
        }

        // Resolve system_id if the admin typed a system name we can look up
        $systemId = null;
        $systemSecurity = null;
        if (!empty($data['system_name'])) {
            $system = DB::table('mapDenormalize')
                ->where('itemName', $data['system_name'])
                ->select('itemID', 'security')
                ->first();
            if ($system) {
                $systemId = (int) $system->itemID;
                $systemSecurity = (float) $system->security;
            }
        }

        // Determine visibility corps from scope — with privilege enforcement.
        // Non-admins may ONLY scope to their own corps; global broadcast and
        // targeting arbitrary corps are admin-only to prevent a user with
        // command-board.create from creating timers visible to corps they
        // have no character in.
        $corpIds = $this->resolveVisibilityCorps(
            $data['visibility_scope'],
            $data['specific_corp_id'] ?? null,
            $user,
            $isBoardAdmin
        );

        if ($corpIds === null) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'You are not permitted to scope a timer to that corporation or to all corps. Pick one of your own corps or ask an admin.');
        }

        $baseAttrs = [
            'source'                    => $data['event_type'] === 'hostile_op' ? 'manual_offense' : 'manual_defense',
            'event_type'                => $data['event_type'],
            'severity'                  => $data['severity'] ?? 'warning',
            'structure_name'            => $data['structure_name'] ?? null,
            'structure_type'            => $structureType,
            'structure_type_id'         => $structureTypeId,
            'system_id'                 => $systemId,
            'system_name'               => $data['system_name'] ?? null,
            'system_security'           => $systemSecurity,
            'owner_corporation_name'    => $data['owner_corporation_name'] ?? null,
            'attacker_corporation_name' => $data['attacker_corporation_name'] ?? null,
            'eve_time'                  => Carbon::parse($data['eve_time']),
            'notes'                     => $data['notes'] ?? null,
            'role_id'                   => !empty($data['role_id']) ? (int) $data['role_id'] : null,
            'created_by_user_id'        => $user->id,
        ];

        // If the visibility scope fans out to multiple corps, create one row
        // per corp and link them via a shared group_id so edits can propagate.
        $groupId = count($corpIds) > 1 ? (string) Str::uuid() : null;

        // Parse comma-separated tag input. Normalization (lowercase, dedupe,
        // length cap) happens inside Timer::syncTags.
        $tagList = !empty($data['tags'])
            ? array_filter(array_map('trim', explode(',', $data['tags'])))
            : [];

        foreach ($corpIds as $corpId) {
            $timer = Timer::create(array_merge($baseAttrs, [
                'corporation_id' => $corpId,
                'group_id'       => $groupId,
            ]));
            if (!empty($tagList)) {
                $timer->syncTags($tagList);
            }
        }

        return redirect()
            ->route('structure-manager.command-board.index')
            ->with('success', count($corpIds) > 1
                ? 'Manual op timer created across ' . count($corpIds) . ' corporations.'
                : 'Manual op timer created.');
    }

    /**
     * POST /command-board/{id}/dismiss
     */
    public function dismiss(Request $request, int $id)
    {
        $timer = Timer::findOrFail($id);
        $user = auth()->user();

        // Only admins, creators, or users with board create can dismiss
        $canDismiss = $user->can('structure-manager.command-board.admin')
            || $user->can('structure-manager.admin')
            || $user->isAdmin()
            || ($timer->created_by_user_id === $user->id);

        if (!$canDismiss) {
            abort(403);
        }

        $timer->dismiss($user->id);

        return back()->with('success', 'Timer dismissed.');
    }

    /**
     * POST /command-board/{id}/undismiss
     */
    public function undismiss(Request $request, int $id)
    {
        $timer = Timer::findOrFail($id);
        $user = auth()->user();

        $canDismiss = $user->can('structure-manager.command-board.admin')
            || $user->can('structure-manager.admin')
            || $user->isAdmin()
            || ($timer->created_by_user_id === $user->id);

        if (!$canDismiss) {
            abort(403);
        }

        $timer->undismiss();

        return back()->with('success', 'Timer restored.');
    }

    /**
     * DELETE /command-board/{id}
     * Permanently delete a timer. Only admins or the creator.
     * Auto-generated timers can only be deleted by admins.
     */
    public function destroy(Request $request, int $id)
    {
        $timer = Timer::findOrFail($id);
        $user = auth()->user();

        $isAdmin = $user->can('structure-manager.command-board.admin')
            || $user->can('structure-manager.admin')
            || $user->isAdmin();

        $isAuto = str_starts_with($timer->source, 'auto_');
        $isOwnManual = !$isAuto && $timer->created_by_user_id === $user->id;

        if (!$isAdmin && !$isOwnManual) {
            abort(403);
        }

        // If it has a group_id, delete the whole group (for "All my corps" manual ops)
        if ($timer->group_id) {
            Timer::where('group_id', $timer->group_id)->delete();
        } else {
            $timer->delete();
        }

        return back()->with('success', 'Timer deleted.');
    }

    /**
     * POST /command-board/bulk-dismiss
     *
     * Take an array of timer IDs and dismiss the ones the current user is
     * allowed to dismiss. Silently skips ones they aren't (no error — bulk
     * actions tolerate partial-permission scenarios so a small mistake
     * doesn't lose all the legitimate dismissals).
     *
     * Permission model: same as single-dismiss (admin OR creator OR
     * board.admin OR superuser). Auto-generated timers can be bulk-dismissed
     * by anyone with view permission.
     */
    public function bulkDismiss(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return back()->with('error', 'No timers selected.');
        }
        // Cap to prevent DoS via huge bulk requests
        $ids = array_slice(array_map('intval', $ids), 0, 500);

        $user = auth()->user();
        $isAdmin = $user->can('structure-manager.command-board.admin')
            || $user->can('structure-manager.admin')
            || $user->isAdmin();

        $timers = Timer::whereIn('id', $ids)->whereNull('dismissed_at')->get();
        $dismissed = 0;
        $skipped = 0;

        foreach ($timers as $timer) {
            $canDismiss = $isAdmin || ($timer->created_by_user_id === $user->id);
            if (!$canDismiss) {
                $skipped++;
                continue;
            }
            $timer->dismiss($user->id);
            $dismissed++;
        }

        $msg = "Dismissed {$dismissed} timer(s).";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} you don't own (admin can dismiss those).";
        }
        return back()->with('success', $msg);
    }

    /**
     * DELETE /command-board/bulk-destroy
     *
     * Permanently delete a batch of timers. Admin-only for auto-generated
     * timers; creators may delete their own manual ops. Skips ones the user
     * isn't allowed to delete.
     *
     * Group expansion: if a manual op was created with "All my corps" it has
     * a group_id with one row per corp. Deleting one of those by ID would
     * leave siblings stranded. To keep behavior consistent with single
     * destroy(), we expand each group_id-bearing row into the whole group.
     */
    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return back()->with('error', 'No timers selected.');
        }
        $ids = array_slice(array_map('intval', $ids), 0, 500);

        $user = auth()->user();
        $isAdmin = $user->can('structure-manager.command-board.admin')
            || $user->can('structure-manager.admin')
            || $user->isAdmin();

        $timers = Timer::whereIn('id', $ids)->get();
        $deletable = collect();
        $skipped = 0;

        foreach ($timers as $timer) {
            $isAuto = str_starts_with((string) $timer->source, 'auto_');
            $isOwnManual = !$isAuto && $timer->created_by_user_id === $user->id;
            if (!$isAdmin && !$isOwnManual) {
                $skipped++;
                continue;
            }
            $deletable->push($timer);
        }

        // Expand group_id-bearing rows into the whole group (matches single-destroy semantics)
        $directIds = $deletable->whereNull('group_id')->pluck('id')->all();
        $groupIds  = $deletable->whereNotNull('group_id')->pluck('group_id')->unique()->all();

        $deleted = 0;
        if (!empty($directIds)) {
            $deleted += Timer::whereIn('id', $directIds)->delete();
        }
        if (!empty($groupIds)) {
            $deleted += Timer::whereIn('group_id', $groupIds)->delete();
        }

        $msg = "Deleted {$deleted} timer(s).";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} you can't delete (admin / creator only).";
        }
        return back()->with('success', $msg);
    }

    // NOTE: an external ICS / iCalendar feed was implemented here in
    // commit d8b816f (April 2026) and explicitly removed before the
    // v2.0.0 public release after an opsec review. The calendar()
    // method generated an RFC 5545 feed of upcoming timers and the
    // private static icsSummary / icsDescription / icsEscape helpers
    // formatted timer data for that feed.
    //
    // Why removed: timer data is tactical intelligence in EVE. Once
    // exported to a third-party calendar service (Google Calendar,
    // Apple Calendar, Outlook), the data is no longer in the trust
    // zone — calendar providers have audit access, subscription URLs
    // leak, multi-device sync expands exposure surface, automated
    // scrapers could aggregate across operators.
    //
    // What replaces it for fleet-planning use cases: cross-plugin
    // EventBus integration. Structure Manager publishes
    // structure.alert.* and structure_manager.timer.* events on
    // Manager Core's EventBus. The future SeAT Broadcast calendar
    // build will subscribe to those events and render them in an
    // in-app calendar (behind SeAT auth) with pre-timer reminder
    // pings via operator-controlled Discord webhooks. All in trust
    // zone; no external data publish.
    //
    // Do NOT re-add an ICS / calendar / external-feed export
    // without revisiting Help & Documentation > Notifications >
    // "Operational Security: Tactical Data Boundaries" first.

    /**
     * Resolve which corp_ids a new manual-op timer should be created for,
     * based on the chosen visibility scope.
     *
     * Privilege rules:
     *   - 'global'       — admin only (creates a null-corp broadcast)
     *   - 'specific'     — admin may target any corp; non-admin must target
     *                      a corp they have a character in
     *   - 'all_my_corps' — any user; fans out to their own corp memberships
     *   - 'my_corp'      — any user; targets their main corp
     *
     * @return array<int|null>|null array of corp_ids (null = global) if the
     *                              scope is permitted; returns null if the
     *                              caller is not authorized for the chosen
     *                              scope (caller should redirect with error)
     */
    private function resolveVisibilityCorps(string $scope, ?int $specificCorpId, $user, bool $isAdmin): ?array
    {
        $userCorps = Timer::getUserCorpIds($user);

        switch ($scope) {
            case 'global':
                // Only admins can broadcast to all corps on the SeAT install
                return $isAdmin ? [null] : null;

            case 'specific':
                if ($specificCorpId === null) {
                    return null;
                }
                // Admins can target any corp
                if ($isAdmin) {
                    return [$specificCorpId];
                }
                // Non-admins must target a corp they belong to
                return in_array($specificCorpId, $userCorps, true)
                    ? [$specificCorpId]
                    : null;

            case 'all_my_corps':
                return !empty($userCorps) ? $userCorps : [null];

            case 'my_corp':
                $main = $this->mainCorpId($user) ?? ($userCorps[0] ?? null);
                // If user has no corp affiliation at all, refuse rather than
                // silently defaulting to null (= global)
                return $main !== null ? [$main] : null;

            default:
                return null;
        }
    }

    /**
     * Resolve the user's "main" corp ID — prefers main_character_id's corp,
     * else the first corp from their refresh tokens. Returns null if the
     * user has no resolvable corp affiliation at all (e.g. only deleted
     * characters, or main_character_id points at a row that no longer
     * exists in character_affiliations).
     *
     * Callers must handle null — resolveVisibilityCorps rejects 'my_corp'
     * scope when this returns null rather than silently creating a global
     * (null-corp) timer.
     */
    private function mainCorpId($user): ?int
    {
        if (!empty($user->main_character_id)) {
            $corpId = DB::table('character_affiliations')
                ->where('character_id', $user->main_character_id)
                ->value('corporation_id');
            if ($corpId) {
                return (int) $corpId;
            }
        }
        // Fallback: first corp from user's refresh tokens
        $first = Timer::getUserCorpIds($user);
        return !empty($first) ? (int) $first[0] : null;
    }
}
