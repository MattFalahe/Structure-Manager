@extends('web::layouts.grids.12')

@section('title', 'Structure Details')
@section('page_header', 'Structure Details')

@push('head')
<style>
    /* DARK THEME COMPATIBLE STYLES */
    .structure-header {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .structure-header h3 {
        margin: 0;
        color: #17a2b8;
    }
    
    .stat-box {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: center;
    }
    
    .stat-box h4 {
        margin: 0 0 0.5rem 0;
        font-size: 0.9rem;
        opacity: 0.8;
    }
    
    .stat-box .value {
        font-size: 1.8rem;
        font-weight: bold;
        margin-bottom: 0.25rem;
    }
    
    .stat-box .label {
        font-size: 0.85rem;
        opacity: 0.7;
    }
    
    /* Bright colors for dark theme */
    .text-success-bright { color: #51cf66; }
    .text-warning-bright { color: #ffd43b; }
    .text-danger-bright { color: #ff6b6b; }
    .text-info-bright { color: #5dade2; }
    
    .info-banner {
        background: rgba(23, 162, 184, 0.15);
        border: 1px solid rgba(23, 162, 184, 0.3);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .warning-banner {
        background: rgba(255, 193, 7, 0.15);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .services-list {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .list-group-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: inherit;
    }
    
    .list-group-item:hover {
        background: rgba(0, 0, 0, 0.3);
    }
    
    /* Metenox-specific styles */
    .metenox-box {
        background: linear-gradient(135deg, rgba(156, 39, 176, 0.15), rgba(103, 58, 183, 0.15));
        border: 2px solid rgba(156, 39, 176, 0.4);
        border-radius: 0.75rem;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 12px rgba(156, 39, 176, 0.2);
    }
    
    .metenox-box h4 {
        color: #9c27b0;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .metenox-resource {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        margin: 0.5rem 0;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 0.5rem;
        border: 1px solid rgba(156, 39, 176, 0.2);
    }
    
    .limiting-factor-badge {
        background: linear-gradient(135deg, #ff5722, #f44336);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 0.25rem;
        font-weight: bold;
        font-size: 0.85rem;
        box-shadow: 0 2px 6px rgba(255, 87, 34, 0.4);
        animation: pulse 2s ease-in-out infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    
    .fuel-history-table {
        width: 100%;
        margin-top: 1rem;
    }
    
    .fuel-history-table th {
        background: rgba(0, 0, 0, 0.3);
        padding: 0.75rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .fuel-history-table td {
        padding: 0.75rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .chart-container {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-top: 1.5rem;
        height: 400px;
    }
</style>
@endpush

@section('content')
<div class="structure-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h3>
                <i class="fas fa-building"></i>
                {{ $structure->name }}
            </h3>
            <p class="mb-0">
                <strong>Type:</strong> {{ $structure->type_name }}<br>
                <strong>System:</strong> {{ $structure->system_name }}<br>
                <strong>Corporation:</strong> {{ $structure->corporation_name }}
            </p>
        </div>
        <div class="col-md-4 text-right">
            <a href="{{ route('structure-manager.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
</div>

@if($structure->type_id == 81826)
    {{-- Metenox Moon Drill Specific Display --}}
    <div class="metenox-box">
        <h4>
            <i class="fas fa-moon" style="color: #9c27b0;"></i>
            Metenox Moon Drill - Dual Fuel System
        </h4>
        
        @if(isset($structure->metenox_data))
            <div class="metenox-resource">
                <span>
                    <i class="fas fa-fire" style="color: #2196f3;"></i> 
                    <strong>Fuel Blocks:</strong>
                    <span class="{{ $structure->metenox_data['fuel_blocks_days'] < 3 ? 'text-danger-bright' : ($structure->metenox_data['fuel_blocks_days'] < 7 ? 'text-warning-bright' : 'text-success-bright') }}" style="font-size: 1.1rem;">
                        {{ number_format($structure->metenox_data['fuel_blocks_days'], 1) }} days
                    </span>
                    <span style="opacity: 0.7; margin-left: 0.5rem;">
                        ({{ number_format($structure->metenox_data['fuel_blocks_quantity']) }} blocks)
                    </span>
                </span>
                @if($structure->metenox_data['limiting_factor'] === 'fuel_blocks')
                    <span class="limiting-factor-badge">
                        <i class="fas fa-exclamation-circle"></i> LIMITING
                    </span>
                @else
                    <span class="badge badge-success" style="background: #4caf50 !important;">OK</span>
                @endif
            </div>
            
            <div class="metenox-resource">
                <span>
                    <i class="fas fa-wind" style="color: #ff9800;"></i> 
                    <strong>Magmatic Gas:</strong>
                    <span class="{{ $structure->metenox_data['magmatic_gas_days'] < 3 ? 'text-danger-bright' : ($structure->metenox_data['magmatic_gas_days'] < 7 ? 'text-warning-bright' : 'text-success-bright') }}" style="font-size: 1.1rem;">
                        {{ number_format($structure->metenox_data['magmatic_gas_days'], 1) }} days
                    </span>
                    <span style="opacity: 0.7; margin-left: 0.5rem;">
                        ({{ number_format($structure->metenox_data['magmatic_gas_quantity']) }} units)
                    </span>
                </span>
                @if($structure->metenox_data['limiting_factor'] === 'magmatic_gas')
                    <span class="limiting-factor-badge">
                        <i class="fas fa-exclamation-circle"></i> LIMITING
                    </span>
                @else
                    <span class="badge badge-success" style="background: #4caf50 !important;">OK</span>
                @endif
            </div>
            
            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(156, 39, 176, 0.3); opacity: 0.85;">
                <small>
                    <i class="fas fa-info-circle" style="color: #2196f3;"></i> 
                    Structure will stop when <strong style="color: #ff5722;">{{ $structure->metenox_data['limiting_factor'] === 'fuel_blocks' ? 'fuel blocks' : 'magmatic gas' }}</strong> runs out
                </small>
            </div>
        @else
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No fuel bay data available for this Metenox Moon Drill
            </div>
        @endif
    </div>
@endif

{{-- Fuel Status Overview --}}
<div class="row">
    <div class="col-md-3">
        <div class="stat-box">
            <h4>Fuel Expires</h4>
            <div class="value {{ $structure->hours_remaining < 168 ? 'text-danger-bright' : ($structure->hours_remaining < 336 ? 'text-warning-bright' : 'text-success-bright') }}">
                {{ $structure->days_remaining }} days
            </div>
            <div class="label">{{ $structure->remaining_hours }} hours</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stat-box">
            <h4>Weekly Requirement</h4>
            <div class="value text-info-bright">
                {{ number_format($structure->blocks_needed) }}
            </div>
            <div class="label">blocks ({{ number_format($structure->blocks_needed * 5) }} m³)</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stat-box">
            <h4>30-Day Need</h4>
            <div class="value text-info-bright">
                {{ number_format($structure->blocks_needed * 4.3) }}
            </div>
            <div class="label">blocks ({{ number_format($structure->blocks_needed * 4.3 * 5) }} m³)</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stat-box">
            <h4>Status</h4>
            <div class="value">
                @if($structure->state === 'online')
                    <span class="text-success-bright"><i class="fas fa-check-circle"></i></span>
                @elseif($structure->state === 'offline')
                    <span class="text-danger-bright"><i class="fas fa-times-circle"></i></span>
                @else
                    <span class="text-warning-bright"><i class="fas fa-exclamation-circle"></i></span>
                @endif
            </div>
            <div class="label">{{ ucfirst($structure->state) }}</div>
        </div>
    </div>
</div>

{{-- Fuel Consumption Details --}}
<div class="info-banner">
    <i class="fas fa-info-circle"></i> 
    <strong>Service-Based Calculation:</strong> Fuel consumption rates are calculated based on active service modules. Rates update in real-time as services come online or offline.
</div>

@if(isset($fuelProjection))
<div class="row">
    <div class="col-md-6">
        <div class="services-list">
            <h4><i class="fas fa-cogs"></i> Current Consumption</h4>
            <ul class="list-group">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Hourly Rate
                    <span class="badge badge-primary badge-pill">{{ number_format($fuelProjection['hourly'], 1) }} blocks/hr</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Daily Rate
                    <span class="badge badge-primary badge-pill">{{ number_format($fuelProjection['daily']) }} blocks/day</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Weekly Rate
                    <span class="badge badge-primary badge-pill">{{ number_format($fuelProjection['weekly']) }} blocks/week</span>
                </li>
            </ul>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="services-list">
            <h4><i class="fas fa-battery-three-quarters"></i> Estimated Supply</h4>
            <ul class="list-group">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Blocks Remaining
                    <span class="badge badge-info badge-pill">{{ number_format($fuelProjection['blocks_remaining']) }} blocks</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Volume in Bay
                    <span class="badge badge-info badge-pill">{{ number_format($fuelProjection['blocks_remaining'] * 5) }} m³</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Time Remaining
                    <span class="badge {{ $structure->hours_remaining < 168 ? 'badge-danger' : ($structure->hours_remaining < 336 ? 'badge-warning' : 'badge-success') }}">
                        {{ $structure->days_remaining }}d {{ $structure->remaining_hours }}h
                    </span>
                </li>
            </ul>
        </div>
    </div>
</div>
@endif

{{-- Active Services --}}
@if(isset($structure->services) && count($structure->services) > 0)
<div class="services-list">
    <h4><i class="fas fa-server"></i> Active Service Modules</h4>
    <ul class="list-group">
        @foreach($structure->services as $service)
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-plug text-success-bright"></i>
                {{ $service->name }}
            </span>
            <span class="badge badge-{{ $service->state === 'online' ? 'success' : 'secondary' }} badge-pill">
                {{ ucfirst($service->state) }}
            </span>
        </li>
        @endforeach
    </ul>
</div>
@endif

{{-- Historical Fuel Data --}}
@if(isset($fuelHistory) && count($fuelHistory) > 0)
<div class="services-list">
    <h4><i class="fas fa-history"></i> Recent Fuel Records</h4>
    <div class="table-responsive">
        <table class="fuel-history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Days Remaining</th>
                    <th>Consumption</th>
                    @if($structure->type_id == 81826)
                    <th>Limiting Factor</th>
                    @endif
                    <th>Method</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fuelHistory as $record)
                <tr>
                    <td>{{ $record->created_at->format('M d, Y H:i') }}</td>
                    <td>{{ number_format($record->days_remaining, 1) }} days</td>
                    <td>{{ number_format($record->daily_consumption ?? 0) }} blocks/day</td>
                    @if($structure->type_id == 81826)
                    <td>
                        @if($record->metadata && isset($record->metadata['limiting_factor']))
                            @if($record->metadata['limiting_factor'] === 'fuel_blocks')
                                <span class="badge badge-primary">
                                    <i class="fas fa-fire"></i> Fuel Blocks
                                </span>
                            @elseif($record->metadata['limiting_factor'] === 'magmatic_gas')
                                <span class="badge badge-warning">
                                    <i class="fas fa-wind"></i> Magmatic Gas
                                </span>
                            @else
                                <span class="badge badge-secondary">N/A</span>
                            @endif
                        @else
                            <span class="badge badge-secondary">N/A</span>
                        @endif
                    </td>
                    @endif
                    <td>
                        <span class="badge badge-{{ $record->tracking_type === 'fuel_bay' ? 'success' : 'info' }}">
                            {{ ucfirst($record->tracking_type) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Fuel Consumption Chart --}}
@if(isset($fuelHistory) && count($fuelHistory) >= 2)
<div class="chart-container">
    <canvas id="fuelChart"></canvas>
</div>
@endif

@endsection

@push('javascript')
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

@if(isset($fuelHistory) && count($fuelHistory) >= 2)
<script>
$(document).ready(function() {
    const ctx = document.getElementById('fuelChart');
    const fuelData = @json($fuelHistory);
    
    const labels = fuelData.map(record => {
        const date = new Date(record.created_at);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    });
    
    const daysData = fuelData.map(record => record.days_remaining);
    
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
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
                            label += context.parsed.y.toFixed(1) + ' days';
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
@endif
@endpush
