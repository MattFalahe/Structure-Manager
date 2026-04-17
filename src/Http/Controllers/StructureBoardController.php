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

        // Build base query
        $query = Timer::active()
            ->visibleTo($user)
            ->withinWindow($days)
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

        return view('structure-manager::command-board.index', [
            'grouped'            => $grouped,
            'timers'             => $timers,
            'days'               => $days,
            'userCorps'          => $userCorps,
            'allTrackedCorps'    => $allTrackedCorps,
            'allRoles'           => $allRoles,
            'corpFilter'         => $corpFilter ?? 'all_mine',
            'isBoardAdmin'       => $isBoardAdmin,
            'canCreate'          => $user->can('structure-manager.command-board.create') || $isBoardAdmin,
            'canViewSensitive'   => $user->can('structure-manager.command-board.view-sensitive') || $isBoardAdmin,
            'defaultOpsecRoleId' => $defaultOpsecRoleId,
            'defaultWindowDays'  => $defaultDays,
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
        ]);

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

        // Determine visibility corps from scope
        $corpIds = $this->resolveVisibilityCorps($data['visibility_scope'], $data['specific_corp_id'] ?? null, $user, $isBoardAdmin);

        $baseAttrs = [
            'source'                    => $data['event_type'] === 'hostile_op' ? 'manual_offense' : 'manual_defense',
            'event_type'                => $data['event_type'],
            'severity'                  => $data['severity'] ?? 'warning',
            'structure_name'            => $data['structure_name'] ?? null,
            'structure_type'            => $data['structure_type'] ?? null,
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

        foreach ($corpIds as $corpId) {
            Timer::create(array_merge($baseAttrs, [
                'corporation_id' => $corpId,
                'group_id'       => $groupId,
            ]));
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
     * Resolve which corp_ids a new manual-op timer should be created for,
     * based on the admin's chosen visibility scope.
     *
     * @return array<int> of corp_ids, or [null] for global
     */
    private function resolveVisibilityCorps(string $scope, ?int $specificCorpId, $user, bool $isAdmin): array
    {
        $userCorps = Timer::getUserCorpIds($user);

        return match ($scope) {
            'global'       => [null],
            'all_my_corps' => !empty($userCorps) ? $userCorps : [null],
            'specific'     => $specificCorpId !== null ? [$specificCorpId] : [null],
            'my_corp'      => [$this->mainCorpId($user) ?? ($userCorps[0] ?? null)],
            default        => [$userCorps[0] ?? null],
        };
    }

    /**
     * Resolve the user's "main" corp ID — prefers main_character_id's corp,
     * else the first corp from their refresh tokens.
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
        return null;
    }
}
