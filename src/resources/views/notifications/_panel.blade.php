{{--
    Notifications panel — embedded inside the Settings page sidebar section.

    Provides the category-by-namespace toggles, default role mention, and
    per-binding webhook + role override controls. The five AJAX endpoints
    used here (updateCategory, upsertBinding, removeBinding, toggleBinding,
    listRoles) still route to NotificationController; only the index view
    moved into Settings.

    Required variables (passed by SettingsController::index):
      $categories             — NotificationCategory grouped by namespace
      $webhooks               — collection of WebhookConfiguration
      $bindings               — pivot rows grouped by category_id
      $namespaces             — metadata array (upwell / events / pos)
      $roleProvider           — primary detected provider (legacy)
      $roleProviders          — full list of detected providers
      $roleProviderLabel      — human-readable provider label
      $roleProviderAvailable  — bool
      $smRoleLookup           — DiscordRoleResolver::roleLookupMap() result
                                (snowflake => role data; used to translate
                                raw role IDs into names)

    The Notification Routing Map (where each category delivers + who it
    pings) is a sibling Settings tab — see _routing_map.blade.php.
--}}

<style>
    /* Scoped to .notif-wrapper so styles don't leak into other settings sections */
    .notif-wrapper { color: #c2c7d0; }
    .notif-wrapper .notif-section {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 8px;
        padding: 1.2rem 1.4rem;
        margin-bottom: 1.4rem;
    }
    .notif-wrapper .notif-section-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.6rem;
    }
    .notif-wrapper .notif-section-header h3 {
        margin: 0;
        color: #fff;
        font-size: 1.15rem;
    }
    .notif-wrapper .notif-section-header .legacy-badge {
        background: #52616b;
        color: #fff;
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 10px;
        letter-spacing: 0.5px;
    }
    .notif-wrapper .notif-section-desc {
        font-size: 0.85rem;
        color: #8b95a5;
        margin-bottom: 1rem;
    }

    .notif-wrapper .category-row {
        background: #1e222b;
        border: 1px solid #3a4049;
        border-radius: 6px;
        padding: 1rem 1.1rem;
        margin-bottom: 0.8rem;
    }
    .notif-wrapper .category-row[data-enabled="0"] {
        opacity: 0.55;
    }
    .notif-wrapper .category-title-row {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        margin-bottom: 0.4rem;
    }
    .notif-wrapper .category-title {
        color: #fff;
        font-weight: 600;
        font-size: 1rem;
        flex-grow: 1;
    }
    .notif-wrapper .category-desc {
        font-size: 0.8rem;
        color: #8b95a5;
        margin-bottom: 0.7rem;
    }
    .notif-wrapper .category-controls {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.9rem;
        margin-bottom: 0.6rem;
    }
    @media (max-width: 900px) {
        .notif-wrapper .category-controls { grid-template-columns: 1fr; }
    }
    .notif-wrapper .form-inline-label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #8b95a5;
        margin-bottom: 0.25rem;
        display: block;
    }
    .notif-wrapper .role-input-group {
        display: flex;
        gap: 0.35rem;
    }
    .notif-wrapper .role-input-group input,
    .notif-wrapper .role-input-group select {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.35rem 0.6rem;
        font-size: 0.85rem;
        border-radius: 4px;
        flex-grow: 1;
    }

    .notif-wrapper .bindings-table {
        width: 100%;
        font-size: 0.83rem;
        border-collapse: collapse;
        margin-top: 0.5rem;
    }
    .notif-wrapper .bindings-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.4rem 0.6rem;
        border-bottom: 1px solid #3a4049;
    }
    .notif-wrapper .bindings-table td {
        padding: 0.45rem 0.6rem;
        border-bottom: 1px solid #2a2f3a;
    }
    .notif-wrapper .bindings-table tr:last-child td { border-bottom: none; }
    .notif-wrapper .bindings-table .no-binding {
        color: #666c76;
        font-style: italic;
    }
    .notif-wrapper .binding-role-cell input {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        border-radius: 3px;
        width: 100%;
        max-width: 220px;
    }
    .notif-wrapper .binding-actions {
        display: flex;
        gap: 0.35rem;
        justify-content: flex-end;
    }

    .notif-wrapper .add-binding-select {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.35rem 0.6rem;
        font-size: 0.85rem;
        border-radius: 4px;
    }

    .notif-wrapper .toggle-switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
    }
    .notif-wrapper .toggle-switch input { display: none; }
    .notif-wrapper .toggle-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #4a4f58;
        border-radius: 22px;
        transition: .2s;
    }
    .notif-wrapper .toggle-slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        top: 3px;
        background: white;
        border-radius: 50%;
        transition: .2s;
    }
    .notif-wrapper .toggle-switch input:checked + .toggle-slider { background: #28a745; }
    .notif-wrapper .toggle-switch input:checked + .toggle-slider:before { transform: translateX(18px); }

    .notif-wrapper .role-provider-banner {
        padding: 0.7rem 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        font-size: 0.85rem;
    }
    .notif-wrapper .role-provider-banner.available {
        background: rgba(40, 167, 69, 0.12);
        border-left: 3px solid #28a745;
    }
    .notif-wrapper .role-provider-banner.manual-only {
        background: rgba(255, 193, 7, 0.1);
        border-left: 3px solid #ffc107;
    }
    .notif-wrapper .role-mention-legend {
        padding: 0.7rem 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        font-size: 0.85rem;
        background: rgba(102, 126, 234, 0.1);
        border-left: 3px solid #667eea;
        color: #cbd5e1;
    }
    .notif-wrapper .role-mention-legend ul.role-mention-tiers {
        list-style: none;
        margin: 0.5rem 0 0 0;
        padding: 0;
    }
    .notif-wrapper .role-mention-legend li { margin-bottom: 0.35rem; }
    .notif-wrapper .role-mention-legend strong { color: #e2e8f0; }

    /* L1/L2/L3 tier chips — shared vocabulary between this legend and the
       Routing Map's "Will mention" via-badges. Colours match the via-badges
       (binding = indigo, category = green, webhook = grey) so an operator
       can cross-reference a map badge to its legend entry at a glance. */
    .notif-wrapper .lvl-badge {
        display: inline-block;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.4px;
        padding: 1px 6px;
        border-radius: 8px;
        margin-right: 5px;
    }
    .notif-wrapper .lvl-badge.lvl-1 { background: #4338ca; color: #e0e7ff; }
    .notif-wrapper .lvl-badge.lvl-2 { background: #1c6f3e; color: #d4f4e2; }
    .notif-wrapper .lvl-badge.lvl-3 { background: #52616b; color: #fff; }

    /* --- Resolved role-name display (translates raw role IDs to names) --- */
    .notif-wrapper .role-name-display {
        margin-top: 0.3rem;
        font-size: 0.76rem;
        line-height: 1.35;
    }
    .notif-wrapper .role-name-display:empty { display: none; }

    /* Resolved-role pill — used by the role inputs here and by the shared
       _role_pill partial on the Notification Routing Map tab. Unscoped
       (sm- prefixed, collision-safe) so the pill renders identically in
       either place without depending on a particular wrapper. */
    .sm-role-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #1b2733;
        border: 1px solid #2f3b49;
        border-radius: 10px;
        padding: 1px 8px;
        color: #cbd5e1;
        max-width: 100%;
        font-size: 0.78rem;
    }
    .sm-role-pill .role-color-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .sm-role-pill.is-unknown {
        background: #3a2e16;
        border-color: #6b5424;
        color: #d4c69a;
    }
    .sm-role-pill.is-user {
        background: #1f2b3a;
        border-color: #2f4a63;
        color: #9ec5e8;
    }
    .sm-role-none { color: #666c76; font-style: italic; }
</style>

<div class="notif-wrapper">

    @php
        // Discord role lookup ($smRoleLookup) is built once in
        // SettingsController::index() and shared by this panel and the
        // Notification Routing Map tab. Here we only reshape it into the
        // compact {id:{name,color}} map embedded as a JSON data island so
        // the settings-page JS can translate raw role IDs into names.
        $smRoleLookupJs = [];
        foreach (($smRoleLookup ?? []) as $smRid => $smRole) {
            $smRoleLookupJs[$smRid] = [
                'name'  => $smRole['name'] ?? null,
                'color' => $smRole['color'] ?? null,
            ];
        }
    @endphp

    {{-- Role-lookup data island — read by the settings-page JS so it can
         translate raw role IDs in any role-mention input into role names
         as the operator types or picks. --}}
    <script type="application/json" id="sm-role-map">@json(['available' => $roleProviderAvailable, 'roles' => (object) $smRoleLookupJs])</script>

    {{-- Role provider status --}}
    <div class="role-provider-banner {{ $roleProviderAvailable ? 'available' : 'manual-only' }}">
        @if($roleProviderAvailable)
            <i class="fas fa-check-circle" style="color:#28a745;"></i>
            <strong>Discord role {{ count($roleProviders) > 1 ? 'sources' : 'provider' }} detected:</strong>
            {{ $roleProviderLabel }}.
            @if(count($roleProviders) > 1)
                <small style="color:#8b95a5;">Roles from all sources are merged and deduped in the picker; use the source filter inside the picker to narrow by provider.</small>
            @endif
            Pick roles from the <i class="fas fa-hashtag"></i> button next to any role-mention input. Manual entry still works as a fallback.
        @else
            <i class="fas fa-info-circle" style="color:#ffc107;"></i>
            <strong>No Discord role source detected.</strong> Role mentions are entered manually as <code>&lt;@&amp;ROLE_ID&gt;</code> or raw role ID.
            Install
            <a href="https://github.com/MattFalahe/seat-discord-pings" target="_blank" rel="noopener">seat-discord-pings</a>
            (curated list with colors) or
            <a href="https://github.com/warlof/seat-discord-connector" target="_blank" rel="noopener">warlof/seat-discord-connector</a>
            to enable the role picker.
        @endif
    </div>

    {{-- Role-mention precedence legend --}}
    <div class="role-mention-legend">
        <i class="fas fa-at" style="color:#667eea;"></i>
        <strong>How role mentions resolve (L1 &rarr; L2 &rarr; L3).</strong>
        When a category fires to a bound webhook, the role it pings is the
        first of these three tiers that is filled in:
        <ul class="role-mention-tiers">
            <li><span class="lvl-badge lvl-1">L1</span> <strong>Binding override:</strong> the role field on that webhook's row in the bindings table below. Highest priority; lets one channel ping a different role than the rest.</li>
            <li><span class="lvl-badge lvl-2">L2</span> <strong>Category "Default Role Mention":</strong> the field on this category. Used whenever a binding leaves its L1 override blank.</li>
            <li><span class="lvl-badge lvl-3">L3</span> <strong>The webhook's own role:</strong> the role saved on the webhook itself in Webhook Configuration. Legacy fallback, used only when L1 and L2 are both blank.</li>
        </ul>
        All three blank = the alert posts with no ping. Resolution is per
        binding, so the same category can ping different roles in different
        channels. The <a href="#" class="nav-section-link" data-section="routing-map"><strong>Routing Map</strong></a>
        tab tags every row with the tier that won (L1/L2/L3).
    </div>

    {{-- MC availability — read once, surface per category that requires MC.
         Categories whose category_key starts with `pre_timer_` need Manager
         Core's EventBus to fire (the PreTimerReminderHandler is subscribed
         there). When MC is absent, an operator could still configure the
         binding (it'll just sit dormant until MC is installed) — we don't
         disable the controls, just mark the requirement visually. --}}
    @php($mcAvailable = \StructureManager\Integrations\ManagerCoreIntegration::isAvailable())

    {{-- Namespace-level banner — surfaces the MC requirement once before the
         loop into the events section, so an operator scrolling through
         doesn't have to read every category description to learn that
         pre_timer_* categories are MC-required. --}}
    @php($nsHasMcRequired = collect($categories['events'] ?? collect())->contains(fn($c) => str_starts_with($c->category_key, 'pre_timer_')))

    {{-- Categories by namespace --}}
    @foreach(['upwell', 'events', 'pos'] as $ns)
        @php($nsCategories = $categories[$ns] ?? collect())
        @if($nsCategories->isEmpty()) @continue @endif
        @php($nsMeta = $namespaces[$ns])

        <div class="notif-section">
            <div class="notif-section-header">
                <h3>
                    @if($ns === 'upwell')  <i class="fas fa-building"></i>
                    @elseif($ns === 'events') <i class="fas fa-bolt"></i>
                    @else <i class="fas fa-broadcast-tower"></i>
                    @endif
                    {{ $nsMeta['label'] }}
                </h3>
                @if($nsMeta['legacy'])
                    <span class="legacy-badge">LEGACY</span>
                @endif
            </div>
            <div class="notif-section-desc">{{ $nsMeta['description'] }}</div>

            {{-- Namespace-level MC-required banner (only in `events` when the
                 namespace contains pre_timer_* categories AND MC is absent).
                 Tells operators at a glance which categories below need MC. --}}
            @if($ns === 'events' && $nsHasMcRequired && !$mcAvailable)
                <div style="padding:0.7rem 0.95rem; margin:0.4rem 0 0.9rem 0; border-left:3px solid #ffc107; background:#3a2e16; border-radius:4px; font-size:0.85rem;">
                    <i class="fas fa-exclamation-triangle" style="color:#ffc107; margin-right:6px;"></i>
                    <strong style="color:#ffd96a;">Manager Core not installed.</strong>
                    Categories below labeled <span style="background:#4338ca; color:#e0e7ff; padding:1px 7px; border-radius:10px; font-size:0.72rem; font-weight:600;">MC REQUIRED</span>
                    will not fire any reminders until Manager Core is installed (their
                    scheduled timer.upcoming_* events have nowhere to publish). You can
                    still configure webhook bindings and role mentions now — they activate
                    automatically the moment Manager Core is added.
                    <a href="https://github.com/MattFalahe/Manager-Core" target="_blank" rel="noopener" style="margin-left:6px; color:#8ab4ff;">
                        Install Manager Core <i class="fas fa-external-link-alt" style="font-size:0.7rem;"></i>
                    </a>
                </div>
            @endif

            @foreach($nsCategories as $cat)
                @php($catBindings = ($bindings[$cat->id] ?? collect()))
                @php($boundWebhookIds = $catBindings->pluck('webhook_id')->all())
                @php($unboundWebhooks = $webhooks->whereNotIn('id', $boundWebhookIds))

                {{-- Does this specific category require Manager Core? Detected
                     by category_key prefix — all pre_timer_* categories ride
                     MC's EventBus, no other categories do. If we add more
                     MC-required categories later, extend this check. --}}
                @php($catRequiresMc = str_starts_with($cat->category_key, 'pre_timer_'))

                <div class="category-row" data-category-id="{{ $cat->id }}" data-enabled="{{ $cat->enabled ? '1' : '0' }}" @if($catRequiresMc) data-requires-mc="1" @endif>

                    <div class="category-title-row">
                        <label class="toggle-switch" title="Master toggle">
                            <input type="checkbox" class="js-category-enabled" {{ $cat->enabled ? 'checked' : '' }}>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="category-title">{{ $cat->display_name }}</span>
                        @if($catRequiresMc)
                            @if($mcAvailable)
                                <span style="background:#1c6f3e; color:#d4f4e2; padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:600; letter-spacing:0.3px;" title="Manager Core is installed and powering this category">
                                    <i class="fas fa-check-circle"></i> MC ACTIVE
                                </span>
                            @else
                                <span style="background:#4338ca; color:#e0e7ff; padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:600; letter-spacing:0.3px;" title="This category requires Manager Core to fire. Install MC to activate.">
                                    <i class="fas fa-puzzle-piece"></i> MC REQUIRED
                                </span>
                            @endif
                        @endif
                    </div>

                    @if($cat->description)
                        <div class="category-desc">{{ $cat->description }}</div>
                    @endif

                    {{-- Per-category dormant-state notice — shown only when this
                         specific category needs MC and MC is absent. The big
                         namespace-level banner above explains the general
                         situation; this inline note is the per-row signal so
                         an operator scrolling to a specific category sees it
                         without scrolling back up. --}}
                    @if($catRequiresMc && !$mcAvailable)
                        <div style="padding:0.4rem 0.7rem; margin:0.35rem 0 0.5rem 0; background:#2d2417; border-left:2px solid #ffc107; border-radius:3px; font-size:0.78rem; color:#d4c69a;">
                            <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                            Dormant until Manager Core is installed. Bindings + role mentions below are saved and applied automatically once MC is present.
                        </div>
                    @endif

                    <div class="category-controls">
                        <div>
                            <label class="form-inline-label">Default Role Mention</label>
                            <div class="role-field">
                                <div class="role-input-group">
                                    <input type="text"
                                           class="js-category-role"
                                           value="{{ $cat->role_mention }}"
                                           placeholder="<@&123456789> or leave blank">
                                    @if($roleProviderAvailable)
                                        <button type="button" class="btn btn-sm btn-secondary js-pick-role" title="Pick from Discord">
                                            <i class="fas fa-hashtag"></i>
                                        </button>
                                    @endif
                                </div>
                                {{-- Resolved role name — filled by JS from the role-lookup data island --}}
                                <div class="role-name-display"></div>
                            </div>
                            <small style="color:#666c76; font-size:0.75rem;">
                                Applied when a binding doesn't override. Leave blank = no mention.
                            </small>
                        </div>
                        <div>
                            <label class="form-inline-label">Bind Webhook</label>
                            <div style="display:flex; gap:0.35rem;">
                                <select class="add-binding-select js-add-binding" style="flex-grow:1;">
                                    <option value="">— pick webhook to add —</option>
                                    @foreach($unboundWebhooks as $wh)
                                        <option value="{{ $wh->id }}">
                                            {{ $wh->description ?: 'Webhook #' . $wh->id }}
                                            @if($wh->corporation_id)
                                                ({{ $wh->getCorporationLabel() }})
                                            @else
                                                (All corps)
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-sm btn-success js-do-add-binding" disabled>
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Bindings table --}}
                    @if($catBindings->count() > 0)
                        <table class="bindings-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;">On</th>
                                    <th>Webhook</th>
                                    <th>Corporation</th>
                                    <th>Role Override (optional)</th>
                                    <th style="width:120px; text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($catBindings as $b)
                                    @php($wh = $webhooks->firstWhere('id', $b->webhook_id))
                                    @if(!$wh) @continue @endif
                                    <tr data-binding-webhook="{{ $b->webhook_id }}" data-binding-category="{{ $cat->id }}">
                                        <td>
                                            <label class="toggle-switch" style="width:34px; height:18px;">
                                                <input type="checkbox" class="js-binding-enabled" {{ $b->enabled ? 'checked' : '' }}>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </td>
                                        <td>{{ $wh->description ?: 'Webhook #' . $wh->id }}</td>
                                        <td>{{ $wh->corporation_id ? $wh->getCorporationLabel() : 'All corps' }}</td>
                                        <td class="binding-role-cell">
                                            <div class="role-field">
                                                <div style="display:flex; gap:4px;">
                                                    <input type="text"
                                                           class="js-binding-role"
                                                           value="{{ $b->role_mention }}"
                                                           placeholder="(uses category default)">
                                                    @if($roleProviderAvailable)
                                                        <button type="button" class="btn btn-xs btn-secondary js-pick-role-binding" title="Pick from Discord">
                                                            <i class="fas fa-hashtag"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                                {{-- Resolved role name — filled by JS --}}
                                                <div class="role-name-display"></div>
                                            </div>
                                        </td>
                                        <td class="binding-actions">
                                            <button type="button" class="btn btn-xs btn-info js-save-binding" title="Save role override">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <button type="button" class="btn btn-xs btn-danger js-remove-binding" title="Unbind">
                                                <i class="fas fa-unlink"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="no-binding" style="padding:0.5rem 0; font-size:0.82rem;">
                            No webhooks bound — this category fires nowhere. Add a binding above.
                        </div>
                    @endif

                </div>
            @endforeach

        </div>
    @endforeach

    {{-- The Notification Routing Map (a read-only "where does each category
         deliver, and who gets pinged" view) lives in its own Settings tab —
         see resources/views/notifications/_routing_map.blade.php. --}}

    {{-- Webhooks summary (CRUD lives in the Webhook Configuration section above) --}}
    <div class="notif-section">
        <div class="notif-section-header">
            <h3><i class="fas fa-plug"></i> Webhooks Summary</h3>
        </div>
        <div class="notif-section-desc">
            Destinations that receive category notifications. Manage webhooks (add / edit / delete) in the
            <a href="#" class="nav-section-link" data-section="webhooks"><strong>Webhook Configuration</strong></a>
            section. The legacy <code>role_mention</code> column on each webhook is still honored as a final
            fallback when neither the category nor the binding supplies one.
        </div>

        @if($webhooks->isEmpty())
            <div style="color:#ffc107;">
                <i class="fas fa-exclamation-triangle"></i>
                No webhooks configured yet. Add one in
                <a href="#" class="nav-section-link" data-section="webhooks">Webhook Configuration</a>
                before binding categories.
            </div>
        @else
            <table class="bindings-table table table-sm table-dark" style="font-size:0.88rem;">
                <thead>
                    <tr>
                        <th style="width:70px;">Enabled</th>
                        <th>Name</th>
                        <th>URL</th>
                        <th>Corporation</th>
                        <th>Legacy Role</th>
                        <th>Bound Categories</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($webhooks as $wh)
                        @php($whCategories = collect())
                        @foreach($bindings as $catId => $catBindings)
                            @foreach($catBindings as $b)
                                @if($b->webhook_id == $wh->id)
                                    @php($whCategories->push($categories->flatten()->firstWhere('id', $catId)))
                                @endif
                            @endforeach
                        @endforeach

                        <tr>
                            <td>{!! $wh->enabled ? '<span class="badge badge-success">On</span>' : '<span class="badge badge-secondary">Off</span>' !!}</td>
                            <td>{{ $wh->description ?: 'Webhook #' . $wh->id }}</td>
                            <td title="{{ $wh->webhook_url }}" style="font-family:'Courier New',monospace; font-size:0.78rem; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $wh->webhook_url }}</td>
                            <td>{{ $wh->corporation_id ? $wh->getCorporationLabel() : 'All corps' }}</td>
                            <td>
                                @if($wh->role_mention)
                                    @include('structure-manager::notifications._role_pill', ['desc' => \StructureManager\Services\DiscordRoleResolver::describeRoleMention($wh->role_mention, $smRoleLookup ?? [])])
                                @else
                                    <span style="color:#666c76;">—</span>
                                @endif
                            </td>
                            <td>
                                @if($whCategories->filter()->count() === 0)
                                    <span style="color:#666c76;">none</span>
                                @else
                                    @foreach($whCategories->filter() as $whc)
                                        <span class="badge badge-info" style="margin-right:3px;">{{ $whc->namespace }}.{{ $whc->category_key }}</span>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

{{-- Role picker modal (only used when a connector is installed) --}}
@if($roleProviderAvailable)
<div class="modal fade" id="rolePickerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="background:#2a2f3a; border:1px solid #454d55;">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#fff;">Pick Discord Role</h5>
                <button type="button" class="close" data-dismiss="modal"><span style="color:#fff;">&times;</span></button>
            </div>
            <div class="modal-body" id="rolePickerBody">
                <div class="text-center" style="padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
</div>
@endif
