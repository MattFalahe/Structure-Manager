@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::common.structure_manager'))
@section('page_header', trans('structure-manager::common.structure_manager'))

@push('head')
<link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
<style>
    .fuel-critical { color: #dc3545; font-weight: bold; }
    .fuel-warning { color: #ffc107; font-weight: bold; }
    .fuel-normal { color: #28a745; }
    .fuel-good { color: #17a2b8; }
    .fuel-unknown { color: #6c757d; }
    
    .status-badge {
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.875rem;
        font-weight: 600;
    }
    
    .status-online { background-color: #d4edda; color: #155724; }
    .status-offline { background-color: #f8d7da; color: #721c24; }
    .status-shield_vulnerable { background-color: #fff3cd; color: #856404; }
    
    .consumption-stats {
        display: flex;
        gap: 1rem;
        font-size: 0.875rem;
    }
    
    .stat-item {
        padding: 0.25rem 0.5rem;
        background: #f8f9fa;
        border-radius: 0.25rem;
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
<script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
<script>
$(document).ready(function() {
    let table = $('#structures-table').DataTable({
        ajax: {
            url: '{{ route("structure-manager.data") }}',
            data: function(d) {
                d.corporation_id = $('#corporation-filter').val();
                d.fuel_status = $('#fuel-filter').val();
            }
        },
        columns: [
            { 
                data: 'structure_name',
                render: function(data, type, row) {
                    return `<a href="{{ url('structure-manager/structure') }}/${row.structure_id}">${data}</a>`;
                }
            },
            { data: 'structure_type' },
            { 
                data: 'system_name',
                render: function(data, type, row) {
                    let secClass = row.security >= 0.5 ? 'text-success' : 
                                  row.security > 0 ? 'text-warning' : 'text-danger';
                    return `${data} <span class="${secClass}">(${row.security.toFixed(1)})</span>`;
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
                    if (data === null) return '<span class="fuel-unknown">N/A</span>';
                    let className = `fuel-${row.fuel_status}`;
                    return `<span class="${className}">${data} days</span>`;
                }
            },
            { 
                data: 'services',
                render: function(data) {
                    if (!data) return '<span class="text-muted">None</span>';
                    let services = data.split(', ');
                    return services.map(s => `<span class="badge badge-info">${s}</span>`).join(' ');
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    if (!row.daily_consumption) return '<span class="text-muted">Calculating...</span>';
                    return `
                        <div class="consumption-stats">
                            <span class="stat-item">Daily: ${row.daily_consumption}</span>
                            <span class="stat-item">Weekly: ${row.weekly_consumption}</span>
                        </div>
                    `;
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    return `
                        <button class="btn btn-sm btn-info view-fuel" data-id="${row.structure_id}">
                            <i class="fas fa-chart-line"></i>
                        </button>
                    `;
                }
            }
        ],
        order: [[5, 'asc']], // Sort by days remaining
        pageLength: 25,
        drawCallback: function() {
            updateFuelSummary();
        }
    });
    
    // Filters
    $('#corporation-filter, #fuel-filter').on('change', function() {
        table.ajax.reload();
    });
    
    $('#refresh-data').on('click', function() {
        table.ajax.reload();
    });
    
    // View fuel history
    $(document).on('click', '.view-fuel', function() {
        let structureId = $(this).data('id');
        loadFuelHistory(structureId);
    });
    
    function updateFuelSummary() {
        let critical = 0, warning = 0, normal = 0;
        table.rows().data().each(function(row) {
            if (row.fuel_status === 'critical') critical++;
            else if (row.fuel_status === 'warning') warning++;
            else if (row.fuel_status === 'normal') normal++;
        });
        
        $('#critical-count').text(critical);
        $('#warning-count').text(warning);
        $('#normal-count').text(normal);
    }
    
    let fuelChart = null;
    
    function loadFuelHistory(structureId) {
        $.get(`{{ url('structure-manager/fuel-history') }}/${structureId}`, function(data) {
            $('#fuelModal').modal('show');
            
            // Prepare chart data
            let labels = data.map(d => moment(d.created_at).format('MM-DD'));
            let fuelData = data.map(d => d.days_remaining);
            
            if (fuelChart) {
                fuelChart.destroy();
            }
            
            let ctx = document.getElementById('fuelChart').getContext('2d');
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
            
            // Calculate and display consumption details
            if (data.length > 1) {
                let latest = data[0];
                let oldest = data[data.length - 1];
                let daysDiff = moment(latest.created_at).diff(moment(oldest.created_at), 'days');
                let fuelUsed = oldest.days_remaining - latest.days_remaining;
                let avgDaily = daysDiff > 0 ? (fuelUsed / daysDiff).toFixed(2) : 0;
                
                $('#consumption-details').html(`
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Period:</strong> ${daysDiff} days
                        </div>
                        <div class="col-md-3">
                            <strong>Fuel Used:</strong> ~${fuelUsed} days
                        </div>
                        <div class="col-md-3">
                            <strong>Avg Daily:</strong> ${avgDaily} days/day
                        </div>
                        <div class="col-md-3">
                            <strong>Est. Blocks/Day:</strong> ${(avgDaily * 40).toFixed(0)}
                        </div>
                    </div>
                `);
            }
        });
    }
});
</script>
@endpush
