@extends('web::layouts.grids.12')

@section('title', $structure->structure_name)
@section('page_header', $structure->structure_name)

@push('head')
<style>
    /* Better contrast for dark themes */
    .text-success-bright { color: #51cf66; }
    .text-warning-bright { color: #ffd43b; }
    .text-danger-bright { color: #ff6b6b; }
    .text-info-bright { color: #5dade2; }
    
    /* DARK THEME COMPATIBLE - Service list items */
    .list-group-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 0.5rem;
    }
    
    .list-group-item:hover {
        background: rgba(0, 0, 0, 0.3);
    }
    
    /* Better badge contrast */
    .badge-success {
        background-color: #28a745 !important;
    }
    
    .badge-danger {
        background-color: #dc3545 !important;
    }
    
    .badge-warning {
        background-color: #ffc107 !important;
        color: #000000 !important;
    }
    
    /* Stat boxes */
    .stat-box {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .stat-box h4 {
        margin-bottom: 0.5rem;
        font-size: 1.1rem;
        color: #17a2b8;
    }
    
    .stat-number {
        font-size: 1.5rem;
        font-weight: bold;
        color: #3c994b;
    }
    
    .stat-label {
        font-size: 0.875rem;
        color: #a0a0a0;
    }
    
    /* Info banner */
    .info-banner {
        background: rgba(23, 162, 184, 0.1);
        border-left: 4px solid #17a2b8;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border-radius: 0.25rem;
    }
    
    .warning-banner {
        background: rgba(255, 193, 7, 0.1);
        border-left: 4px solid #ffc107;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border-radius: 0.25rem;
    }
    
    /* Metenox-specific styles */
    .metenox-banner {
        background: rgba(156, 39, 176, 0.1);
        border-left: 4px solid #9c27b0;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border-radius: 0.25rem;
    }
    
    .metenox-badge {
        background-color: rgba(193, 114, 207, 0.2);
        color: #c04ed4;
        border: 1px solid rgba(156, 39, 176, 0.3);
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.85rem;
        font-weight: bold;
        margin-left: 0.5rem;
    }
    
    .resource-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .limiting-factor {
        border: 2px solid #ff6b6b;
        background: rgba(220, 53, 69, 0.1);
    }
    
    .resource-card h5 {
        margin-bottom: 0.75rem;
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-info-circle"></i> Structure Information
                    @if($structure->structure_type == 'Metenox Moon Drill')
                        <span class="metenox-badge"><i class="fas fa-wind"></i> METENOX</span>
                    @endif
                </h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">Type:</dt>
                    <dd class="col-sm-8">
                        {{ $structure->structure_type }}
                        @if($structure->structure_type == 'Metenox Moon Drill')
                            <span class="badge badge-info">Deployable</span>
                        @endif
                    </dd>
                    
                    <dt class="col-sm-4">System:</dt>
                    <dd class="col-sm-8">
                        {{ $structure->system_name }} 
                        <span class="{{ $structure->security >= 0.5 ? 'text-success-bright' : ($structure->security > 0 ? 'text-warning-bright' : 'text-danger-bright') }}">
                            ({{ number_format($structure->security, 1) }})
                        </span>
                    </dd>
                    
                    <dt class="col-sm-4">Corporation:</dt>
                    <dd class="col-sm-8">{{ $structure->corporation_name }}</dd>
                    
                    <dt class="col-sm-4">State:</dt>
                    <dd class="col-sm-8">
                        <span class="badge badge-{{ $structure->state == 'shield_vulnerable' ? 'success' : 'warning' }}">
                            {{ str_replace('_', ' ', ucwords($structure->state)) }}
                        </span>
                    </dd>
                    
                    <dt class="col-sm-4">Fuel Expires:</dt>
                    <dd class="col-sm-8">
                        @if($structure->fuel_expires)
                            {{ \Carbon\Carbon::parse($structure->fuel_expires)->format('Y-m-d H:i:s') }}
                            <br>
                            @php
                                $fuelExpires = \Carbon\Carbon::parse($structure->fuel_expires);
                                $now = now();
                                $totalHours = $fuelExpires->diffInHours($now);
                                $days = floor($totalHours / 24);
                                $hours = $totalHours % 24;
                                $colorClass = $totalHours < 168 ? 'text-danger-bright' : ($totalHours < 336 ? 'text-warning-bright' : 'text-success-bright');
                            @endphp
                            <strong class="{{ $colorClass }}">
                                <i class="fas fa-clock"></i> {{ $days }}d {{ $hours }}h remaining
                            </strong>
                            @if($structure->structure_type == 'Metenox Moon Drill')
                                <br><small class="text-warning-bright"><i class="fas fa-exclamation-triangle"></i> Fuel blocks only - check gas below!</small>
                            @endif
                        @else
                            <span class="text-muted">Unknown</span>
                        @endif
                    </dd>
                    
                    <dt class="col-sm-4">Reinforce Hour:</dt>
                    <dd class="col-sm-8">{{ $structure->reinforce_hour }}:00 EVE Time</dd>
                    
                    <dt class="col-sm-4">Last Updated:</dt>
                    <dd class="col-sm-8">{{ \Carbon\Carbon::parse($structure->updated_at)->diffForHumans() }}</dd>
                </dl>
            </div>
        </div>
        
        {{-- Metenox Dual Fuel Display --}}
        @php
            // Better Metenox detection - check BOTH structure type AND consumption method
            $isMetenox = ($structure->structure_type == 'Metenox Moon Drill') || 
                         (isset($consumption['method']) && $consumption['method'] == 'metenox_drill');
        @endphp
        
        @if($isMetenox)
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-gas-pump"></i> Metenox Fuel Status</h3>
            </div>
            <div class="card-body">
                <div class="metenox-banner">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Dual Fuel System:</strong> Metenox requires both fuel blocks AND magmatic gas. Structure stops when EITHER runs out!
                </div>
                
                @php
                    $fuelBlocks = $consumption['fuel_blocks'] ?? null;
                    $magmaticGas = $consumption['magmatic_gas'] ?? null;
                    $limitingFactor = $consumption['limiting_factor'] ?? 'unknown';
                    $actualDays = $consumption['actual_days_remaining'] ?? 0;
                @endphp
                
                @if($fuelBlocks && $magmaticGas)
                    <div class="row">
                        <div class="col-md-6">
                            <div class="resource-card {{ $limitingFactor == 'fuel_blocks' ? 'limiting-factor' : '' }}">
                                <h5>
                                    <i class="fas fa-battery-three-quarters text-info-bright"></i> Fuel Blocks
                                    @if($limitingFactor == 'fuel_blocks')
                                        <span class="badge badge-danger">LIMITING</span>
                                    @endif
                                </h5>
                                <div class="stat-number">{{ number_format($fuelBlocks['current_quantity'] ?? 0) }}</div>
                                <div class="stat-label">blocks available</div>
                                
                                <hr>
                                
                                <dl class="row mb-0">
                                    <dt class="col-sm-6">Days Remaining:</dt>
                                    <dd class="col-sm-6">
                                        <strong class="{{ ($fuelBlocks['days_remaining'] ?? 0) < 7 ? 'text-danger-bright' : (($fuelBlocks['days_remaining'] ?? 0) < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                                            {{ number_format($fuelBlocks['days_remaining'] ?? 0, 1) }} days
                                        </strong>
                                    </dd>
                                    
                                    <dt class="col-sm-6">Consumption:</dt>
                                    <dd class="col-sm-6">5 blocks/hour</dd>
                                    
                                    <dt class="col-sm-6">Daily Use:</dt>
                                    <dd class="col-sm-6">120 blocks/day</dd>
                                </dl>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="resource-card {{ $limitingFactor == 'magmatic_gas' ? 'limiting-factor' : '' }}">
                                <h5>
                                    <i class="fas fa-wind text-warning-bright"></i> Magmatic Gas
                                    @if($limitingFactor == 'magmatic_gas')
                                        <span class="badge badge-danger">LIMITING</span>
                                    @endif
                                </h5>
                                <div class="stat-number">{{ number_format($magmaticGas['current_quantity'] ?? 0) }}</div>
                                <div class="stat-label">units available</div>
                                
                                <hr>
                                
                                <dl class="row mb-0">
                                    <dt class="col-sm-6">Days Remaining:</dt>
                                    <dd class="col-sm-6">
                                        <strong class="{{ ($magmaticGas['days_remaining'] ?? 0) < 7 ? 'text-danger-bright' : (($magmaticGas['days_remaining'] ?? 0) < 14 ? 'text-warning-bright' : 'text-success-bright') }}">
                                            {{ number_format($magmaticGas['days_remaining'] ?? 0, 1) }} days
                                        </strong>
                                    </dd>
                                    
                                    <dt class="col-sm-6">Consumption:</dt>
                                    <dd class="col-sm-6">200 gas/hour</dd>
                                    
                                    <dt class="col-sm-6">Daily Use:</dt>
                                    <dd class="col-sm-6">4,800 gas/day</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-{{ $actualDays < 7 ? 'danger' : ($actualDays < 14 ? 'warning' : 'info') }} mb-0">
                        <h5 class="mb-2">
                            <i class="fas fa-stopwatch"></i> Actual Time Until Empty
                        </h5>
                        <div style="font-size: 1.5rem; font-weight: bold;">
                            {{ number_format($actualDays, 1) }} days
                        </div>
                        @if(isset($consumption['warning']) && $consumption['warning'])
                            <hr>
                            <p class="mb-0"><i class="fas fa-exclamation-triangle"></i> {{ $consumption['warning'] }}</p>
                        @endif
                    </div>
                @else
                    {{-- Fallback if consumption data not available --}}
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Dual Fuel System Active</strong><br>
                        This Metenox Moon Drill requires both fuel blocks and magmatic gas.<br>
                        Consumption: 120 blocks/day + 4,800 gas/day<br>
                        <small>Detailed fuel bay data will be available after the next tracking update.</small>
                    </div>
                @endif
            </div>
        </div>

        {{-- METENOX CONSUMPTION STATISTICS --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fire"></i> Fuel Consumption Statistics</h3>
            </div>
            <div class="card-body">
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Metenox Moon Drill:</strong> Consumption rates are fixed at 5 blocks/hour (120/day) and 200 gas/hour (4,800/day).
                </div>
                
                {{-- Fuel Blocks Consumption --}}
                <h5 class="mb-3"><i class="fas fa-fire text-warning-bright"></i> Fuel Blocks</h5>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-day"></i> Daily</h4>
                            <div class="stat-number">120</div>
                            <div class="stat-label">blocks/day</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-week"></i> Weekly</h4>
                            <div class="stat-number">840</div>
                            <div class="stat-label">blocks/week</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-alt"></i> Monthly</h4>
                            <div class="stat-number">3,600</div>
                            <div class="stat-label">blocks/month</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar"></i> Quarterly</h4>
                            <div class="stat-number">10,800</div>
                            <div class="stat-label">blocks/quarter</div>
                        </div>
                    </div>
                </div>

                {{-- Magmatic Gas Consumption --}}
                <h5 class="mb-3"><i class="fas fa-wind text-info-bright"></i> Magmatic Gas</h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-day"></i> Daily</h4>
                            <div class="stat-number">4,800</div>
                            <div class="stat-label">units/day</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-week"></i> Weekly</h4>
                            <div class="stat-number">33,600</div>
                            <div class="stat-label">units/week</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-alt"></i> Monthly</h4>
                            <div class="stat-number">144,000</div>
                            <div class="stat-label">units/month</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar"></i> Quarterly</h4>
                            <div class="stat-number">432,000</div>
                            <div class="stat-label">units/quarter</div>
                        </div>
                    </div>
                </div>

                @if($fuelBlocks && $magmaticGas)
                <hr>
                
                <h5><i class="fas fa-chart-line"></i> Fuel Projections</h5>
                <div class="row">
                    {{-- Fuel Blocks Projections --}}
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-fire text-info-bright"></i> Fuel Blocks</h6>
                        <dl class="row">
                            <dt class="col-sm-6">Current Stock:</dt>
                            <dd class="col-sm-6">{{ number_format($fuelBlocks['current_quantity'] ?? 0) }} blocks</dd>
                            
                            <dt class="col-sm-6">Volume:</dt>
                            <dd class="col-sm-6">{{ number_format(($fuelBlocks['current_quantity'] ?? 0) * 5) }} m³</dd>
                            
                            <dt class="col-sm-6">Runs Out:</dt>
                            <dd class="col-sm-6">
                                @if(isset($fuelBlocks['days_remaining']) && $fuelBlocks['days_remaining'] > 0)
                                    {{ \Carbon\Carbon::now()->addDays($fuelBlocks['days_remaining'])->format('Y-m-d H:i') }}
                                @else
                                    <span class="text-danger-bright">Empty</span>
                                @endif
                            </dd>
                            
                            <dt class="col-sm-6">Days Left:</dt>
                            @php
                                $fuelDays = isset($fuelBlocks['days_remaining']) ? floor($fuelBlocks['days_remaining']) : 0;
                                $fuelHours = isset($fuelBlocks['days_remaining']) ? floor(($fuelBlocks['days_remaining'] - $fuelDays) * 24) : 0;
                                $fuelColorClass = ($fuelBlocks['days_remaining'] ?? 0) < 7 ? 'text-danger-bright' : 
                                                 (($fuelBlocks['days_remaining'] ?? 0) < 14 ? 'text-warning-bright' : 'text-success-bright');
                            @endphp
                            <dd class="col-sm-6 {{ $fuelColorClass }}">{{ $fuelDays }}d {{ $fuelHours }}h</dd>
                            
                            <dt class="col-sm-6">Hourly Rate:</dt>
                            <dd class="col-sm-6">5.00 blocks/hr</dd>
                        </dl>
                    </div>
                    
                    {{-- Magmatic Gas Projections --}}
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-wind text-warning-bright"></i> Magmatic Gas</h6>
                        <dl class="row">
                            <dt class="col-sm-6">Current Stock:</dt>
                            <dd class="col-sm-6">{{ number_format($magmaticGas['current_quantity'] ?? 0) }} units</dd>
                            
                            <dt class="col-sm-6">Volume:</dt>
                            <dd class="col-sm-6">{{ number_format(($magmaticGas['current_quantity'] ?? 0) * 0.01) }} m³</dd>
                            
                            <dt class="col-sm-6">Runs Out:</dt>
                            <dd class="col-sm-6">
                                @if(isset($magmaticGas['days_remaining']) && $magmaticGas['days_remaining'] > 0)
                                    {{ \Carbon\Carbon::now()->addDays($magmaticGas['days_remaining'])->format('Y-m-d H:i') }}
                                @else
                                    <span class="text-danger-bright">Empty</span>
                                @endif
                            </dd>
                            
                            <dt class="col-sm-6">Days Left:</dt>
                            @php
                                $gasDays = isset($magmaticGas['days_remaining']) ? floor($magmaticGas['days_remaining']) : 0;
                                $gasHours = isset($magmaticGas['days_remaining']) ? floor(($magmaticGas['days_remaining'] - $gasDays) * 24) : 0;
                                $gasColorClass = ($magmaticGas['days_remaining'] ?? 0) < 7 ? 'text-danger-bright' : 
                                                (($magmaticGas['days_remaining'] ?? 0) < 14 ? 'text-warning-bright' : 'text-success-bright');
                            @endphp
                            <dd class="col-sm-6 {{ $gasColorClass }}">{{ $gasDays }}d {{ $gasHours }}h</dd>
                            
                            <dt class="col-sm-6">Hourly Rate:</dt>
                            <dd class="col-sm-6">200.00 units/hr</dd>
                        </dl>
                    </div>
                </div>

                {{-- Limiting Factor Summary --}}
                <div class="alert alert-{{ $actualDays < 7 ? 'danger' : ($actualDays < 14 ? 'warning' : 'info') }} mb-0 mt-3">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="mb-2">
                                <i class="fas fa-stopwatch"></i> Actual Time Until Empty
                            </h6>
                            <div style="font-size: 1.5rem; font-weight: bold;">
                                {{ number_format($actualDays, 1) }} days
                            </div>
                            @if($limitingFactor == 'fuel_blocks')
                                <small class="text-muted">Limited by: <strong>Fuel Blocks</strong> (runs out first)</small>
                            @elseif($limitingFactor == 'magmatic_gas')
                                <small class="text-muted">Limited by: <strong>Magmatic Gas</strong> (runs out first)</small>
                            @elseif($limitingFactor == 'none')
                                <small class="text-danger-bright"><strong>No fuel detected!</strong></small>
                            @else
                                <small class="text-muted">Calculating limiting factor...</small>
                            @endif
                        </div>
                        <div class="col-md-4 text-right">
                            @if($limitingFactor == 'fuel_blocks')
                                <span class="badge badge-info" style="font-size: 1rem; padding: 0.5rem 1rem;">
                                    <i class="fas fa-fire"></i> FUEL LIMITING
                                </span>
                            @elseif($limitingFactor == 'magmatic_gas')
                                <span class="badge badge-warning" style="font-size: 1rem; padding: 0.5rem 1rem; color: #000;">
                                    <i class="fas fa-wind"></i> GAS LIMITING
                                </span>
                            @elseif($limitingFactor == 'none')
                                <span class="badge badge-danger" style="font-size: 1rem; padding: 0.5rem 1rem;">
                                    <i class="fas fa-exclamation-triangle"></i> EMPTY
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
        @else
        {{-- Standard Upwell Structure Fuel Display --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fire"></i> Fuel Consumption Statistics</h3>
            </div>
            <div class="card-body">
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Service-Based Calculation:</strong> Consumption rates below are calculated from currently active service modules and update in real-time when services change.
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-day"></i> Daily</h4>
                            <div class="stat-number">{{ number_format($consumption['daily'] ?? 0) }}</div>
                            <div class="stat-label">blocks/day</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-week"></i> Weekly</h4>
                            <div class="stat-number">{{ number_format($consumption['weekly'] ?? 0) }}</div>
                            <div class="stat-label">blocks/week</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-alt"></i> Monthly</h4>
                            <div class="stat-number">{{ number_format($consumption['monthly'] ?? 0) }}</div>
                            <div class="stat-label">blocks/month</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar"></i> Quarterly</h4>
                            <div class="stat-number">{{ number_format($consumption['quarterly'] ?? 0) }}</div>
                            <div class="stat-label">blocks/quarter</div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <h5><i class="fas fa-chart-line"></i> Fuel Projections</h5>
                @if($structure->fuel_expires && ($consumption['daily'] ?? 0) > 0)
                    @php
                        $daysRemaining = \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now());
                        $blocksRemaining = $daysRemaining * ($consumption['daily'] ?? 0);
                        $volumeM3 = $blocksRemaining * 5; // Each fuel block is 5 m³
                    @endphp
                    <div class="row">
                        <div class="col-md-4">
                            <dl class="row">
                                <dt class="col-sm-6">Est. Blocks:</dt>
                                <dd class="col-sm-6">~{{ number_format($blocksRemaining) }}</dd>
                                
                                <dt class="col-sm-6">Volume:</dt>
                                <dd class="col-sm-6">~{{ number_format($volumeM3) }} m³</dd>
                            </dl>
                        </div>
                        <div class="col-md-4">
                            <dl class="row">
                                <dt class="col-sm-6">Runs Out:</dt>
                                <dd class="col-sm-6">{{ \Carbon\Carbon::parse($structure->fuel_expires)->format('Y-m-d H:i') }}</dd>
                                
                                <dt class="col-sm-6">Days Left:</dt>
                                <dd class="col-sm-6 {{ $colorClass }}">{{ $days }}d {{ $hours }}h</dd>
                            </dl>
                        </div>
                        <div class="col-md-4">
                            <dl class="row">
                                <dt class="col-sm-6">Hourly Rate:</dt>
                                <dd class="col-sm-6">{{ number_format($consumption['hourly'] ?? 0, 2) }} blocks/hr</dd>
                            </dl>
                        </div>
                    </div>
                @else
                    <p class="text-muted">Insufficient data for projections</p>
                @endif
                
                @if(isset($historicalAnalysis) && $historicalAnalysis['status'] === 'success')
                    <hr>
                    <div class="warning-banner">
                        <strong><i class="fas fa-database"></i> Historical Tracking Data:</strong>
                        Based on {{ $historicalAnalysis['analysis_period']['data_points'] ?? 0 }} fuel bay snapshots over {{ $historicalAnalysis['analysis_period']['days'] ?? 30 }} days.
                        @if(isset($historicalAnalysis['refuel_events']) && count($historicalAnalysis['refuel_events']) > 0)
                            <br><i class="fas fa-sync-alt"></i> {{ count($historicalAnalysis['refuel_events']) }} refuel event(s) detected.
                        @endif
                        @if(isset($historicalAnalysis['anomalies']) && count($historicalAnalysis['anomalies']) > 0)
                            <br><i class="fas fa-exclamation-triangle"></i> {{ count($historicalAnalysis['anomalies']) }} consumption anomaly(ies) detected (possible service changes).
                        @endif
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-server"></i> Online Services</h3>
            </div>
            <div class="card-body">
                @if($services->count() > 0)
                    <div class="list-group">
                        @foreach($services->where('state', 'online') as $service)
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <h6 class="mb-0">
                                        <i class="fas fa-circle text-success-bright" style="font-size: 0.5rem;"></i>
                                        {{ $service->name }}
                                    </h6>
                                    <span class="badge badge-success">Online</span>
                                </div>
                            </div>
                        @endforeach
                        
                        @foreach($services->where('state', '!=', 'online') as $service)
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <h6 class="mb-0 text-muted">
                                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                        {{ $service->name }}
                                    </h6>
                                    <span class="badge badge-secondary">{{ ucfirst($service->state) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            <strong>{{ $services->where('state', 'online')->count() }}</strong> service(s) online consuming fuel. 
                            Only online service modules consume fuel blocks.
                        </small>
                    </div>
                @else
                    @if($structure->structure_type == 'Metenox Moon Drill')
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Metenox Moon Drills do not have service modules. They automatically mine moons and consume fuel blocks + magmatic gas.
                        </div>
                    @else
                        <p class="text-muted">No services installed</p>
                    @endif
                @endif
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-area"></i> Fuel History (30 Days)</h3>
            </div>
            <div class="card-body">
                <canvas id="fuelHistoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list"></i> Recent Fuel Records</h3>
    </div>
    <div class="card-body">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Fuel Expires</th>
                    <th>Days Remaining</th>
                    @if($isMetenox)
                        <th>Magmatic Gas Days</th>
                        <th>Limiting Factor</th>
                    @endif
                    <th>Change</th>
                    <th>Event</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fuelHistory->take(15) as $record)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($record->created_at)->format('Y-m-d H:i') }}</td>
                        <td>{{ $record->fuel_expires ? \Carbon\Carbon::parse($record->fuel_expires)->format('Y-m-d H:i') : 'N/A' }}</td>
                        <td>{{ $record->days_remaining ?? 'N/A' }}</td>
                        @if($isMetenox)
                            <td>{{ $record->magmatic_gas_days ? number_format($record->magmatic_gas_days, 1) : 'N/A' }}</td>
                            <td>
                                @php
                                    $metadata = is_string($record->metadata) ? json_decode($record->metadata, true) : $record->metadata;
                                @endphp
                                @if($metadata && isset($metadata['limiting_factor']))
                                    @if($metadata['limiting_factor'] == 'magmatic_gas')
                                        <span class="badge badge-warning">Gas</span>
                                    @elseif($metadata['limiting_factor'] == 'fuel_blocks')
                                        <span class="badge badge-info">Fuel</span>
                                    @else
                                        <span class="badge badge-secondary">N/A</span>
                                    @endif
                                @else
                                    <span class="badge badge-secondary">N/A</span>
                                @endif
                            </td>
                        @endif
                        <td>
                            @if($loop->index < $fuelHistory->count() - 1)
                                @php
                                    $prev = $fuelHistory[$loop->index + 1];
                                    $change = $record->days_remaining - $prev->days_remaining;
                                @endphp
                                @if($change > 5)
                                    <span class="text-success-bright">
                                        <i class="fas fa-arrow-up"></i> +{{ $change }} days
                                    </span>
                                @elseif($change < -2)
                                    <span class="text-danger-bright">
                                        <i class="fas fa-arrow-down"></i> {{ $change }} days
                                    </span>
                                @else
                                    <span class="text-muted">{{ $change }} days</span>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @if($loop->index < $fuelHistory->count() - 1)
                                @php
                                    $prev = $fuelHistory[$loop->index + 1];
                                    $change = $record->days_remaining - $prev->days_remaining;
                                @endphp
                                @if($change > 5)
                                    <span class="badge badge-success">
                                        <i class="fas fa-gas-pump"></i> Refuel
                                    </span>
                                @elseif($change < -2)
                                    <span class="badge badge-warning" style="color: #000;">
                                        <i class="fas fa-bolt"></i> High Usage
                                    </span>
                                @else
                                    <span class="badge badge-secondary">Normal</span>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        
        @if($fuelHistory->count() > 15)
            <div class="text-center mt-3">
                <small class="text-muted">Showing 15 of {{ $fuelHistory->count() }} records</small>
            </div>
        @endif
    </div>
</div>
@endsection

@push('javascript')
{{-- Use plugin's bundled Chart.js instead of CDN --}}
<script src="{{ asset('vendor/structure-manager/js/chart.min.js') }}"></script>
<script src="{{ asset('vendor/structure-manager/js/moment.min.js') }}"></script>
<script>
    
$(document).ready(function() {
    // Prepare fuel history chart data
    let fuelHistory = @json($fuelHistory);
    
    let labels = fuelHistory.map(h => moment(h.created_at).format('MM-DD HH:mm')).reverse();
    let daysData = fuelHistory.map(h => h.days_remaining).reverse();
    
    // Calculate current consumption line
    let currentRate = {{ $consumption['daily'] ?? 0 }};
    let currentDaysRemaining = {{ $structure->fuel_expires ? \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now()) : 0 }};
    
    // Fix chart height to prevent infinite growth
    const canvas = document.getElementById('fuelHistoryChart');
    const ctx = canvas.getContext('2d');
    canvas.parentNode.style.height = '400px';
    canvas.parentNode.style.width = '100%';
    
    // Create gradient for historical data
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(75, 192, 192, 0.4)');
    gradient.addColorStop(1, 'rgba(75, 192, 192, 0.0)');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Days of Fuel Remaining',
                data: daysData,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: gradient,
                tension: 0.1,
                fill: true,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    labels: {
                        color: '#e0e0e0',
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.y + ' days';
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Days Remaining',
                        color: '#e0e0e0',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    ticks: {
                        color: '#a0a0a0'
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    }
                },
                x: {
                    ticks: {
                        color: '#a0a0a0',
                        maxRotation: 45,
                        minRotation: 45
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)'
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
});
</script>
@endpush
