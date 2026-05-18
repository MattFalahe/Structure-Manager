@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::menu.economics'))
@section('page_header', trans('structure-manager::menu.economics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
@endpush

@section('full')
<div class="structure-manager-wrapper">
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-coins"></i> Fuel Economics</h3>
        </div>
        <div class="card-body">
            <div class="alert alert-info" style="background:#1d2c3a; border:1px solid #17a2b8; color:#d0e4fb;">
                <h5 style="margin-top:0;">
                    <i class="fas fa-pause-circle"></i> Economics integration disabled
                </h5>
                <p>
                    Manager Core is installed, but Structure Manager's Economics integration has been
                    set to <strong>Disabled</strong> via SM Settings.
                    No pricing registration is running and the page is showing this notice instead of fuel costs.
                </p>
                <p style="margin-bottom:0;">
                    To re-enable, switch the mode back to <strong>Auto</strong> in
                    <a href="{{ route('structure-manager.settings') }}#economics" style="color:#17a2b8; text-decoration:underline;">
                        SM Settings &rsaquo; Economics
                    </a>
                    and click Save Settings.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
