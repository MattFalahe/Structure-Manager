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

    /**
     * GET /command-board/calendar.ics
     *
     * Subscribed-feed ICS calendar. The signed URL embeds a HMAC over
     * (route + query) using APP_KEY; Laravel's `signed` middleware rejects
     * tampered URLs. The user_id query parameter identifies whose visibility
     * filter to apply — same scope rules as the web board (corp memberships
     * + role gates).
     *
     * Output: text/calendar (RFC 5545). One VEVENT per active timer in the
     * window. eve_time is the event's start (UTC). DTEND is +1 hour for
     * visualization (most calendar apps need a non-zero duration).
     *
     * @return \Illuminate\Http\Response
     */
    public function calendar(Request $request)
    {
        $userId = (int) $request->input('user_id');
        if ($userId <= 0) {
            abort(400, 'Missing user_id');
        }

        $user = \Seat\Web\Models\User::find($userId);
        if (!$user) {
            abort(404);
        }

        // Apply the same visibility filters the web board uses. The scope
        // bypasses for admins; for non-admins it intersects corp + role.
        $now = Carbon::now();

        $timers = Timer::query()
            ->visibleTo($user)
            ->whereNull('dismissed_at')
            ->where('eve_time', '>=', $now->copy()->subDays(7))
            ->where('eve_time', '<=', $now->copy()->addDays(60))
            ->orderBy('eve_time', 'asc')
            ->limit(500)
            ->get();

        // Build the ICS document. Newlines must be CRLF per RFC 5545.
        $crlf = "\r\n";
        $now_ical = $now->format('Ymd\THis\Z');
        $host = parse_url(config('app.url') ?? request()->getSchemeAndHttpHost(), PHP_URL_HOST) ?? 'seat.local';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SeAT Structure Manager//Structure Board//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Structure Board',
            'X-WR-CALDESC:Upwell timers, fuel events, manual ops + lifecycle',
            'X-WR-TIMEZONE:UTC',
        ];

        foreach ($timers as $timer) {
            if ($timer->eve_time === null) {
                continue;
            }
            $start = $timer->eve_time->copy()->utc();
            $end   = $start->copy()->addHour();

            $summary = self::icsSummary($timer);
            $description = self::icsDescription($timer);
            $location = $timer->system_name
                ? ($timer->system_name . ($timer->system_security !== null ? sprintf(' (%.2f)', $timer->system_security) : ''))
                : '';

            $url = url('/structure-manager/command-board?timer_id=' . $timer->id);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:sm-timer-' . $timer->id . '@' . $host;
            $lines[] = 'DTSTAMP:' . $now_ical;
            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'   . $end->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . self::icsEscape($summary);
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . self::icsEscape($description);
            }
            if ($location !== '') {
                $lines[] = 'LOCATION:' . self::icsEscape($location);
            }
            $lines[] = 'URL:' . self::icsEscape($url);
            $lines[] = 'CATEGORIES:' . self::icsEscape(strtoupper($timer->category_group ?? 'TIMER'));
            // Severity hint for clients that respect priority (1=high, 9=low)
            $lines[] = 'PRIORITY:' . match ($timer->severity) {
                'critical' => '1',
                'warning'  => '5',
                default    => '9',
            };
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        $ics = implode($crlf, $lines) . $crlf;

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="structure-board.ics"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Build an ICS SUMMARY line from a Timer. Concise, scannable in calendar
     * grid views ("Astrahus: fuel critical (Jita)").
     */
    private static function icsSummary(Timer $timer): string
    {
        $struct = $timer->structure_name ?? $timer->structure_type ?? 'Structure';
        $type   = str_replace('_', ' ', $timer->event_type ?? '');
        $sys    = $timer->system_name ? " ({$timer->system_name})" : '';
        return "{$struct}: {$type}{$sys}";
    }

    /**
     * Build an ICS DESCRIPTION line from a Timer. Multi-line detail for
     * subscribers viewing event detail in their calendar app.
     */
    private static function icsDescription(Timer $timer): string
    {
        $parts = [];
        if ($timer->severity) {
            $parts[] = 'Severity: ' . strtoupper($timer->severity);
        }
        if ($timer->source) {
            $parts[] = 'Source: ' . str_replace('_', ' ', $timer->source);
        }
        if ($timer->owner_corporation_name) {
            $parts[] = 'Owner: ' . $timer->owner_corporation_name;
        }
        if ($timer->attacker_corporation_name) {
            $parts[] = 'Attacker: ' . $timer->attacker_corporation_name;
        }
        if ($timer->notes) {
            $parts[] = '';
            $parts[] = $timer->notes;
        }
        return implode("\n", $parts);
    }

    /**
     * Escape a value for inclusion in an ICS line. Per RFC 5545:
     *   - backslashes escaped to \\
     *   - newlines to \n
     *   - commas to \,
     *   - semicolons to \;
     */
    private static function icsEscape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        return $value;
    }

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
