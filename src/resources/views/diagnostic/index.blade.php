@extends('web::layouts.grids.12')

@section('title', 'Structure Manager - Diagnostics')
@section('page_header', 'Structure Manager - Diagnostics')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}">
<style>
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
.structure-manager-wrapper.diagnostic-page .diag-badge {
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0.25rem 0.55rem;
    border-radius: 0.25rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.structure-manager-wrapper.diagnostic-page .diag-badge.ok    { background: #1c6f3e; color: #d4f4e2; }
.structure-manager-wrapper.diagnostic-page .diag-badge.warn  { background: #7a5a0f; color: #fff1c7; }
.structure-manager-wrapper.diagnostic-page .diag-badge.error { background: #7a1d2b; color: #fbd5db; }
.structure-manager-wrapper.diagnostic-page .diag-badge.info  { background: #1d4d7a; color: #d0e4fb; }

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
.structure-manager-wrapper.diagnostic-page .diag-summary.ok    { border-left: 4px solid #28a745; }
.structure-manager-wrapper.diagnostic-page .diag-summary.warn  { border-left: 4px solid #ffc107; }
.structure-manager-wrapper.diagnostic-page .diag-summary.error { border-left: 4px solid #dc3545; }

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
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
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
    <div class="diag-summary {{ $overall }}">
        <h4 style="margin:0 0 0.3rem 0;">Diagnostic Summary</h4>
        <p style="margin:0;">
            <strong>{{ $overallLabel }}</strong>
            &mdash; OK: {{ $summary['counts']['ok'] }}
            &middot; Warnings: {{ $summary['counts']['warn'] }}
            &middot; Errors: {{ $summary['counts']['error'] }}
            &middot; Informational: {{ $summary['counts']['info'] }}
            ({{ $summary['total'] }} total).
            Reload the page to re-run all checks.
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
            <small>Runs <code>structure-manager:create-test-metenox</code>. Creates a fake Metenox Moon Drill (ID 9999999999) plus a linked Astrahus reserve structure with magmatic gas assets. Exercises the dual-fuel (blocks + gas) tracking logic.</small>
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

</div>
@endsection
