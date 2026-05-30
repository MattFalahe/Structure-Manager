@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::menu.economics'))
@section('page_header', trans('structure-manager::menu.economics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    .econ-totals { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.8rem; margin: 0.8rem 0 1.2rem 0; }
    .econ-totals .card { background: #1f242c; border: 1px solid #454d55; border-radius: 6px; padding: 1rem; }
    .econ-totals .label { color: #9aa3b3; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .econ-totals .value { color: #dfe3eb; font-size: 1.6rem; font-weight: 700; line-height: 1; margin: 0.4rem 0 0 0; }
    .econ-totals .scale { color: #8b95a5; font-size: 0.72rem; margin-top: 0.25rem; }

    .econ-meta { color: #9aa3b3; font-size: 0.85rem; margin-bottom: 0.8rem; }
    .econ-meta strong { color: #dfe3eb; }
    .econ-period-form { display: flex; align-items: center; gap: 0.6rem; }
    .econ-period-form select { background:#1f242c; color:#dfe3eb; border:1px solid #454d55; padding:0.35rem 0.6rem; border-radius:4px; }
    .econ-period-form button { background:#667eea; border:none; color:#fff; padding:0.35rem 0.9rem; border-radius:4px; cursor:pointer; }

    .econ-table { width: 100%; border-collapse: collapse; }
    .econ-table th { background:#2a2f3a; color:#c2c7d0; font-weight:600; padding:0.6rem 0.8rem; text-align:left; }
    .econ-table td { padding:0.55rem 0.8rem; vertical-align: middle; border-top:1px solid #2a3038; color:#dfe3eb; }
    .econ-table tr:hover td { background:#1f242c; }
    .econ-table .num { text-align: right; font-variant-numeric: tabular-nums; }

    .econ-empty { color:#8b95a5; font-style:italic; padding:1rem 0; }
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper">

    @php
        $totals         = $payload['totals']              ?? [];
        $bySystem       = $payload['by_system']           ?? [];
        $byStructure    = $payload['by_structure']        ?? [];
        $byType         = $payload['by_type']             ?? [];
        $trend          = $payload['trend']               ?? [];
        $pricingMeta    = $payload['pricing_meta']        ?? [];
        $cheapestBlock  = $payload['cheapest_fuel_block'] ?? null;
        $optimization   = $payload['optimization']        ?? null;
        $breakdown      = $payload['breakdown']           ?? null;

        $fmtIsk = function ($n) {
            if ($n === null) return '-';
            $abs = abs((float) $n);
            if ($abs >= 1e12) return number_format($n / 1e12, 2) . 'T';
            if ($abs >= 1e9)  return number_format($n / 1e9,  2) . 'B';
            if ($abs >= 1e6)  return number_format($n / 1e6,  2) . 'M';
            if ($abs >= 1e3)  return number_format($n / 1e3,  1) . 'k';
            return number_format($n, 0);
        };
    @endphp

    {{-- Header card with period selector + force-refresh --}}
    <div class="card card-dark">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
            <h3 class="card-title" style="margin:0;"><i class="fas fa-coins"></i> Fuel Economics</h3>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <form method="GET" action="{{ route('structure-manager.economics.index') }}" class="econ-period-form">
                    <label style="color:#dfe3eb; font-size:0.85rem; margin:0;">Period:</label>
                    <select name="period">
                        @foreach($periods as $p)
                            <option value="{{ $p }}" {{ $periodDays === $p ? 'selected' : '' }}>
                                {{ $p }} days
                            </option>
                        @endforeach
                    </select>
                    @if($isAdmin)
                        <label style="color:#dfe3eb; font-size:0.85rem; margin:0 0 0 0.5rem;">Scope:</label>
                        <select name="scope" onchange="this.form.submit()" title="Corporation scope">
                            <option value="mine" {{ $currentScope === 'mine' ? 'selected' : '' }}>My Corporations</option>
                            <option value="all"  {{ $currentScope === 'all'  ? 'selected' : '' }}>All Corporations</option>
                        </select>
                    @endif
                    <button type="submit"><i class="fas fa-redo-alt"></i> Apply</button>
                </form>
                <a href="{{ route('structure-manager.economics.index') }}?period={{ $periodDays }}&scope={{ $currentScope }}&refresh=1"
                   style="background:#2a2f3a; color:#dfe3eb; border:1px solid #454d55; padding:0.35rem 0.9rem; border-radius:4px; text-decoration:none; font-size:0.85rem;">
                    <i class="fas fa-bolt"></i> Force refresh
                </a>
            </div>
        </div>
        <div class="card-body">
            {{-- Pricing meta --}}
            <div class="econ-meta">
                @if($pricingMeta['available'] ?? false)
                    Pricing source: <strong>{{ strtoupper($pricingMeta['price_type'] ?? '?') }}</strong>
                    on <strong>{{ strtoupper($pricingMeta['market'] ?? '?') }}</strong>
                    @if($pricingMeta['admin_overridden'] ?? false)
                        <span style="color:#ffc107;"><i class="fas fa-user-shield"></i> admin override</span>
                    @else
                        <span style="color:#9aa3b3;">(plugin default)</span>
                    @endif
                    &nbsp;&middot;&nbsp; Configurable in
                    <a href="{{ url('manager-core/pricing-preferences') }}" style="color:#667eea;">Manager Core &rsaquo; Pricing Preferences</a>.
                @else
                    Pricing unavailable. <em>This is unexpected: Manager Core's pricing service was reachable when the page was loaded.</em>
                @endif
            </div>

            {{-- Period scope --}}
            <div class="econ-meta">
                Period: <strong>{{ $payload['period_start'] }} → {{ $payload['period_end'] }}</strong>
                ({{ $periodDays }} days)
                &nbsp;&middot;&nbsp; Scope:
                @if($isAdmin && $currentScope === 'all')
                    <strong>All corporations</strong> (admin override)
                @else
                    <strong>Your corporations only</strong>
                @endif
            </div>

            <div class="econ-meta" style="color:#9aa3b3; font-size:0.8rem;">
                <i class="fas fa-calculator"></i>
                Projections derived from active services + EVE static data
                (same path as <a href="{{ route('structure-manager.logistics-report') }}" style="color:#667eea;">Logistics Report</a>),
                independent of consumption-tracker uptime.
            </div>

            {{-- Totals cards --}}
            <div class="econ-totals">
                <div class="card">
                    <div class="label">Weekly</div>
                    <div class="value">{{ $fmtIsk($totals['weekly_isk'] ?? 0) }} <span style="font-size:0.7rem; color:#8b95a5;">ISK</span></div>
                    <div class="scale">7-day projection</div>
                </div>
                <div class="card">
                    <div class="label">Monthly</div>
                    <div class="value">{{ $fmtIsk($totals['monthly_isk'] ?? 0) }} <span style="font-size:0.7rem; color:#8b95a5;">ISK</span></div>
                    <div class="scale">30-day projection</div>
                </div>
                <div class="card">
                    <div class="label">Quarterly</div>
                    <div class="value">{{ $fmtIsk($totals['quarterly_isk'] ?? 0) }} <span style="font-size:0.7rem; color:#8b95a5;">ISK</span></div>
                    <div class="scale">90-day projection</div>
                </div>
                <div class="card">
                    <div class="label">Yearly</div>
                    <div class="value">{{ $fmtIsk($totals['yearly_isk'] ?? 0) }} <span style="font-size:0.7rem; color:#8b95a5;">ISK</span></div>
                    <div class="scale">365-day projection</div>
                </div>
            </div>

            {{-- Cheapest Upwell fuel block suggestion --}}
            @if($cheapestBlock !== null)
                <div style="margin-top:0.9rem; padding:0.7rem 0.9rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid #28a745; border-radius:6px; display:flex; flex-wrap:wrap; align-items:center; gap:0.8rem;">
                    <i class="fas fa-lightbulb" style="color:#28a745; font-size:1.1rem;"></i>
                    <div style="flex:1; min-width:220px;">
                        <div style="color:#dfe3eb; font-size:0.92rem;">
                            <strong>Cheapest fuel block right now:</strong>
                            <span style="color:#28a745;">{{ $cheapestBlock['type_name'] }}</span>
                            at <strong>{{ number_format($cheapestBlock['price'], 2) }} ISK</strong> each.
                        </div>
                        <div style="color:#9aa3b3; font-size:0.8rem; margin-top:0.2rem;">
                            Used to price all Upwell + Metenox fuel projections. POS towers consume their racial type and can't substitute.
                        </div>
                    </div>
                    <details style="font-size:0.82rem;">
                        <summary style="color:#8b95a5; cursor:pointer;">All 4 block prices</summary>
                        <div style="margin-top:0.4rem; padding:0.5rem; background:#181c22; border-radius:3px;">
                            @foreach($cheapestBlock['all_prices'] as $tid => $info)
                                <div style="display:flex; justify-content:space-between; gap:1rem; color:#dfe3eb;">
                                    <span>{{ $info['name'] }}</span>
                                    <span style="font-variant-numeric:tabular-nums; color:{{ $tid === $cheapestBlock['type_id'] ? '#28a745' : '#c2c7d0' }};">
                                        {{ number_format($info['price'], 2) }}
                                        @if($tid === $cheapestBlock['type_id']) <i class="fas fa-check"></i> @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>
            @endif

            {{-- Structure-type breakdown: counts of Upwell / Metenox / POS
                 (with race split for POS) so the operator can sanity-check
                 the page is detecting every structure they expect. --}}
            @if($breakdown && $breakdown['total_count'] > 0)
                <div style="margin-top:0.6rem; padding:0.7rem 0.9rem; background:#1f242c; border:1px solid #454d55; border-left:4px solid #17a2b8; border-radius:6px;">
                    <div style="display:flex; gap:1.5rem; flex-wrap:wrap; align-items:center;">
                        <div>
                            <i class="fas fa-layer-group" style="color:#17a2b8;"></i>
                            <strong style="color:#dfe3eb;">{{ $breakdown['total_count'] }} structures</strong>
                            <span style="color:#9aa3b3;">included in projection</span>
                        </div>

                        @if($breakdown['upwell_count'] > 0)
                            <div style="color:#dfe3eb; font-size:0.85rem;">
                                <i class="fas fa-building" style="color:#9aa3b3;"></i>
                                <strong>{{ $breakdown['upwell_count'] }}</strong> Upwell
                                <span style="color:#9aa3b3;">(can substitute fuel block)</span>
                            </div>
                        @endif

                        @if($breakdown['metenox_count'] > 0)
                            <div style="color:#dfe3eb; font-size:0.85rem;">
                                <i class="fas fa-industry" style="color:#9aa3b3;"></i>
                                <strong>{{ $breakdown['metenox_count'] }}</strong> Metenox
                                <span style="color:#9aa3b3;">(blocks substitute, gas fixed)</span>
                            </div>
                        @endif

                        @if($breakdown['pos_count'] > 0)
                            <div style="color:#dfe3eb; font-size:0.85rem;">
                                <i class="fas fa-broadcast-tower" style="color:#9aa3b3;"></i>
                                <strong>{{ $breakdown['pos_count'] }}</strong> POS
                                <span style="color:#9aa3b3;">(racial fuel, locked):</span>
                                @php
                                    $raceParts = [];
                                    foreach (['caldari' => 'Caldari', 'minmatar' => 'Minmatar', 'amarr' => 'Amarr', 'gallente' => 'Gallente', 'other' => 'Other'] as $key => $label) {
                                        $n = $breakdown['pos_by_race'][$key] ?? 0;
                                        if ($n > 0) {
                                            $raceParts[] = "{$n} {$label}";
                                        }
                                    }
                                @endphp
                                <span style="color:#c2c7d0;">{{ implode(' + ', $raceParts) }}</span>
                            </div>
                        @endif

                        @if($breakdown['substitutable_count'] === 0 && $breakdown['pos_count'] > 0)
                            <div style="color:#9aa3b3; font-size:0.78rem; flex-basis:100%;">
                                Note: All your structures use racial / fixed fuel types. The cheapest-block suggestion above doesn't apply to any of them.
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Optimization summary: surfaces total savings if all sub-optimal
                 structures switched to the cheapest fuel type. Only shows when
                 there's actual savings to report. --}}
            @if($optimization && $optimization['structures_count'] > 0 && $optimization['savings_period'] > 0)
                <div style="margin-top:0.6rem; padding:0.7rem 0.9rem; background:#3a2e15; border:1px solid #ffc107; border-left:4px solid #ffc107; border-radius:6px; display:flex; flex-wrap:wrap; align-items:center; gap:0.8rem;">
                    <i class="fas fa-piggy-bank" style="color:#ffc107; font-size:1.1rem;"></i>
                    <div style="flex:1; min-width:240px;">
                        <div style="color:#fff1c7; font-size:0.92rem;">
                            <strong>{{ $optimization['structures_count'] }} structure{{ $optimization['structures_count'] === 1 ? '' : 's' }}</strong>
                            running on sub-optimal fuel. Switching everything to {{ $cheapestBlock['type_name'] ?? 'the cheapest type' }} would save:
                        </div>
                        <div style="margin-top:0.3rem; display:flex; gap:1.2rem; flex-wrap:wrap; color:#fff1c7;">
                            <span><strong>{{ $fmtIsk($optimization['savings_monthly']) }} ISK</strong> / month</span>
                            <span style="opacity:0.85;">{{ $fmtIsk($optimization['savings_yearly']) }} ISK / year</span>
                            <span style="opacity:0.7; font-size:0.85rem;">({{ $fmtIsk($optimization['savings_period']) }} ISK over the {{ $periodDays }}-day projection)</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Per-system table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-globe"></i> By Solar System</h3>
        </div>
        <div class="card-body">
            @if(empty($bySystem))
                <p class="econ-empty">
                    No fuel consumption data found for the selected period.
                    Either no structures are tracked, or the consumption tables are empty.
                </p>
            @else
                <table class="econ-table">
                    <thead>
                        <tr>
                            <th>System</th>
                            <th class="num">Structures</th>
                            <th class="num">Weekly ISK</th>
                            <th class="num">Monthly ISK</th>
                            <th class="num">Period total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bySystem as $sys)
                            <tr>
                                <td>{{ $sys['system_name'] ?? 'Unknown' }}</td>
                                <td class="num">{{ $sys['structures_count'] }}</td>
                                <td class="num">{{ $fmtIsk($sys['weekly_isk']) }}</td>
                                <td class="num">{{ $fmtIsk($sys['monthly_isk']) }}</td>
                                <td class="num">{{ $fmtIsk($sys['period_isk']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Trend chart + type pie side-by-side --}}
    <div class="row">
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-line"></i> Daily ISK Trend</h3>
                </div>
                <div class="card-body" style="position:relative; height:280px;">
                    @if(empty($trend))
                        <p class="econ-empty">No trend data for the selected period.</p>
                    @else
                        <canvas id="econ-trend-chart"></canvas>
                    @endif
                </div>
                <div class="card-footer">
                    <small style="color:#8b95a5;">
                        Daily fuel ISK across the {{ $periodDays }}-day window. Flat zero days = structure offline (low-power) or tracker gap.
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-pie"></i> By Fuel Type</h3>
                </div>
                <div class="card-body" style="position:relative; height:280px;">
                    @if(empty($byType))
                        <p class="econ-empty">No fuel-type data for the selected period.</p>
                    @else
                        <canvas id="econ-type-chart"></canvas>
                    @endif
                </div>
                <div class="card-footer">
                    <small style="color:#8b95a5;">
                        Period total {{ $fmtIsk($totals['period_isk'] ?? 0) }} ISK by fuel type.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- Per-structure table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-building"></i> By Structure</h3>
        </div>
        <div class="card-body">
            @if(empty($byStructure))
                <p class="econ-empty">
                    No per-structure data for the selected period.
                </p>
            @else
                <table class="econ-table">
                    <thead>
                        <tr>
                            <th>Structure</th>
                            <th>Type</th>
                            <th>System</th>
                            @if($isAdmin)<th>Corp</th>@endif
                            <th title="The fuel block type currently in this structure's fuel bay (highest quantity wins when multiple types are stocked). For POS, the racial type is locked. For Magmatic Gas in Metenox, no substitute exists.">
                                Current fuel
                            </th>
                            <th class="num" title="Weekly cost at the CURRENT fuel type's price. If the structure could switch to a cheaper type, the savings show in the next column.">
                                Weekly ISK
                            </th>
                            <th class="num">Monthly ISK</th>
                            <th class="num">Period total</th>
                            <th class="num" title="Monthly savings if this structure switched from its current fuel type to the cheapest available block. Zero for POS racial / Metenox gas / structures already on the cheapest type.">
                                Monthly savings
                            </th>
                            <th class="num" title="Days within the look-back window with no consumption tracked. Either the structure was offline (low-power) or the consumption tracker had a gap.">
                                Offline days
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byStructure as $st)
                            @php
                                $offDaysFloat = (float) ($st['services_offline_days'] ?? 0);
                                $offDaysDisplay = $offDaysFloat < 0.1 ? '0' : number_format($offDaysFloat, 1);
                                $offCellColor = $offDaysFloat < 0.1 ? '#28a745' : ($offDaysFloat > 14 ? '#dc3545' : '#ffc107');
                                $monthlySav   = (float) ($st['monthly_savings_isk'] ?? 0);
                                $hasSavings   = $monthlySav > 0;
                                $isPos        = $st['is_pos'] ?? false;
                                $sub          = $st['fuel_substitutable'] ?? false;
                                $current      = $st['current_fuel_type_name']  ?? null;
                                $optimal      = $st['optimal_fuel_type_name']  ?? null;
                                $currentMatchesOptimal = $sub && $current && $optimal && ($st['current_fuel_type_id'] === $st['optimal_fuel_type_id']);
                            @endphp
                            @php
                                $isMetenox = !$isPos && (int) ($st['type_id'] ?? 0) === 81826;
                                $posRace   = $st['pos_race'] ?? null;
                                $posRacialName = $st['pos_racial_fuel_name'] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <div style="color:#dfe3eb;">{{ $st['structure_name'] ?? 'Unknown' }}</div>
                                    @if($isPos)
                                        <div style="color:#ffc107; font-size:0.72rem; font-weight:600; text-transform:uppercase;">
                                            <i class="fas fa-broadcast-tower"></i> POS
                                            @if($posRace)
                                                ({{ ucfirst($posRace) }})
                                            @endif
                                        </div>
                                    @elseif($isMetenox)
                                        <div style="color:#17a2b8; font-size:0.72rem; font-weight:600; text-transform:uppercase;">
                                            <i class="fas fa-industry"></i> Metenox (dual-fuel)
                                        </div>
                                    @endif
                                </td>
                                <td style="color:#c2c7d0;">{{ $st['type_name'] ?? '-' }}</td>
                                <td style="color:#c2c7d0;">{{ $st['system_name'] ?? '-' }}</td>
                                @if($isAdmin)
                                    <td style="color:#9aa3b3; font-size:0.85rem;">{{ $st['corp_name'] ?? '-' }}</td>
                                @endif
                                <td>
                                    @if($isPos)
                                        <div style="color:#c2c7d0; font-size:0.85rem;">
                                            {{ $posRacialName ?? 'Racial' }}
                                        </div>
                                        <div style="color:#8b95a5; font-size:0.72rem;">
                                            @if($posRace)
                                                {{ ucfirst($posRace) }} racial (locked)
                                            @else
                                                racial (locked)
                                            @endif
                                        </div>
                                    @elseif($sub && $current)
                                        @if($currentMatchesOptimal)
                                            <div style="color:#28a745; font-size:0.85rem;">
                                                <i class="fas fa-check"></i> {{ $current }}
                                            </div>
                                            <div style="color:#8b95a5; font-size:0.72rem;">
                                                @if($isMetenox)
                                                    blocks already optimal (gas fixed)
                                                @else
                                                    already optimal
                                                @endif
                                            </div>
                                        @else
                                            <div style="color:#ffc107; font-size:0.85rem;">{{ $current }}</div>
                                            <div style="color:#8b95a5; font-size:0.72rem;">
                                                switch blocks to <strong style="color:#28a745;">{{ $optimal }}</strong>
                                            </div>
                                        @endif
                                    @else
                                        <div style="color:#9aa3b3; font-size:0.85rem;">-</div>
                                    @endif
                                </td>
                                <td class="num">{{ $fmtIsk($st['weekly_isk']) }}</td>
                                <td class="num">{{ $fmtIsk($st['monthly_isk']) }}</td>
                                <td class="num">{{ $fmtIsk($st['period_isk']) }}</td>
                                <td class="num" style="color:{{ $hasSavings ? '#ffc107' : '#9aa3b3' }};">
                                    @if($hasSavings)
                                        {{ $fmtIsk($monthlySav) }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="num" style="color:{{ $offCellColor }};">
                                    {{ $offDaysDisplay }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="card-footer">
            <small style="color:#8b95a5;">
                <strong>Current fuel</strong> = what's in the bay right now. Green check = already on the cheapest substitutable type. Amber = could save by switching to the suggested type.
                <strong>Monthly savings</strong> = projected monthly fuel ISK saved if this structure switched to the cheapest type.
                <strong>Offline days</strong> = real outage time computed from fuel-history gaps. A row records the structure's projected fuel_expires; if the next history snapshot arrives AFTER that projected expiry, the gap (rounded to 0.1 day) is counted as offline. Gaps shorter than 1 hour are ignored to avoid false positives from tracker scheduling jitter. Only counts gaps INSIDE observed history, so newly-installed trackers don't get penalized for missing earlier days.
            </small>
        </div>
    </div>
</div>

@push('javascript')
{{-- SM ships Chart.js bundled (same one used by Upwell list / structure detail) --}}
<script src="{{ asset('vendor/structure-manager/js/chart.min.js') }}"></script>
<script>
(function () {
    'use strict';
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded for Economics page');
        return;
    }

    // Chart text/grid colors tuned to the dark card-dark background
    var textColor    = '#c2c7d0';
    var gridColor    = '#2a3038';
    var lineColor    = '#667eea';
    var fillColor    = 'rgba(102, 126, 234, 0.18)';

    // ISK formatter shared with the page-side $fmtIsk helper.
    function fmtIsk(n) {
        if (n === null || n === undefined) return '-';
        var abs = Math.abs(Number(n));
        if (abs >= 1e12) return (n / 1e12).toFixed(2) + 'T';
        if (abs >= 1e9)  return (n / 1e9).toFixed(2)  + 'B';
        if (abs >= 1e6)  return (n / 1e6).toFixed(2)  + 'M';
        if (abs >= 1e3)  return (n / 1e3).toFixed(1)  + 'k';
        return Math.round(n).toString();
    }

    // ---- Trend chart ----
    var trend = @json($trend);
    var trendCanvas = document.getElementById('econ-trend-chart');
    if (trendCanvas && trend && trend.length > 0) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: trend.map(function (d) { return d.date; }),
                datasets: [{
                    label: 'Daily fuel ISK',
                    data: trend.map(function (d) { return d.total_isk; }),
                    borderColor: lineColor,
                    backgroundColor: fillColor,
                    fill: true,
                    tension: 0.2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                }],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return fmtIsk(ctx.parsed.y) + ' ISK'; }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor, maxTicksLimit: 10 },
                        grid:  { color: gridColor },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: textColor, callback: function (v) { return fmtIsk(v); } },
                        grid:  { color: gridColor },
                    }
                }
            }
        });
    }

    // ---- Type pie ----
    var byType = @json($byType);
    var typeCanvas = document.getElementById('econ-type-chart');
    if (typeCanvas && byType && byType.length > 0) {
        // Color palette aligned with SM's existing fuel-type chips
        var typePalette = [
            '#667eea', // indigo
            '#28a745', // green
            '#ffc107', // amber
            '#dc3545', // red
            '#17a2b8', // cyan
            '#9b59b6', // purple
            '#e67e22', // orange
            '#16a085', // teal
        ];

        new Chart(typeCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: byType.map(function (t) { return t.type_name; }),
                datasets: [{
                    data: byType.map(function (t) { return t.period_isk; }),
                    backgroundColor: byType.map(function (_, i) { return typePalette[i % typePalette.length]; }),
                    borderColor: '#1f242c',
                    borderWidth: 2,
                }],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: textColor, font: { size: 11 }, boxWidth: 12 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.label + ': ' + fmtIsk(ctx.parsed) + ' ISK'; }
                        }
                    }
                }
            }
        });
    }
})();
</script>
@endpush
@endsection
