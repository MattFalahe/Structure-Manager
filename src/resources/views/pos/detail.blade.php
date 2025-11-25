@extends('web::layouts.grids.12')

@section('title', $pos->starbase_name ?? 'POS Details')
@section('page_header', $pos->starbase_name ?? 'Player Owned Starbase')

@push('head')
<style>
    /* Dark theme compatible styles */
    .text-success-bright { color: #51cf66; }
    .text-warning-bright { color: #ffd43b; }
    .text-danger-bright { color: #ff6b6b; }
    .text-info-bright { color: #5dade2; }
    
    .stat-box {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
    }
    
    .stat-number {
        font-size: 1.3rem;
        font-weight: bold;
    }
    
    .stat-label {
        font-size: 0.8rem;
        color: #a0a0a0;
    }
    
    /* POS-specific banner */
    .pos-banner {
        background: rgba(255, 152, 0, 0.1);
        border-left: 4px solid #ff9800;
        padding: 0.6rem;
        margin-bottom: 0.75rem;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }
    
    .pos-badge {
        background-color: rgba(255, 152, 0, 0.2);
        color: #ff9800;
        border: 1px solid rgba(255, 152, 0, 0.3);
        padding: 0.2rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.8rem;
        font-weight: bold;
        margin-left: 0.5rem;
    }
    
    /* Dual fuel resource cards */
    .resource-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
        position: relative;
    }
    
    .limiting-factor {
        border: 2px solid #ff6b6b;
        background: rgba(220, 53, 69, 0.1);
    }
    
    .limiting-badge {
        position: absolute;
        top: 0.4rem;
        right: 0.4rem;
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
        border: 1px solid rgba(220, 53, 69, 0.3);
        padding: 0.1rem 0.3rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        font-weight: bold;
    }
    
    .resource-card h5 {
        margin-bottom: 0.5rem;
        color: #17a2b8;
        font-size: 0.95rem;
    }
    
    .resource-icon {
        font-size: 1.5rem;
        opacity: 0.7;
        margin-bottom: 0.3rem;
    }
    
    .progress-bar-wrapper {
        background: rgba(0, 0, 0, 0.4);
        border-radius: 0.25rem;
        height: 24px;
        margin-top: 0.4rem;
        overflow: hidden;
    }
    
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #28a745, #17a2b8);
        transition: width 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 0.8rem;
    }
    
    .progress-bar-fill.critical {
        background: linear-gradient(90deg, #dc3545, #c82333);
    }
    
    .progress-bar-fill.warning {
        background: linear-gradient(90deg, #ffc107, #e0a800);
    }
    
    /* Strontium card */
    .strontium-card {
        background: rgba(138, 43, 226, 0.1);
        border: 1px solid rgba(138, 43, 226, 0.3);
    }
    
    .strontium-critical {
        border: 2px solid #dc3545;
        background: rgba(220, 53, 69, 0.1);
    }
    
    .danger-banner {
        background: rgba(220, 53, 69, 0.1);
        border-left: 4px solid #dc3545;
        padding: 0.6rem;
        margin-bottom: 0.75rem;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }
    
    .warning-banner {
        background: rgba(255, 193, 7, 0.1);
        border-left: 4px solid #ffc107;
        padding: 0.6rem;
        margin-bottom: 0.75rem;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }
    
    /* Compact layout */
    .structure-manager-wrapper .card-body dl {
        margin-bottom: 0;
        font-size: 0.9rem;
    }
    
    .structure-manager-wrapper .card-body dl dt {
        font-weight: 600;
    }
    
    .structure-manager-wrapper .card-body dl dd {
        margin-bottom: 0.3rem;
    }
    
    .charter-not-required {
        background: rgba(108, 117, 125, 0.1);
        border: 1px solid rgba(108, 117, 125, 0.2);
    }
</style>
@endpush

@section('content')
<div class="structure-manager-wrapper">

<!-- Back Button -->
<div class="row mb-2">
    <div class="col-md-12">
        <a href="{{ route('structure-manager.pos.index') }}" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Control Towers
        </a>
    </div>
</div>

<!-- Critical Alerts -->
@if($latestHistory && $latestHistory->hasCriticalStrontium())
<div class="danger-banner">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>CRITICAL:</strong> Strontium below 6 hours! POS vulnerable if attacked.
</div>
@endif

@if($latestHistory && $latestHistory->hasCriticalFuel())
<div class="danger-banner">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>CRITICAL:</strong> Fuel below 7 days! Refuel immediately.
</div>
@endif

@if($latestHistory && $latestHistory->hasLowCharters())
<div class="warning-banner">
    <i class="fas fa-exclamation"></i>
    <strong>WARNING:</strong> Charters running low (< 7 days).
</div>
@endif

<div class="row">
    <!-- Left Column -->
    <div class="col-md-8">
        <div class="row">
            <!-- POS Info Card -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-broadcast-tower"></i> Control Tower
                            <span class="pos-badge"><i class="fas fa-circle"></i> POS</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-5">POS Name:</dt>
                            <dd class="col-sm-7">
                                <strong>{{ $pos->starbase_name ?? 'Unnamed' }}</strong>
                            </dd>
                            
                            <dt class="col-sm-5">Tower Type:</dt>
                            <dd class="col-sm-7">
                                {{ $pos->tower_type }}
                                @if($rates && $rates['has_fuel_bonus'])
                                <span class="badge badge-info badge-sm">{{ $rates['bonus_type'] }}</span>
                                @endif
                            </dd>
                            
                            @if($pos->location_name)
                            <dt class="col-sm-5">Location:</dt>
                            <dd class="col-sm-7">
                                <i class="fas fa-map-marker-alt text-info"></i> {{ $pos->location_name }}
                            </dd>
                            @endif
                            
                            <dt class="col-sm-5">System:</dt>
                            <dd class="col-sm-7">
                                {{ $pos->system_name }} 
                                <span class="{{ $pos->system_security >= 0.5 ? 'text-success-bright' : ($pos->system_security > 0 ? 'text-warning-bright' : 'text-danger-bright') }}">
                                    ({{ number_format($pos->system_security, 2) }})
                                </span>
                            </dd>
                            
                            <dt class="col-sm-5">Space Type:</dt>
                            <dd class="col-sm-7">
                                @if($latestHistory)
                                    <span class="badge badge-{{ $latestHistory->space_type == 'High-Sec' ? 'success' : ($latestHistory->space_type == 'Low-Sec' ? 'warning' : 'danger') }}">
                                        {{ $latestHistory->space_type }}
                                    </span>
                                @endif
                            </dd>
                            
                            <dt class="col-sm-5">Corporation:</dt>
                            <dd class="col-sm-7">{{ $pos->corporation_name }}</dd>
                            
                            <dt class="col-sm-5">State:</dt>
                            <dd class="col-sm-7">
                                @php
                                    $state = strtolower($pos->state ?? 'unknown');
                                @endphp
                                <span class="badge badge-{{ $state == 'online' ? 'success' : ($state == 'reinforced' ? 'warning' : ($state == 'anchored' || $state == 'unanchoring' ? 'info' : 'secondary')) }}">
                                    {{ ucfirst($state) }}
                                </span>
                            </dd>
                            
                            @if($latestHistory)
                            <dt class="col-sm-5">Last Updated:</dt>
                            <dd class="col-sm-7">{{ $latestHistory->created_at->diffForHumans() }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
            
            <!-- Consumption Rates Card -->
            <div class="col-md-6">
                @if($rates)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tachometer-alt"></i> Consumption</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-6">Fuel/Hour:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['fuel_per_hour']) }}</dd>
                            
                            <dt class="col-sm-6">Fuel/Day:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['fuel_per_day']) }}</dd>
                            
                            <dt class="col-sm-6">Fuel/Month:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['fuel_per_month']) }}</dd>
                            
                            @if($rates['has_fuel_bonus'])
                            <dt class="col-sm-6">Reduction:</dt>
                            <dd class="col-sm-6">
                                <span class="text-success-bright">{{ $rates['fuel_reduction_percent'] }}%</span>
                            </dd>
                            @endif
                            
                            <dt class="col-sm-6">Charters:</dt>
                            <dd class="col-sm-6">
                                @if($rates['requires_charters'])
                                    1/hr (High-Sec)
                                @else
                                    <span class="text-muted">1/hr (not req.)</span>
                                @endif
                            </dd>
                            
                            <dt class="col-sm-6">Strontium:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['strontium_for_reinforced']) }}/hr</dd>
                            
                            <dt class="col-sm-6">Shared Bay:</dt>
                            <dd class="col-sm-6">
                                <strong>{{ number_format($rates['shared_bay_capacity'] ?? 0) }} m³</strong>
                            </dd>
                            
                            <dt class="col-sm-6">Max Fuel:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['fuel_bay_capacity'] ?? 0) }} blocks</dd>
                            
                            <dt class="col-sm-6">Max Charters:</dt>
                            <dd class="col-sm-6">{{ number_format($rates['charter_bay_capacity'] ?? 0) }}</dd>
                            
                            <dt class="col-sm-6">Stront Bay:</dt>
                            <dd class="col-sm-6">
                                {{ number_format($rates['strontium_bay_capacity'] ?? 0) }} units
                                <small class="text-muted d-block">({{ number_format($rates['strontium_bay_capacity_m3'] ?? 0) }} m³ @ 3m³/unit)</small>
                            </dd>
                        </dl>
                        
                        <div class="alert alert-info mb-0" style="background: rgba(23, 162, 184, 0.1); border-color: rgba(23, 162, 184, 0.2); padding: 0.5rem; font-size: 0.85rem;">
                            <i class="fas fa-info-circle"></i>
                            <strong>Shared Bay:</strong> Fuel blocks and charters compete for the same {{ number_format($rates['shared_bay_capacity'] ?? 0) }} m³ cargo space.
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
        
        <!-- Fuel Status Row -->
        @if($latestHistory)
        <div class="row">
            <!-- POS Info Banner -->
            <div class="col-md-12">
                <div class="pos-banner">
                    <i class="fas fa-info-circle"></i>
                    <strong>Player Owned Starbase:</strong> Legacy control tower requiring fuel blocks
                    @if($latestHistory->requires_charters)
                        and starbase charters (High-Sec requirement).
                    @else
                        (charters also required in High-Sec ≥0.5).
                    @endif
                    Strontium provides reinforcement timer when attacked.
                </div>
            </div>
            
            <!-- Fuel Blocks -->
            <div class="col-md-6">
                <div class="resource-card {{ ($latestHistory->requires_charters && $latestHistory->limiting_factor == 'fuel') ? 'limiting-factor' : '' }}">
                    @if($latestHistory->requires_charters && $latestHistory->limiting_factor == 'fuel')
                    <span class="limiting-badge">LIMITING</span>
                    @endif
                    
                    <div class="text-center resource-icon">
                        <i class="fas fa-gas-pump text-info-bright"></i>
                    </div>
                    
                    <h5 class="text-center">Fuel Blocks</h5>
                    
                    <div class="stat-box text-center">
                        <div class="stat-number {{ $latestHistory->fuel_days_remaining < 7 ? 'text-danger-bright' : ($latestHistory->fuel_days_remaining < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                            {{ number_format($latestHistory->fuel_blocks_quantity) }}
                        </div>
                        <div class="stat-label">Blocks in Bay</div>
                    </div>
                    
                    <div class="stat-box text-center">
                        <div class="stat-number {{ $latestHistory->fuel_days_remaining < 7 ? 'text-danger-bright' : ($latestHistory->fuel_days_remaining < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                            @php
                                $fuelDays = floor($latestHistory->fuel_days_remaining);
                                $fuelHours = floor(($latestHistory->fuel_days_remaining - $fuelDays) * 24);
                            @endphp
                            @if ($fuelDays == 0)
                                {{ $fuelHours }}h
                            @else
                                {{ $fuelDays }}d {{ $fuelHours }}h
                            @endif
                        </div>
                        <div class="stat-label">Days Remaining</div>
                    </div>
                    
                    @if($rates)
                    <div class="progress-bar-wrapper">
                        @php
                            $fuelBayPct = $rates['fuel_bay_usage_pct'] ?? 0;
                            // Gradient: red -> yellow -> green -> blue (blue is highest)
                            if ($fuelBayPct >= 90) {
                                $fuelGradient = 'linear-gradient(90deg, #17a2b8, #5dade2)'; // blue (excellent)
                            } elseif ($fuelBayPct >= 70) {
                                $fuelGradient = 'linear-gradient(90deg, #28a745, #51cf66)'; // green (good)
                            } elseif ($fuelBayPct >= 50) {
                                $fuelGradient = 'linear-gradient(90deg, #ffc107, #28a745)'; // yellow to green
                            } elseif ($fuelBayPct >= 25) {
                                $fuelGradient = 'linear-gradient(90deg, #ff8c00, #ffc107)'; // orange to yellow
                            } else {
                                $fuelGradient = 'linear-gradient(90deg, #dc3545, #ff6b6b)'; // red (critical)
                            }
                        @endphp
                        <div class="progress-bar-fill" style="width: {{ $fuelBayPct }}%; background: {{ $fuelGradient }};">
                            {{ number_format($fuelBayPct, 0) }}%
                        </div>
                    </div>
                    <small class="text-muted">
                        Bay Usage: {{ number_format($rates['fuel_per_hour']) }}/hr | 
                        @if($latestHistory->requires_charters)
                            Shares {{ number_format($rates['shared_bay_capacity'] ?? 0) }} m³ with charters
                        @else
                            Bay: {{ number_format($rates['shared_bay_capacity'] ?? 0) }} m³
                        @endif
                    </small>
                    @endif
                </div>
            </div>
            
            <!-- Charters (always show) -->
            <div class="col-md-6">
                @if($latestHistory->requires_charters)
                    <div class="resource-card {{ $latestHistory->limiting_factor == 'charters' ? 'limiting-factor' : '' }}">
                        @if($latestHistory->limiting_factor == 'charters')
                        <span class="limiting-badge">LIMITING</span>
                        @endif
                        
                        <div class="text-center resource-icon">
                            <i class="fas fa-certificate text-warning-bright"></i>
                        </div>
                        
                        <h5 class="text-center">Starbase Charters</h5>
                        
                        <div class="stat-box text-center">
                            <div class="stat-number {{ $latestHistory->charter_days_remaining < 7 ? 'text-danger-bright' : ($latestHistory->charter_days_remaining < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                                {{ number_format($latestHistory->charter_quantity) }}
                            </div>
                            <div class="stat-label">Charters in Bay</div>
                        </div>
                        
                        <div class="stat-box text-center">
                            <div class="stat-number {{ $latestHistory->charter_days_remaining < 7 ? 'text-danger-bright' : ($latestHistory->charter_days_remaining < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                                @php
                                    $charterDays = floor($latestHistory->charter_days_remaining);
                                    $charterHours = floor(($latestHistory->charter_days_remaining - $charterDays) * 24);
                                @endphp
                                @if ($charterDays == 0)
                                    {{ $charterHours }}h
                                @else
                                    {{ $charterDays }}d {{ $charterHours }}h
                                @endif
                            </div>
                            <div class="stat-label">Days Remaining</div>
                        </div>
                        
                        @if($rates)
                        <div class="progress-bar-wrapper">
                            @php
                                $charterBayPct = $rates['charter_bay_usage_pct'] ?? 0;
                                // Gradient: red -> yellow -> green -> blue (blue is highest)
                                if ($charterBayPct >= 90) {
                                    $charterGradient = 'linear-gradient(90deg, #17a2b8, #5dade2)'; // blue
                                } elseif ($charterBayPct >= 70) {
                                    $charterGradient = 'linear-gradient(90deg, #28a745, #51cf66)'; // green
                                } elseif ($charterBayPct >= 50) {
                                    $charterGradient = 'linear-gradient(90deg, #ffc107, #28a745)'; // yellow to green
                                } elseif ($charterBayPct >= 25) {
                                    $charterGradient = 'linear-gradient(90deg, #ff8c00, #ffc107)'; // orange to yellow
                                } else {
                                    $charterGradient = 'linear-gradient(90deg, #dc3545, #ff6b6b)'; // red
                                }
                            @endphp
                            <div class="progress-bar-fill" style="width: {{ $charterBayPct }}%; background: {{ $charterGradient }};">
                                {{ number_format($charterBayPct, 0) }}%
                            </div>
                        </div>
                        <small class="text-muted">
                            Bay Usage: 1/hr | Shares {{ number_format($rates['shared_bay_capacity'] ?? 0) }} m³ with fuel
                        </small>
                        @endif
                    </div>
                @else
                    {{-- Not required - show info card --}}
                    <div class="resource-card charter-not-required">
                        <div class="text-center resource-icon">
                            <i class="fas fa-certificate" style="color: #6c757d;"></i>
                        </div>
                        
                        <h5 class="text-center" style="color: #6c757d;">Starbase Charters</h5>
                        
                        <div class="stat-box text-center">
                            <div style="font-size: 2rem; color: #6c757d; margin: 2rem 0;">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="stat-label">Not Required</div>
                            <div class="stat-label mt-2">
                                Charters only needed in<br>High-Sec (≥0.5 security)
                            </div>
                        </div>
                        
                        <small class="text-muted text-center d-block">
                            Consumption: 1/hr (static) | Not needed in {{ $latestHistory->space_type }}
                        </small>
                    </div>
                @endif
            </div>
            
            <!-- Strontium Clathrates -->
            <div class="col-md-12">
                <div class="resource-card strontium-card {{ $latestHistory->hasCriticalStrontium() ? 'strontium-critical' : '' }}">
                    <div class="text-center resource-icon">
                        <i class="fas fa-shield-alt {{ $latestHistory->hasCriticalStrontium() ? 'text-danger-bright' : 'text-info-bright' }}"></i>
                    </div>
                    
                    <h5 class="text-center">Strontium Clathrates (Reinforcement)</h5>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-box text-center">
                                <div class="stat-number {{ $latestHistory->strontium_hours_available < 6 ? 'text-danger-bright' : ($latestHistory->strontium_hours_available < 12 ? 'text-warning-bright' : 'text-success-bright') }}">
                                    {{ number_format($latestHistory->strontium_quantity) }}
                                </div>
                                <div class="stat-label">Units in Bay</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center">
                                <div class="stat-number {{ $latestHistory->strontium_hours_available < 6 ? 'text-danger-bright' : ($latestHistory->strontium_hours_available < 12 ? 'text-warning-bright' : 'text-success-bright') }}">
                                    {{ number_format($latestHistory->strontium_hours_available, 1) }}h
                                </div>
                                <div class="stat-label">Reinforcement Timer</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center">
                                <div class="stat-number text-info-bright">
                                    {{ number_format($rates['strontium_bay_capacity'] ?? 0) }}
                                </div>
                                <div class="stat-label">Bay Capacity (units)</div>
                                <div class="stat-label" style="font-size: 0.7rem; margin-top: 0.2rem;">
                                    {{ number_format($rates['strontium_bay_capacity_m3'] ?? 0) }} m³ @ 3m³/unit
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box text-center">
                                <div class="stat-number text-warning-bright">
                                    {{ number_format($rates['strontium_good_level'] ?? 2400) }}
                                </div>
                                <div class="stat-label">Good Level (24h)</div>
                            </div>
                        </div>
                    </div>
                    
                    @if($rates)
                    {{-- Bar 1: Bay Capacity Usage --}}
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small style="color: #a0a0a0; font-weight: 600;">Bay Capacity Usage</small>
                            <small style="color: #5dade2;">{{ number_format($latestHistory->strontium_quantity) }} / {{ number_format($rates['strontium_bay_capacity']) }}</small>
                        </div>
                        <div class="progress-bar-wrapper">
                            @php
                                $strontBayPct = $rates['strontium_bay_usage_pct'] ?? 0;
                                // Gradient: red -> yellow -> green based on fullness
                                if ($strontBayPct >= 75) {
                                    $bayGradient = 'linear-gradient(90deg, #28a745, #51cf66)'; // green
                                } elseif ($strontBayPct >= 50) {
                                    $bayGradient = 'linear-gradient(90deg, #ffc107, #17a2b8)'; // yellow to blue
                                } elseif ($strontBayPct >= 25) {
                                    $bayGradient = 'linear-gradient(90deg, #ff8c00, #ffc107)'; // orange to yellow
                                } else {
                                    $bayGradient = 'linear-gradient(90deg, #dc3545, #ff6b6b)'; // red
                                }
                            @endphp
                            <div class="progress-bar-fill" style="width: {{ $strontBayPct }}%; background: {{ $bayGradient }};">
                                {{ number_format($strontBayPct, 1) }}%
                            </div>
                        </div>
                    </div>
                    
                    {{-- Bar 2: Good Level Coverage --}}
                    <div class="mt-2">
                        <div class="d-flex justify-content-between mb-1">
                            <small style="color: #a0a0a0; font-weight: 600;">Good Level Coverage (24h Optimal)</small>
                            <small style="color: {{ $latestHistory->strontium_hours_available >= 24 ? '#51cf66' : ($latestHistory->strontium_hours_available >= 12 ? '#ffc107' : '#ff6b6b') }};">
                                {{ number_format($latestHistory->strontium_hours_available, 1) }}h / 24h
                            </small>
                        </div>
                        <div class="progress-bar-wrapper">
                            @php
                                $strontGoodPct = $rates['strontium_good_level_pct'] ?? 0;
                                // Gradient based on good level achievement
                                if ($strontGoodPct >= 100) {
                                    $goodGradient = 'linear-gradient(90deg, #28a745, #51cf66)'; // green - excellent
                                } elseif ($strontGoodPct >= 75) {
                                    $goodGradient = 'linear-gradient(90deg, #5dade2, #17a2b8)'; // blue - good
                                } elseif ($strontGoodPct >= 50) {
                                    $goodGradient = 'linear-gradient(90deg, #ffc107, #e0a800)'; // yellow - fair
                                } elseif ($strontGoodPct >= 25) {
                                    $goodGradient = 'linear-gradient(90deg, #ff8c00, #ffc107)'; // orange - low
                                } else {
                                    $goodGradient = 'linear-gradient(90deg, #dc3545, #c82333)'; // red - critical
                                }
                            @endphp
                            <div class="progress-bar-fill" style="width: {{ min(100, $strontGoodPct) }}%; background: {{ $goodGradient }};">
                                {{ number_format(min(100, $strontGoodPct), 1) }}%
                            </div>
                        </div>
                    </div>
                    
                    <small class="text-muted d-block text-center mt-2">
                        Status: <strong>{{ strtoupper($latestHistory->strontium_status) }}</strong> | 
                        Consumption: {{ number_format($rates['strontium_for_reinforced']) }}/hr when reinforced
                    </small>
                    @endif
                </div>
            </div>
        </div>
        @else
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            No fuel tracking data available yet.
        </div>
        @endif
    </div>
    
    <!-- Right Column: Empty for now, chart moved to bottom -->
    <div class="col-md-4">
        <!-- Reserved for future use -->
    </div>
</div>

<!-- Full-width Fuel History Chart (bottom) -->
@if($history && $history->count() > 0)
<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> Fuel History (30 Days)</h3>
            </div>
            <div class="card-body">
                <canvas id="fuel-history-chart-full" height="60"></canvas>
            </div>
        </div>
    </div>
</div>
@endif

</div>
@endsection

@push('javascript')
<script src="{{ asset('web/css/structure-manager/js/chart.min.js') }}"></script>
<script>
@if($history && $history->count() > 0)
// Prepare chart data
var dates = {!! json_encode($history->pluck('created_at')->map(function($date) { return $date->format('M d'); })) !!};
var fuelData = {!! json_encode($history->pluck('fuel_days_remaining')) !!};
var strontiumData = {!! json_encode($history->pluck('strontium_hours_available')->map(function($h) { return $h / 24; })) !!};
@if($latestHistory && $latestHistory->requires_charters)
var charterData = {!! json_encode($history->pluck('charter_days_remaining')) !!};
@endif

// Full-width chart at bottom
var ctxFull = document.getElementById('fuel-history-chart-full');
if (ctxFull) {
    var chartFull = new Chart(ctxFull.getContext('2d'), {
        type: 'line',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'Fuel (days)',
                    data: fuelData,
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    tension: 0.4
                },
                @if($latestHistory && $latestHistory->requires_charters)
                {
                    label: 'Charters (days)',
                    data: charterData,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4
                },
                @endif
                {
                    label: 'Strontium (days)',
                    data: strontiumData,
                    borderColor: '#9c27b0',
                    backgroundColor: 'rgba(156, 39, 176, 0.1)',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    labels: { color: '#fff' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });
}
@endif
</script>
@endpush
