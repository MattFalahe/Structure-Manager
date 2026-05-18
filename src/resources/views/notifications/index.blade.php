@extends('web::layouts.grids.12')

@section('title', 'Structure Manager — Notifications')
@section('page_header', 'Notifications')

@section('full')

<style>
    .notif-wrapper { color: #c2c7d0; }
    .notif-section {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 8px;
        padding: 1.2rem 1.4rem;
        margin-bottom: 1.4rem;
    }
    .notif-section-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.6rem;
    }
    .notif-section-header h3 {
        margin: 0;
        color: #fff;
        font-size: 1.15rem;
    }
    .notif-section-header .legacy-badge {
        background: #52616b;
        color: #fff;
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 10px;
        letter-spacing: 0.5px;
    }
    .notif-section-desc {
        font-size: 0.85rem;
        color: #8b95a5;
        margin-bottom: 1rem;
    }

    .category-row {
        background: #1e222b;
        border: 1px solid #3a4049;
        border-radius: 6px;
        padding: 1rem 1.1rem;
        margin-bottom: 0.8rem;
    }
    .category-row[data-enabled="0"] {
        opacity: 0.55;
    }
    .category-title-row {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        margin-bottom: 0.4rem;
    }
    .category-title {
        color: #fff;
        font-weight: 600;
        font-size: 1rem;
        flex-grow: 1;
    }
    .category-desc {
        font-size: 0.8rem;
        color: #8b95a5;
        margin-bottom: 0.7rem;
    }
    .category-controls {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.9rem;
        margin-bottom: 0.6rem;
    }
    @media (max-width: 900px) {
        .category-controls { grid-template-columns: 1fr; }
    }
    .form-inline-label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #8b95a5;
        margin-bottom: 0.25rem;
        display: block;
    }
    .role-input-group {
        display: flex;
        gap: 0.35rem;
    }
    .role-input-group input, .role-input-group select {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.35rem 0.6rem;
        font-size: 0.85rem;
        border-radius: 4px;
        flex-grow: 1;
    }

    .bindings-table {
        width: 100%;
        font-size: 0.83rem;
        border-collapse: collapse;
        margin-top: 0.5rem;
    }
    .bindings-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.4rem 0.6rem;
        border-bottom: 1px solid #3a4049;
    }
    .bindings-table td {
        padding: 0.45rem 0.6rem;
        border-bottom: 1px solid #2a2f3a;
    }
    .bindings-table tr:last-child td { border-bottom: none; }
    .bindings-table .no-binding {
        color: #666c76;
        font-style: italic;
    }
    .binding-role-cell input {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        border-radius: 3px;
        width: 100%;
        max-width: 220px;
    }
    .binding-actions {
        display: flex;
        gap: 0.35rem;
        justify-content: flex-end;
    }

    .webhooks-table {
        width: 100%;
        font-size: 0.88rem;
    }
    .webhooks-table th, .webhooks-table td {
        padding: 0.6rem 0.8rem;
        border-bottom: 1px solid #3a4049;
    }
    .webhooks-table th {
        color: #8b95a5;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.5px;
    }
    .webhooks-table .url-cell {
        font-family: 'Courier New', monospace;
        font-size: 0.78rem;
        color: #c2c7d0;
        max-width: 280px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .add-binding-select {
        background: #2a2f3a;
        border: 1px solid #454d55;
        color: #fff;
        padding: 0.35rem 0.6rem;
        font-size: 0.85rem;
        border-radius: 4px;
    }

    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
    }
    .toggle-switch input { display: none; }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #4a4f58;
        border-radius: 22px;
        transition: .2s;
    }
    .toggle-slider:before {
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
    .toggle-switch input:checked + .toggle-slider { background: #28a745; }
    .toggle-switch input:checked + .toggle-slider:before { transform: translateX(18px); }

    .role-provider-banner {
        padding: 0.7rem 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        font-size: 0.85rem;
    }
    .role-provider-banner.available {
        background: rgba(40, 167, 69, 0.12);
        border-left: 3px solid #28a745;
    }
    .role-provider-banner.manual-only {
        background: rgba(255, 193, 7, 0.1);
        border-left: 3px solid #ffc107;
    }
</style>

<div class="notif-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

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

            @foreach($nsCategories as $cat)
                @php($catBindings = ($bindings[$cat->id] ?? collect()))
                @php($boundWebhookIds = $catBindings->pluck('webhook_id')->all())
                @php($unboundWebhooks = $webhooks->whereNotIn('id', $boundWebhookIds))

                <div class="category-row" data-category-id="{{ $cat->id }}" data-enabled="{{ $cat->enabled ? '1' : '0' }}">

                    <div class="category-title-row">
                        <label class="toggle-switch" title="Master toggle">
                            <input type="checkbox" class="js-category-enabled" {{ $cat->enabled ? 'checked' : '' }}>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="category-title">{{ $cat->display_name }}</span>
                    </div>

                    @if($cat->description)
                        <div class="category-desc">{{ $cat->description }}</div>
                    @endif

                    <div class="category-controls">
                        <div>
                            <label class="form-inline-label">Default Role Mention</label>
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

    {{-- Webhooks list (CRUD still lives on /settings page for now) --}}
    <div class="notif-section">
        <div class="notif-section-header">
            <h3><i class="fas fa-plug"></i> Webhooks</h3>
        </div>
        <div class="notif-section-desc">
            Destinations that receive category notifications. Add or remove webhooks from the
            <a href="{{ route('structure-manager.settings') }}#webhooks"><strong>Webhook Configuration</strong></a>
            tab in Settings (first tab on that page).
            The legacy <code>role_mention</code> column on each webhook is still honored as a final
            fallback when neither the category nor the binding supplies one.
        </div>

        @if($webhooks->isEmpty())
            <div style="color:#ffc107;">
                <i class="fas fa-exclamation-triangle"></i>
                No webhooks configured yet. Add one in
                <a href="{{ route('structure-manager.settings') }}">Settings</a> before binding categories.
            </div>
        @else
            <table class="webhooks-table table table-sm table-dark">
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
                            <td class="url-cell" title="{{ $wh->webhook_url }}">{{ $wh->webhook_url }}</td>
                            <td>{{ $wh->corporation_id ? $wh->getCorporationLabel() : 'All corps' }}</td>
                            <td>
                                @if($wh->role_mention)
                                    <code style="font-size:0.75rem;">{{ $wh->role_mention }}</code>
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

@push('javascript')
<script>
(function($) {
    'use strict';

    const CSRF = '{{ csrf_token() }}';
    const ROUTES = {
        updateCategory:  '{{ url('/structure-manager/settings/notifications/category/:id') }}',
        upsertBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid') }}',
        removeBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid') }}',
        toggleBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid/toggle') }}',
        listRoles:       '{{ route('structure-manager.notifications.roles') }}',
    };

    function ajax(method, url, data, onSuccess, onError) {
        $.ajax({
            url: url,
            method: method,
            data: Object.assign({ _token: CSRF }, data || {}),
            dataType: 'json',
        })
        .done(function (res) { onSuccess && onSuccess(res); })
        .fail(function (xhr) {
            const msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Request failed';
            if (onError) { onError(msg); } else { alert(msg); }
        });
    }

    // -------- Category: toggle enabled / save role --------

    $(document).on('change', '.js-category-enabled', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const enabled = $(this).is(':checked');
        const role = $row.find('.js-category-role').val();

        $row.attr('data-enabled', enabled ? '1' : '0');

        ajax('POST', ROUTES.updateCategory.replace(':id', catId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        });
    });

    $(document).on('blur', '.js-category-role', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const enabled = $row.find('.js-category-enabled').is(':checked');
        const role = $(this).val();

        ajax('POST', ROUTES.updateCategory.replace(':id', catId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        });
    });

    // -------- Binding: add / remove / toggle / save role override --------

    $(document).on('change', '.js-add-binding', function () {
        const $row = $(this).closest('.category-row');
        $row.find('.js-do-add-binding').prop('disabled', !$(this).val());
    });

    $(document).on('click', '.js-do-add-binding', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const webhookId = $row.find('.js-add-binding').val();

        if (!webhookId) return;

        ajax('POST', ROUTES.upsertBinding.replace(':cid', catId).replace(':wid', webhookId),
            { enabled: 1 },
            function () { location.reload(); }
        );
    });

    $(document).on('change', '.js-binding-enabled', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');

        ajax('POST', ROUTES.toggleBinding.replace(':cid', catId).replace(':wid', whId));
    });

    $(document).on('click', '.js-save-binding', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');
        const enabled = $tr.find('.js-binding-enabled').is(':checked');
        const role = $tr.find('.js-binding-role').val();

        ajax('POST', ROUTES.upsertBinding.replace(':cid', catId).replace(':wid', whId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        }, function () {
            $tr.find('.js-save-binding').removeClass('btn-info').addClass('btn-success')
                .html('<i class="fas fa-check"></i>');
            setTimeout(() => {
                $tr.find('.js-save-binding').removeClass('btn-success').addClass('btn-info')
                    .html('<i class="fas fa-save"></i>');
            }, 1200);
        });
    });

    $(document).on('click', '.js-remove-binding', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');

        if (!confirm('Unbind this webhook from the category? The webhook itself stays configured; only this category stops firing to it.')) return;

        ajax('DELETE', ROUTES.removeBinding.replace(':cid', catId).replace(':wid', whId),
            {},
            function () { location.reload(); }
        );
    });

    // -------- Role picker modal (connector dropdown) --------

    let activeRoleTarget = null;

    $(document).on('click', '.js-pick-role', function () {
        const $row = $(this).closest('.category-row');
        activeRoleTarget = $row.find('.js-category-role');
        openRolePicker();
    });

    // Per-binding role override picker
    $(document).on('click', '.js-pick-role-binding', function () {
        const $tr = $(this).closest('tr');
        activeRoleTarget = $tr.find('.js-binding-role');
        openRolePicker();
    });

    function openRolePicker() {
        const $modal = $('#rolePickerModal');
        if (!$modal.length) {
            // Fallback if connector isn't detected
            return;
        }
        const $body = $('#rolePickerBody');
        $body.html('<div class="text-center" style="padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
        $modal.modal('show');

        $.getJSON(ROUTES.listRoles, function (res) {
            if (!res.roles || res.roles.length === 0) {
                $body.html(`
                    <div class="alert alert-warning">
                        <strong>No roles returned from ${res.label || 'provider'}.</strong><br>
                        Enter the mention manually as <code>&lt;@&amp;ROLE_ID&gt;</code> or raw role ID.
                    </div>`);
                return;
            }

            // Count roles per source (unique by snowflake, but counted per primary tag)
            const perSource = {};
            res.roles.forEach(function (r) {
                perSource[r.source] = (perSource[r.source] || 0) + 1;
            });
            const sourceLabels = {
                // Display names follow the project's canonical naming convention:
                // "SeAT Broadcast" is the display name for the seat-discord-pings
                // package (operators see "SeAT Broadcast", not "Pings"). Internal
                // identifiers like discord-roles-table stay unchanged.
                'discord-roles-table':  'SeAT Broadcast',
                'seat-connector':       'SeAT Connector',
                'warlof-discord':       'Warlof (legacy)',
            };
            const sourceColors = {
                'discord-roles-table':  '#28a745',
                'seat-connector':       '#3498db',
                'warlof-discord':       '#95a5a6',
            };

            // Badge styling — black text on the bright accent colors gives
            // ~7:1 contrast ratio vs ~3:1 for white text. The bright tones
            // act as visual category markers and keep their color identity,
            // but the label stays readable.
            const badgeStyle = 'color:#000; font-weight:700; font-size:0.7rem; padding:2px 6px;';

            let html = '<div style="max-height:460px; overflow-y:auto;">';
            html += '<div style="font-size:0.78rem; color:#8b95a5; margin-bottom:0.5rem;">';
            html += `${res.roles.length} unique role(s) from ${Object.keys(perSource).length} source(s): `;
            html += Object.keys(perSource).map(function (s) {
                return `<span class="badge" style="background:${sourceColors[s]||'#666'}; ${badgeStyle} margin-left:3px;">${sourceLabels[s]||s}: ${perSource[s]}</span>`;
            }).join(' ');
            html += '</div>';

            // Filter controls row: search + source filter
            html += '<div style="display:flex; gap:0.4rem; margin-bottom:0.8rem;">';
            html += '<input type="text" id="roleFilter" class="form-control" placeholder="Search roles..." style="background:#1e222b; border:1px solid #454d55; color:#fff; flex-grow:1;">';
            if (Object.keys(perSource).length > 1) {
                html += '<select id="sourceFilter" class="form-control" style="background:#1e222b; border:1px solid #454d55; color:#fff; max-width:180px;">';
                html += '<option value="">All sources</option>';
                Object.keys(perSource).forEach(function (s) {
                    html += `<option value="${s}">${sourceLabels[s]||s}</option>`;
                });
                html += '</select>';
            }
            html += '</div>';

            html += '<div id="roleList" style="display:flex; flex-wrap:wrap; gap:4px;">';
            res.roles.forEach(function (r) {
                const hex = r.color && /^#[0-9a-f]{6}$/i.test(r.color) ? r.color : '';
                const dot = hex
                    ? `<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${hex}; margin-right:6px; vertical-align:middle;"></span>`
                    : '';
                const format = (r.mention_format || ('<@&' + r.id + '>')).replace(/"/g, '&quot;');
                // Source badge: small colored indicator. If role is in multiple sources, show a "+N" tag.
                // Black text + bold + larger padding keeps the label readable on
                // the bright accent colors (vs the older near-invisible thin white).
                const primarySrc = r.source;
                const alsoIn = (r.sources || []).filter(s => s !== primarySrc);
                const primaryBadge = `<span class="badge" style="background:${sourceColors[primarySrc]||'#666'}; color:#000; font-weight:700; font-size:0.65rem; padding:2px 6px; margin-left:4px; vertical-align:middle;">${sourceLabels[primarySrc]||primarySrc}</span>`;
                const extraBadge = alsoIn.length > 0
                    ? `<span class="badge badge-secondary" style="color:#fff; font-weight:600; font-size:0.65rem; padding:2px 6px; margin-left:2px;" title="Also in: ${alsoIn.map(s => sourceLabels[s]||s).join(', ')}">+${alsoIn.length}</span>`
                    : '';
                html += `<button type="button" class="btn btn-sm btn-outline-primary js-role-pick-btn"
                    data-role-id="${r.id}"
                    data-role-name="${r.name}"
                    data-mention-format="${format}"
                    data-source="${primarySrc}"
                    style="text-align:left;">
                    ${dot}${r.name}
                    <small style="opacity:0.55; margin-left:4px;">#${r.id.slice(-6)}</small>
                    ${primaryBadge}${extraBadge}
                </button>`;
            });
            html += '</div></div>';
            $body.html(html);

            const applyFilter = function () {
                const textV = ($('#roleFilter').val() || '').toLowerCase();
                const srcV  = $('#sourceFilter').val() || '';
                $('#roleList .js-role-pick-btn').each(function () {
                    const n = ($(this).data('role-name') + ' ' + $(this).data('role-id')).toLowerCase();
                    const s = $(this).data('source');
                    const matchesText = n.includes(textV);
                    const matchesSrc = !srcV || s === srcV;
                    $(this).toggle(matchesText && matchesSrc);
                });
            };
            $('#roleFilter').on('input', applyFilter);
            $('#sourceFilter').on('change', applyFilter);
        }).fail(function () {
            $body.html('<div class="alert alert-danger">Failed to load roles from Discord provider(s).</div>');
        });
    }

    $(document).on('click', '.js-role-pick-btn', function () {
        const mentionFormat = $(this).data('mention-format') || ('<@&' + $(this).data('role-id') + '>');
        if (activeRoleTarget) {
            activeRoleTarget.val(mentionFormat).trigger('blur');
        }
        $('#rolePickerModal').modal('hide');
    });

})(jQuery);
</script>
@endpush

@stop
