@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::doctrines.title'))
@section('page_header', trans('structure-manager::doctrines.title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* Inside .structure-manager-wrapper (theme-exempt) so the canonical sheet
       styles cards/buttons; only the page-specific helpers live here. */
    .structure-manager-wrapper { color: #c2c7d0; }
    .structure-manager-wrapper .sc-muted { color: #8b95a5; }
    .structure-manager-wrapper .sc-warning { background: rgba(240,173,78,0.10); border: 1px solid rgba(240,173,78,0.30); border-radius: 6px; padding: 10px 12px; color: #f0ad4e; }
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    <a href="{{ route('structure-manager.compliance', ['corporation_id' => $corporationId]) }}" class="btn btn-sm btn-secondary mb-3"><i class="fas fa-arrow-left"></i> {{ trans('structure-manager::doctrines.back_compliance') }}</a>

    <p class="sc-muted">{{ trans('structure-manager::doctrines.intro') }}</p>

    @if($corporations->count() > 1)
        <div class="mb-3">
            <form method="GET" action="{{ route('structure-manager.doctrines.index') }}" class="form-inline" style="gap: 8px;">
                <label class="mb-0 mr-2 sc-muted">{{ trans('structure-manager::doctrines.corporation') }}:</label>
                <select name="corporation_id" class="form-control form-control-sm" style="min-width: 320px;" onchange="this.form.submit()">
                    @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}" {{ (int) $corp->corporation_id === (int) $corporationId ? 'selected' : '' }}>
                            @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                        </option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-sm-primary ml-2">Go</button></noscript>
            </form>
        </div>
    @endif

    {{-- Global compliance settings --}}
    <div class="card card-dark mb-3">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-sliders-h"></i> {{ trans('structure-manager::doctrines.settings_heading') }}</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('structure-manager.doctrines.settings') }}" class="form-inline" style="gap: 18px; flex-wrap: wrap;">
                @csrf
                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                <div class="form-group">
                    <label class="mr-2" style="color: #c2c7d0;">{{ trans('structure-manager::doctrines.scope_label') }}</label>
                    <select name="structure_doctrine_scope" class="form-control form-control-sm">
                        <option value="corp" {{ $scopeMode === 'corp' ? 'selected' : '' }}>{{ trans('structure-manager::doctrines.scope_corp') }}</option>
                        <option value="alliance" {{ $scopeMode === 'alliance' ? 'selected' : '' }}>{{ trans('structure-manager::doctrines.scope_alliance') }}</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="offlineStrict" name="structure_offline_noncompliant" value="1" {{ $offlineStrict ? 'checked' : '' }}>
                    <label class="form-check-label" for="offlineStrict" style="color: #c2c7d0;">{{ trans('structure-manager::doctrines.offline_strict_label') }}</label>
                </div>
                <button type="submit" class="btn btn-sm btn-sm-primary"><i class="fas fa-save"></i> {{ trans('structure-manager::doctrines.save_settings') }}</button>
            </form>
            <small class="d-block mt-2 sc-muted">{{ trans('structure-manager::doctrines.scope_help') }}</small>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header d-flex align-items-center" style="gap: 10px;">
            <h3 class="card-title" style="margin: 0;">{{ trans('structure-manager::doctrines.list_heading') }}</h3>
            <a href="{{ route('structure-manager.doctrines.create', ['corporation_id' => $corporationId]) }}" class="btn btn-sm btn-sm-primary"><i class="fas fa-plus"></i> {{ trans('structure-manager::doctrines.add') }}</a>
        </div>
        <div class="card-body">

    @if($allianceMissing)
        <div class="sc-warning"><i class="fas fa-exclamation-triangle"></i> {{ trans('structure-manager::doctrines.alliance_missing') }}</div>
    @elseif($doctrines->isEmpty())
        <p class="sc-muted">{{ trans('structure-manager::doctrines.none') }}</p>
    @else
        <table class="table table-sm" style="color: #c2c7d0;">
            <thead>
                <tr class="sc-muted">
                    <th>{{ trans('structure-manager::doctrines.col_structure') }}</th>
                    <th>{{ trans('structure-manager::doctrines.col_band') }}</th>
                    <th>{{ trans('structure-manager::doctrines.col_name') }}</th>
                    <th>{{ trans('structure-manager::doctrines.col_required') }}</th>
                    <th>{{ trans('structure-manager::doctrines.col_gates') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($doctrines as $d)
                    <tr style="{{ $d->is_active ? '' : 'opacity: 0.5;' }}">
                        <td>{{ $d->structure_type_name }}</td>
                        <td><span class="badge badge-secondary">{{ trans('structure-manager::compliance.band_' . $d->security_band) }}</span></td>
                        <td>{{ $d->name }}@if(!$d->is_active) <span class="badge badge-secondary">{{ trans('structure-manager::doctrines.inactive') }}</span>@endif</td>
                        <td>{{ count($d->parsed['required'] ?? []) }}</td>
                        <td>
                            @if($d->require_fighters)<span class="badge badge-info">{{ trans('structure-manager::doctrines.gate_fighters') }}</span>@endif
                            @if($d->require_ammo)<span class="badge badge-info">{{ trans('structure-manager::doctrines.gate_ammo') }}</span>@endif
                        </td>
                        <td class="text-right" style="white-space: nowrap;">
                            <a href="{{ route('structure-manager.doctrines.edit', ['id' => $d->id, 'corporation_id' => $corporationId]) }}" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                            <form method="POST" action="{{ route('structure-manager.doctrines.destroy', $d->id) }}" class="d-inline" onsubmit="return confirm(@js(trans('structure-manager::doctrines.confirm_delete')))">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

        </div>
    </div>

</div>
@endsection
