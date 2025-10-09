@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::menu.fuel_reserves'))
@section('page_header', trans('structure-manager::menu.fuel_reserves'))

@push('head')
<style>
    /* DARK THEME COMPATIBLE */
    .summary-box {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .system-section {
        margin-bottom: 2rem;
    }
    
    .system-header {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.15);
        padding: 0.75rem 1rem;
        border-radius: 0.25rem;
        margin-bottom: 0.5rem;
    }
    
    .system-stats {
        display: flex;
        gap: 2rem;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }
    
    .structure-card {
        background: rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .structure-card-header {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 0.5rem 0.75rem;
        border-radius: 0.25rem;
        margin-bottom: 0.75rem;
    }
    
    .reserve-table {
        background: transparent;
    }
    
    .reserve-table thead {
        background: rgba(0, 0, 0, 0.2);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .reserve-table tbody tr {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .reserve-table tbody tr:hover {
        background: rgba(255, 255, 255, 0.05);
    }
    
    .refuel-event-card {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .refuel-event-card .card-header {
        background: rgba(0, 0, 0, 0.3);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }
    
    /* Better badge contrast */
    .badge-success { background-color: #51cf66; color: #000; }
    .badge-warning { background-color: #ffd43b; color: #000; }
    .badge-danger { background-color: #ff6b6b; color: #fff; }
    .badge-info { background-color: #4dabf7; color: #000; }
    .badge-secondary { background-color: #868e96; color: #fff; }
    
    /* Security status badges */
    .sec-high { background-color: #51cf66; color: #000; }
    .sec-low { background-color: #ffd43b; color: #000; }
    .sec-null { background-color: #ff6b6b; color: #fff; }
</style>
@endpush

@section('full')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Fuel Reserves by System</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-sm btn-primary" id="refresh-reserves">
                <i class="fas fa-sync"></i> Refresh
            </button>
        </div>
    </div>
    <div class="card-body">
        <div id="reserves-loading" class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
            <p class="mt-3">Loading reserve data...</p>
        </div>
        <div id="reserves-content" style="display: none;">
            <!-- System-based reserves will be loaded here -->
        </div>
    </div>
</div>

<div class="card refuel-event-card">
    <div class="card-header">
        <h3 class="card-title">Recent Refuel Events</h3>
        <div class="card-tools">
            <select id="history-days" class="form-control form-control-sm">
                <option value="7">Last 7 Days</option>
                <option value="30" selected>Last 30 Days</option>
                <option value="90">Last 90 Days</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="refuel-events-table" class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>System</th>
                        <th>Structure</th>
                        <th>Blocks Moved</th>
                        <th>From Location</th>
                        <th>Fuel Type</th>
                    </tr>
                </thead>
                <tbody id="refuel-events-body">
                    <tr>
                        <td colspan="6" class="text-center py-3">
                            <i class="fas fa-spinner fa-spin"></i> Loading refuel events...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
// Fix SeAT's mixed content issue - must be FIRST
(function() {
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

$(document).ready(function() {
    const fuelTypeNames = {
        4051: 'Nitrogen Fuel Block',
        4246: 'Hydrogen Fuel Block',
        4247: 'Helium Fuel Block',
        4312: 'Oxygen Fuel Block'
    };
    
    // Base route URLs
    const reservesUrl = '{{ route('structure-manager.reserves-data') }}';
    const refuelHistoryBaseUrl = '{{ route('structure-manager.refuel-history', ['days' => 'DAYS_PLACEHOLDER']) }}'.replace('DAYS_PLACEHOLDER', '');
    const structureDetailBaseUrl = '{{ route('structure-manager.detail', ['id' => 'ID_PLACEHOLDER']) }}'.replace('ID_PLACEHOLDER', '');

    function loadReserves() {
        $('#reserves-loading').show();
        $('#reserves-content').hide();
        
        $.get(reservesUrl, function(data) {
            let html = '';
            
            if (Object.keys(data).length === 0) {
                html = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No fuel reserves detected in any structures.</div>';
            } else {
                for (const [system, systemData] of Object.entries(data)) {
                    // Determine security class
                    const secClass = systemData.security >= 0.5 ? 'sec-high' : 
                                   systemData.security > 0 ? 'sec-low' : 'sec-null';
                    
                    html += `
                        <div class="system-section">
                            <div class="system-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-map-marker-alt"></i> ${system}
                                    <span class="badge ${secClass} ml-2">${systemData.security.toFixed(1)}</span>
                                    <span class="badge badge-success ml-2">${systemData.structures.length} Structure${systemData.structures.length > 1 ? 's' : ''}</span>
                                </h5>
                                <div class="system-stats">
                                    <span><strong>Total Reserves:</strong> ${systemData.total_reserves.toLocaleString()} blocks</span>
                                    <span><strong>Volume:</strong> ${(systemData.total_reserves * 5).toLocaleString()} m³</span>
                                </div>
                            </div>
                            <div class="row">
                    `;
                    
                    for (const structure of systemData.structures) {
                        html += `
                            <div class="col-md-6 mb-3">
                                <div class="structure-card">
                                    <div class="structure-card-header">
                                        <strong>${structure.name}</strong>
                                        <br><small class="text-muted">${structure.type} - ${structure.corporation}</small>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Total Reserves:</strong> 
                                        <span class="badge badge-info">${structure.total_reserves.toLocaleString()} blocks</span>
                                        <small class="text-muted">(${(structure.total_reserves * 5).toLocaleString()} m³)</small>
                                    </div>
                                    <table class="table table-sm reserve-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Division</th>
                                                <th class="text-right">Quantity</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        for (const reserve of structure.reserves) {
                            html += `
                                <tr>
                                    <td>
                                        <strong>${reserve.division_name}</strong>
                                        <br><small class="text-muted">${reserve.location}</small>
                                    </td>
                                    <td class="text-right"><strong>${reserve.quantity.toLocaleString()}</strong></td>
                                    <td><small>${fuelTypeNames[reserve.fuel_type_id] || 'Unknown'}</small></td>
                                </tr>
                            `;
                        }
                        
                        html += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    }
                    
                    html += `
                            </div>
                        </div>
                    `;
                }
            }
            
            $('#reserves-content').html(html);
            $('#reserves-loading').hide();
            $('#reserves-content').show();
        }).fail(function(xhr, status, error) {
            console.error('Error loading reserves:', error);
            $('#reserves-content').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error loading reserves:</strong> ${error}
                </div>
            `);
            $('#reserves-loading').hide();
            $('#reserves-content').show();
        });
    }
    
    function loadRefuelEvents(days = 30) {
        // Show loading state
        $('#refuel-events-body').html(`
            <tr>
                <td colspan="6" class="text-center py-3">
                    <i class="fas fa-spinner fa-spin"></i> Loading refuel events...
                </td>
            </tr>
        `);
        
        // Build URL by appending days to base
        const url = refuelHistoryBaseUrl + days;
        
        $.get(url, function(data) {
            let html = '';
            
            if (data.length === 0) {
                html = '<tr><td colspan="6" class="text-center py-3"><i class="fas fa-info-circle"></i> No refuel events in this period.</td></tr>';
            } else {
                for (const event of data) {
                    const timestamp = new Date(event.timestamp);
                    const detailUrl = structureDetailBaseUrl + event.structure_id;
                    
                    html += `
                        <tr>
                            <td>${timestamp.toLocaleString()}</td>
                            <td>${event.system_name}</td>
                            <td>
                                <a href="${detailUrl}" class="text-decoration-none">
                                    ${event.structure_name}
                                </a>
                            </td>
                            <td><strong>${event.blocks_moved.toLocaleString()}</strong> blocks</td>
                            <td><span class="badge badge-info">${event.from_location}</span></td>
                            <td><small>${fuelTypeNames[event.fuel_type_id] || 'Unknown'}</small></td>
                        </tr>
                    `;
                }
            }
            
            $('#refuel-events-body').html(html);
        }).fail(function(xhr, status, error) {
            console.error('Error loading refuel events:', error);
            $('#refuel-events-body').html(`
                <tr>
                    <td colspan="6" class="text-center text-danger py-3">
                        <i class="fas fa-exclamation-triangle"></i> Error loading refuel events: ${error}
                    </td>
                </tr>
            `);
        });
    }
    
    // Initial load
    loadReserves();
    loadRefuelEvents(30);
    
    // Refresh button
    $('#refresh-reserves').click(function() {
        $(this).find('i').addClass('fa-spin');
        loadReserves();
        loadRefuelEvents($('#history-days').val());
        
        setTimeout(() => {
            $(this).find('i').removeClass('fa-spin');
        }, 1000);
    });
    
    // History period selector
    $('#history-days').change(function() {
        loadRefuelEvents($(this).val());
    });
    
    // Auto-refresh every 5 minutes
    setInterval(function() {
        loadReserves();
        loadRefuelEvents($('#history-days').val());
    }, 300000);
});
</script>
@endpush
