@extends('web::layouts.grids.12')

@section('title', $structure->structure_name)
@section('page_header', $structure->structure_name)

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Structure Information</h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-4">Type:</dt>
                    <dd class="col-sm-8">{{ $structure->structure_type }}</dd>
                    
                    <dt class="col-sm-4">System:</dt>
                    <dd class="col-sm-8">
                        {{ $structure->system_name }} 
                        <span class="{{ $structure->security >= 0.5 ? 'text-success' : ($structure->security > 0 ? 'text-warning' : 'text-danger') }}">
                            ({{ number_format($structure->security, 1) }})
                        </span>
                    </dd>
                    
                    <dt class="col-sm-4">Corporation:</dt>
                    <dd class="col-sm-8">{{ $structure->corporation_name }}</dd>
                    
                    <dt class="col-sm-4">State:</dt>
                    <dd class="col-sm-8">
                        <span class="badge badge-{{ $structure->state == 'shield_vulnerable' ? 'success' : 'warning' }}">
                            {{ str_replace('_', ' ', $structure->state) }}
                        </span>
                    </dd>
                    
                    <dt class="col-sm-4">Fuel Expires:</dt>
                    <dd class="col-sm-8">
                        @if($structure->fuel_expires)
                            {{ \Carbon\Carbon::parse($structure->fuel_expires)->format('Y-m-d H:i:s') }}
                            <br>
                            <strong class="{{ $days = \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now()) < 7 ? 'text-danger' : ($days < 14 ? 'text-warning' : 'text-success') }}">
                                {{ $days }} days remaining
                            </strong>
                        @else
                            <span class="text-muted">Unknown</span>
                        @endif
                    </dd>
                    
                    <dt class="col-sm-4">Reinforce Hour:</dt>
                    <dd class="col-sm-8">{{ $structure->reinforce_hour }}:00 EVE Time</dd>
                    
                    <dt class="col-sm-4">Last Updated:</dt>
                    <dd class="col-sm-8">{{ \Carbon\Carbon::parse($structure->updated_at)->diffForHumans() }}</dd>
                    
                    <dt class="col-sm-4">Reinforce Hour:</dt>
                    <dd class="col-sm-8">{{ $structure->reinforce_hour }}:00 EVE Time</dd>
                    
                    <dt class="col-sm-4">Last Updated:</dt>
                    <dd class="col-sm-8">{{ \Carbon\Carbon::parse($structure->updated_at)->diffForHumans() }}</dd>
                </dl>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Fuel Consumption Statistics</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Consumption Rates</h5>
                        <dl class="row">
                            <dt class="col-sm-6">Daily:</dt>
                            <dd class="col-sm-6">{{ $consumption['daily'] }} blocks</dd>
                            
                            <dt class="col-sm-6">Weekly:</dt>
                            <dd class="col-sm-6">{{ $consumption['weekly'] }} blocks</dd>
                            
                            <dt class="col-sm-6">Monthly:</dt>
                            <dd class="col-sm-6">{{ $consumption['monthly'] }} blocks</dd>
                            
                            <dt class="col-sm-6">Quarterly:</dt>
                            <dd class="col-sm-6">{{ $consumption['quarterly'] }} blocks</dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <h5>Fuel Projections</h5>
                        @if($structure->fuel_expires && $consumption['daily'] > 0)
                            @php
                                $daysRemaining = \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now());
                                $blocksRemaining = $daysRemaining * $consumption['daily'];
                            @endphp
                            <dl class="row">
                                <dt class="col-sm-6">Est. Blocks Left:</dt>
                                <dd class="col-sm-6">~{{ number_format($blocksRemaining) }}</dd>
                                
                                <dt class="col-sm-6">Fuel Runs Out:</dt>
                                <dd class="col-sm-6">{{ \Carbon\Carbon::parse($structure->fuel_expires)->format('Y-m-d H:i') }}</dd>
                            </dl>
                        @else
                            <p class="text-muted">Insufficient data for projections</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Online Services</h3>
            </div>
            <div class="card-body">
                @if($services->count() > 0)
                    <div class="list-group">
                        @foreach($services as $service)
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">{{ $service->name }}</h6>
                                    <span class="badge badge-{{ $service->state == 'online' ? 'success' : 'danger' }}">
                                        {{ ucfirst($service->state) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted">No services installed</p>
                @endif
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Fuel History</h3>
            </div>
            <div class="card-body">
                <canvas id="fuelHistoryChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">Recent Fuel Records</h3>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Fuel Expires</th>
                    <th>Days Remaining</th>
                    <th>Change</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fuelHistory->take(10) as $record)
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
                                @if($change > 0)
                                    <span class="text-success">+{{ $change }} days</span>
                                @elseif($change < 0)
                                    <span class="text-danger">{{ $change }} days</span>
                                @else
                                    <span class="text-muted">No change</span>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('javascript')
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
$(document).ready(function() {
    // Prepare fuel history chart data
    let fuelHistory = @json($fuelHistory);
    
    let labels = fuelHistory.map(h => moment(h.created_at).format('MM-DD')).reverse();
    let daysData = fuelHistory.map(h => h.days_remaining).reverse();
    
    let ctx = document.getElementById('fuelHistoryChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Days of Fuel Remaining',
                data: daysData,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Days Remaining'
                    }
                }
            }
        }
    });
});
</script>
@endpush
