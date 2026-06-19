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
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper">

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    <a href="{{ route('structure-manager.doctrines.index', ['corporation_id' => $corporationId]) }}" class="btn btn-sm btn-secondary mb-3"><i class="fas fa-arrow-left"></i> {{ trans('structure-manager::doctrines.back') }}</a>

    <div class="card card-dark">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-building"></i> {{ $doctrine ? trans('structure-manager::doctrines.edit_heading') : trans('structure-manager::doctrines.add_heading') }}</h3></div>
        <div class="card-body">
            <div class="alert" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3); color: #c2c7d0;">
                <i class="fas fa-info-circle"></i> {{ trans('structure-manager::doctrines.form_help') }}
            </div>

            <form method="POST" action="{{ $doctrine ? route('structure-manager.doctrines.update', $doctrine->id) : route('structure-manager.doctrines.store') }}">
                @csrf
                @if($doctrine) @method('PUT') @endif
                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>{{ trans('structure-manager::doctrines.f_name') }}</label>
                        <input type="text" name="name" class="form-control" maxlength="120" required value="{{ old('name', $doctrine->name ?? '') }}" placeholder="{{ trans('structure-manager::doctrines.f_name_ph') }}">
                    </div>
                    <div class="col-md-6 form-group">
                        <label>{{ trans('structure-manager::doctrines.f_band') }}</label>
                        <select name="security_band" class="form-control" required>
                            @foreach($bands as $band)
                                <option value="{{ $band }}" {{ old('security_band', $doctrine->security_band ?? '') === $band ? 'selected' : '' }}>{{ trans('structure-manager::compliance.band_' . $band) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>{{ trans('structure-manager::doctrines.f_eft') }}</label>
                    <textarea name="eft" class="form-control" rows="14" required style="font-family: monospace; font-size: 0.85rem;" placeholder="{{ trans('structure-manager::doctrines.f_eft_ph') }}">{{ old('eft', $doctrine->eft_raw ?? '') }}</textarea>
                    <small class="sc-muted">{{ trans('structure-manager::doctrines.f_eft_help') }}</small>
                </div>

                <div class="row mb-1">
                    <div class="col-md-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="reqFighters" name="require_fighters" value="1" {{ old('require_fighters', $doctrine->require_fighters ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label" for="reqFighters" style="color: #c2c7d0;">{{ trans('structure-manager::doctrines.f_require_fighters') }}</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="reqAmmo" name="require_ammo" value="1" {{ old('require_ammo', $doctrine->require_ammo ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label" for="reqAmmo" style="color: #c2c7d0;">{{ trans('structure-manager::doctrines.f_require_ammo') }}</label>
                        </div>
                    </div>
                    @if($doctrine)
                    <div class="col-md-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="isActive" name="is_active" value="1" {{ old('is_active', $doctrine->is_active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive" style="color: #c2c7d0;">{{ trans('structure-manager::doctrines.f_active') }}</label>
                        </div>
                    </div>
                    @endif
                </div>
                <small class="d-block mb-3 sc-muted">{{ trans('structure-manager::doctrines.f_gates_help') }}</small>

                <button type="submit" class="btn btn-sm-primary"><i class="fas fa-save"></i> {{ trans('structure-manager::doctrines.f_save') }}</button>
            </form>
        </div>
    </div>

</div>
@endsection
