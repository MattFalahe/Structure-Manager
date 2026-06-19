@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::compliance.page_title'))
@section('page_header', trans('structure-manager::compliance.page_title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* The page lives inside .structure-manager-wrapper (one of the theme's
       exempt roots), so the canonical structure-manager.css styles cards,
       buttons and alerts. Only the compliance-specific chrome is defined here,
       scoped to the wrapper so the site theme can't override it. */
    .structure-manager-wrapper { color: #c2c7d0; }
    .structure-manager-wrapper .sc-muted { color: #8b95a5; }
    .structure-manager-wrapper .sc-banner {
        background: rgba(99,102,241,0.10); border-left: 3px solid #6366f1;
        border-radius: 6px; padding: 12px 14px; margin-bottom: 16px;
    }
    .structure-manager-wrapper .sc-warning {
        background: rgba(240,173,78,0.10); border: 1px solid rgba(240,173,78,0.30);
        border-radius: 6px; padding: 10px 12px; color: #f0ad4e; margin-bottom: 14px;
    }
    .structure-manager-wrapper .sc-summary-cell { background: rgba(255,255,255,0.03); border-radius: 6px; padding: 10px; text-align: center; }
    /* Collapsible structure cards (native details element) */
    .structure-manager-wrapper details.sc-structure { background: #2a2f3a; border: 1px solid #454d55; border-radius: 8px; margin-bottom: 10px; overflow: hidden; }
    .structure-manager-wrapper details.sc-structure > summary.sc-sum { list-style: none; cursor: pointer; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .structure-manager-wrapper details.sc-structure > summary.sc-sum::-webkit-details-marker { display: none; }
    .structure-manager-wrapper details.sc-structure[open] > summary .sc-chevron { transform: rotate(90deg); }
    .structure-manager-wrapper details.sc-structure .sc-chevron { transition: transform 0.12s ease; }
    .structure-manager-wrapper details.sc-structure .sc-body { padding: 0 14px 14px; }
    .structure-manager-wrapper .sc-diff-table { width: 100%; font-family: monospace; font-size: 0.8rem; border-collapse: collapse; }
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper">
    @php $sc = $compliance ?? ['available' => false]; @endphp

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-clipboard-check"></i> {{ trans('structure-manager::compliance.page_title') }}</h3>
        </div>
        <div class="card-body">
        <p class="sc-muted" style="font-size: 0.86rem;">{{ trans('structure-manager::compliance.banner_body') }}</p>

    <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 10px;">
        @if($corporations->count() > 1)
            <form method="GET" action="{{ route('structure-manager.compliance') }}" class="form-inline m-0" style="gap: 8px;">
                <label class="mb-0 mr-2 sc-muted">{{ trans('structure-manager::compliance.corporation') }}:</label>
                <select name="corporation_id" class="form-control form-control-sm" style="min-width: 300px;" onchange="this.form.submit()">
                    @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}" {{ (int) $corp->corporation_id === (int) $corporationId ? 'selected' : '' }}>
                            @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                        </option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-sm-primary ml-2">Go</button></noscript>
            </form>
        @endif
        @can('structure-manager.admin')
            <a href="{{ route('structure-manager.doctrines.index', ['corporation_id' => $corporationId]) }}" class="btn btn-sm btn-sm-primary"><i class="fas fa-cog"></i> {{ trans('structure-manager::compliance.manage') }}</a>
        @endcan
        @if(!empty($sc['available']))
            <small class="sc-muted">
                {{ trans('structure-manager::compliance.scope_' . $sc['scope_mode']) }} &middot;
                {{ trans('structure-manager::compliance.doctrines_n', ['n' => $sc['doctrine_count']]) }}
                @if($sc['offline_strict']) &middot; {{ trans('structure-manager::compliance.offline_strict') }}@endif
            </small>
        @endif
    </div>

    @if(empty($sc['available']))
        <div class="sc-warning"><i class="fas fa-info-circle"></i> {{ trans('structure-manager::compliance.unavailable') }}</div>
    @else
        @if(!$sc['has_assets'])
            <div class="sc-warning"><i class="fas fa-key"></i> {{ trans('structure-manager::compliance.no_assets') }}</div>
        @endif

        @php $sm = $sc['summary']; @endphp
        <div class="row mb-3">
            @foreach([
                'compliant'          => ['#28a745', trans('structure-manager::compliance.compliant')],
                'compliant_upgraded' => ['#3bc47a', trans('structure-manager::compliance.compliant_upgraded')],
                'partial'            => ['#ffc107', trans('structure-manager::compliance.partial')],
                'non_compliant'      => ['#dc3545', trans('structure-manager::compliance.non_compliant')],
                'no_doctrine'        => ['#9ca3af', trans('structure-manager::compliance.no_doctrine')],
                'no_data'            => ['#6c757d', trans('structure-manager::compliance.no_data')],
            ] as $k => $meta)
                <div class="col-md-2 col-4 mb-2">
                    <div class="sc-summary-cell">
                        <div style="font-size: 1.5rem; font-weight: 700; color: {{ $meta[0] }};">{{ $sm[$k] ?? 0 }}</div>
                        <small class="sc-muted">{{ $meta[1] }}</small>
                    </div>
                </div>
            @endforeach
        </div>

        @if(empty($sc['structures']))
            <p class="sc-muted mb-0">{{ trans('structure-manager::compliance.no_structures') }}</p>
        @else
            @foreach($sc['structures'] as $st)
                @php
                    $statusMeta = [
                        'compliant'          => ['#28a745', 'fa-check', trans('structure-manager::compliance.compliant')],
                        'compliant_upgraded' => ['#3bc47a', 'fa-arrow-up', trans('structure-manager::compliance.compliant_upgraded')],
                        'partial'            => ['#ffc107', 'fa-exclamation-triangle', trans('structure-manager::compliance.partial')],
                        'non_compliant'      => ['#dc3545', 'fa-times', trans('structure-manager::compliance.non_compliant')],
                        'no_doctrine'        => ['#9ca3af', 'fa-question', trans('structure-manager::compliance.no_doctrine')],
                        'no_data'            => ['#6c757d', 'fa-eye-slash', trans('structure-manager::compliance.no_data')],
                    ][$st['status']] ?? ['#9ca3af', 'fa-circle', $st['status']];
                @endphp
                {{-- Default-open the structures with no comparison table (no
                     doctrine / no data) so their short diagnosis is visible. --}}
                <details class="sc-structure" style="border-left: 4px solid {{ $statusMeta[0] }};" {{ empty($st['sections']) ? 'open' : '' }}>
                    <summary class="sc-sum">
                        <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis;">
                            <i class="fas fa-chevron-right sc-chevron" style="color: #8b95a5; font-size: 0.72rem; margin-right: 5px;"></i>
                            <i class="fas fa-building"></i> <strong style="font-size: 0.95rem; color: #e6e9ef;">{{ $st['structure_name'] }}</strong>
                            <small class="sc-muted">&middot; {{ $st['structure_type'] }} &middot; {{ $st['system'] }} &middot; {{ trans('structure-manager::compliance.band_' . $st['band']) }}@if($st['doctrine_name']) &middot; {{ $st['doctrine_name'] }}@endif</small>
                        </span>
                        <span class="badge" style="background: {{ $statusMeta[0] }}22; color: {{ $statusMeta[0] }}; border: 1px solid {{ $statusMeta[0] }}66; white-space: nowrap;">
                            <i class="fas {{ $statusMeta[1] }}"></i> {{ $statusMeta[2] }}
                        </span>
                    </summary>
                    <div class="sc-body">
                        @if(!empty($st['reasons']))
                            <div class="mt-2">
                                @foreach($st['reasons'] as $r)
                                    <span class="badge" style="background: rgba(220,53,69,0.18); color: #fca5a5;">{{ trans('structure-manager::compliance.reason_' . $r) }}</span>
                                @endforeach
                            </div>
                        @endif

                        @if(!empty($st['sections']))
                            @php
                                $stateMeta = [
                                    'exact'      => ['fa-check',               '#28a745', null],
                                    'upgraded'   => ['fa-arrow-up',            '#3bc47a', 'diff_upgraded'],
                                    'lower_tier' => ['fa-exclamation-triangle','#f0ad4e', 'diff_lower'],
                                    'mismatch'   => ['fa-exchange-alt',        '#dc3545', 'diff_mismatch'],
                                    'missing'    => ['fa-times',               '#dc3545', 'diff_missing'],
                                    'extra'      => ['fa-plus',                '#6c9bd1', 'diff_extra'],
                                    'empty'      => ['fa-minus',               '#5a5a5a', null],
                                ];
                                $emptyLabel = trans('structure-manager::compliance.empty_slot');
                            @endphp
                            <div class="mt-2" style="background: rgba(0,0,0,0.18); border-radius: 6px; padding: 6px 8px; overflow-x: auto;">
                                <table class="sc-diff-table">
                                    <thead>
                                        <tr style="color: #8b95a5; text-align: left; font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.4px;">
                                            <th style="padding: 2px 10px 4px 4px; font-weight: 600;">{{ trans('structure-manager::compliance.col_current') }}</th>
                                            <th style="padding: 2px 10px 4px 4px; font-weight: 600;">{{ trans('structure-manager::compliance.col_required') }}</th>
                                            <th style="padding: 2px 4px 4px; font-weight: 600;">{{ trans('structure-manager::compliance.col_diff') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($st['sections'] as $section)
                                            <tr><td colspan="3" style="padding: 6px 4px 1px; color: #8b95a5; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.4px;">{{ trans('structure-manager::compliance.section_' . $section['section']) }} <span style="opacity: 0.55;">({{ $section['slot_count'] }})</span></td></tr>
                                            @foreach($section['rows'] as $row)
                                                @php $stm = $stateMeta[$row['state']] ?? ['fa-circle', '#9ca3af', null]; @endphp
                                                <tr style="{{ $row['state'] === 'empty' ? 'opacity: 0.4;' : '' }}">
                                                    <td style="padding: 1px 10px 1px 4px; color: #8b95a5;">{{ $row['current'] ?? $emptyLabel }}</td>
                                                    <td style="padding: 1px 10px 1px 4px; color: #c2c7d0;">{{ $row['required'] ?? $emptyLabel }}</td>
                                                    <td style="padding: 1px 4px; color: {{ $stm[1] }}; white-space: nowrap;"><i class="fas {{ $stm[0] }}"></i>@if($stm[2]) {{ trans('structure-manager::compliance.' . $stm[2]) }}@endif</td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                        @if(!empty($st['optional']))
                                            <tr><td colspan="3" style="padding: 6px 4px 1px; color: #8b95a5; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.4px;">{{ trans('structure-manager::compliance.section_optional') }}</td></tr>
                                            @foreach($st['optional'] as $op)
                                                <tr>
                                                    <td style="padding: 1px 10px 1px 4px; color: #8b95a5;">{{ $op['type_name'] }} x{{ $op['quantity'] }}</td>
                                                    <td style="padding: 1px 10px 1px 4px; color: #8b95a5;">&mdash;</td>
                                                    <td style="padding: 1px 4px; color: #8b95a5; white-space: nowrap;">{{ trans('structure-manager::compliance.optional_tag') }}</td>
                                                </tr>
                                            @endforeach
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        @elseif($st['status'] === 'no_doctrine')
                            @php $diag = $st['diag'] ?? null; @endphp
                            @if($diag && !empty($diag['existing']))
                                <div class="mt-2" style="background: rgba(240,173,78,0.1); border: 1px solid rgba(240,173,78,0.3); border-radius: 6px; padding: 8px 10px;">
                                    <div style="color: #ffc107; font-size: 0.85rem;"><i class="fas fa-exclamation-triangle"></i>
                                        {{ trans('structure-manager::compliance.diag_' . $diag['reason'], ['band' => trans('structure-manager::compliance.band_' . $diag['band'])]) }}
                                    </div>
                                    <div class="mt-1" style="font-size: 0.78rem; color: #8b95a5;">
                                        {{ trans('structure-manager::compliance.diag_found') }}
                                        @foreach($diag['existing'] as $ex)
                                            <span class="badge badge-secondary mr-1">{{ $ex['name'] }} &middot; {{ $ex['scope_type'] === 'alliance' ? trans('structure-manager::compliance.scope_alliance') : trans('structure-manager::compliance.scope_corp') }} &middot; {{ trans('structure-manager::compliance.band_' . $ex['band']) }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <p class="mb-0 mt-2"><small class="sc-muted">{{ trans('structure-manager::compliance.no_doctrine_hint') }}</small></p>
                            @endif
                        @elseif($st['status'] === 'no_data')
                            <p class="mb-0 mt-2"><small class="sc-muted">{{ trans('structure-manager::compliance.no_data_hint') }}</small></p>
                        @endif

                        @php
                            $fits = [
                                ['label' => trans('structure-manager::compliance.fit_current'),     'raw' => $st['current_raw']],
                                ['label' => trans('structure-manager::compliance.fit_recommended'), 'raw' => $st['recommended_raw']],
                                ['label' => trans('structure-manager::compliance.fit_missing'),     'raw' => $st['missing_raw']],
                            ];
                            $fits = array_values(array_filter($fits, fn ($f) => !empty($f['raw'])));
                        @endphp
                        @if(!empty($fits))
                            <div class="mt-2" style="display: flex; flex-direction: column; gap: 5px;">
                                @foreach($fits as $fit)
                                    <div class="sc-copy-wrap d-flex flex-wrap align-items-center" style="gap: 6px;">
                                        <textarea class="sc-raw" readonly aria-hidden="true" tabindex="-1" style="position: absolute; left: -9999px; width: 1px; height: 1px;">{{ $fit['raw'] }}</textarea>
                                        <span style="font-size: 0.78rem; color: #8b95a5; min-width: 120px;">{{ $fit['label'] }}</span>
                                        <button type="button" class="btn btn-sm btn-secondary sc-copy"><i class="fas fa-copy"></i> {{ trans('structure-manager::compliance.btn_copy') }}</button>
                                        @if(!empty($sc['buyback_available']))
                                            <form method="POST" action="{{ route('buyback.appraisal.create') }}" target="_blank" rel="noopener" class="d-inline m-0">
                                                @csrf
                                                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                                                <textarea name="items" style="display: none;">{{ $fit['raw'] }}</textarea>
                                                <button type="submit" class="btn btn-sm btn-sm-primary"><i class="fas fa-balance-scale"></i> {{ trans('structure-manager::compliance.btn_appraise') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach
        @endif
    @endif

        </div>
    </div>

    <script>
    (function () {
        document.querySelectorAll('.sc-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var holder = btn.closest('.sc-copy-wrap');
                var ta = holder ? holder.querySelector('.sc-raw') : null;
                if (!ta) return;
                var done = function () {
                    var orig = btn.getAttribute('data-orig') || btn.innerHTML;
                    btn.setAttribute('data-orig', orig);
                    btn.innerHTML = '<i class="fas fa-check"></i> {{ trans('structure-manager::compliance.copied') }}';
                    setTimeout(function () { btn.innerHTML = orig; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(ta.value).then(done, function () { legacy(ta, done); });
                } else {
                    legacy(ta, done);
                }
            });
        });
        function legacy(ta, cb) {
            var prev = ta.style.cssText;
            ta.style.cssText = 'position:fixed;left:0;top:0;opacity:0;';
            ta.focus(); ta.select();
            try { document.execCommand('copy'); cb(); } catch (e) {}
            ta.style.cssText = prev;
        }
    })();
    </script>
</div>
@endsection
