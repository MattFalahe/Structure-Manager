@extends('web::layouts.grids.12')

@section('title', 'Structure Board')
@section('page_header', 'Structure Board')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* === Structure Board Timeline — page-specific chrome ===
       Generic card/button/alert styling now comes from canonical
       structure-manager.css. Everything below is functional Structure
       Board layout (timer cards, severity stripes, filter chips, day
       headers, bulk-action bar, badges) that the canonical sheet
       intentionally does NOT cover. */

    .cb-wrapper { color: #c2c7d0; }

    /* Filter strip */
    .cb-filters {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 18px;
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: center;
    }
    .cb-filter-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .cb-filter-label {
        font-size: 0.74rem;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        color: #8b95a5;
        margin-right: 4px;
    }
    .cb-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #1e222b;
        border: 1px solid #454d55;
        color: #c2c7d0;
        padding: 5px 12px;
        border-radius: 14px;
        cursor: pointer;
        font-size: 0.82rem;
        user-select: none;
        transition: all 0.15s;
    }
    .cb-chip:hover { border-color: #17a2b8; }
    .cb-chip.active {
        background: rgba(23, 162, 184, 0.15);
        border-color: #17a2b8;
        color: #fff;
    }
    .cb-chip .cb-chip-check {
        width: 14px;
        height: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.68rem;
    }

    /* Filter / modal selects — kept bespoke because the canonical
       form-control-sm-styled class is opt-in and we want every
       <select.cb-select> on the board to render dark. */
    .cb-select, .cb-window-select {
        background: #1e222b;
        border: 1px solid #454d55;
        color: #fff;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    /* Timeline */
    .cb-day-header {
        margin: 22px 0 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid #454d55;
        color: #17a2b8;
        font-size: 0.95rem;
        font-weight: 600;
    }
    .cb-day-header .cb-day-count {
        color: #8b95a5;
        font-size: 0.78rem;
        font-weight: normal;
        margin-left: 8px;
    }

    /* Timer cards — custom layout primitive (NOT card-dark — needs
       severity left-stripe + flex layout with image + actions). */
    .cb-timer {
        background: #2a2f3a;
        border: 1px solid #3a4049;
        border-left: 4px solid #6c757d;
        border-radius: 6px;
        padding: 12px 14px;
        margin-bottom: 10px;
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }
    /* SEMANTIC severity colors — DO NOT CHANGE */
    .cb-timer[data-severity="info"]     { border-left-color: #17a2b8; }
    .cb-timer[data-severity="warning"]  { border-left-color: #ffc107; }
    .cb-timer[data-severity="critical"] { border-left-color: #dc3545; }
    .cb-timer.elapsed { opacity: 0.6; }
    .cb-timer.dismissed { opacity: 0.4; }

    .cb-timer-img {
        width: 56px; height: 56px;
        border-radius: 4px;
        flex-shrink: 0;
        background: #1e222b;
    }

    .cb-timer-body { flex-grow: 1; min-width: 0; }

    .cb-timer-title {
        color: #fff;
        font-weight: 600;
        font-size: 0.98rem;
        margin-bottom: 3px;
    }
    .cb-timer-title .cb-gate-lock {
        color: #ffc107;
        margin-right: 5px;
        font-size: 0.82rem;
    }
    .cb-timer-meta {
        font-size: 0.82rem;
        color: #8b95a5;
        display: flex;
        flex-wrap: wrap;
        gap: 10px 16px;
    }
    .cb-timer-meta strong { color: #c2c7d0; }
    .cb-timer-notes {
        margin-top: 6px;
        font-size: 0.82rem;
        color: #9da5b4;
        background: #1e222b;
        padding: 6px 10px;
        border-radius: 4px;
        border-left: 2px solid #454d55;
    }

    .cb-timer-when {
        text-align: right;
        flex-shrink: 0;
        min-width: 160px;
    }
    .cb-timer-when .cb-abs {
        font-size: 0.78rem;
        color: #8b95a5;
    }
    /* Local-time line. Subtler than EVE because EVE is the canonical reference;
       Local is a convenience for the operator's clock. Filled by JS on first
       tick; stays blank with display:none for no-JS clients (so they don't
       see a stray empty line). */
    .cb-timer-when .cb-local {
        font-size: 0.74rem;
        color: #6c7587;
        margin-top: 1px;
        display: none;
    }
    .cb-timer-when .cb-local.rendered { display: block; }
    .cb-timer-when .cb-rel {
        font-size: 0.95rem;
        color: #fff;
        font-weight: 600;
        margin-top: 3px;
        /* Monospace numerals so seconds ticking by don't cause horizontal jitter */
        font-variant-numeric: tabular-nums;
    }
    .cb-timer-when .cb-rel.cb-elapsed {
        color: #8b95a5;
        font-weight: 500;
        font-style: italic;
    }

    .cb-timer-actions {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    /* SEMANTIC badge severity colors — DO NOT CHANGE */
    .cb-badge {
        display: inline-block;
        font-size: 0.66rem;
        padding: 2px 7px;
        border-radius: 10px;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        font-weight: 600;
    }
    .cb-badge.info     { background: rgba(23, 162, 184, 0.18); color: #17a2b8; }
    .cb-badge.warning  { background: rgba(255, 193, 7, 0.18);  color: #ffc107; }
    .cb-badge.critical { background: rgba(220, 53, 69, 0.18);  color: #dc3545; }
    .cb-badge.source   { background: #454d55; color: #c2c7d0; }
    .cb-badge.global   { background: rgba(23, 162, 184, 0.12); color: #17a2b8; border:1px solid rgba(23, 162, 184, 0.4); }
    /* FINAL TIMER badge — high-contrast red with a glow border so it stands
       out as the most-urgent marker on a packed board. Indicates the structure
       has NO hull reinforce break: armor cycle is the final defense window. */
    .cb-badge.final-timer {
        background: #dc2626;
        color: #fff;
        border: 1px solid #fca5a5;
        font-weight: 700;
        letter-spacing: 0.5px;
        box-shadow: 0 0 6px rgba(220, 38, 38, 0.5);
    }

    .cb-empty {
        text-align: center;
        padding: 50px 20px;
        color: #8b95a5;
        font-style: italic;
    }

    .cb-header-actions {
        margin-bottom: 15px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    /* Legend panel — collapsible reference for badges / severities / sources.
       Default state: closed. Toggle via the "Legend" button in cb-header-actions
       (state persists per-browser in localStorage). */
    .cb-legend {
        background: #2d333b;
        border: 1px solid #3a4250;
        border-radius: 6px;
        padding: 14px 16px;
        margin-bottom: 14px;
        display: none;
    }
    .cb-legend.open { display: block; }
    .cb-legend-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #c2c7d0;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .cb-legend-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 12px;
    }
    @media (max-width: 1100px) {
        .cb-legend-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 600px) {
        .cb-legend-grid { grid-template-columns: 1fr; }
    }
    .cb-legend-col h6 {
        font-size: 0.78rem;
        color: #9aa3b3;
        margin: 0 0 6px 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        font-weight: 600;
    }
    .cb-legend-col ul {
        list-style: none;
        padding: 0;
        margin: 0;
        font-size: 0.78rem;
    }
    .cb-legend-col li {
        margin-bottom: 4px;
        line-height: 1.4;
        color: #c2c7d0;
    }
    .cb-legend-col li strong {
        color: #e8ebef;
        font-weight: 600;
    }
    .cb-legend-shared {
        border-top: 1px solid #3a4250;
        padding-top: 10px;
        font-size: 0.78rem;
        color: #c2c7d0;
    }
    .cb-legend-shared > div {
        margin-bottom: 5px;
    }
    .cb-legend-shared strong {
        color: #e8ebef;
    }
    .cb-legend-close {
        background: none;
        border: none;
        color: #9aa3b3;
        cursor: pointer;
        padding: 0 4px;
        font-size: 1rem;
    }
    .cb-legend-close:hover { color: #fff; }

    /* Tiny inline-badge mock used inside the legend so operators can
       visually match what they see on a row. Mirrors .cb-badge but
       smaller and never uppercases the demo label. */
    .cb-legend-badge {
        display: inline-block;
        font-size: 0.65rem;
        padding: 1px 6px;
        border-radius: 8px;
        font-weight: 600;
        margin-right: 4px;
    }
    .cb-legend-badge.critical { background: rgba(220, 53, 69, 0.18); color: #dc3545; }
    .cb-legend-badge.warning  { background: rgba(255, 193, 7, 0.18);  color: #ffc107; }
    .cb-legend-badge.info     { background: rgba(23, 162, 184, 0.18); color: #17a2b8; }
    .cb-legend-badge.source   { background: #454d55; color: #c2c7d0; }
    .cb-legend-badge.global   { background: rgba(23, 162, 184, 0.12); color: #17a2b8; border:1px solid rgba(23, 162, 184, 0.4); }
</style>
@endpush

@section('full')

<div class="structure-manager-wrapper">
<div class="cb-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="cb-header-actions">
        {{-- 2026-05-13: the ICS / external-calendar-subscription button + modal
             that previously sat here was removed before the v2.0.0 release
             after an opsec review. Timer data is tactical intelligence in
             EVE; publishing it to Google Calendar / Apple Calendar / Outlook
             leaves the trust zone. The in-app Grid view (button below) stays
             because it's an authenticated SeAT page, not an external feed.
             For cross-plugin fleet-planning use cases, SeAT Broadcast will
             consume Structure Manager's structure.alert.* events on Manager
             Core's EventBus and render them in an in-SeAT calendar with
             operator-controlled Discord webhook reminders. See
             Help & Documentation > Notifications > "Operational Security". --}}
        <button type="button" id="cbLegendToggle" class="btn btn-outline-secondary btn-sm" title="Show / hide the badge + severity legend">
            <i class="fas fa-question-circle"></i> Legend
        </button>

        <a href="{{ route('structure-manager.command-board.grid') }}" class="btn btn-outline-secondary btn-sm" title="Switch to monthly calendar grid view">
            <i class="far fa-calendar"></i> Grid view
        </a>

        @if($canCreate)
            <button type="button" class="btn btn-sm-primary btn-sm" data-toggle="modal" data-target="#cbManualOpModal">
                <i class="fas fa-plus"></i> Add Manual Op Timer
            </button>
        @endif
    </div>

    {{-- Legend panel — collapsible reference for what each badge / source /
         severity means. Hidden by default. State persists per-browser via
         localStorage so operators who keep it open don't have to reopen on
         every visit. Toggle button lives in cb-header-actions above. --}}
    <div id="cbLegend" class="cb-legend">
        <div class="cb-legend-title">
            <span><i class="fas fa-info-circle"></i> Structure Board Legend</span>
            <button type="button" class="cb-legend-close" id="cbLegendClose" title="Hide legend">&times;</button>
        </div>

        <div class="cb-legend-grid">
            <div class="cb-legend-col">
                <h6><i class="fas fa-gas-pump"></i> Fuel</h6>
                <ul>
                    <li><span class="cb-legend-badge warning">Fuel Warning</span> Below early-warning threshold</li>
                    <li><span class="cb-legend-badge critical">Fuel Critical</span> Below critical threshold, restock now</li>
                    <li><span class="cb-legend-badge critical">Fuel Final</span> Runs out at this time (24h or less)</li>
                </ul>
            </div>

            <div class="cb-legend-col">
                <h6><i class="fas fa-crosshairs"></i> Tactical</h6>
                <ul>
                    <li><span class="cb-legend-badge critical">Shield Reinforced</span> Attack underway, shields hit</li>
                    <li><span class="cb-legend-badge critical">Armor Reinforced</span> Shield timer ended, armor next</li>
                    <li><span class="cb-legend-badge critical">Hull Reinforced</span> Armor timer ended, hull window</li>
                    <li><span class="cb-legend-badge critical">Destroyed</span> Structure was killed</li>
                    <li><span class="cb-legend-badge info">Hostile Op</span> Manual: we&rsquo;re attacking</li>
                    <li><span class="cb-legend-badge info">Defense Op</span> Manual: we&rsquo;re defending</li>
                </ul>
            </div>

            <div class="cb-legend-col">
                <h6><i class="fas fa-cog"></i> Lifecycle</h6>
                <ul>
                    <li><span class="cb-legend-badge warning">Anchoring Started</span> Upwell anchoring detected (24h)</li>
                    <li><span class="cb-legend-badge info">Anchoring Complete</span> Finished anchoring</li>
                    <li><span class="cb-legend-badge warning">Unanchoring Started</span> Operator-initiated removal</li>
                    <li><span class="cb-legend-badge info">Unanchoring Complete</span> Structure removed</li>
                    <li><span class="cb-legend-badge info">Ownership Transferred</span> Changed hands (shows recipient)</li>
                </ul>
            </div>

            <div class="cb-legend-col">
                <h6><i class="fas fa-flag"></i> Sov</h6>
                <ul>
                    <li><span class="cb-legend-badge warning">Sov Reinforced</span> Hub damaged, decloak countdown</li>
                    <li><span class="cb-legend-badge critical">Command Nodes Spawned</span> Campaign event begins</li>
                    <li><span class="cb-legend-badge critical">Entosis Active</span> Capture window open right now</li>
                </ul>
            </div>
        </div>

        <div class="cb-legend-shared">
            <div>
                <strong>Severity colors:</strong>
                <span class="cb-legend-badge critical">critical</span> live combat / urgent &middot;
                <span class="cb-legend-badge warning">warning</span> future deadline, plan ahead &middot;
                <span class="cb-legend-badge info">info</span> informational
            </div>
            <div>
                <strong>Source badges (where the row came from):</strong>
                <span class="cb-legend-badge source">Auto Fuel</span>
                <span class="cb-legend-badge source">Auto Reinforce</span>
                <span class="cb-legend-badge source">Auto Anchor</span>
                <span class="cb-legend-badge source">Auto Unanchor</span>
                <span class="cb-legend-badge source">Auto Sov</span>
                &mdash; system-generated from ESI notifications or fuel-tracking jobs.
                <span class="cb-legend-badge source">Manual Op</span>
                &mdash; created via the &ldquo;Add Manual Op Timer&rdquo; button.
            </div>
            <div>
                <strong>Visibility indicators:</strong>
                <span class="cb-legend-badge global">&#127760; Global</span> visible to all corps (vs corp-scoped by default) &middot;
                <i class="fas fa-lock" style="color:#ffc107;"></i> role-gated (visible only to a specific SeAT role)
            </div>
        </div>
    </div>

    {{-- Filter strip --}}
    <div class="cb-filters">
        <div class="cb-filter-group">
            <span class="cb-filter-label">Show</span>
            <span class="cb-chip js-group-chip" data-group="all">
                <span class="cb-chip-check"><i class="fas fa-check"></i></span> All
            </span>
            <span class="cb-chip js-group-chip" data-group="fuel">
                <span class="cb-chip-check"><i class="fas fa-gas-pump"></i></span> Fuel
            </span>
            <span class="cb-chip js-group-chip" data-group="tactical">
                <span class="cb-chip-check"><i class="fas fa-crosshairs"></i></span> Tactical
            </span>
            <span class="cb-chip js-group-chip" data-group="lifecycle">
                <span class="cb-chip-check"><i class="fas fa-cog"></i></span> Lifecycle
            </span>
            <span class="cb-chip js-group-chip" data-group="sov">
                <span class="cb-chip-check"><i class="fas fa-flag"></i></span> Sov
            </span>
        </div>

        <div class="cb-filter-group">
            <span class="cb-filter-label">Time</span>
            <select id="cbWindowSelect" class="cb-window-select">
                @foreach([1 => 'Next 24h', 3 => 'Next 3d', 7 => 'Next 7d', 14 => 'Next 14d', 30 => 'Next 30d'] as $d => $label)
                    <option value="{{ $d }}" @if($days == $d) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if($userCorps->count() > 1 || $isBoardAdmin)
            <div class="cb-filter-group">
                <span class="cb-filter-label">Corp</span>
                <select id="cbCorpSelect" class="cb-select">
                    <option value="all_mine" @if($corpFilter == 'all_mine') selected @endif>All my corps</option>
                    @foreach($userCorps as $corp)
                        <option value="{{ $corp->corporation_id }}" @if((string)$corpFilter === (string)$corp->corporation_id) selected @endif>
                            {{ $corp->name }}@if($corp->ticker) [{{ $corp->ticker }}]@endif
                        </option>
                    @endforeach
                    @if($isBoardAdmin)
                        <option value="all_tracked" @if($corpFilter == 'all_tracked') selected @endif>— All tracked corps (admin) —</option>
                    @endif
                </select>
            </div>
        @endif

        <div class="cb-filter-group" style="margin-left:auto;">
            <span class="cb-filter-label">{{ $timers->count() }} visible</span>
        </div>
    </div>

    {{-- Bulk-action bar — appears when at least one timer is checked --}}
    <div id="cbBulkBar" class="cb-bulk-bar" style="display:none; padding:8px 12px; background:#3a4250; border-radius:4px; margin-bottom:12px; align-items:center; gap:12px;">
        <strong style="color:#fff;"><span id="cbBulkCount">0</span> selected</strong>

        <form id="cbBulkDismissForm" method="POST" action="{{ route('structure-manager.command-board.bulk-dismiss') }}" style="margin:0; display:inline;">
            @csrf
            <input type="hidden" name="ids" id="cbBulkDismissIds" value="">
            <button type="submit" class="btn btn-sm btn-secondary">
                <i class="fas fa-eye-slash"></i> Dismiss selected
            </button>
        </form>

        @if($isBoardAdmin)
            <form id="cbBulkDestroyForm" method="POST" action="{{ route('structure-manager.command-board.bulk-destroy') }}" style="margin:0; display:inline;" onsubmit="return confirm('Permanently delete the selected timers? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <input type="hidden" name="ids" id="cbBulkDestroyIds" value="">
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="fas fa-trash"></i> Delete selected
                </button>
            </form>
        @endif

        <button type="button" id="cbBulkClear" class="btn btn-sm btn-link" style="color:#aaa;">
            Clear selection
        </button>

        <span style="margin-left:auto; color:#aaa; font-size:12px;">
            <i class="fas fa-info-circle"></i>
            Bulk actions skip rows you can't dismiss/delete (no error — admins can clean up the rest).
        </span>
    </div>

    {{-- Timeline --}}
    <div id="cbTimeline">
        @if($timers->isEmpty())
            <div class="cb-empty">
                <i class="fas fa-chess fa-2x" style="margin-bottom:12px; opacity:0.5;"></i><br>
                No timers in the next {{ $days }} days.
                @if($canCreate)
                    <br><small>Create a manual op with the button above, or wait for the fuel tracker and ESI notifications to populate events.</small>
                @endif
            </div>
        @else
            @foreach($grouped as $day => $dayTimers)
                @php($dayCarbon = \Carbon\Carbon::parse($day))
                <div class="cb-day-header" data-day="{{ $day }}">
                    <i class="far fa-calendar-alt"></i>
                    {{ $dayCarbon->format('l, F j Y') }}
                    <span class="cb-day-count">{{ $dayTimers->count() }} event(s)</span>
                </div>

                @foreach($dayTimers as $timer)
                    <div class="cb-timer js-timer-row"
                         data-timer-id="{{ $timer->id }}"
                         data-group="{{ $timer->category_group }}"
                         data-severity="{{ $timer->severity }}"
                         @if($timer->is_elapsed) class="cb-timer elapsed" @endif>

                        <label class="cb-timer-select" title="Select for bulk action" style="display:flex; align-items:center; padding:0 8px 0 0; cursor:pointer; user-select:none;">
                            <input type="checkbox" class="js-timer-checkbox" value="{{ $timer->id }}" style="cursor:pointer;">
                        </label>

                        <img src="{{ $timer->structure_image }}" class="cb-timer-img" alt="{{ $timer->structure_type }}">

                        <div class="cb-timer-body">
                            <div class="cb-timer-title">
                                @if($timer->is_role_gated)
                                    <i class="fas fa-lock cb-gate-lock" title="Restricted: {{ optional($timer->role)->title ?? 'Role-gated' }}"></i>
                                @endif
                                {{ $timer->structure_name ?? ($timer->structure_type ?? 'Unknown Structure') }}
                                <span class="cb-badge {{ $timer->severity }}">{{ $timer->event_label }}</span>
                                @if($timer->is_final_timer)
                                    {{-- FINAL TIMER badge — appears next to the event label when
                                         this structure type has NO separate hull reinforce timer
                                         (medium Upwell, FLEX, Metenox, Skyhook). Operators reading
                                         a packed board need this signal at a glance: an Athanor
                                         "Armor Reinforced" means "armor IS the final cycle" while
                                         the same label on a Fortizar means there's a hull timer
                                         to follow. See StructureTimerMechanics for the full list. --}}
                                    <span class="cb-badge final-timer"
                                          title="No hull reinforce break — armor cycle is the final defense window. Structure will be destroyed if undefended.">
                                        ⚠ FINAL TIMER
                                    </span>
                                @endif
                                @if($timer->is_global)
                                    <span class="cb-badge global" title="Global — visible to all corps">🌐 Global</span>
                                @endif
                                <span class="cb-badge source">{{ ucwords(str_replace('_', ' ', $timer->source)) }}</span>
                            </div>
                            <div class="cb-timer-meta">
                                @if($timer->system_name)
                                    <span><i class="fas fa-map-marker-alt"></i> <strong>{{ $timer->system_name }}</strong>@if($timer->system_security !== null) ({{ number_format($timer->system_security, 2) }})@endif</span>
                                @endif
                                @if($timer->structure_type)
                                    <span><i class="fas fa-cube"></i> {{ $timer->structure_type }}</span>
                                @endif
                                @if($timer->owner_corporation_name)
                                    <span><i class="fas fa-building"></i> Owner: <strong>{{ $timer->owner_corporation_name }}</strong></span>
                                @endif
                                {{-- Secondary party — meaning depends on event_type. For
                                     reinforce/destroyed/sov this is the attacker; for
                                     ownership_transferred it's the new owner. The model's
                                     secondary_party_label accessor picks the right label
                                     (or returns null for events with no relevant party
                                     like fuel_* / anchor_start). --}}
                                @if($timer->secondary_party_label)
                                    @php($icon = $timer->event_type === 'ownership_transferred' ? 'fa-handshake' : 'fa-skull-crossbones')
                                    <span><i class="fas {{ $icon }}"></i> {{ $timer->secondary_party_label }}: <strong>{{ $timer->attacker_corporation_name }}</strong></span>
                                @endif
                            </div>
                            @if($timer->notes)
                                <div class="cb-timer-notes">{{ $timer->notes }}</div>
                            @endif
                            @if(!$timer->tags->isEmpty())
                                <div class="cb-timer-tags" style="margin-top:4px;">
                                    @foreach($timer->tags_list as $tag)
                                        <span class="cb-tag-chip" style="display:inline-block; padding:1px 8px; margin-right:4px; font-size:11px; background:#454d55; color:#aaa; border-radius:10px; border:1px solid #5a6268;">
                                            <i class="fas fa-tag" style="font-size:9px;"></i> {{ $tag }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="cb-timer-when">
                            {{-- EVE time (UTC, what CCP gave us) --}}
                            <div class="cb-abs">{{ $timer->eve_time->format('Y-m-d H:i') }} EVE</div>
                            {{-- Local time (browser timezone). JS fills the text
                                 on page load using the ISO timestamp emitted
                                 server-side, so this works regardless of where
                                 the user is. Falls back to blank if JS is off. --}}
                            <div class="cb-local" data-local-time="{{ $timer->eve_time->toIso8601String() }}"></div>
                            {{-- Relative countdown. Server emits Carbon's
                                 diffForHumans() as the initial render (so
                                 no-JS clients still see something useful).
                                 JS replaces it with a live d/h/m/s countdown
                                 for timers within 7 days, refreshing each
                                 second. Past timers show 'elapsed X ago'
                                 (static, no ongoing tick). --}}
                            <div class="cb-rel" data-countdown="{{ $timer->eve_time->toIso8601String() }}">{{ $timer->eve_time->diffForHumans() }}</div>
                        </div>

                        <div class="cb-timer-actions">
                            <form method="POST" action="{{ route('structure-manager.command-board.dismiss', $timer->id) }}" style="margin:0;">
                                @csrf
                                <button type="submit" class="btn btn-xs btn-secondary" title="Dismiss (hide from board)">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </form>

                            @if($isBoardAdmin || ($timer->created_by_user_id === auth()->id() && str_starts_with($timer->source, 'manual_')))
                                <form method="POST" action="{{ route('structure-manager.command-board.destroy', $timer->id) }}" style="margin:0;" onsubmit="return confirm('Permanently delete this timer?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endforeach
        @endif
    </div>
</div>
</div>

{{-- Manual Op Modal --}}
@if($canCreate)
<div class="modal fade" id="cbManualOpModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="background:#2a2f3a; border:1px solid #454d55;">
            <form method="POST" action="{{ route('structure-manager.command-board.op.store') }}">
                @csrf
                <div class="modal-header" style="border-bottom-color:#454d55;">
                    <h5 class="modal-title" style="color:#fff;">
                        <i class="fas fa-crosshairs"></i> Add Manual Op Timer
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;"><span>&times;</span></button>
                </div>
                <div class="modal-body" style="color:#c2c7d0;">

                    <div class="form-group">
                        <label>Event Type *</label>
                        <select name="event_type" class="form-control cb-select" required>
                            <option value="hostile_op">Hostile Op (we're attacking)</option>
                            <option value="defense_op">Defense Op (we're defending)</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Target / Structure Name</label>
                            <input type="text" name="structure_name" class="form-control cb-select" maxlength="255" placeholder="e.g. 4-HWWF Fortizar">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Structure Type</label>
                            {{-- Dropdown options built in the controller
                                 (StructureBoardController::buildStructureTypeOptions)
                                 so we capture the EVE type_id, not a free-text
                                 name. Picking from a known list means the board
                                 can render the correct EVE in-game icon via
                                 images.evetech.net instead of falling back to
                                 a generic Astrahus. --}}
                            <select name="structure_type_id" class="form-control cb-select" required>
                                <option value="">— Select structure type —</option>
                                @foreach($structureTypeOptions as $groupLabel => $options)
                                    <optgroup label="{{ $groupLabel }}">
                                        @foreach($options as $typeId => $name)
                                            <option value="{{ $typeId }}">{{ $name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>System Name</label>
                            <input type="text" name="system_name" class="form-control cb-select" maxlength="128" placeholder="e.g. 4-HWWF">
                        </div>
                        <div class="form-group col-md-6">
                            <label>EVE Time *</label>
                            <input type="datetime-local" name="eve_time" class="form-control cb-select" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Owner Corp (target)</label>
                            <input type="text" name="owner_corporation_name" class="form-control cb-select" maxlength="255" placeholder="Enemy corp name">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Attacker Corp</label>
                            <input type="text" name="attacker_corporation_name" class="form-control cb-select" maxlength="255" placeholder="Us / your corp">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Severity</label>
                        <select name="severity" class="form-control cb-select">
                            <option value="info">Info</option>
                            <option value="warning" selected>Warning</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <hr style="border-color:#454d55;">
                    <h6 style="color:#fff;">Who sees this?</h6>

                    <div class="form-group">
                        <div class="form-check">
                            <input type="radio" name="visibility_scope" id="vs_my_corp" value="my_corp" class="form-check-input" checked>
                            <label class="form-check-label" for="vs_my_corp">My corporation (default)</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" name="visibility_scope" id="vs_all_my" value="all_my_corps" class="form-check-input">
                            <label class="form-check-label" for="vs_all_my">All my corporations ({{ $userCorps->count() }} corp(s))</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" name="visibility_scope" id="vs_specific" value="specific" class="form-check-input">
                            <label class="form-check-label" for="vs_specific">Specific corporation</label>
                            <select name="specific_corp_id" class="form-control cb-select" style="margin-top:6px; max-width:400px;">
                                @foreach($userCorps as $corp)
                                    <option value="{{ $corp->corporation_id }}">{{ $corp->name }}@if($corp->ticker) [{{ $corp->ticker }}]@endif</option>
                                @endforeach
                                @if($isBoardAdmin)
                                    @foreach($allTrackedCorps as $corp)
                                        @if(!$userCorps->contains('corporation_id', $corp->corporation_id))
                                            <option value="{{ $corp->corporation_id }}">{{ $corp->name }} [admin]</option>
                                        @endif
                                    @endforeach
                                @endif
                            </select>
                        </div>
                        <div class="form-check">
                            <input type="radio" name="visibility_scope" id="vs_global" value="global" class="form-check-input">
                            <label class="form-check-label" for="vs_global">Global (everyone on this SeAT)</label>
                        </div>
                    </div>

                    @if($allRoles->count() > 0)
                        <div class="form-group">
                            <label>Role gate (optional)</label>
                            <select name="role_id" class="form-control cb-select">
                                <option value="">Public within chosen visibility scope</option>
                                @foreach($allRoles as $role)
                                    <option value="{{ $role->id }}" @if($defaultOpsecRoleId && $defaultOpsecRoleId == $role->id) selected @endif>
                                        Only users with role: {{ $role->title }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control cb-select" rows="3" maxlength="2000" placeholder="Op details, callouts, doctrine, etc."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Tags <small class="text-muted">(optional)</small></label>
                        <input type="text" name="tags" class="form-control cb-select" maxlength="1024"
                               placeholder="e.g. op-stillwater, vs-tribe, doctrine-armor">
                        <small class="form-text text-muted">
                            Comma-separated. Free-form labels for filtering / grouping. Lowercased + deduped on save. Up to 16 per timer, max 64 chars each.
                        </small>
                    </div>

                </div>
                <div class="modal-footer" style="border-top-color:#454d55;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-sm-primary"><i class="fas fa-save"></i> Create Timer</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('javascript')
<script>
(function($) {
    'use strict';

    // ============================================================
    // Legend panel toggle — collapsible "what does this badge mean"
    // reference. Default closed; state persists per-browser so an
    // operator who keeps it open doesn't have to reopen each visit.
    // ============================================================
    const LEGEND_KEY = 'sm_command_board_legend_open';
    const $legend = $('#cbLegend');
    const $legendBtn = $('#cbLegendToggle');

    function setLegendOpen(open) {
        $legend.toggleClass('open', open);
        try { localStorage.setItem(LEGEND_KEY, open ? '1' : '0'); } catch (e) {}
    }
    // Restore prior state (false unless explicitly saved as open)
    try {
        if (localStorage.getItem(LEGEND_KEY) === '1') {
            $legend.addClass('open');
        }
    } catch (e) {}
    $legendBtn.on('click', function () {
        setLegendOpen(!$legend.hasClass('open'));
    });
    $('#cbLegendClose').on('click', function () {
        setLegendOpen(false);
    });

    // Storage key bumped to v2 when 'sov' was added to ALL_GROUPS (2026-05-13).
    // Bumping forces returning users to default-show all groups including
    // the new sov chip; otherwise their cached v1 selection (which only knew
    // fuel/tactical/lifecycle) would leave sov hidden by default and they'd
    // wonder where the new chip's content went.
    const STORAGE_KEY = 'sm_command_board_groups_v2';
    const ALL_GROUPS = ['fuel', 'tactical', 'lifecycle', 'sov'];

    // Load selection (default: all)
    function loadSelection() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                const arr = JSON.parse(raw);
                if (Array.isArray(arr)) return arr;
            }
        } catch (e) {}
        return ALL_GROUPS.slice();
    }

    function saveSelection(arr) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
    }

    let selected = loadSelection();

    function renderChipState() {
        $('.js-group-chip').each(function () {
            const g = $(this).data('group');
            const active = g === 'all' ? (selected.length === ALL_GROUPS.length) : selected.includes(g);
            $(this).toggleClass('active', active);
        });
    }

    function applyFilter() {
        $('.js-timer-row').each(function () {
            const g = $(this).data('group');
            const visible = !g || selected.includes(g);
            $(this).toggle(visible);
        });

        // Hide day headers with no visible timers
        $('.cb-day-header').each(function () {
            const day = $(this).data('day');
            const hasVisible = $('.js-timer-row:visible').filter(function () {
                return $(this).prevAll('.cb-day-header:first').data('day') === day;
            }).length > 0;
            $(this).toggle(hasVisible);
        });
    }

    $(document).on('click', '.js-group-chip', function () {
        const g = $(this).data('group');

        if (g === 'all') {
            selected = (selected.length === ALL_GROUPS.length) ? [] : ALL_GROUPS.slice();
        } else {
            if (selected.includes(g)) {
                selected = selected.filter(x => x !== g);
            } else {
                selected.push(g);
            }
        }

        saveSelection(selected);
        renderChipState();
        applyFilter();
    });

    // Window change → page reload with new ?days param
    $('#cbWindowSelect').on('change', function () {
        const d = $(this).val();
        const url = new URL(window.location.href);
        url.searchParams.set('days', d);
        window.location.href = url.toString();
    });

    // ============================================================
    // Live countdown + local-time render.
    //
    // Server emits each timer's eve_time as an ISO 8601 string in two
    // data attributes:
    //   data-countdown   on .cb-rel   — replaced each tick with the
    //                                   live d/h/m/s remaining
    //   data-local-time  on .cb-local — replaced once on load with the
    //                                   user's browser-local rendering
    //                                   of the same instant
    //
    // The countdown ticks at 1Hz. Under 24h shows full d/h/m/s; 1-7 days
    // shows d/h/m (no seconds — the precision is noise at that scale);
    // over 7 days falls back to a coarse "Xd Yh" (still ticks but won't
    // visibly change for hours). Elapsed events render once as
    // "elapsed N ago" with no ongoing tick.
    // ============================================================
    function pad2(n) { return String(n).padStart(2, '0'); }

    function formatLocalTime(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
            + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ' Local';
    }

    function formatCountdown(target) {
        const now = Date.now();
        const diffMs = target.getTime() - now;

        if (diffMs <= 0) {
            // Past — render once as 'elapsed X ago' (no ongoing tick)
            const ago = Math.floor(-diffMs / 1000);
            if (ago < 60)         return 'elapsed ' + ago + 's ago';
            if (ago < 3600)       return 'elapsed ' + Math.floor(ago / 60) + 'm ago';
            if (ago < 86400)      return 'elapsed ' + Math.floor(ago / 3600) + 'h ago';
            return 'elapsed ' + Math.floor(ago / 86400) + 'd ago';
        }

        const totalSec = Math.floor(diffMs / 1000);
        const days = Math.floor(totalSec / 86400);
        const hours = Math.floor((totalSec % 86400) / 3600);
        const mins = Math.floor((totalSec % 3600) / 60);
        const secs = totalSec % 60;

        if (days >= 7) {
            return days + 'd ' + hours + 'h';
        }
        if (days >= 1) {
            return days + 'd ' + pad2(hours) + 'h ' + pad2(mins) + 'm ' + pad2(secs) + 's';
        }
        // Under 24h — drop the leading "0d"
        return pad2(hours) + 'h ' + pad2(mins) + 'm ' + pad2(secs) + 's';
    }

    function renderLocalTimes() {
        // Local time is static per event (doesn't tick — the moment in user-
        // local time is the same whether viewed now or in an hour). Render
        // once on load.
        document.querySelectorAll('.cb-local[data-local-time]').forEach(el => {
            if (el.dataset.rendered === '1') return;
            const iso = el.getAttribute('data-local-time');
            const d = new Date(iso);
            if (isNaN(d.getTime())) return;
            el.textContent = formatLocalTime(d);
            el.classList.add('rendered');
            el.dataset.rendered = '1';
        });
    }

    function tickCountdowns() {
        document.querySelectorAll('.cb-rel[data-countdown]').forEach(el => {
            const iso = el.getAttribute('data-countdown');
            const d = new Date(iso);
            if (isNaN(d.getTime())) return;
            const elapsed = d.getTime() <= Date.now();
            el.textContent = formatCountdown(d);
            el.classList.toggle('cb-elapsed', elapsed);
        });
    }

    renderLocalTimes();
    tickCountdowns();
    // 1Hz tick. Modern browsers handle this trivially even with 100+ timers
    // on screen — each tick is a few microseconds of date arithmetic + a
    // textContent swap. Cheaper than rendering anything graphical.
    setInterval(tickCountdowns, 1000);

    // Corp change → page reload with new ?corp param
    $('#cbCorpSelect').on('change', function () {
        const c = $(this).val();
        const url = new URL(window.location.href);
        url.searchParams.set('corp', c);
        window.location.href = url.toString();
    });

    // ============================================================
    // Bulk-action bar — sticky toolbar above the timeline.
    // Tracks the set of checked timer IDs as the user clicks rows,
    // shows / hides itself based on whether any are selected, and
    // before the user submits the dismiss/destroy form, fills the
    // hidden `ids` field with a JSON-array-style indexed value list.
    // ============================================================
    function refreshBulkBar() {
        const $checked = $('.js-timer-checkbox:checked');
        const count = $checked.length;
        $('#cbBulkCount').text(count);
        $('#cbBulkBar').css('display', count > 0 ? 'flex' : 'none');
    }

    function buildIdsArrayString() {
        // Laravel happily accepts either form submission of multiple
        // ids[]= values or a serialized array via a JSON-style hidden
        // field. We use the simpler approach: comma-joined string,
        // then split server-side. But the controller expects an array
        // from request->input('ids'). So we'll inject hidden inputs
        // dynamically right before submit instead — cleaner.
        return $('.js-timer-checkbox:checked').map(function () {
            return parseInt(this.value, 10);
        }).get();
    }

    function injectIdsIntoForm($form, ids) {
        // Remove any prior dynamically-added id inputs
        $form.find('input[name="ids[]"]').remove();
        ids.forEach(function (id) {
            $('<input type="hidden" name="ids[]">').val(id).appendTo($form);
        });
    }

    // Track changes
    $(document).on('change', '.js-timer-checkbox', refreshBulkBar);

    // Clear all selections
    $('#cbBulkClear').on('click', function () {
        $('.js-timer-checkbox').prop('checked', false);
        refreshBulkBar();
    });

    // Inject the chosen IDs as ids[] hidden inputs right before submit.
    // The static `name="ids"` field on the form is just a placeholder so
    // the controller never sees an empty submission with no array; we
    // strip it and add real ids[] inputs here.
    $('#cbBulkDismissForm').on('submit', function (e) {
        const ids = buildIdsArrayString();
        if (ids.length === 0) {
            e.preventDefault();
            return false;
        }
        $(this).find('#cbBulkDismissIds').remove(); // drop the placeholder
        injectIdsIntoForm($(this), ids);
    });

    $('#cbBulkDestroyForm').on('submit', function (e) {
        const ids = buildIdsArrayString();
        if (ids.length === 0) {
            e.preventDefault();
            return false;
        }
        $(this).find('#cbBulkDestroyIds').remove();
        injectIdsIntoForm($(this), ids);
    });

    // Initial render
    renderChipState();
    applyFilter();
    refreshBulkBar();

})(jQuery);
</script>
@endpush

@stop
