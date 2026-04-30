@extends('web::layouts.grids.12')

@section('title', 'Structure Board')
@section('page_header', 'Structure Board')

@section('full')

<style>
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
        min-width: 130px;
    }
    .cb-timer-when .cb-abs {
        font-size: 0.78rem;
        color: #8b95a5;
    }
    .cb-timer-when .cb-rel {
        font-size: 0.95rem;
        color: #fff;
        font-weight: 600;
        margin-top: 2px;
    }

    .cb-timer-actions {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
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
</style>

<div class="cb-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="cb-header-actions">
        @php
            $icsUrl = \Illuminate\Support\Facades\URL::signedRoute(
                'structure-manager.command-board.calendar',
                ['user_id' => auth()->id()]
            );
        @endphp
        <a href="{{ route('structure-manager.command-board.grid') }}" class="btn btn-outline-secondary btn-sm" title="Switch to monthly calendar grid view">
            <i class="far fa-calendar"></i> Grid view
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#cbCalendarSubscribeModal" title="Subscribe to your timers in your calendar app">
            <i class="far fa-calendar-plus"></i> Subscribe (ICS)
        </button>

        @if($canCreate)
            <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#cbManualOpModal">
                <i class="fas fa-plus"></i> Add Manual Op Timer
            </button>
        @endif
    </div>

    {{-- ICS subscription modal — lets the user copy their personal feed URL --}}
    <div class="modal fade" id="cbCalendarSubscribeModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="background:#2a2f3a; border:1px solid #454d55; color:#c2c7d0;">
                <div class="modal-header" style="border-bottom-color:#454d55;">
                    <h5 class="modal-title" style="color:#fff;">
                        <i class="far fa-calendar-plus"></i> Subscribe to Structure Board
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>
                        Paste this URL into Google Calendar, Outlook, or Apple Calendar as a
                        new <em>subscription</em> calendar (NOT an import — subscriptions
                        refresh automatically).
                    </p>
                    <div class="form-group">
                        <label>Your subscription URL (keep it private — it grants read access to YOUR view of the board)</label>
                        <div class="input-group">
                            <input type="text" id="cbIcsUrl" class="form-control" value="{{ $icsUrl }}" readonly style="background:#1f242c; color:#fff; border-color:#454d55;">
                            <span class="input-group-append">
                                <button type="button" class="btn btn-secondary" onclick="cbCopyIcsUrl()">
                                    <i class="far fa-copy"></i> Copy
                                </button>
                            </span>
                        </div>
                    </div>
                    <small class="text-muted">
                        The URL is signed with this SeAT instance's APP_KEY and is unique
                        to you. Anyone with the URL can read your visible timers as a
                        calendar feed (read-only — they can't dismiss, delete, or modify).
                        The URL stays valid until your SeAT installation rotates APP_KEY.
                    </small>

                    <hr style="border-color:#454d55;">
                    <strong style="color:#fff;">Where to paste it:</strong>
                    <ul style="margin-top:6px;">
                        <li><strong>Google Calendar</strong>: Settings → Add calendar → From URL</li>
                        <li><strong>Outlook</strong>: Calendar → Add calendar → Subscribe from web</li>
                        <li><strong>Apple Calendar</strong>: File → New Calendar Subscription</li>
                        <li><strong>Thunderbird / Lightning</strong>: New Calendar → On the Network → iCalendar</li>
                    </ul>
                </div>
                <div class="modal-footer" style="border-top-color:#454d55;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function cbCopyIcsUrl() {
            const input = document.getElementById('cbIcsUrl');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                document.execCommand('copy');
            } catch (e) { /* clipboard blocked — user can copy manually */ }
        }
    </script>

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
                                <span class="cb-badge {{ $timer->severity }}">{{ str_replace('_', ' ', $timer->event_type) }}</span>
                                @if($timer->is_global)
                                    <span class="cb-badge global" title="Global — visible to all corps">🌐 Global</span>
                                @endif
                                <span class="cb-badge source">{{ str_replace('_', ' ', $timer->source) }}</span>
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
                                @if($timer->attacker_corporation_name)
                                    <span><i class="fas fa-skull-crossbones"></i> Attacker: <strong>{{ $timer->attacker_corporation_name }}</strong></span>
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
                            <div class="cb-abs">{{ $timer->eve_time->format('Y-m-d H:i') }} EVE</div>
                            <div class="cb-rel">{{ $timer->eve_time->diffForHumans() }}</div>
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
                            <input type="text" name="structure_type" class="form-control cb-select" maxlength="128" placeholder="e.g. Fortizar">
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
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Timer</button>
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

    const STORAGE_KEY = 'sm_command_board_groups';
    const ALL_GROUPS = ['fuel', 'tactical', 'lifecycle'];

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
