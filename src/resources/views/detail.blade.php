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
        color: #51cf66;
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
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Structure Information</h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">Type:</dt>
                    <dd class="col-sm-8">{{ $structure->structure_type }}</dd>
                    
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
                            <div class="stat-number">{{ number_format($consumption['daily']) }}</div>
                            <div class="stat-label">blocks/day</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-week"></i> Weekly</h4>
                            <div class="stat-number">{{ number_format($consumption['weekly']) }}</div>
                            <div class="stat-label">blocks/week</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar-alt"></i> Monthly</h4>
                            <div class="stat-number">{{ number_format($consumption['monthly']) }}</div>
                            <div class="stat-label">blocks/month</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box">
                            <h4><i class="fas fa-calendar"></i> Quarterly</h4>
                            <div class="stat-number">{{ number_format($consumption['quarterly']) }}</div>
                            <div class="stat-label">blocks/quarter</div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <h5><i class="fas fa-chart-line"></i> Fuel Projections</h5>
                @if($structure->fuel_expires && $consumption['daily'] > 0)
                    @php
                        $daysRemaining = \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now());
                        $blocksRemaining = $daysRemaining * $consumption['daily'];
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
                                <dd class="col-sm-6">{{ number_format($consumption['hourly'], 2) }} blocks/hr</dd>
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
                    <p class="text-muted">No services installed</p>
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
    let currentRate = {{ $consumption['daily'] }};
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
