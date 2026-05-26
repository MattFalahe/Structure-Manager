@extends('web::layouts.grids.12')

@section('title', 'Structure Manager - Diagnostics')
@section('page_header', 'Structure Manager - Diagnostics')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* === Diagnostic page — bespoke chrome ===
       The diagnostic chrome uses an underline-tab pattern (.diag-tab),
       boxed sections (.diag-section), key-value lists (.diag-kv) and a
       danger-zone block (.diag-danger-zone). These primitives are intentionally
       NOT in canonical structure-manager.css per the migration plan — the
       audit specifically called out that SM's diagnostic chrome is "actually
       nicer than MM's", so we keep the bespoke variant in this view. */

    /* All rules below are scoped to .structure-manager-wrapper.diagnostic-page
       so they cannot leak into other Structure Manager views. */

    .structure-manager-wrapper.diagnostic-page .diag-tabs {
        display: flex;
        gap: 0;
        border-bottom: 2px solid #454d55;
        margin: 1.5rem 0 1.5rem 0;
        padding: 0;
        list-style: none;
        flex-wrap: wrap;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab {
        padding: 0.6rem 1.2rem;
        color: #8b95a5;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.15s;
        user-select: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab:hover {
        color: #c2c7d0;
        border-bottom-color: #3a4049;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab.active {
        color: var(--sm-info);
        border-bottom-color: var(--sm-info);
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab.danger.active {
        color: #e57373;
        border-bottom-color: #e57373;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab .diag-tab-count {
        font-size: 0.72rem;
        background: #454d55;
        color: #c2c7d0;
        padding: 1px 6px;
        border-radius: 8px;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab.active .diag-tab-count {
        background: rgba(23, 162, 184, 0.25);
        color: var(--sm-info);
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-pane {
        display: none;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-pane.active {
        display: block;
    }

    /* Loading spinner for lazy-loaded tabs (renders briefly during the
       redirect that populates the heavy section data). */
    .structure-manager-wrapper.diagnostic-page .diag-loading {
        padding: 2rem;
        text-align: center;
        color: #94a3b8;
        font-size: 1rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-loading i {
        margin-right: 0.5rem;
        color: #6366f1;
    }

    /* Tab intro box — sits at the top of every tab pane and explains
       what the tab is for and when to use it. Diagnostic page is not
       in Help & Documentation, so these intros are the only place
       operators learn each tab's purpose. */
    .structure-manager-wrapper.diagnostic-page .diag-tab-intro {
        padding: 0.85rem 1.1rem;
        background: rgba(99, 102, 241, 0.08);
        border-left: 3px solid #6366f1;
        border-radius: 5px;
        margin-bottom: 1.25rem;
        color: #c2c7d0;
        font-size: 0.92rem;
        line-height: 1.5;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-intro strong {
        color: #c7d2fe;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-intro p {
        margin-bottom: 0.4rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-intro p:last-child {
        margin-bottom: 0;
    }
    .structure-manager-wrapper.diagnostic-page .diag-tab-intro code {
        color: #a5b4fc;
        background: rgba(0, 0, 0, 0.25);
        padding: 0 0.25rem;
        border-radius: 3px;
    }

    .structure-manager-wrapper.diagnostic-page .diag-section {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .structure-manager-wrapper.diagnostic-page .diag-section-header {
        padding: 0.8rem 1.2rem;
        background: #343a45;
        border-bottom: 1px solid #454d55;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .structure-manager-wrapper.diagnostic-page .diag-section-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 600;
        color: #fff;
    }
    .structure-manager-wrapper.diagnostic-page .diag-section-body {
        padding: 1.2rem;
        color: #c2c7d0;
    }

    /* SEMANTIC diagnostic status badges — DO NOT CHANGE colors */
    .structure-manager-wrapper.diagnostic-page .diag-badge {
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.55rem;
        border-radius: 0.25rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .structure-manager-wrapper.diagnostic-page .diag-badge.ok      { background: #1c6f3e; color: #d4f4e2; }
    .structure-manager-wrapper.diagnostic-page .diag-badge.warn,
    .structure-manager-wrapper.diagnostic-page .diag-badge.warning { background: #7a5a0f; color: #fff1c7; }
    .structure-manager-wrapper.diagnostic-page .diag-badge.error,
    .structure-manager-wrapper.diagnostic-page .diag-badge.danger  { background: #7a1d2b; color: #fbd5db; }
    .structure-manager-wrapper.diagnostic-page .diag-badge.info    { background: #1d4d7a; color: #d0e4fb; }

    .structure-manager-wrapper.diagnostic-page .diag-msg {
        margin: 0;
        padding: 0;
        color: #e2e8f0;
        font-size: 0.95rem;
    }

    .structure-manager-wrapper.diagnostic-page .diag-detail-table {
        width: 100%;
        margin-top: 0.8rem;
        font-size: 0.85rem;
        border-collapse: collapse;
    }
    .structure-manager-wrapper.diagnostic-page .diag-detail-table th,
    .structure-manager-wrapper.diagnostic-page .diag-detail-table td {
        padding: 0.4rem 0.65rem;
        border-bottom: 1px solid #3a3f4a;
        color: #c2c7d0;
        text-align: left;
        vertical-align: top;
    }
    .structure-manager-wrapper.diagnostic-page .diag-detail-table th {
        color: #8b95a5;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.05em;
    }
    /* SEMANTIC row tints — DO NOT CHANGE */
    .structure-manager-wrapper.diagnostic-page .diag-detail-table tr.row-ok td   { }
    .structure-manager-wrapper.diagnostic-page .diag-detail-table tr.row-warn td { background: rgba(122, 90, 15, 0.08); }
    .structure-manager-wrapper.diagnostic-page .diag-detail-table tr.row-error td { background: rgba(122, 29, 43, 0.10); }

    .structure-manager-wrapper.diagnostic-page .diag-kv {
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 0.4rem 1rem;
        font-size: 0.9rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-kv dt { color: #8b95a5; font-weight: 500; }
    .structure-manager-wrapper.diagnostic-page .diag-kv dd { margin: 0; color: #e2e8f0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

    .structure-manager-wrapper.diagnostic-page .diag-summary {
        padding: 1rem 1.25rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #454d55;
        background: #2a2f3a;
        color: #e2e8f0;
    }
    /* SEMANTIC summary stripes — DO NOT CHANGE */
    .structure-manager-wrapper.diagnostic-page .diag-summary.ok    { border-left: 4px solid var(--sm-success); }
    .structure-manager-wrapper.diagnostic-page .diag-summary.warn  { border-left: 4px solid var(--sm-warning); }
    .structure-manager-wrapper.diagnostic-page .diag-summary.error { border-left: 4px solid var(--sm-danger); }

    .structure-manager-wrapper.diagnostic-page .diag-danger-zone {
        border: 2px solid #7a1d2b;
        background: rgba(122, 29, 43, 0.08);
        border-radius: 8px;
        padding: 1.25rem;
        margin-top: 2rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone h4 {
        color: #f5a8b0;
        margin-top: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone .alert {
        background: rgba(122, 29, 43, 0.15);
        border: 1px solid rgba(220, 53, 69, 0.4);
        color: #fbd5db;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone .dev-only-card {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 6px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone .dev-only-card h5 {
        color: #fff;
        margin-top: 0;
        font-size: 1rem;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone label.confirm-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0.6rem 0;
        font-size: 0.88rem;
        color: #fbd5db;
    }
    .structure-manager-wrapper.diagnostic-page .diag-danger-zone small {
        color: #8b95a5;
        font-size: 0.82rem;
    }

    .structure-manager-wrapper.diagnostic-page code {
        background: rgba(0,0,0,0.4);
        color: #8be9fd;
        padding: 0.12em 0.3em;
        border-radius: 3px;
        font-size: 0.88em;
    }
</style>
@endpush

@section('content')
<div class="structure-manager-wrapper diagnostic-page">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
            @if(session('cleanup_details') && count(session('cleanup_details')) > 0)
                <div style="margin-top:0.6rem;">
                    <div style="color:#2c3138; font-weight:600; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.3rem;">
                        Breakdown by table:
                    </div>
                    <ul style="margin:0; padding-left:1.2rem; line-height:1.55;">
                        @foreach(session('cleanup_details') as $detail)
                            <li>
                                <strong>{{ number_format($detail['count']) }}</strong>
                                — {{ $detail['label'] }}
                                <code style="font-size:0.82em; opacity:0.7;">{{ $detail['table'] }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
            @if(session('cleanup_details') && count(session('cleanup_details')) > 0)
                <div style="margin-top:0.6rem;">
                    <div style="font-weight:600; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.3rem;">
                        Partial cleanup — what did get deleted:
                    </div>
                    <ul style="margin:0; padding-left:1.2rem; line-height:1.55;">
                        @foreach(session('cleanup_details') as $detail)
                            <li>
                                <strong>{{ number_format($detail['count']) }}</strong>
                                — {{ $detail['label'] }}
                                <code style="font-size:0.82em; opacity:0.7;">{{ $detail['table'] }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- Overall summary banner --}}
    @php
        $overall = $summary['overall'];
        $overallLabel = [
            'ok'    => 'All checks passed',
            'warn'  => 'Some checks returned warnings',
            'error' => 'One or more checks failed',
        ][$overall] ?? 'Diagnostics complete';
    @endphp
    <div class="diag-summary {{ $overall }}" style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;">
        <div style="flex:1; min-width:0;">
            <h4 style="margin:0 0 0.3rem 0;">Diagnostic Summary</h4>
            <p style="margin:0;">
                <strong>{{ $overallLabel }}</strong>
                &mdash; OK: {{ $summary['counts']['ok'] }}
                &middot; Warnings: {{ $summary['counts']['warn'] }}
                &middot; Errors: {{ $summary['counts']['error'] }}
                &middot; Informational: {{ $summary['counts']['info'] }}
                ({{ $summary['total'] }} total).
            </p>
            <p style="margin:0.3rem 0 0 0; font-size:0.8rem; color:#9aa3b3;">
                Heavy sections (System Validation, Settings Health, Data Integrity, Fuel Trace catalog) are cached for 30s-5m for performance.
                Reload to refresh light checks; click <strong>Force refresh</strong> to recompute everything live.
            </p>
        </div>
        <div style="flex-shrink:0; display:flex; gap:0.4rem;">
            <a href="{{ url()->current() }}{{ request()->getQueryString() ? '?' . preg_replace('/(^|&)refresh=[^&]*/', '', request()->getQueryString()) : '' }}"
               class="btn btn-sm" style="background:#2a2f3a; color:#dfe3eb; border:1px solid #454d55; padding:0.4rem 0.8rem; border-radius:4px; text-decoration:none; white-space:nowrap;">
                <i class="fas fa-sync"></i> Reload
            </a>
            <a href="{{ url()->current() }}?refresh=1{{ request('diag_tab') ? '&diag_tab=' . request('diag_tab') : '' }}"
               class="btn btn-sm" style="background:#667eea; color:#fff; border:none; padding:0.4rem 0.8rem; border-radius:4px; text-decoration:none; white-space:nowrap;">
                <i class="fas fa-bolt"></i> Force refresh
            </a>
        </div>
    </div>

    {{-- Wrap tabs + content in card-dark so the page reads as a panel
         instead of plain text on the page background, matching the rest
         of the SM design system. --}}
    <div class="card card-dark">
        <div class="card-body">

    {{-- Diagnostic tab navigation --}}
    <ul class="diag-tabs" role="tablist">
        <li class="diag-tab active" data-diag-target="health">
            <i class="fas fa-heartbeat"></i> Health Checks
        </li>
        <li class="diag-tab" data-diag-target="type-ids">
            <i class="fas fa-database"></i> Type IDs (SDE)
        </li>
        <li class="diag-tab" data-diag-target="master-test">
            <i class="fas fa-rocket"></i> Master Test
        </li>
        <li class="diag-tab" data-diag-target="system-validation">
            <i class="fas fa-shield-alt"></i> System Validation
        </li>
        <li class="diag-tab" data-diag-target="settings-health">
            <i class="fas fa-sliders-h"></i> Settings Health
        </li>
        <li class="diag-tab" data-diag-target="data-integrity">
            <i class="fas fa-database"></i> Data Integrity
        </li>
        <li class="diag-tab" data-diag-target="fuel-trace">
            <i class="fas fa-route"></i> Fuel Trace
        </li>
        <li class="diag-tab" data-diag-target="notification-testing">
            <i class="fas fa-paper-plane"></i> Notification Testing
        </li>
        <li class="diag-tab danger" data-diag-target="notification-lab">
            <i class="fas fa-vial"></i> Notification Lab
            <span class="diag-tab-count">DEV</span>
        </li>
        <li class="diag-tab danger" data-diag-target="test-data">
            <i class="fas fa-flask"></i> Test Data
            <span class="diag-tab-count">DEV</span>
        </li>
    </ul>

    {{-- ============ HEALTH CHECKS TAB ============ --}}
    <div class="diag-tab-pane active" data-diag-pane="health">

    <div class="diag-tab-intro">
        <p>
            <strong>What this tab does:</strong> At-a-glance dashboard of plugin health. Thirteen read-only
            checks across environment, tables, type IDs, schedules, webhooks, ESI coverage, notification
            state, polling state, pricing integration, webhook delivery, and your admin context. Each shows
            <strong>OK / WARN / FAIL</strong> with a one-line message; expand any section to see the detail.
        </p>
        <p>
            <strong>When to use:</strong> First place to look when troubleshooting. The Diagnostic Summary
            banner at the very top of the page tells you whether anything needs attention. Heavy checks
            (ESI coverage, notification state, polling state, pricing, webhook delivery) are cached for 60s
            for speed — click <code>Force refresh</code> to recompute live.
        </p>
    </div>

    {{-- =================== CHECK: Environment =================== --}}
    @php $c = $checks['environment']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Environment</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <dl class="diag-kv" style="margin-top:0.8rem;">
                @foreach($c['details'] as $k => $v)
                    <dt>{{ $k }}</dt>
                    <dd>{{ is_bool($v) ? ($v ? 'true' : 'false') : (string) $v }}</dd>
                @endforeach
            </dl>
        </div>
    </div>

    {{-- =================== CHECK: Required SeAT / SDE tables =================== --}}
    @php $c = $checks['required_tables']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">SeAT &amp; SDE Tables</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @if(!empty($c['details']))
                @if(isset($c['details']['Missing']))
                    <p style="margin-top:0.6rem;"><strong>Missing tables:</strong></p>
                    <ul>
                        @foreach($c['details']['Missing'] as $t)
                            <li><code>{{ $t }}</code></li>
                        @endforeach
                    </ul>
                @else
                    <small style="color:#8b95a5;">
                        @foreach($c['details'] as $t)
                            <code>{{ $t }}</code>@if(!$loop->last), @endif
                        @endforeach
                    </small>
                @endif
            @endif
        </div>
    </div>

    {{-- =================== CHECK: Plugin tables =================== --}}
    @php $c = $checks['plugin_tables']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Plugin Tables &amp; Row Counts</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <table class="diag-detail-table">
                <thead>
                    <tr><th>Table</th><th>Rows</th><th>Oldest</th><th>Newest</th></tr>
                </thead>
                <tbody>
                    @foreach($c['details'] as $name => $info)
                        <tr class="{{ ($info['exists'] ?? false) ? 'row-ok' : 'row-error' }}">
                            <td><code>{{ $name }}</code></td>
                            <td>
                                @if($info['exists'] ?? false)
                                    {{ number_format($info['rows'] ?? 0) }}
                                @else
                                    <span class="diag-badge error">MISSING</span>
                                @endif
                            </td>
                            <td>{{ $info['oldest'] ?? '—' }}</td>
                            <td>{{ $info['newest'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Close HEALTH tab pane (Type IDs moves to its own tab below) --}}
    </div>
    {{-- ============ TYPE IDs (SDE) TAB ============ --}}
    <div class="diag-tab-pane" data-diag-pane="type-ids">

    <div class="diag-tab-intro">
        <p>
            <strong>What this tab does:</strong> Verifies that every hardcoded EVE type ID the plugin
            references — fuel blocks, magmatic gas, strontium clathrates, all six starbase charters,
            every Upwell structure type, every Metenox / Skyhook variant, every POS tower (T1 / faction /
            officer) — resolves to a real row in your SeAT SDE. Catches the <em>CCP renamed a type</em>
            and <em>SDE not yet imported</em> bug classes.
        </p>
        <p>
            <strong>When to use:</strong> After a SeAT upgrade, after refreshing the SDE, or when the
            plugin claims it can't find a structure type CCP definitely shipped (e.g. a new expansion
            added something). Cached 30 min — click <code>Force refresh</code> after running
            <code>php artisan eveapi:update:sde</code> to confirm the new IDs landed.
        </p>
    </div>

    {{-- =================== CHECK: Type IDs (headline check) =================== --}}
    @php $c = $checks['type_ids']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Type ID Verification (SDE)</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @foreach($c['details'] as $groupName => $rows)
                <h5 style="color:#fff;margin-top:1.2rem;margin-bottom:0.5rem;">{{ $groupName }}</h5>
                <table class="diag-detail-table">
                    <thead>
                        <tr>
                            <th>Type ID</th>
                            <th>Expected</th>
                            <th>SDE name</th>
                            @if($groupName === 'Control Towers')
                                <th>Modifier</th>
                            @endif
                            <th>Group</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="row-{{ $row['status'] }}">
                                <td><code>{{ $row['id'] }}</code></td>
                                <td>{{ $row['expected'] ?? '—' }}</td>
                                <td>{{ $row['actual'] ?? '—' }}</td>
                                @if($groupName === 'Control Towers')
                                    <td>
                                        @if(isset($row['modifier']))
                                            &times;{{ number_format($row['modifier'], 1) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $row['group_id'] ?? '—' }}</td>
                                <td>
                                    @if($row['status'] !== 'ok')
                                        <span class="diag-badge {{ $row['status'] }}">{{ strtoupper($row['status']) }}</span>
                                    @endif
                                    {{ $row['note'] ?? '' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    </div>

    {{-- Close TYPE-IDS tab pane, reopen HEALTH tab pane (continuation).
         The JS tab switcher toggles EVERY pane with data-diag-pane="health"
         as a group, so the two health panes before/after Type IDs behave
         as one logical tab. --}}
    </div>
    <div class="diag-tab-pane" data-diag-pane="health" style="display:none;">

    {{-- =================== CHECK: Schedules =================== --}}
    @php $c = $checks['schedules']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Scheduled Commands</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <table class="diag-detail-table">
                <thead>
                    <tr>
                        <th>Command</th>
                        <th>Expected cron</th>
                        <th>Actual cron</th>
                        <th>Last run</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($c['details'] as $row)
                        <tr class="row-{{ $row['status'] }}">
                            <td><code>{{ $row['command'] }}</code></td>
                            <td><code>{{ $row['expected'] }}</code></td>
                            <td>
                                @if($row['actual'])
                                    <code>{{ $row['actual'] }}</code>
                                @else
                                    <span class="diag-badge error">MISSING</span>
                                @endif
                            </td>
                            <td>{{ $row['last_run'] ?? '—' }}</td>
                            <td>
                                @if($row['status'] !== 'ok')
                                    {{ $row['note'] ?? '' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- =================== CHECK: Webhooks =================== --}}
    @php $c = $checks['webhooks']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Webhook Configuration</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @if(!empty($c['details']))
                <table class="diag-detail-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>URL (token masked)</th>
                            <th>Corporation</th>
                            <th>Enabled</th>
                            <th>Role mention</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($c['details'] as $row)
                            <tr class="row-{{ $row['status'] }}">
                                <td>{{ $row['id'] }}</td>
                                <td><code style="font-size:0.8em;">{{ $row['url_masked'] }}</code></td>
                                <td>{{ $row['corporation'] }}</td>
                                <td>{{ $row['enabled'] ? 'yes' : 'no' }}</td>
                                <td>{{ $row['role_mention'] ?? '—' }}</td>
                                <td>{{ $row['note'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- =================== CHECK: ESI coverage =================== --}}
    @php $c = $checks['esi_coverage']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">ESI Data Coverage (per corporation)</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @if(!empty($c['details']))
                <table class="diag-detail-table">
                    <thead>
                        <tr>
                            <th>Corporation</th>
                            <th>Structures (tracked/total)</th>
                            <th>Null fuel_expires</th>
                            <th>Structure last run</th>
                            <th>POSes (tracked/total)</th>
                            <th>POS last run</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($c['details'] as $row)
                            <tr class="row-{{ $row['status'] }}">
                                <td>{{ $row['corporation_name'] }} <small style="color:#8b95a5;">({{ $row['corporation_id'] }})</small></td>
                                <td>{{ $row['structures_tracked'] }} / {{ $row['structures_total'] }}</td>
                                <td>{{ $row['structures_null_fuel'] }}</td>
                                <td>{{ $row['structures_last_tracked'] ?? '—' }}</td>
                                <td>{{ $row['poses_tracked'] }} / {{ $row['poses_total'] }}</td>
                                <td>{{ $row['poses_last_tracked'] ?? '—' }}</td>
                                <td>{{ $row['note'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- =================== CHECK: Notification state =================== --}}
    @php $c = $checks['notification_state']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Notification State</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <dl class="diag-kv" style="margin-top:0.8rem;">
                @foreach($c['details'] as $k => $v)
                    @if(!is_array($v))
                        <dt>{{ $k }}</dt>
                        <dd>{{ $v }}</dd>
                    @endif
                @endforeach
            </dl>
            @if(isset($c['details']['Stuck latches detail']))
                <p style="margin-top:1rem;"><strong>Stuck latches (will auto-clear on next notify run):</strong></p>
                <table class="diag-detail-table">
                    <thead>
                        <tr><th>POS</th><th>Latch</th><th>Note</th></tr>
                    </thead>
                    <tbody>
                        @foreach($c['details']['Stuck latches detail'] as $row)
                            <tr class="row-warn">
                                <td>{{ $row['starbase_name'] }} <small style="color:#8b95a5;">(#{{ $row['starbase_id'] }})</small></td>
                                <td><code>{{ $row['latch_type'] }}</code></td>
                                <td>{{ $row['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- =================== CHECK: Upwell Notification State =================== --}}
    @php $c = $checks['upwell_notification_state']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Upwell Notification State</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <dl class="diag-kv" style="margin-top:0.8rem;">
                @foreach($c['details'] as $k => $v)
                    @if(!is_array($v))
                        <dt>{{ $k }}</dt>
                        <dd>{{ $v }}</dd>
                    @endif
                @endforeach
            </dl>
        </div>
    </div>

    {{-- =================== CHECK: ESI Polling State =================== --}}
    @php $c = $checks['esi_polling_state']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">ESI Notification Path</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <dl class="diag-kv" style="margin-top:0.8rem;">
                @foreach($c['details'] as $k => $v)
                    <dt>{{ $k }}</dt>
                    <dd>{{ $v }}</dd>
                @endforeach
            </dl>
        </div>
    </div>

    {{-- =================== CHECK: Pricing integration =================== --}}
    @php $c = $checks['pricing_integration']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Pricing Integration (Manager Core)</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @if(!empty($c['details']))
                <dl class="diag-kv" style="margin-top:0.8rem;">
                    @foreach($c['details'] as $k => $v)
                        <dt>{{ $k }}</dt>
                        <dd>
                            @if($k === 'configurable_at')
                                <a href="{{ url($v) }}" style="color:#667eea;">{{ $v }}</a>
                            @elseif($k === 'install_link')
                                <a href="{{ $v }}" target="_blank" style="color:#667eea;">{{ $v }}</a>
                            @else
                                {{ $v ?? '-' }}
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>

    {{-- =================== CHECK: Webhook delivery health (v2.0.0) =================== --}}
    @php $c = $checks['webhook_delivery']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Webhook Delivery Health (Last 24h)</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            @if(!empty($c['details']['webhooks']))
                <table class="table table-sm" style="margin-top:0.8rem; background:transparent;">
                    <thead>
                        <tr style="background:#2a2f3a; color:#c2c7d0;">
                            <th style="padding:0.4rem 0.6rem;">Webhook</th>
                            <th style="padding:0.4rem 0.6rem;">Scope</th>
                            <th style="padding:0.4rem 0.6rem;">Status</th>
                            <th style="padding:0.4rem 0.6rem; text-align:right;">Attempts</th>
                            <th style="padding:0.4rem 0.6rem; text-align:right;">Success rate</th>
                            <th style="padding:0.4rem 0.6rem; text-align:right;">Avg ms</th>
                            <th style="padding:0.4rem 0.6rem;">Last attempt</th>
                            <th style="padding:0.4rem 0.6rem;">Last failure</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($c['details']['webhooks'] as $wh)
                            <tr style="border-top:1px solid #3a414c;">
                                <td style="padding:0.5rem 0.6rem;">
                                    <strong>{{ $wh['label'] }}</strong>
                                    <br><small style="color:#8b95a5;">#{{ $wh['id'] }}{{ $wh['enabled'] ? '' : ' · disabled' }}</small>
                                </td>
                                <td style="padding:0.5rem 0.6rem; color:#c2c7d0;">{{ $wh['corp_scope'] }}</td>
                                <td style="padding:0.5rem 0.6rem;">
                                    @if($wh['status'] === 'ok')
                                        <span class="diag-badge ok">OK</span>
                                    @elseif($wh['status'] === 'warn')
                                        <span class="diag-badge warning">WARN</span>
                                    @elseif($wh['status'] === 'error')
                                        <span class="diag-badge danger">FAIL</span>
                                    @else
                                        <span class="diag-badge info">{{ strtoupper($wh['status']) }}</span>
                                    @endif
                                </td>
                                <td style="padding:0.5rem 0.6rem; text-align:right;">
                                    <strong>{{ $wh['attempts_24h'] }}</strong>
                                    @if($wh['attempts_24h'] > 0)
                                        <br><small style="color:#8b95a5;">{{ $wh['successes_24h'] }} ok · {{ $wh['failures_24h'] }} fail</small>
                                    @endif
                                </td>
                                <td style="padding:0.5rem 0.6rem; text-align:right;">
                                    @if($wh['success_rate'] !== null)
                                        @php
                                            $rateColor = $wh['success_rate'] >= 95 ? '#5acf85'
                                                : ($wh['success_rate'] >= 50 ? '#e0bd4f' : '#e36b6b');
                                        @endphp
                                        <strong style="color:{{ $rateColor }};">{{ $wh['success_rate'] }}%</strong>
                                    @else
                                        <span style="color:#8b95a5;">—</span>
                                    @endif
                                </td>
                                <td style="padding:0.5rem 0.6rem; text-align:right; color:#c2c7d0;">
                                    {{ $wh['avg_duration_ms'] !== null ? $wh['avg_duration_ms'] . ' ms' : '—' }}
                                </td>
                                <td style="padding:0.5rem 0.6rem; color:#c2c7d0;">
                                    {{ $wh['last_attempt_at'] ? \Carbon\Carbon::parse($wh['last_attempt_at'])->diffForHumans() : 'never' }}
                                </td>
                                <td style="padding:0.5rem 0.6rem;">
                                    @if($wh['last_failure'])
                                        <strong style="color:#e36b6b;">HTTP {{ $wh['last_failure']['status_code'] }}</strong>
                                        <br><small style="color:#8b95a5;">
                                            {{ \Carbon\Carbon::parse($wh['last_failure']['at'])->diffForHumans() }}
                                            @if($wh['last_failure']['category_key'])
                                                · {{ $wh['last_failure']['category_key'] }}
                                            @endif
                                        </small>
                                        @if($wh['last_failure']['error_short'])
                                            <br><small style="color:#9aa3b3;" title="{{ $wh['last_failure']['error_short'] }}">{{ \Illuminate\Support\Str::limit($wh['last_failure']['error_short'], 60) }}</small>
                                        @endif
                                    @else
                                        <span style="color:#8b95a5;">none</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(!empty($c['details']['telemetry_started_at']))
                    <small style="color:#8b95a5; display:block; margin-top:0.5rem;">
                        Telemetry collection started {{ \Carbon\Carbon::parse($c['details']['telemetry_started_at'])->diffForHumans() }}.
                    </small>
                @endif
            @endif
        </div>
    </div>

    {{-- =================== CHECK: Current user context =================== --}}
    @php $c = $checks['user_context']; @endphp
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Current Admin Context</h3>
            <span class="diag-badge {{ $c['status'] }}">{{ strtoupper($c['status']) }}</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">{{ $c['message'] }}</p>
            <dl class="diag-kv" style="margin-top:0.8rem;">
                @foreach($c['details'] as $k => $v)
                    <dt>{{ $k }}</dt>
                    <dd>
                        @if(is_bool($v))
                            {{ $v ? 'yes' : 'no' }}
                        @elseif(is_array($v))
                            @if(empty($v))
                                (none)
                            @else
                                <ul style="list-style: disc; padding-left: 1.2rem; margin: 0;">
                                    @foreach($v as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @else
                            {{ (string) $v }}
                        @endif
                    </dd>
                @endforeach
            </dl>
        </div>
    </div>

    {{-- Close HEALTH tab pane (second/continuation pane) --}}
    </div>

    {{-- ============ MASTER TEST TAB ============ --}}
    {{-- Deep diagnostic: aggregates the same checks Health Checks displays
         but with a pass/fail/warn count, score, and grouped by category. --}}
    <div class="diag-tab-pane" data-diag-pane="master-test">

        <div class="diag-tab-intro">
            <p>
                <strong>What this tab does:</strong> Runs every health check from the Health tab and groups
                the results by category (Schema / Runtime / Notifications / Constants) with a pass-rate
                score. Same data as Health Checks, different framing — Health is the at-a-glance dashboard,
                Master Test is the audit view where you see every check with its full status regardless of
                whether it's currently OK.
            </p>
            <p>
                <strong>When to use:</strong> Filing a bug report (the score + counts give a quick health
                snapshot), scanning for warnings that don't show on the Health summary, or eyeballing every
                check at once after a deploy. Auto-runs on page load; refresh to re-run.
            </p>
        </div>

        @php
            $mtCounts  = $summary['counts'] ?? ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
            $mtTotal   = $summary['total'] ?? count($checks);
            $mtPassed  = $mtCounts['ok'] + $mtCounts['info'];
            $mtScore   = $mtTotal > 0 ? round(($mtPassed / $mtTotal) * 100) : 100;
            $mtOverall = $summary['overall'] ?? 'ok';

            // Friendly check labels and category groupings. Each check key maps
            // to a category so the Master Test view groups them like
            // Mining Manager's diagnostic page does — easier to scan than a
            // flat list, especially as more checks are added.
            $checkLabels = [
                'environment'                 => ['label' => 'Environment',                'group' => 'Runtime'],
                'required_tables'             => ['label' => 'Required SeAT/SDE tables',   'group' => 'Schema'],
                'plugin_tables'               => ['label' => 'Plugin tables',              'group' => 'Schema'],
                'type_ids'                    => ['label' => 'Hardcoded type IDs',         'group' => 'Constants'],
                'schedules'                   => ['label' => 'Scheduled jobs',             'group' => 'Runtime'],
                'webhooks'                    => ['label' => 'Webhook configuration',      'group' => 'Notifications'],
                'esi_coverage'                => ['label' => 'ESI scope coverage',         'group' => 'Runtime'],
                'notification_state'          => ['label' => 'POS notification state',     'group' => 'Notifications'],
                'upwell_notification_state'   => ['label' => 'Upwell notification state',  'group' => 'Notifications'],
                'esi_polling_state'           => ['label' => 'ESI polling state',          'group' => 'Runtime'],
                'pricing_integration'         => ['label' => 'Pricing integration (MC)',   'group' => 'Runtime'],
                'webhook_delivery'            => ['label' => 'Webhook delivery health',    'group' => 'Notifications'],
                'user_context'                => ['label' => 'Current user context',      'group' => 'Runtime'],
            ];

            // Group checks by category for the rendering loop below
            $checksByGroup = [];
            foreach ($checks as $key => $check) {
                $meta = $checkLabels[$key] ?? ['label' => $key, 'group' => 'Other'];
                $checksByGroup[$meta['group']][] = [
                    'key'   => $key,
                    'label' => $meta['label'],
                    'check' => $check,
                ];
            }

            // Stable group order so tabs always render the same way
            $groupOrder = ['Runtime', 'Schema', 'Constants', 'Notifications', 'Other'];
            uksort($checksByGroup, function ($a, $b) use ($groupOrder) {
                return array_search($a, $groupOrder) <=> array_search($b, $groupOrder);
            });

            // Status icon + color picker
            $statusBadge = function (string $status): string {
                return match ($status) {
                    'ok'    => '<span class="diag-badge ok"><i class="fas fa-check-circle"></i> PASS</span>',
                    'warn'  => '<span class="diag-badge warning"><i class="fas fa-exclamation-triangle"></i> WARN</span>',
                    'error' => '<span class="diag-badge danger"><i class="fas fa-times-circle"></i> FAIL</span>',
                    'info'  => '<span class="diag-badge info"><i class="fas fa-info-circle"></i> INFO</span>',
                    default => '<span class="diag-badge"><i class="fas fa-question-circle"></i> '. strtoupper($status) .'</span>',
                };
            };
        @endphp

        {{-- Score banner --}}
        <div class="diag-section">
            <div class="diag-section-header">
                <h3 class="diag-section-title">Master Test</h3>
                <span class="diag-badge {{ $mtOverall === 'ok' ? 'ok' : ($mtOverall === 'warn' ? 'warning' : 'danger') }}">
                    {{ strtoupper($mtOverall) }}
                </span>
            </div>
            <div class="diag-section-body">
                <div style="display:grid; grid-template-columns: 1fr repeat(4, auto); gap:1rem; align-items:center; padding:1rem; background:#1f242c; border:1px solid #454d55; border-radius:6px;">
                    <div>
                        <div style="font-size:2.4rem; font-weight:700; line-height:1; color:#dfe3eb;">{{ $mtScore }}<span style="font-size:1rem; color:#9aa3b3;">%</span></div>
                        <div style="font-size:0.85rem; color:#9aa3b3; margin-top:0.25rem;">{{ $mtPassed }} / {{ $mtTotal }} checks passed</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#28a745;">{{ $mtCounts['ok'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Pass</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#ffc107;">{{ $mtCounts['warn'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Warn</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#dc3545;">{{ $mtCounts['error'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Fail</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#17a2b8;">{{ $mtCounts['info'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Info</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Per-category check breakdown --}}
        @foreach($checksByGroup as $groupName => $groupChecks)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">{{ $groupName }}</h3>
                    <span class="diag-badge">{{ count($groupChecks) }}</span>
                </div>
                <div class="diag-section-body">
                    @foreach($groupChecks as $entry)
                        @php
                            $check = $entry['check'];
                            $cs = $check['status'] ?? 'info';
                        @endphp
                        <div style="margin-bottom:1rem; padding:0.8rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid {{ ['ok'=>'#28a745','warn'=>'#ffc107','error'=>'#dc3545','info'=>'#17a2b8'][$cs] ?? '#6c757d' }}; border-radius:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.8rem; margin-bottom:0.4rem;">
                                <h5 style="margin:0; color:#dfe3eb; font-size:0.95rem;">{{ $entry['label'] }}</h5>
                                {!! $statusBadge($cs) !!}
                            </div>
                            <div style="color:#c2c7d0; font-size:0.88rem;">{{ $check['message'] ?? '' }}</div>
                            @if(!empty($check['details']))
                                <details style="margin-top:0.5rem;">
                                    <summary style="color:#8b95a5; font-size:0.82rem; cursor:pointer;">Detail</summary>
                                    <div style="margin-top:0.4rem; padding:0.6rem; background:#181c22; border-radius:4px; font-family:monospace; font-size:0.78rem; max-height:300px; overflow-y:auto;">
                                        @if(is_array($check['details']))
                                            @foreach($check['details'] as $k => $v)
                                                <div style="margin-bottom:0.2rem;">
                                                    @if(is_string($k))
                                                        <strong style="color:#9aa3b3;">{{ $k }}:</strong>
                                                    @endif
                                                    @if(is_array($v))
                                                        {{-- Force <pre> color via !important — AdminLTE
                                                             ships a dark default that wins via specificity. --}}
                                                        <pre style="margin:0; padding:0.4rem; background:#0f1217; border:1px solid #2a3038; border-radius:3px; color:#e6ebf5 !important; font-size:0.78rem; line-height:1.45; white-space:pre-wrap; word-break:break-word;">{{ json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    @else
                                                        <span style="color:#e6ebf5;">{{ $v }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @else
                                            <span style="color:#e6ebf5;">{{ $check['details'] }}</span>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- ============ SYSTEM VALIDATION TAB ============ --}}
    {{-- Verifies the HARDCODED constants and DEPENDENCIES the plugin relies on
         are still valid. Catches CCP renames, SeAT class removals, missing
         dependencies, and threshold ordering bugs. --}}
    <div class="diag-tab-pane" data-diag-pane="system-validation" data-lazy="{{ $systemValidation === null ? 'true' : 'false' }}">

        <div class="diag-tab-intro">
            <p>
                <strong>What this tab does:</strong> Verifies the plugin's <strong>hardcoded constants and
                dependencies</strong> are still valid. Different from Health Checks (runtime state) and
                Settings Health (operator-configured values) — this tab audits values baked into the
                plugin's source code: every type ID in <code>TypeIdRegistry</code>, fuel consumption rates,
                threshold defaults, required SeAT package versions, and the Manager Core capability
                surface SM depends on.
            </p>
            <p>
                <strong>When to use:</strong> After upgrading the plugin, after a SeAT update, after a
                Manager Core deploy, or when filing a bug report. Cached 30 min — slow on cold load,
                cheap on subsequent visits.
            </p>
        </div>

        @if($systemValidation === null)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">System Validation</h3>
                </div>
                <div class="diag-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        @else
        @php
            $svCounts  = $systemValidation['counts'] ?? ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
            $svTotal   = $systemValidation['total']  ?? 0;
            $svOverall = $systemValidation['overall'] ?? 'ok';
            $svPassed  = $svCounts['ok'] + $svCounts['info'];
            $svScore   = $svTotal > 0 ? round(($svPassed / $svTotal) * 100) : 100;

            $svBadge = function (string $status): string {
                return match ($status) {
                    'ok'    => '<span class="diag-badge ok"><i class="fas fa-check-circle"></i> PASS</span>',
                    'warn'  => '<span class="diag-badge warning"><i class="fas fa-exclamation-triangle"></i> WARN</span>',
                    'error' => '<span class="diag-badge danger"><i class="fas fa-times-circle"></i> FAIL</span>',
                    'info'  => '<span class="diag-badge info"><i class="fas fa-info-circle"></i> INFO</span>',
                    default => '<span class="diag-badge"><i class="fas fa-question-circle"></i> '. strtoupper($status) .'</span>',
                };
            };
        @endphp

        <div class="diag-section">
            <div class="diag-section-header">
                <h3 class="diag-section-title">System Validation</h3>
                <span class="diag-badge {{ $svOverall === 'ok' ? 'ok' : ($svOverall === 'warn' ? 'warning' : 'danger') }}">
                    {{ strtoupper($svOverall) }}
                </span>
            </div>
            <div class="diag-section-body">
                <p class="diag-msg">
                    Verifies the plugin's <strong>hardcoded constants and dependencies</strong> are still valid.
                    Distinct from Master Test (which inspects runtime state): this tab is the long-term safety net
                    that catches "CCP renamed a type", "SeAT removed a class", "MC version too old", and
                    "constants out of order" the moment something drifts from what the plugin expects.
                </p>

                <div style="display:grid; grid-template-columns: 1fr repeat(4, auto); gap:1rem; align-items:center; margin-top:1rem; padding:1rem; background:#1f242c; border:1px solid #454d55; border-radius:6px;">
                    <div>
                        <div style="font-size:2.4rem; font-weight:700; line-height:1; color:#dfe3eb;">{{ $svScore }}<span style="font-size:1rem; color:#9aa3b3;">%</span></div>
                        <div style="font-size:0.85rem; color:#9aa3b3; margin-top:0.25rem;">{{ $svPassed }} / {{ $svTotal }} validations passed</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#28a745;">{{ $svCounts['ok'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Pass</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#ffc107;">{{ $svCounts['warn'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Warn</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#dc3545;">{{ $svCounts['error'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Fail</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#17a2b8;">{{ $svCounts['info'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Info</div>
                    </div>
                </div>
            </div>
        </div>

        @foreach($systemValidation['groups'] ?? [] as $group)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">{{ $group['title'] }}</h3>
                    <span class="diag-badge">{{ count($group['items']) }}</span>
                </div>
                <div class="diag-section-body">
                    @if(!empty($group['description']))
                        <p class="diag-msg" style="margin-bottom:0.8rem;">{{ $group['description'] }}</p>
                    @endif
                    @foreach($group['items'] as $item)
                        @php $is = $item['status'] ?? 'info'; @endphp
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:0.8rem; padding:0.6rem 0.8rem; margin-bottom:0.4rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid {{ ['ok'=>'#28a745','warn'=>'#ffc107','error'=>'#dc3545','info'=>'#17a2b8'][$is] ?? '#6c757d' }}; border-radius:4px;">
                            <div style="flex:1; min-width:0;">
                                <div style="color:#dfe3eb; font-size:0.9rem; font-weight:600; margin-bottom:0.15rem; word-break:break-word;">{{ $item['label'] }}</div>
                                <div style="color:#c2c7d0; font-size:0.82rem;">{{ $item['message'] ?? '' }}</div>
                            </div>
                            <div style="flex-shrink:0;">{!! $svBadge($is) !!}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        @endif {{-- end if $systemValidation === null --}}
    </div>

    {{-- ============ SETTINGS HEALTH TAB ============ --}}
    {{-- Audit every setting key the plugin reads. Shows current value, default,
         changed flag, validation status, and which surfaces respect it.
         Catches the "settings drift" bug class (UI sets a value, code ignores it). --}}
    <div class="diag-tab-pane" data-diag-pane="settings-health" data-lazy="{{ $settingsHealth === null ? 'true' : 'false' }}">

        <div class="diag-tab-intro">
            <p>
                <strong>What this tab does:</strong> Audits every setting key the plugin reads. Shows
                current value, default, whether the operator has changed it, validation status, and which
                code surfaces actually respect it. Catches the <em>"I set it but nothing happened"</em>
                bug class — where the UI accepts a value but the code ignores it (settings drift between
                config + code).
            </p>
            <p>
                <strong>When to use:</strong> When a setting change doesn't seem to take effect, when an
                advice from a release note says "review your settings", or as a regular post-deploy
                sanity check. Deprecated settings sitting at their default values are hidden from the
                main list (a small footer at the bottom of the tab lists what's been suppressed).
            </p>
        </div>

        @if($settingsHealth === null)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">Settings Health</h3>
                </div>
                <div class="diag-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        @else
        @php
            $shCounts  = $settingsHealth['counts'] ?? ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
            $shTotal   = $settingsHealth['total']  ?? 0;
            $shOverall = $settingsHealth['overall'] ?? 'ok';
            $shPassed  = $shCounts['ok'] + $shCounts['info'];
            $shScore   = $shTotal > 0 ? round(($shPassed / $shTotal) * 100) : 100;

            $shBadge = function (string $status): string {
                return match ($status) {
                    'ok'    => '<span class="diag-badge ok"><i class="fas fa-check-circle"></i> OK</span>',
                    'warn'  => '<span class="diag-badge warning"><i class="fas fa-exclamation-triangle"></i> WARN</span>',
                    'error' => '<span class="diag-badge danger"><i class="fas fa-times-circle"></i> INVALID</span>',
                    'info'  => '<span class="diag-badge info"><i class="fas fa-info-circle"></i> INFO</span>',
                    default => '<span class="diag-badge"><i class="fas fa-question-circle"></i> '. strtoupper($status) .'</span>',
                };
            };
        @endphp

        <div class="diag-section">
            <div class="diag-section-header">
                <h3 class="diag-section-title">Settings Health</h3>
                <span class="diag-badge {{ $shOverall === 'ok' ? 'ok' : ($shOverall === 'warn' ? 'warning' : 'danger') }}">
                    {{ strtoupper($shOverall) }}
                </span>
            </div>
            <div class="diag-section-body">
                <p class="diag-msg">
                    Audit every setting the plugin reads. Shows current value, default, whether it has been
                    changed, and (critically) whether the value is actually <strong>respected</strong> by the
                    code or quietly ignored. This is the surface that catches "I changed the setting but
                    nothing happened" bugs the moment they appear.
                </p>

                <div style="display:grid; grid-template-columns: 1fr repeat(4, auto); gap:1rem; align-items:center; margin-top:1rem; padding:1rem; background:#1f242c; border:1px solid #454d55; border-radius:6px;">
                    <div>
                        <div style="font-size:2.4rem; font-weight:700; line-height:1; color:#dfe3eb;">{{ $shScore }}<span style="font-size:1rem; color:#9aa3b3;">%</span></div>
                        <div style="font-size:0.85rem; color:#9aa3b3; margin-top:0.25rem;">{{ $shPassed }} / {{ $shTotal }} settings healthy</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#28a745;">{{ $shCounts['ok'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">OK</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#ffc107;">{{ $shCounts['warn'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Warn</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#dc3545;">{{ $shCounts['error'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Invalid</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#17a2b8;">{{ $shCounts['info'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Info</div>
                    </div>
                </div>

                <div style="margin-top:0.6rem; font-size:0.82rem; color:#9aa3b3;">
                    Registered keys: <strong style="color:#c2c7d0;">{{ $settingsHealth['registered_count'] }}</strong>
                    &nbsp;&middot;&nbsp;
                    Rows in settings table: <strong style="color:#c2c7d0;">{{ $settingsHealth['db_row_count'] }}</strong>
                    @if(count($settingsHealth['orphans']) > 0)
                        &nbsp;&middot;&nbsp;
                        <span style="color:#ffc107;">Orphan keys: <strong>{{ count($settingsHealth['orphans']) }}</strong></span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Orphan keys block (only shown when something was found) --}}
        @if(count($settingsHealth['orphans']) > 0)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">Orphan setting keys</h3>
                    <span class="diag-badge warning">{{ count($settingsHealth['orphans']) }}</span>
                </div>
                <div class="diag-section-body">
                    <p class="diag-msg">
                        These rows exist in <code>structure_manager_settings</code> but no current code reads them.
                        They are usually leftovers from a renamed key, an old migration, or a hand-edit. Safe to
                        ignore unless they are sensitive (e.g. an old webhook URL).
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.5rem;">
                        @foreach($settingsHealth['orphans'] as $orphanKey)
                            <code style="background:#1f242c; padding:0.3rem 0.6rem; border-radius:3px; color:#ffc107;">{{ $orphanKey }}</code>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Per-category audit grid --}}
        @foreach($settingsHealth['by_category'] as $categoryName => $categoryItems)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">{{ $categoryName }}</h3>
                    <span class="diag-badge">{{ count($categoryItems) }}</span>
                </div>
                <div class="diag-section-body">
                    @foreach($categoryItems as $item)
                        @php $is = $item['status']; @endphp
                        <div style="margin-bottom:0.8rem; padding:0.7rem 0.9rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid {{ ['ok'=>'#28a745','warn'=>'#ffc107','error'=>'#dc3545','info'=>'#17a2b8'][$is] ?? '#6c757d' }}; border-radius:4px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.8rem; margin-bottom:0.4rem;">
                                <div style="flex:1;">
                                    <code style="color:#dfe3eb; font-size:0.92rem; font-weight:600;">{{ $item['key'] }}</code>
                                    @if($item['deprecated'])
                                        <span class="diag-badge warning" style="margin-left:0.4rem; font-size:0.7rem;">DEPRECATED</span>
                                    @endif
                                    @if(!$item['respected'])
                                        <span class="diag-badge info" style="margin-left:0.4rem; font-size:0.7rem;">NOT RESPECTED</span>
                                    @endif
                                    @if($item['changed'])
                                        <span class="diag-badge" style="margin-left:0.4rem; font-size:0.7rem;">CHANGED</span>
                                    @endif
                                </div>
                                <div style="flex-shrink:0;">{!! $shBadge($is) !!}</div>
                            </div>
                            <div style="display:grid; grid-template-columns:auto 1fr auto 1fr; gap:0.6rem 1rem; font-size:0.82rem; margin-top:0.4rem; align-items:center;">
                                <span style="color:#9aa3b3;">Current:</span>
                                <code style="color:{{ $item['changed'] ? '#ffc107' : '#c2c7d0' }};">{{ $item['value'] }}</code>
                                <span style="color:#9aa3b3;">Default:</span>
                                <code style="color:#8b95a5;">{{ $item['default'] }}</code>
                            </div>
                            <div style="color:#9aa3b3; font-size:0.78rem; margin-top:0.4rem;">{{ $item['description'] }}</div>
                            <div style="color:#c2c7d0; font-size:0.82rem; margin-top:0.3rem; padding-top:0.3rem; border-top:1px dashed #454d55;">
                                <strong style="color:#9aa3b3;">Status:</strong> {{ $item['status_msg'] }}
                            </div>
                            @if(!empty($item['respected_by']))
                                <details style="margin-top:0.4rem;">
                                    <summary style="color:#8b95a5; font-size:0.78rem; cursor:pointer;">Respected by ({{ count($item['respected_by']) }})</summary>
                                    <ul style="margin:0.3rem 0 0 1.2rem; padding:0; color:#c2c7d0; font-size:0.78rem;">
                                        @foreach($item['respected_by'] as $surface)
                                            <li><code>{{ $surface }}</code></li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Quiet footer when deprecated-at-default settings have been
             hidden from the main listing. They're still tracked - the
             audit will WARN the moment an operator sets a value on them -
             but at default they're pure noise. --}}
        @if(!empty($settingsHealth['hidden_deprecated']))
            <div style="margin-top:1rem; padding:0.6rem 0.8rem; background:#1f2530; border:1px dashed #454d55; border-radius:6px; color:#8b95a5; font-size:0.78rem;">
                <i class="fas fa-info-circle" style="color:#6366f1; margin-right:0.4rem;"></i>
                Hidden {{ count($settingsHealth['hidden_deprecated']) }} deprecated setting(s) at default value:
                <code style="color:#a5b4fc;">{{ implode(', ', $settingsHealth['hidden_deprecated']) }}</code>.
                Replaced by newer surfaces — kept under audit so a warning fires if an operator accidentally sets a value, but hidden here because there's nothing to act on.
            </div>
        @endif
        @endif {{-- end if $settingsHealth === null --}}
    </div>

    {{-- ============ DATA INTEGRITY TAB ============ --}}
    {{-- DB-level consistency: row counts, FK orphans, stale dedup rows,
         failed jobs, settings table integrity. All read-only. --}}
    <div class="diag-tab-pane" data-diag-pane="data-integrity" data-lazy="{{ $dataIntegrity === null ? 'true' : 'false' }}">

        <div class="diag-tab-intro">
            <p>
                <strong>What this tab does:</strong> Read-only DB-level consistency checks across every
                plugin-owned table. Counts rows, flags FK orphans (history rows pointing at deleted
                structures), reports stale dedup entries that should have been pruned, and lists failed
                jobs in the Laravel queue.
            </p>
            <p>
                <strong>When to use:</strong> When fuel history feels stuck, notifications stop firing,
                after a long downtime / migration when you want to verify the tables look healthy, or
                when investigating an "old data didn't clean up" complaint. Cached 5 min — heavy queries
                so refreshes are not free.
            </p>
        </div>

        @if($dataIntegrity === null)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">Data Integrity</h3>
                </div>
                <div class="diag-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        @else
        @php
            $diCounts  = $dataIntegrity['counts'] ?? ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
            $diTotal   = $dataIntegrity['total']  ?? 0;
            $diOverall = $dataIntegrity['overall'] ?? 'ok';
            $diPassed  = $diCounts['ok'] + $diCounts['info'];
            $diScore   = $diTotal > 0 ? round(($diPassed / $diTotal) * 100) : 100;

            $diBadge = function (string $status): string {
                return match ($status) {
                    'ok'    => '<span class="diag-badge ok"><i class="fas fa-check-circle"></i> CLEAN</span>',
                    'warn'  => '<span class="diag-badge warning"><i class="fas fa-exclamation-triangle"></i> WARN</span>',
                    'error' => '<span class="diag-badge danger"><i class="fas fa-times-circle"></i> FAIL</span>',
                    'info'  => '<span class="diag-badge info"><i class="fas fa-info-circle"></i> INFO</span>',
                    default => '<span class="diag-badge"><i class="fas fa-question-circle"></i> '. strtoupper($status) .'</span>',
                };
            };
        @endphp

        <div class="diag-section">
            <div class="diag-section-header">
                <h3 class="diag-section-title">Data Integrity</h3>
                <span class="diag-badge {{ $diOverall === 'ok' ? 'ok' : ($diOverall === 'warn' ? 'warning' : 'danger') }}">
                    {{ strtoupper($diOverall) }}
                </span>
            </div>
            <div class="diag-section-body">
                <p class="diag-msg">
                    Read-only DB-level consistency checks. Walks every plugin-owned table to count rows,
                    flag foreign-key orphans (rows whose parent was deleted), surface stale dedup rows
                    that should have been cleaned up, and report failed-job accumulation.
                    Useful for spotting "cleanup-history is not running" or "a structure was abandoned but
                    its history piled up" before they cause downstream noise.
                </p>

                <div style="display:grid; grid-template-columns: 1fr repeat(4, auto); gap:1rem; align-items:center; margin-top:1rem; padding:1rem; background:#1f242c; border:1px solid #454d55; border-radius:6px;">
                    <div>
                        <div style="font-size:2.4rem; font-weight:700; line-height:1; color:#dfe3eb;">{{ $diScore }}<span style="font-size:1rem; color:#9aa3b3;">%</span></div>
                        <div style="font-size:0.85rem; color:#9aa3b3; margin-top:0.25rem;">{{ $diPassed }} / {{ $diTotal }} checks clean</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#28a745;">{{ $diCounts['ok'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Clean</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#ffc107;">{{ $diCounts['warn'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Warn</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#dc3545;">{{ $diCounts['error'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Fail</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:1.6rem; font-weight:600; color:#17a2b8;">{{ $diCounts['info'] }}</div>
                        <div style="font-size:0.78rem; color:#9aa3b3; text-transform:uppercase;">Info</div>
                    </div>
                </div>
            </div>
        </div>

        @foreach($dataIntegrity['groups'] ?? [] as $group)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">{{ $group['title'] }}</h3>
                    <span class="diag-badge">{{ count($group['items']) }}</span>
                </div>
                <div class="diag-section-body">
                    @if(!empty($group['description']))
                        <p class="diag-msg" style="margin-bottom:0.8rem;">{{ $group['description'] }}</p>
                    @endif
                    @foreach($group['items'] as $item)
                        @php $is = $item['status'] ?? 'info'; @endphp
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:0.8rem; padding:0.6rem 0.8rem; margin-bottom:0.4rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid {{ ['ok'=>'#28a745','warn'=>'#ffc107','error'=>'#dc3545','info'=>'#17a2b8'][$is] ?? '#6c757d' }}; border-radius:4px;">
                            <div style="flex:1; min-width:0;">
                                <div style="color:#dfe3eb; font-size:0.9rem; font-weight:600; margin-bottom:0.15rem; word-break:break-word;">
                                    @if(strpos($item['label'], '_') !== false || strpos($item['label'], 'orphan') !== false)
                                        <code>{{ $item['label'] }}</code>
                                    @else
                                        {{ $item['label'] }}
                                    @endif
                                </div>
                                <div style="color:#c2c7d0; font-size:0.82rem;">{{ $item['message'] ?? '' }}</div>
                            </div>
                            <div style="flex-shrink:0;">{!! $diBadge($is) !!}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        @endif {{-- end if $dataIntegrity === null --}}
    </div>

    {{-- ============ FUEL TRACE TAB ============ --}}
    {{-- Pick one structure or POS, walk the fuel pipeline showing what
         the plugin sees and would do for that specific row. The most
         powerful "why is this not alerting" debugging surface.

         Lazy-loaded: the catalog is only built when the controller knows
         the user is on this tab (URL ?diag_tab=fuel-trace) or has explicitly
         requested a trace. When `data-lazy="true"`, the JS at the bottom of
         the page auto-redirects with the diag_tab param so the server fills
         the catalog. This avoids a 4-table join on every diagnostic page
         load when nobody is looking at this tab. --}}
    <div class="diag-tab-pane" data-diag-pane="fuel-trace" data-lazy="{{ $traceCatalog === null ? 'true' : 'false' }}">

        <div class="diag-tab-intro">
            <p>
                <strong>What this tab does:</strong> Pick one structure or POS, then walk through the
                entire fuel pipeline as the plugin sees it. Each step shows what the code finds + what
                it would do: input row → universe context → reserves snapshot → fuel history → event
                classification (v2) → forensic candidates (v2) → threshold determination → notification
                gate → recent ESI dedup entries. The deepest "why isn't this alerting / why is this row
                behaving like that" debugging surface.
            </p>
            <p>
                <strong>When to use:</strong> An operator reports "I didn't get the alert for X" — pick X
                here and the trace tells you exactly why (threshold not crossed yet, notification gate
                disabled, dedup row blocking re-fire, etc.). Catalog is cached 5 min; per-entity trace is
                live every time so no stale data.
            </p>
        </div>

        @if($traceCatalog === null)
            <div class="diag-section">
                <div class="diag-section-body">
                    <p class="diag-msg" style="color:#8b95a5;">
                        <i class="fas fa-spinner fa-spin"></i> Loading catalog...
                    </p>
                    <noscript>
                        <p class="diag-msg" style="color:#ffc107;">
                            JavaScript is disabled. <a href="?diag_tab=fuel-trace" style="color:#667eea;">Click here</a> to load the Fuel Trace catalog.
                        </p>
                    </noscript>
                </div>
            </div>
        @else
        @php
            $catalogItems  = $traceCatalog['items'] ?? [];
            $catalogCap    = $traceCatalog['cap'] ?? 0;
            $upwellOptions = collect($catalogItems)->where('type', 'upwell')->values();
            $posOptions    = collect($catalogItems)->where('type', 'pos')->values();
            $upwellTotal   = $traceCatalog['upwell_total'] ?? $upwellOptions->count();
            $posTotal      = $traceCatalog['pos_total'] ?? $posOptions->count();
            $upwellShown   = $traceCatalog['upwell_shown'] ?? $upwellOptions->count();
            $posShown      = $traceCatalog['pos_shown'] ?? $posOptions->count();
            $upwellTrunc   = !empty($traceCatalog['upwell_truncated']);
            $posTrunc      = !empty($traceCatalog['pos_truncated']);
            $anyTrunc      = $upwellTrunc || $posTrunc;

            $ftBadge = function (string $status): string {
                return match ($status) {
                    'ok'    => '<span class="diag-badge ok"><i class="fas fa-check-circle"></i> OK</span>',
                    'warn'  => '<span class="diag-badge warning"><i class="fas fa-exclamation-triangle"></i> WARN</span>',
                    'error' => '<span class="diag-badge danger"><i class="fas fa-times-circle"></i> FAIL</span>',
                    'info'  => '<span class="diag-badge info"><i class="fas fa-info-circle"></i> INFO</span>',
                    default => '<span class="diag-badge"><i class="fas fa-question-circle"></i> '. strtoupper($status) .'</span>',
                };
            };
        @endphp

        <div class="diag-section">
            <div class="diag-section-header">
                <h3 class="diag-section-title">Fuel Trace</h3>
                <span class="diag-badge info">DEBUG</span>
            </div>
            <div class="diag-section-body">
                <p class="diag-msg">
                    Pick one structure or POS, then walk through the entire fuel pipeline as the plugin sees it
                    right now. Surfaces input data, threshold logic, notification gates, recent firings, and the
                    dedup table state. Use this when an admin asks "why didn't I get alerted about X" or
                    "why does the board show Y" for a specific row.
                </p>

                {{-- Catalog inventory + truncation warning. Always visible so the
                     admin knows whether the dropdown is complete or capped. --}}
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin:0.8rem 0; padding:0.6rem 0.8rem; background:{{ $anyTrunc ? '#3a2e15' : '#1f242c' }}; border:1px solid {{ $anyTrunc ? '#ffc107' : '#454d55' }}; border-radius:6px;">
                    <i class="fas {{ $anyTrunc ? 'fa-exclamation-triangle' : 'fa-info-circle' }}" style="color:{{ $anyTrunc ? '#ffc107' : '#17a2b8' }};"></i>
                    <div style="flex:1; font-size:0.85rem; color:#dfe3eb;">
                        Catalog inventory:
                        <strong>
                            @if($upwellTrunc)
                                <span style="color:#ffc107;">{{ number_format($upwellShown) }} of {{ number_format($upwellTotal) }}</span>
                            @else
                                {{ number_format($upwellTotal) }}
                            @endif
                        </strong> Upwell structures,
                        <strong>
                            @if($posTrunc)
                                <span style="color:#ffc107;">{{ number_format($posShown) }} of {{ number_format($posTotal) }}</span>
                            @else
                                {{ number_format($posTotal) }}
                            @endif
                        </strong> POSes
                        @if($anyTrunc)
                            <span style="color:#ffc107;">&nbsp;&middot; truncated at {{ number_format($catalogCap) }} per type for performance.</span>
                        @endif
                    </div>
                </div>

                @if($anyTrunc)
                    <p class="diag-msg" style="color:#ffc107; font-size:0.82rem; margin:0 0 0.8rem 0;">
                        <strong>Heads up:</strong> the dropdown is showing the alphabetically-first
                        {{ number_format($catalogCap) }} entries of each type. If the entity you want to trace is
                        not in the list, contact the plugin author and a name-search filter can be added.
                    </p>
                @endif

                <form method="GET" style="display:grid; grid-template-columns: auto 1fr auto; gap:0.6rem; align-items:center; margin-top:0.4rem;">
                    <input type="hidden" name="diag_tab" value="fuel-trace">
                    <label style="color:#dfe3eb; font-weight:600;">Pick a structure:</label>
                    <select name="trace_target" style="background:#1f242c; color:#dfe3eb; border:1px solid #454d55; padding:0.4rem 0.6rem; border-radius:4px;">
                        <option value="">Select a structure or POS...</option>
                        @if($upwellOptions->isNotEmpty())
                            <optgroup label="Upwell structures ({{ $upwellOptions->count() }}{{ $upwellTrunc ? ' shown of ' . number_format($upwellTotal) : '' }})">
                                @foreach($upwellOptions as $opt)
                                    @php
                                        $optKey = 'upwell:' . $opt['id'];
                                        $selected = ($fuelTrace && $fuelTrace['type'] === 'upwell' && $fuelTrace['id'] === $opt['id']);
                                    @endphp
                                    <option value="{{ $optKey }}" {{ $selected ? 'selected' : '' }}>
                                        {{ $opt['name'] }} ({{ $opt['subtitle'] }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if($posOptions->isNotEmpty())
                            <optgroup label="POSes ({{ $posOptions->count() }}{{ $posTrunc ? ' shown of ' . number_format($posTotal) : '' }})">
                                @foreach($posOptions as $opt)
                                    @php
                                        $optKey = 'pos:' . $opt['id'];
                                        $selected = ($fuelTrace && $fuelTrace['type'] === 'pos' && $fuelTrace['id'] === $opt['id']);
                                    @endphp
                                    <option value="{{ $optKey }}" {{ $selected ? 'selected' : '' }}>
                                        {{ $opt['name'] }} ({{ $opt['subtitle'] }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                    <button type="submit" class="btn btn-sm btn-sm-primary" style="background:#667eea; border:none; color:#fff; padding:0.45rem 1rem; border-radius:4px;">
                        <i class="fas fa-route"></i> Run trace
                    </button>
                </form>
            </div>
        </div>

        {{-- Hidden helpers so the form submits the right query params. The select
             value is "upwell:12345" or "pos:67890"; we split it client-side
             and submit trace_id + trace_type. --}}
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.querySelector('[data-diag-pane="fuel-trace"] form');
                if (!form) return;
                form.addEventListener('submit', function (e) {
                    var sel = form.querySelector('select[name="trace_target"]');
                    if (!sel || !sel.value) { e.preventDefault(); return; }
                    var parts = sel.value.split(':');
                    if (parts.length !== 2) { e.preventDefault(); return; }
                    // Replace the form fields with trace_type + trace_id
                    var existing = form.querySelectorAll('input[name="trace_id"], input[name="trace_type"]');
                    existing.forEach(function (n) { n.remove(); });
                    var t = document.createElement('input'); t.type = 'hidden'; t.name = 'trace_type'; t.value = parts[0];
                    var i = document.createElement('input'); i.type = 'hidden'; i.name = 'trace_id';   i.value = parts[1];
                    form.appendChild(t); form.appendChild(i);
                    sel.disabled = true; // do not submit the combined value
                });
            });
        </script>

        @if($fuelTrace)
            <div class="diag-section">
                <div class="diag-section-header">
                    <h3 class="diag-section-title">{{ $fuelTrace['name'] }}</h3>
                    <span class="diag-badge info">{{ strtoupper($fuelTrace['type']) }} #{{ $fuelTrace['id'] }}</span>
                </div>
                <div class="diag-section-body">
                    @foreach($fuelTrace['steps'] as $idx => $step)
                        @php $is = $step['status'] ?? 'info'; @endphp
                        <div style="margin-bottom:0.8rem; padding:0.7rem 0.9rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid {{ ['ok'=>'#28a745','warn'=>'#ffc107','error'=>'#dc3545','info'=>'#17a2b8'][$is] ?? '#6c757d' }}; border-radius:4px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.8rem; margin-bottom:0.4rem;">
                                <h5 style="margin:0; color:#dfe3eb; font-size:0.95rem;">{{ $step['title'] }}</h5>
                                {!! $ftBadge($is) !!}
                            </div>
                            <div style="color:#c2c7d0; font-size:0.86rem;">{{ $step['message'] ?? '' }}</div>
                            @if(!empty($step['details']))
                                <details style="margin-top:0.5rem;">
                                    <summary style="color:#8b95a5; font-size:0.78rem; cursor:pointer;">Raw details</summary>
                                    <div style="margin-top:0.4rem; padding:0.6rem; background:#181c22; border-radius:4px; font-family:monospace; font-size:0.78rem; max-height:400px; overflow:auto;">
                                        @if(is_array($step['details']))
                                            @php
                                                // PHP 8.0-safe alternative to array_is_list:
                                                // a list has consecutive integer keys 0..n-1.
                                                $isNumericList = $step['details'] === []
                                                    || (array_keys($step['details']) === range(0, count($step['details']) - 1));
                                            @endphp
                                            @if($isNumericList)
                                                {{-- numeric-keyed list of rows. Force the
                                                     <pre> color with !important — AdminLTE
                                                     ships a dark default that wins via
                                                     specificity otherwise. --}}
                                                @foreach($step['details'] as $rowDetail)
                                                    <pre style="margin:0 0 0.6rem 0; padding:0.5rem; background:#0f1217; border:1px solid #2a3038; border-radius:3px; color:#e6ebf5 !important; font-size:0.78rem; line-height:1.45; white-space:pre-wrap; word-break:break-word;">{{ json_encode($rowDetail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                @endforeach
                                            @else
                                                <pre style="margin:0; padding:0.5rem; background:#0f1217; border:1px solid #2a3038; border-radius:3px; color:#e6ebf5 !important; font-size:0.78rem; line-height:1.45; white-space:pre-wrap; word-break:break-word;">{{ json_encode($step['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            @endif
                                        @else
                                            <span style="color:#e6ebf5;">{{ $step['details'] }}</span>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="diag-section">
                <div class="diag-section-body">
                    <p class="diag-msg" style="color:#8b95a5; font-style:italic;">
                        Pick a structure or POS above and click Run trace to see the pipeline output.
                    </p>
                </div>
            </div>
        @endif
        @endif {{-- /traceCatalog === null --}}
    </div>

    {{-- ============ NOTIFICATION TESTING TAB ============ --}}
    <div class="diag-tab-pane" data-diag-pane="notification-testing">

    <div class="diag-tab-intro">
        <p>
            <strong>What this tab does:</strong> Manually triggers the real cron jobs on demand. Each
            button below dispatches the SAME job the scheduler runs — against your real structures,
            POSes, and ESI notifications, sending to your configured production webhooks. No synthetic
            data anywhere.
        </p>
        <p>
            <strong>When to use:</strong> Verifying the live pipeline end-to-end without waiting for
            the next 10-min / hourly / daily cron tick. After fixing a webhook URL, after a new
            structure is added, after changing thresholds — click the relevant button to see whether
            the change works in production.
        </p>
        <p>
            <strong>For sample previews or synthetic injections,</strong> use the
            <strong>Notification Lab</strong> tab instead. That tab is for fake-data scenarios; this
            one is real ammo only.
        </p>
    </div>

    {{-- =================================================================== --}}
    {{-- NOTIFICATION TESTING                                                 --}}
    {{-- =================================================================== --}}
    <div class="diag-section">
        <div class="diag-section-header">
            <h3 class="diag-section-title">Notification Testing</h3>
            <span class="diag-badge info">TOOLS</span>
        </div>
        <div class="diag-section-body">
            <p class="diag-msg">
                Three triggers below — each runs a real job against real data. Confirm with the checkbox,
                click the button, watch your webhooks for the result.
            </p>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">

                {{-- Run Upwell Notification Check --}}
                <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem;">
                    <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">Run Upwell Notification Check</h5>
                    <small style="color:#8b95a5;">
                        Dispatches the <code>NotifyUpwellLowFuel</code> job against your real structures.
                        Any structure currently below your configured thresholds will fire a real alert to configured webhooks.
                    </small>
                    <form method="POST" action="{{ route('structure-manager.diagnostic.notify.upwell') }}" style="margin-top:0.8rem;">
                        @csrf
                        <label style="display:flex; align-items:center; gap:0.5rem; margin:0.5rem 0; font-size:0.85rem; color:#c2c7d0;">
                            <input type="checkbox" name="confirm" value="yes" required>
                            I understand this sends real notifications
                        </label>
                        <button type="submit" class="btn btn-sm-primary btn-sm">
                            <i class="fas fa-building"></i> Check Upwell Structures
                        </button>
                    </form>
                </div>

                {{-- Run POS Notification Check --}}
                <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem;">
                    <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">Run POS Notification Check</h5>
                    <small style="color:#8b95a5;">
                        Dispatches the <code>NotifyPosLowFuel</code> job against your real POSes.
                        Any POS currently below configured thresholds will fire a real alert to configured webhooks.
                    </small>
                    <form method="POST" action="{{ route('structure-manager.diagnostic.notify.pos') }}" style="margin-top:0.8rem;">
                        @csrf
                        <label style="display:flex; align-items:center; gap:0.5rem; margin:0.5rem 0; font-size:0.85rem; color:#c2c7d0;">
                            <input type="checkbox" name="confirm" value="yes" required>
                            I understand this sends real notifications
                        </label>
                        <button type="submit" class="btn btn-sm-primary btn-sm">
                            <i class="fas fa-broadcast-tower"></i> Check POSes
                        </button>
                    </form>
                </div>
            </div>

                {{-- Run ESI Poll / Fallback Now --}}
                <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem;">
                    <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">Run Notification Job Now</h5>
                    <small style="color:#8b95a5;">
                        @if(\StructureManager\Integrations\ManagerCoreIntegration::isAvailable())
                            Dispatches Manager Core's <code>manager-core:poll-esi-notifications</code> fast-poll.
                            Polls the next key holder(s) in the shared rotation and dispatches any new events to registered handlers.
                        @else
                            Dispatches <code>structure-manager:process-notifications</code>. Reads SeAT's native
                            <code>character_notifications</code> table and processes any new structure events.
                        @endif
                    </small>
                    <form method="POST" action="{{ route('structure-manager.diagnostic.notify.esi-poll') }}" style="margin-top:0.8rem;">
                        @csrf
                        <label style="display:flex; align-items:center; gap:0.5rem; margin:0.5rem 0; font-size:0.85rem; color:#c2c7d0;">
                            <input type="checkbox" name="confirm" value="yes" required>
                            I understand this may send real notifications
                        </label>
                        <button type="submit" class="btn btn-warning btn-sm">
                            <i class="fas fa-satellite-dish"></i> Run Now
                        </button>
                    </form>
                </div>
            </div>

            {{-- Send Sample Upwell Alert form lives in Notification Lab now (synthetic-data action
                 belongs alongside the other fake-injection paths). This tab only contains
                 real-job triggers — buttons that dispatch the actual cron jobs against real
                 structures + production webhooks. --}}
        </div>
    </div>

    {{-- Close NOTIFICATION TESTING tab pane --}}
    </div>
    {{-- ============ NOTIFICATION LAB TAB ============ --}}
    <div class="diag-tab-pane" data-diag-pane="notification-lab">

    <div class="diag-tab-intro">
        <p>
            <strong>What this tab does:</strong> Synthetic notification testing. Inject fake CCP-shaped
            notification rows that walk the FULL dispatch pipeline (Structure Board upsert → EventBus
            publish → Discord webhook embed) end-to-end. Use this to verify your webhook bindings,
            role mentions, and embed formatting without waiting for a real structure to come under
            attack or run low on fuel. Also includes a one-shot "Send Sample Upwell Alert" preview that
            posts a fake embed directly to a webhook you select.
        </p>
        <p>
            <strong>When to use:</strong> Verifying a new webhook landed in the right Discord channel,
            previewing how an embed will render before the real event fires, debugging "why didn't the
            board update" or "why did role X not get pinged". All synthetic data lives in safe ID
            ranges (structures 2.3B, characters 2.4B, notifications 8e18) that can never collide with
            real EVE data.
        </p>
        <p>
            <strong>Heads up:</strong> Read the red warning below before clicking anything — without a
            Test webhook URL set, fake injections WILL hit your real Discord channels with a
            <code>[TEST INJECTION]</code> banner.
        </p>
    </div>

    {{-- =================================================================== --}}
    {{-- TEST NOTIFICATION LAB                                                --}}
    {{-- Generate fake structures + inject fake CCP notifications to verify   --}}
    {{-- the SM dispatch pipeline (board → EventBus → webhook embed) end to   --}}
    {{-- end without waiting for a real attack. All test data lives in safe   --}}
    {{-- ID ranges enforced by TestDataGenerator.                             --}}
    {{-- =================================================================== --}}
    <div class="diag-danger-zone">
        <h4><i class="fas fa-exclamation-triangle"></i> Test Notification Lab — Development Only</h4>

        <div class="alert">
            <strong>Warning:</strong> This tab injects synthetic CCP-shaped notification rows
            into <code>character_notifications</code> and walks them through the FULL Structure
            Manager dispatch pipeline (Structure Board upsert, EventBus publish, Discord
            webhook embed). When a <strong>Test webhook URL</strong> is set, fake traffic
            routes there ONLY (stamped with a <code>[TEST INJECTION]</code> banner) and
            production webhook bindings are skipped. If you leave it empty, the webhook
            dispatch is skipped entirely: the Structure Board upsert and EventBus publish
            still fire, but no Discord embed goes out. Fake test structures and injected
            notification rows live in safe ID ranges
            <em>(structures 2.3B, characters 2.4B, notifications 8e18)</em>, but they WILL
            appear in the Structure Board and Recent Notifications UI until you clean them up.
            <strong>Always set the Test webhook URL before injecting on a production install,
            or do not use this tab there at all.</strong>
        </div>

        <div class="dev-only-card">
            <p class="diag-msg" style="margin-top:0;">
                Generate fake structures and inject CCP-shaped notifications to verify the
                Structure Manager dispatch pipeline (Structure Board upsert, EventBus publish,
                Discord webhook embed) end-to-end. All test data lives in safe ID ranges
                <em>(structures 2.3B, characters 2.4B, notifications 8e18)</em> and cannot
                touch real ESI data.
            </p>

            {{-- ----- Test webhook URL ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-link"></i> Test webhook URL
                </h5>
                <small style="color:#8b95a5;">
                    Discord/Slack webhook URL that will receive ALL fake-injected notifications.
                    When set, test injections route ONLY here (production webhook bindings are
                    skipped) so test traffic can never accidentally hit real channels. Embeds
                    are stamped with a <code>[TEST INJECTION]</code> banner.
                </small>
                <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.test-webhook') }}" style="margin-top:0.8rem;">
                    @csrf
                    <input type="hidden" name="confirm" value="yes">
                    <div style="display:flex; gap:0.6rem; align-items:stretch;">
                        <input type="url" name="test_webhook_url" class="form-control"
                               value="{{ $testLab['test_webhook_url'] ?? '' }}"
                               placeholder="https://discord.com/api/webhooks/... (leave empty to clear)"
                               style="flex:1; background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                        <button type="submit" class="btn btn-sm btn-primary" style="white-space:nowrap;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
            </div>

            {{-- ----- Inventory snapshot ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-clipboard-list"></i> Test data inventory
                </h5>
                <dl class="diag-kv">
                    <dt>Test corporations (2.1B)</dt>
                    <dd>{{ $testLab['inventory']['test_corps'] }}</dd>
                    <dt>Test characters (2.4B)</dt>
                    <dd>{{ $testLab['inventory']['test_characters'] }}</dd>
                    <dt>Test Upwell structures (2.3B)</dt>
                    <dd>{{ $testLab['inventory']['test_upwell_structures'] }} / 12</dd>
                    <dt>Legacy Metenox/Astrahus</dt>
                    <dd>{{ $testLab['inventory']['legacy_structures'] }}</dd>
                    <dt>Test POSes (2.2B)</dt>
                    <dd>{{ $testLab['inventory']['test_poses'] }}</dd>
                    <dt>Pending test notifications</dt>
                    <dd>{{ $testLab['inventory']['test_notifications'] }}</dd>
                </dl>
            </div>

            {{-- ----- Generate test structures ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-magic"></i> Generate test Upwell structures
                </h5>
                <small style="color:#8b95a5;">
                    Runs <code>structure-manager:create-test-upwell-structures</code>. Creates one of
                    each Upwell type (Astrahus, Fortizar, Keepstar, Raitaru, Azbel, Sotiyo, Athanor, Tatara,
                    Metenox, Ansiblex, Pharolux, Tenebrex) plus a test corporation and primary test character.
                    Fully idempotent — re-running just refreshes fuel timers.
                </small>
                <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.upwell-structures') }}" style="margin-top:0.8rem;">
                    @csrf
                    <div class="form-group" style="margin-bottom:0.6rem;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">Optional: subset (comma-separated slugs)</label>
                        <input type="text" name="types" class="form-control"
                               placeholder="e.g. astrahus,fortizar,metenox (leave empty = all 12)"
                               style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                    </div>
                    <label class="confirm-label">
                        <input type="checkbox" name="confirm" value="yes" required>
                        I understand this inserts synthetic rows.
                    </label>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="fas fa-play"></i> Generate test structures
                    </button>
                </form>
            </div>

            {{-- ----- Inject notification ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-syringe"></i> Inject fake notification
                </h5>
                <small style="color:#8b95a5;">
                    Pick a target structure, optionally tweak attacker context, then click any of the
                    24 notification-type buttons below. The fake notification is written to SeAT's
                    <code>character_notifications</code> table; <code>structure-manager:process-notifications</code>
                    picks it up within ~60 seconds and dispatches via the same code path as a real CCP notification.
                </small>

                <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.inject-notification') }}"
                      id="diag-test-inject-form" style="margin-top:0.8rem;">
                    @csrf
                    <input type="hidden" name="confirm" value="yes">
                    <input type="hidden" name="type" id="diag-test-inject-type" value="">

                    {{-- Target structure picker --}}
                    <div class="form-group" style="margin-bottom:0.6rem;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">Target structure (test range only)</label>
                        <select name="structure_id" class="form-control" required
                                style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            @foreach($testLab['structures'] as $s)
                                <option value="{{ $s['structure_id'] }}" @if(!$s['exists']) disabled @endif>
                                    {{ $s['display_name'] ?? ('TEST - ' . $s['name'] . ' (NOT YET CREATED)') }}
                                    — #{{ $s['structure_id'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Optional attacker context --}}
                    <details style="margin:0.6rem 0;">
                        <summary style="color:#8b95a5; font-size:0.85rem; cursor:pointer;">
                            Advanced options (attacker corp / alliance / timer duration)
                        </summary>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.6rem; margin-top:0.6rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="color:#c2c7d0;font-size:0.82rem;">Attacker corp name</label>
                                <input type="text" name="attacker_corp" class="form-control"
                                       placeholder="Test Aggressor Corp"
                                       style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="color:#c2c7d0;font-size:0.82rem;">Attacker alliance name</label>
                                <input type="text" name="attacker_alliance" class="form-control"
                                       placeholder="(omit for unaffiliated)"
                                       style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="color:#c2c7d0;font-size:0.82rem;">Attacker alliance ID</label>
                                <input type="number" name="attacker_alliance_id" class="form-control"
                                       placeholder="(numeric, only if alliance is set)"
                                       style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="color:#c2c7d0;font-size:0.82rem;">Timer duration (seconds)</label>
                                <input type="number" name="time_left_seconds" class="form-control" min="60" max="2592000"
                                       placeholder="86400 (24h) — for reinforce / anchor / unanchor"
                                       style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            </div>
                        </div>
                    </details>

                    {{-- Confirmation checkbox (also hidden via the hidden field above so each click works without re-checking) --}}
                    <label class="confirm-label" style="margin-bottom:0.8rem;">
                        <input type="checkbox" id="diag-test-inject-confirm-visual" checked disabled>
                        Confirmation pre-set: each button click submits with confirm=yes.
                    </label>

                    {{-- 24 notification type buttons grouped by family --}}
                    @foreach(['attack' => 'Attack family', 'lifecycle' => 'Lifecycle', 'fuel' => 'Fuel + power', 'services' => 'Services', 'sov' => 'Sovereignty'] as $famKey => $famLabel)
                        @if(isset($testLab['notification_types'][$famKey]))
                            <div style="margin-top:0.7rem;">
                                <div style="color:#9aa3b3; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.35rem;">
                                    {{ $famLabel }}
                                </div>
                                <div style="display:flex; flex-wrap:wrap; gap:0.4rem;">
                                    @foreach($testLab['notification_types'][$famKey] as $nt)
                                        @php
                                            $btnClass = match($famKey) {
                                                'attack'    => 'btn-danger',
                                                'lifecycle' => 'btn-info',
                                                'fuel'      => 'btn-warning',
                                                'services'  => 'btn-secondary',
                                                'sov'       => 'btn-dark',
                                                default     => 'btn-secondary',
                                            };
                                        @endphp
                                        <button type="button"
                                                class="btn btn-sm {{ $btnClass }} diag-inject-btn"
                                                data-type="{{ $nt['type'] }}"
                                                title="{{ $nt['type'] }}">
                                            {{ $nt['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </form>
            </div>

            {{-- ----- SM-side dual-fuel embed (analysis path) ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-flask"></i> Send SM dual-fuel Metenox embed
                </h5>
                <small style="color:#8b95a5;">
                    Different from CCP's <code>StructureLowReagentsAlert</code> above.
                    SM has its OWN dual-fuel analysis (<code>NotifyUpwellLowFuel</code>) that calculates
                    which resource will run out first (fuel blocks vs. magmatic gas), shows a
                    <code>[LIMITING]</code> flag, and gives a predictive offline time. This button
                    posts the SM-style embed directly to your test webhook with a critical-fuel
                    scenario synthesized in memory (no DB mutation, no waiting for cron).
                </small>
                <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.metenox-dual-fuel') }}" style="margin-top:0.8rem;">
                    @csrf
                    <input type="hidden" name="confirm" value="yes">
                    <div class="form-group" style="margin-bottom:0.6rem;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">Limiting factor (which resource runs out first)</label>
                        <select name="limiting_factor" class="form-control"
                                style="background:#1f242c; border-color:#454d55; color:#dfe3eb;">
                            <option value="magmatic_gas" selected>Magmatic Gas (gas-limited — typical Metenox)</option>
                            <option value="fuel_blocks">Fuel Blocks (block-limited)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="fas fa-paper-plane"></i> Send dual-fuel embed
                    </button>
                </form>
            </div>

            {{-- ----- Recent test notifications ----- --}}
            <div style="background:#2a2f3a; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-history"></i> Recent test notifications
                    <button type="button" class="btn btn-sm btn-secondary" id="diag-test-refresh-recent" style="margin-left:auto;">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </h5>
                <small style="color:#8b95a5;">Last 10 injected notifications. Status reflects whether SM's process-notifications job has dispatched them yet (auto-refresh every 30s).</small>
                <table class="table table-sm" style="margin-top:0.6rem; color:#dfe3eb;">
                    <thead style="color:#9aa3b3; font-size:0.78rem; text-transform:uppercase;">
                        <tr>
                            <th>Type</th>
                            <th>Notification ID</th>
                            <th>Injected</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="diag-test-recent-tbody">
                        @forelse($testLab['recent'] as $r)
                            <tr>
                                <td>{{ $r['type'] }}</td>
                                <td style="font-family:monospace; font-size:0.78rem;">{{ $r['notification_id'] }}</td>
                                <td style="font-size:0.85rem;">{{ $r['timestamp'] }}</td>
                                <td>
                                    @if($r['processed'] === 'processed')
                                        <span class="diag-badge info">processed</span>
                                    @elseif($r['processed'] === 'pending')
                                        <span class="diag-badge warning">pending</span>
                                    @else
                                        <span class="diag-badge">queued</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" style="color:#8b95a5; font-style:italic;">No test notifications yet — inject one above.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ----- Cleanup --}}
            <div style="background:#2a2f3a; border:1px solid #7a1d2b; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#f5a8b0; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-trash"></i> Clean up all Test Notification Lab data
                </h5>
                <small style="color:#8b95a5;">
                    Same as the Test Data tab's cleanup: removes every test corp, test character, test structure
                    (2.3B + legacy), test POS, and every test notification (8e18 range). Production data is
                    protected by ID-range guards.
                </small>
                <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.cleanup') }}" style="margin-top:0.8rem;"
                      onsubmit="return confirm('Remove ALL Structure Manager test data? Test corps, characters, structures, POSes, and notifications.');">
                    @csrf
                    <label class="confirm-label">
                        <input type="checkbox" name="confirm" value="yes" required>
                        I understand this deletes all plugin-generated test rows.
                    </label>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete all test data
                    </button>
                </form>
            </div>

            {{-- ----- Send Sample Upwell Alert ----- --}}
            {{-- Moved here from Notification Testing tab so all synthetic-data
                 dispatch paths live alongside each other. This one is
                 different from the buttons above: those inject CCP-shaped
                 notification rows and walk the full pipeline (board upsert
                 → EventBus → Discord). This one just renders a sample embed
                 (fake Fortizar + fake Metenox) and POSTs it directly to one
                 selected webhook. Used to verify embed format + role
                 mentions + channel routing without needing a real low-fuel
                 structure. --}}
            <div style="background:#1f242c; border:1px solid #454d55; border-radius:6px; padding:1rem; margin-top:1rem;">
                <h5 style="color:#fff; margin-top:0; font-size:0.95rem;">
                    <i class="fas fa-paper-plane"></i> Send Sample Upwell Alert (preview embed)
                </h5>
                <small style="color:#8b95a5;">
                    Posts a sample embed (test Fortizar + test Metenox data) to a specific webhook so you
                    can preview the notification format without needing a real low-fuel structure. Useful for
                    verifying embed formatting, role mentions, and channel routing.
                    <br><br>
                    <strong>Note:</strong> this dispatches directly to the selected webhook regardless of the
                    Test webhook URL above. If you want the sample to land in your test channel, pick the
                    webhook that points at it.
                </small>
                <form method="POST" action="{{ route('structure-manager.diagnostic.notify.test-upwell-alert') }}" style="margin-top:0.8rem;">
                    @csrf
                    <div class="form-group" style="margin-bottom:0.6rem;">
                        <label style="color:#c2c7d0; font-size:0.82rem;">Send to webhook:</label>
                        <select name="webhook_id" class="form-control" style="max-width:400px;" required>
                            <option value="">— Select webhook —</option>
                            @php $allWebhooks = \StructureManager\Models\WebhookConfiguration::all(); @endphp
                            @foreach($allWebhooks as $wh)
                                <option value="{{ $wh->id }}">
                                    #{{ $wh->id }}
                                    — {{ $wh->description ?: $wh->getCorporationLabel() }}
                                    {{ $wh->enabled ? '' : '(disabled)' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info btn-sm">
                        <i class="fas fa-paper-plane"></i> Send Sample Alert
                    </button>
                </form>
            </div>

        </div>{{-- /.dev-only-card --}}
    </div>{{-- /.diag-danger-zone --}}

    {{-- Close NOTIFICATION LAB tab pane --}}
    </div>
    {{-- ============ TEST DATA TAB ============ --}}
    <div class="diag-tab-pane" data-diag-pane="test-data">

    <div class="diag-tab-intro">
        <p>
            <strong>What this tab does:</strong> Synthetic data generators. Buttons here write fake corps,
            characters, structures, POSes, and Metenox drills into SeAT's tables directly (with safe ID
            ranges far outside CCP's real allocation) plus the plugin's own tracking tables, so you can
            exercise features that need real-looking data (webhook filtering, threshold transitions,
            dual-fuel logic, etc.) without setting up an actual EVE corp.
        </p>
        <p>
            <strong>When to use:</strong> Local development, QA installs, regression testing after a
            plugin upgrade. <strong>NEVER on production.</strong> While test IDs are isolated by range,
            synthetic rows WILL appear in your dashboards / alerts / Structure Board until you clean
            them up.
        </p>
        <p>
            <strong>Heads up:</strong> Read the red warning below before clicking anything. Cleanup
            button at the bottom removes everything this tab created.
        </p>
    </div>

    {{-- =================================================================== --}}
    {{-- DEV-ONLY DANGER ZONE: test data generation                           --}}
    {{-- =================================================================== --}}
    <div class="diag-danger-zone">
        <h4><i class="fas fa-exclamation-triangle"></i> Test Data Generation — Development Only</h4>

        <div class="alert">
            <strong>Warning:</strong> These buttons write synthetic rows directly into SeAT's
            <code>corporation_infos</code>, <code>corporation_starbases</code>, <code>corporation_structures</code>,
            and <code>corporation_assets</code> tables, plus the plugin's own tracking tables.
            Test corporations and POS/structure IDs are placed in ID ranges far outside EVE's real
            allocation (test corps &gt;= 2.1B, test POSes &gt;= 2.2B, test Metenox at 9999999999),
            but they WILL appear in dashboards, alerts, and ESI-derived UI until you clean them up.
            <strong>Do not use on a production installation.</strong>
        </div>

        {{-- Current test data snapshot --}}
        <div class="dev-only-card">
            <h5>Current test data in this installation</h5>
            <dl class="diag-kv">
                <dt>Test corporations</dt>
                <dd>{{ $testData['test_corporations'] }}</dd>
                <dt>Test POSes</dt>
                <dd>{{ $testData['test_poses'] }}</dd>
                <dt>Test POS history rows</dt>
                <dd>{{ number_format($testData['test_pos_history_rows']) }}</dd>
                <dt>Test Metenox/Astrahus</dt>
                <dd>{{ $testData['test_metenox_structures'] }}</dd>
                <dt>Test Metenox history rows</dt>
                <dd>{{ number_format($testData['test_metenox_history']) }}</dd>
            </dl>
        </div>

        {{-- Generate test POSes --}}
        <div class="dev-only-card">
            <h5>Generate test POSes (multi-corp)</h5>
            <small>Runs <code>structure-manager:create-test-poses</code>. Creates fake corps with fake POSes, fuel, strontium, and charters so webhook filtering and notification flow can be exercised.</small>
            <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.poses') }}" style="margin-top:0.8rem;">
                @csrf
                <div class="form-row" style="display:flex;gap:0.8rem;align-items:end;flex-wrap:wrap;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">Corporations (1-10)</label>
                        <input type="number" name="corporations" class="form-control" value="3" min="1" max="10">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">POSes per corp (1-10)</label>
                        <input type="number" name="poses_per_corp" class="form-control" value="2" min="1" max="10">
                    </div>
                </div>
                <label class="confirm-label">
                    <input type="checkbox" name="confirm" value="yes" required>
                    I understand this inserts synthetic rows; this is NOT a production instance.
                </label>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-flask"></i> Create test POSes
                </button>
            </form>
        </div>

        {{-- Generate test Metenox --}}
        <div class="dev-only-card">
            <h5>Generate test Metenox + Astrahus</h5>
            <small>Runs <code>structure-manager:create-test-metenox</code>. Creates a fake Metenox Moon Drill (ID 9999999999) plus a linked Astrahus reserve with magmatic gas assets — exercises the dual-fuel (blocks + gas) tracking logic. Also seeds the Metenox's <code>MoonMaterialBay</code> at ~90% with a mixed moon-ore payload (Hydrocarbons, Atmospheric Gases, Evaporite Deposits, Silicates, Cobaltite, Euxenite) so Mining Manager v2.0.1's Metenox cargo readout renders with data end-to-end from this one click.</small>
            <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.metenox') }}" style="margin-top:0.8rem;">
                @csrf
                <label class="confirm-label">
                    <input type="checkbox" name="confirm" value="yes" required>
                    I understand this inserts synthetic rows; this is NOT a production instance.
                </label>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-flask"></i> Create test Metenox
                </button>
            </form>
        </div>

        {{-- Simulate consumption --}}
        <div class="dev-only-card">
            <h5>Simulate fast consumption (test POSes only)</h5>
            <small>Runs <code>structure-manager:simulate-consumption --test-only</code>. Advances fuel on test POSes (IDs &gt;= 1B) by a 20-minute "cycle" so you can watch status transitions, webhook fires, and final-alert latches without waiting for real time.</small>
            <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.simulate') }}" style="margin-top:0.8rem;">
                @csrf
                <div class="form-row" style="display:flex;gap:0.8rem;align-items:end;flex-wrap:wrap;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="color:#c2c7d0;font-size:0.82rem;">Cycles (1-20)</label>
                        <input type="number" name="cycles" class="form-control" value="1" min="1" max="20">
                    </div>
                </div>
                <label class="confirm-label">
                    <input type="checkbox" name="confirm" value="yes" required>
                    I understand this mutates test POS history.
                </label>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-clock"></i> Advance cycle
                </button>
            </form>
        </div>

        {{-- Cleanup --}}
        <div class="dev-only-card" style="border-color:#7a1d2b;">
            <h5 style="color:#f5a8b0;">Clean up all test data</h5>
            <small>Runs <code>--cleanup</code> on both create-test commands. Removes every test corp, test POS, test Metenox/Astrahus, and their history/reserve rows. Production ESI data is NOT touched (ID-range guards).</small>
            <form method="POST" action="{{ route('structure-manager.diagnostic.test-data.cleanup') }}" style="margin-top:0.8rem;"
                  onsubmit="return confirm('Remove ALL test data generated by this plugin? (Test corps, test POSes, test Metenox, and their history.) Production data is protected by ID range.');">
                @csrf
                <label class="confirm-label">
                    <input type="checkbox" name="confirm" value="yes" required>
                    I understand this deletes all plugin-generated test rows.
                </label>
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash"></i> Delete all test data
                </button>
            </form>
        </div>
    </div>

    {{-- Close TEST DATA tab pane --}}
    </div>

        </div>{{-- /.card-body --}}
    </div>{{-- /.card.card-dark --}}

</div>

@push('javascript')
<script>
(function ($) {
    'use strict';

    const $tabs  = $('.diag-tab');
    const $panes = $('.diag-tab-pane');

    function setActive(target, syncUrl) {
        $tabs.removeClass('active');
        $tabs.filter('[data-diag-target="' + target + '"]').addClass('active');

        // Hide every pane first, then show every pane matching the target.
        // The health tab has TWO panes (split around Type IDs) that should
        // both show when active.
        $panes.removeClass('active').hide();
        $panes.filter('[data-diag-pane="' + target + '"]').addClass('active').show();

        // Lazy-load auto-redirect: if user activates a tab whose pane is
        // marked data-lazy="true" (server didn't include the data because
        // the URL didn't request it), redirect to the same URL with
        // ?diag_tab=<target> so the server populates it. This is what
        // makes the heavy Tier 1 tabs cheap on cold page loads.
        var pane = document.querySelector('[data-diag-pane="' + target + '"]');
        var isLazy = pane && pane.getAttribute('data-lazy') === 'true';

        if (isLazy) {
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('diag_tab', target);
                window.location.replace(url.toString());
            } catch (e) {
                window.location.href = window.location.pathname + '?diag_tab=' + encodeURIComponent(target);
            }
            return;
        }

        // Non-lazy panes: sync the URL via replaceState so the Referer
        // header carries diag_tab=<target> on form POSTs from inside this
        // tab. Without this, controllers that finish with back() land the
        // user on Health Checks (the bootstrap default) instead of the
        // tab they were actually working in. No page reload — pure URL
        // update. Only runs on user-driven tab switches (syncUrl=true),
        // not on the initial bootstrap call below, so we don't pollute a
        // freshly-loaded /diagnostic URL with ?diag_tab=health.
        if (syncUrl) {
            try {
                var url2 = new URL(window.location.href);
                url2.searchParams.set('diag_tab', target);
                history.replaceState(null, '', url2.toString());
            } catch (e) {
                // History API unsupported. Tab still switches visually;
                // back() will fall back to the bootstrap default.
            }
        }
    }

    $tabs.on('click', function () {
        const target = $(this).data('diag-target');
        if (target) setActive(target, true);
    });

    // Default landing tab: ALWAYS Health Checks on a fresh visit.
    // Previously this restored the last-clicked tab from localStorage,
    // which surprised operators who clicked into Test Data once and
    // then kept landing there. Per ops feedback, Health Checks is the
    // canonical at-a-glance dashboard and should be the default every
    // time. Explicit URL deep-links via ?diag_tab=X still win (e.g.
    // Fuel Trace form submissions need to land back on Fuel Trace).
    const validTargets = $tabs.map(function () { return $(this).data('diag-target'); }).get();

    let urlTab = null;
    try {
        const params = new URLSearchParams(window.location.search);
        urlTab = params.get('diag_tab');
    } catch (e) {}

    if (urlTab && validTargets.includes(urlTab)) {
        setActive(urlTab, false);
    } else {
        setActive('health', false);
    }

    // ====================================================================
    // Test Notification Lab wiring
    // ====================================================================

    // Inject buttons: clicking any of the 24 type buttons sets the hidden
    // `type` field on the form and submits. The structure_id comes from the
    // dropdown above the buttons, attacker context comes from the optional
    // fields below the dropdown.
    $('.diag-inject-btn').on('click', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const type = $btn.data('type');
        const $form = $('#diag-test-inject-form');
        const $structureSelect = $form.find('select[name="structure_id"]');

        if (!$structureSelect.val()) {
            alert('Pick a target structure first.');
            return;
        }

        $('#diag-test-inject-type').val(type);
        $form.trigger('submit');
    });

    // Recent test notifications: refresh button + auto-refresh every 30s
    function refreshRecent() {
        const url = '{{ route('structure-manager.diagnostic.test-data.state') }}';
        $.getJSON(url, function (state) {
            const $tbody = $('#diag-test-recent-tbody');
            if (!state.recent || state.recent.length === 0) {
                $tbody.html('<tr><td colspan="4" style="color:#8b95a5; font-style:italic;">No test notifications yet — inject one above.</td></tr>');
                return;
            }
            const rows = state.recent.map(function (r) {
                const status = r.processed === 'processed'
                    ? '<span class="diag-badge info">processed</span>'
                    : r.processed === 'pending'
                        ? '<span class="diag-badge warning">pending</span>'
                        : '<span class="diag-badge">queued</span>';
                return '<tr>'
                    + '<td>' + $('<div>').text(r.type).html() + '</td>'
                    + '<td style="font-family:monospace; font-size:0.78rem;">' + $('<div>').text(r.notification_id).html() + '</td>'
                    + '<td style="font-size:0.85rem;">' + $('<div>').text(r.timestamp).html() + '</td>'
                    + '<td>' + status + '</td>'
                    + '</tr>';
            }).join('');
            $tbody.html(rows);
        }).fail(function () {
            // Silent fail — keep showing the previous data
        });
    }

    $('#diag-test-refresh-recent').on('click', function (e) {
        e.preventDefault();
        refreshRecent();
    });

    // Auto-refresh on a 30s interval, ONLY while the lab tab is active.
    // We poll a bit aggressively because admins want to see notifications
    // transition queued -> pending -> processed without manually refreshing.
    setInterval(function () {
        if ($('.diag-tab.active').data('diag-target') === 'notification-lab') {
            refreshRecent();
        }
    }, 30000);
})(jQuery);
</script>
@endpush

@endsection
