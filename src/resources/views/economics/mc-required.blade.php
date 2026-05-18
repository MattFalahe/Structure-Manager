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
            <div class="alert alert-warning" style="background:#3a2e15; border:1px solid #ffc107; color:#fff1c7;">
                <h5 style="margin-top:0;">
                    <i class="fas fa-puzzle-piece"></i> Manager Core required
                </h5>
                <p>
                    The Fuel Economics page uses Manager Core's pricing service to convert fuel
                    consumption into ISK cost. Manager Core is not installed on this SeAT instance,
                    so the page can't compute prices.
                </p>
                <p style="margin-bottom:0;">
                    Install <a href="https://github.com/MattFalahe/Manager-Core" target="_blank" style="color:#ffc107; text-decoration:underline;">Manager Core</a>
                    to unlock fuel cost prognosis (weekly / monthly / quarterly / yearly) plus per-system and per-structure breakdowns.
                    Structure Manager continues to work standalone for everything else: notifications, fuel tracking, structure board.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
