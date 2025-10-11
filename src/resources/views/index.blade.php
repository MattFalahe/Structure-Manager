@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::common.structure_manager'))
@section('page_header', trans('structure-manager::common.structure_manager'))

@push('head')
<style>
    /* Better contrast for dark themes */
    .fuel-critical { color: #ff6b6b; font-weight: bold; }
    .fuel-warning { color: #ffd43b; font-weight: bold; }
    .fuel-normal { color: #51cf66; }
    .fuel-good { color: #17a2b8; }
    .fuel-unknown { color: #a0a0a0; }
    
    /* DARK THEME COMPATIBLE - Status badges */
    .status-badge {
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.875rem;
        font-weight: 600;
    }
    
    .status-online { 
        background-color: rgba(40, 167, 69, 0.2);
        color: #51cf66;
        border: 1px solid rgba(40, 167, 69, 0.3);
    }
    
    .status-offline { 
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
        border: 1px solid rgba(220, 53, 69, 0.3);
    }
    
    .status-shield_vulnerable { 
        background-color: rgba(255, 193, 7, 0.2);
        color: #ffd43b;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }
    
    .consumption-stats {
        display: flex;
        gap: 0.5rem;
        font-size: 0.875rem;
        flex-wrap: wrap;
    }
    
    /* DARK THEME COMPATIBLE - Changed from #f8f9fa */
    .stat-item {
        padding: 0.25rem 0.5rem;
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        white-space: nowrap;
    }
    
    .stat-item i {
        margin-right: 0.25rem;
        opacity: 0.7;
    }
    
    /* Service badges - matching consumption column style */
    .badge-info {
        background-color: rgba(23, 162, 184, 0.15) !important;
        color: #5dade2 !important;
        border: 1px solid rgba(23, 162, 184, 0.3) !important;
        font-weight: 500 !important;
    }
    
    .badge-secondary {
        background-color: rgba(108, 117, 125, 0.15) !important;
        color: #a0a0a0 !important;
        border: 1px solid rgba(108, 117, 125, 0.3) !important;
        font-weight: 500 !important;
    }
    
    .badge-primary {
        background-color: rgba(0, 123, 255, 0.15) !important;
        color: #5dade2 !important;
        border: 1px solid rgba(0, 123, 255, 0.3) !important;
        font-weight: 500 !important;
    }
    
    .badge-success {
        background-color: rgba(40, 167, 69, 0.15) !important;
        color: #51cf66 !important;
        border: 1px solid rgba(40, 167, 69, 0.3) !important;
        font-weight: 500 !important;
    }
    
    .badge-warning {
        background-color: rgba(255, 193, 7, 0.15) !important;
        color: #ffd43b !important;
        border: 1px solid rgba(255, 193, 7, 0.3) !important;
        font-weight: 500 !important;
    }
    
    .badge-danger {
        background-color: rgba(220, 53, 69, 0.15) !important;
        color: #ff6b6b !important;
        border: 1px solid rgba(220, 53, 69, 0.3) !important;
        font-weight: 500 !important;
    }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Structure Fuel Management</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary" id="refresh-data">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-3">
                <label for="corporation-filter">Corporation:</label>
                <select id="corporation-filter" class="form-control">
                    <option value="all">All Corporations</option>
                    @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}">{{ $corp->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="fuel-filter">Fuel Status:</label>
                <select id="fuel-filter" class="form-control">
                    <option value="all">All Status</option>
                    <option value="critical">Critical (<7 days)</option>
                    <option value="warning">Warning (7-14 days)</option>
                    <option value="normal">Normal (14-30 days)</option>
                    <option value="good">Good (>30 days)</option>
                </select>
            </div>
            <div class="col-md-6">
                <div class="info-box">
                    <span class="info-box-icon bg-info"><i class="fas fa-gas-pump"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Fuel Summary</span>
                        <span class="info-box-number">
                            <span id="critical-count" class="fuel-critical">0</span> Critical |
                            <span id="warning-count" class="fuel-warning">0</span> Warning |
                            <span id="normal-count" class="fuel-normal">0</span> Normal
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <table id="structures-table" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Structure Name</th>
                    <th>Type</th>
                    <th>System</th>
                    <th>Corporation</th>
                    <th>Fuel Expires</th>
                    <th>Days Remaining</th>
                    <th>Services</th>
                    <th>Consumption</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Fuel Projection Modal -->
<div class="modal fade" id="fuelModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fuel Consumption Analysis</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <canvas id="fuelChart"></canvas>
                <div class="mt-3">
                    <h6>Consumption Estimates:</h6>
                    <div id="consumption-details"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
{{-- Fix SeAT's mixed content issue first --}}
<script>
(function() {
    // Wait for jQuery to be available
    if (typeof $ !== 'undefined' && $.ajax) {
        var originalAjax = $.ajax;
        $.ajax = function(settings) {
            if (settings && settings.url && typeof settings.url === 'string' && settings.url.startsWith('http://')) {
                settings.url = settings.url.replace('http://', 'https://');
            }
            return originalAjax.call(this, settings);
        };
    }
})();
</script>

{{-- Load assets from plugin --}}
<script src="{{ asset('vendor/structure-manager/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('vendor/structure-manager/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('vendor/structure-manager/js/chart.min.js') }}"></script>
<script src="{{ asset('vendor/structure-manager/js/moment.min.js') }}"></script>

<script>
// Verify libraries loaded
if (typeof moment === 'undefined') {
    console.error('Moment.js failed to load');
}
if (typeof Chart === 'undefined') {
    console.error('Chart.js failed to load');
}
if (typeof $.fn.DataTable === 'undefined') {
    console.error('DataTables failed to load');
}

$(document).ready(function() {
    console.log('Document ready');
    
    // Declare table variable in broader scope
    var table;
    
    // Define updateFuelSummary function
    function updateFuelSummary() {
        if (typeof table === 'undefined' || !table) {
            console.log('Table not yet initialized');
            return;
        }
        
        var critical = 0, warning = 0, normal = 0;
        table.rows().data().each(function(row) {
            if (row.fuel_status === 'critical') critical++;
            else if (row.fuel_status === 'warning') warning++;
            else if (row.fuel_status === 'normal') normal++;
        });
        
        $('#critical-count').text(critical);
        $('#warning-count').text(warning);
        $('#normal-count').text(normal);
    }
    
    console.log('Initializing DataTable...');
    
    // Initialize the table
    table = $('#structures-table').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: '{{ route("structure-manager.data") }}',
            data: function(d) {
                d.corporation_id = $('#corporation-filter').val();
                d.fuel_status = $('#fuel-filter').val();
            },
            dataSrc: function(json) {
                console.log('Received data:', json);
                if (json.error) {
                    alert('Error: ' + json.message);
                    return [];
                }
                return json.data || [];
            },
            error: function(xhr, error, thrown) {
                console.error('AJAX Error:', {xhr: xhr, error: error, thrown: thrown});
                console.error('Response:', xhr.responseText);
                alert('Error loading data: ' + (thrown || error));
            }
        },
        columns: [
            { 
                data: 'structure_name',
                render: function(data, type, row) {
                    return '<a href="{{ url('structure-manager/structure') }}/' + row.structure_id + '">' + data + '</a>';
                }
            },
            { data: 'structure_type' },
            { 
                data: 'system_name',
                render: function(data, type, row) {
                    var secClass = row.security >= 0.5 ? 'text-success' : 
                                  row.security > 0 ? 'text-warning' : 'text-danger';
                    return data + ' <span class="' + secClass + '">(' + parseFloat(row.security).toFixed(1) + ')</span>';
                }
            },
            { data: 'corporation_name' },
            { 
                data: 'fuel_expires',
                render: function(data) {
                    if (!data) return '<span class="fuel-unknown">Unknown</span>';
                    return moment(data).format('YYYY-MM-DD HH:mm');
                }
            },
            { 
                data: 'days_remaining',
                render: function(data, type, row) {
                    if (data === null || typeof row.hours_remaining === 'undefined') {
                        return '<span class="fuel-unknown">N/A</span>';
                    }
                    
                    var className = 'fuel-' + row.fuel_status;
                    var days = row.days_remaining;
                    var hours = row.remaining_hours;
                    
                    // For sorting, return just the hours
                    if (type === 'sort' || type === 'type') {
                        return row.hours_remaining;
                    }
                    
                    // Format display text
                    var displayText = '';
                    if (days > 0) {
                        displayText = days + 'd ' + hours + 'h';
                    } else {
                        displayText = hours + ' hours';
                    }
                    
                    return '<span class="' + className + '" title="' + row.hours_remaining + ' total hours">' + displayText + '</span>';
                }
            },
            { 
                data: 'services',
                render: function(data) {
                    if (!data) return '<span class="text-muted">None</span>';
                    var services = data.split(', ');
                    return services.map(function(s) { 
                        return '<span class="badge badge-info">' + s + '</span>';
                    }).join(' ');
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    if (!row.daily_consumption) {
                        return '<span class="text-muted">Calculating...</span>';
                    }
                    
                    // Format numbers with commas for readability
                    var daily = Number(row.daily_consumption).toLocaleString();
                    var weekly = Number(row.weekly_consumption).toLocaleString();
                    var monthly = Number(row.monthly_consumption).toLocaleString();
                    
                    return '<div class="consumption-stats">' +
                           '<span class="stat-item" title="Daily consumption"><i class="fas fa-fire"></i>' + daily + '/day</span>' +
                           '<span class="stat-item" title="Weekly consumption"><i class="fas fa-calendar-week"></i>' + weekly + '/week</span>' +
                           '<span class="stat-item" title="Monthly consumption"><i class="fas fa-calendar"></i>' + monthly + '/month</span>' +
                           '</div>';
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    return '<button class="btn btn-sm btn-info view-fuel" data-id="' + row.structure_id + '" title="View fuel history">' +
                           '<i class="fas fa-chart-line"></i>' +
                           '</button>';
                }
            }
        ],
        order: [[5, 'asc']], // Sort by days remaining (ascending = most critical first)
        pageLength: 25,
        drawCallback: function() {
            updateFuelSummary();
        }
    });
    
    console.log('DataTable initialized');
    
    // Filters
    $('#corporation-filter, #fuel-filter').on('change', function() {
        table.ajax.reload();
    });
    
    $('#refresh-data').on('click', function() {
        table.ajax.reload();
    });
    
    // View fuel history
    $(document).on('click', '.view-fuel', function() {
        var structureId = $(this).data('id');
        loadFuelHistory(structureId);
    });
    
    var fuelChart = null;
    
    function loadFuelHistory(structureId) {
        $.get('{{ url('structure-manager/fuel-history') }}/' + structureId, function(data) {
            $('#fuelModal').modal('show');
            
            var labels = data.map(function(d) { return moment(d.created_at).format('MM-DD'); });
            var fuelData = data.map(function(d) { return d.days_remaining; });
            
            if (fuelChart) {
                fuelChart.destroy();
            }
            
            var ctx = document.getElementById('fuelChart').getContext('2d');
            fuelChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels.reverse(),
                    datasets: [{
                        label: 'Days of Fuel Remaining',
                        data: fuelData.reverse(),
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
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
            
            // Calculate consumption estimates from historical data
            if (data.length > 1) {
                var latest = data[0];
                var oldest = data[data.length - 1];
                var daysDiff = moment(latest.created_at).diff(moment(oldest.created_at), 'days');
                var fuelUsed = oldest.days_remaining - latest.days_remaining;
                var avgDaily = daysDiff > 0 ? (fuelUsed / daysDiff).toFixed(2) : 0;
                var estimatedBlocks = (avgDaily * 40).toFixed(0);
                
                $('#consumption-details').html(
                    '<div class="row">' +
                    '<div class="col-md-3"><strong>Period:</strong> ' + daysDiff + ' days</div>' +
                    '<div class="col-md-3"><strong>Fuel Used:</strong> ~' + fuelUsed + ' days</div>' +
                    '<div class="col-md-3"><strong>Avg Daily:</strong> ' + avgDaily + ' days/day</div>' +
                    '<div class="col-md-3"><strong>Est. Blocks/Day:</strong> ' + Number(estimatedBlocks).toLocaleString() + '</div>' +
                    '</div>'
                );
            }
        }).fail(function(xhr, status, error) {
            console.error('Error loading fuel history:', error);
            alert('Error loading fuel history. Please try again.');
        });
    }
});
</script>
@endpush
