@extends('web::layouts.grids.12')

@section('title', 'Control Towers (POSes)')
@section('page_header', 'Control Towers (POSes)')

@push('head')
<style>
    /* Dark theme compatible styles */
    .fuel-critical { color: #ff6b6b; font-weight: bold; }
    .fuel-warning { color: #ffd43b; font-weight: bold; }
    .fuel-normal { color: #51cf66; }
    .fuel-good { color: #17a2b8; }
    .fuel-unknown { color: #a0a0a0; }
    
    /* Status badges */
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
    
    /* Strontium status badges */
    .stront-critical {
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
        border: 1px solid rgba(220, 53, 69, 0.3);
        padding: 0.15rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    .stront-warning {
        background-color: rgba(255, 193, 7, 0.2);
        color: #ffd43b;
        border: 1px solid rgba(255, 193, 7, 0.3);
        padding: 0.15rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    .stront-good {
        background-color: rgba(40, 167, 69, 0.2);
        color: #51cf66;
        border: 1px solid rgba(40, 167, 69, 0.3);
        padding: 0.15rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    /* Space type badges */
    .space-highsec {
        background-color: rgba(40, 167, 69, 0.2);
        color: #51cf66;
    }
    
    .space-lowsec {
        background-color: rgba(255, 193, 7, 0.2);
        color: #ffd43b;
    }
    
    .space-nullsec {
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
    }
    
    .pos-name {
        font-weight: bold;
        color: #17a2b8;
    }
    
    .tower-type {
        font-size: 0.875rem;
        color: #a0a0a0;
    }
    
    /* POS State badges */
    .state-online { 
        background-color: rgba(40, 167, 69, 0.2);
        color: #51cf66;
        border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .state-reinforced { 
        background-color: rgba(255, 193, 7, 0.2);
        color: #ffd43b;
        border: 1px solid rgba(255, 193, 7, 0.3);
        animation: pulse-state 2s infinite;
    }

    .state-offline { 
        background-color: rgba(108, 117, 125, 0.2);
        color: #a0a0a0;
        border: 1px solid rgba(108, 117, 125, 0.3);
    }

    .state-onlining {
        background-color: rgba(23, 162, 184, 0.2);
        color: #17a2b8;
        border: 1px solid rgba(23, 162, 184, 0.3);
    }

    .state-unanchored {
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
        border: 1px solid rgba(220, 53, 69, 0.3);
    }

    @keyframes pulse-state {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
</style>
@endpush

@section('content')
<div class="structure-manager-wrapper">

<div class="row mb-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-broadcast-tower"></i> Player Owned Starbases (Control Towers)
                </h3>
                <div class="card-tools">
                    <a href="{{ route('structure-manager.settings') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="corporation-select">Filter by Corporation:</label>
                    <select id="corporation-select" class="form-control">
                        <option value="">All Corporations</option>
                        @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}">{{ $corp->name }}</option>
                        @endforeach
                    </select>
                </div>
                
                <table id="pos-table" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>POS Name</th>
                            <th>Tower Type</th>
                            <th>System</th>
                            <th>Space Type</th>
                            <th>State</th>
                            <th>Fuel Status</th>
                            <th>Strontium</th>
                            <th>Charters</th>
                            <th>Corporation</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

</div>
@endsection

@push('javascript')
<script>
$(document).ready(function() {
    var table = $('#pos-table').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: '{{ route('structure-manager.pos.data') }}',
            data: function(d) {
                d.corporation_id = $('#corporation-select').val();
            }
        },
        columns: [
            {
                data: 'starbase_name',
                render: function(data, type, row) {
                    var html = '<div class="pos-name">' + (data || 'Unnamed POS') + '</div>' +
                               '<div class="tower-type">' + row.tower_type + '</div>';
                    
                    // Add location if available
                    if (row.location_name) {
                        html += '<div class="tower-type" style="color: #17a2b8;">' +
                                '<i class="fas fa-map-marker-alt"></i> ' + row.location_name + '</div>';
                    }
                    
                    return html;
                }
            },
            { 
                data: 'tower_type',
                visible: false 
            },
            { 
                data: 'system_name',
                render: function(data, type, row) {
                    var secClass = row.security >= 0.5 ? 'text-success' : 
                                  (row.security > 0 ? 'text-warning' : 'text-danger');
                    return data + ' <span class="' + secClass + '">(' + 
                           Number(row.security).toFixed(1) + ')</span>';
                }
            },
            { 
                data: 'space_type',
                render: function(data, type, row) {
                    if (!data) return '<span class="badge badge-secondary">Unknown</span>';
                    
                    var badgeClass = data === 'High-Sec' ? 'space-highsec' : 
                                    (data === 'Low-Sec' ? 'space-lowsec' : 'space-nullsec');
                    return '<span class="status-badge ' + badgeClass + '">' + data + '</span>';
                }
            },
            { 
                data: 'state',
                render: function(data, type, row) {
                    // Handle null/undefined state (POS not synced yet)
                    if (data === null || data === undefined) {
                        return '<span class="badge badge-secondary" title="POS state not yet synced from ESI">' +
                               '<i class="fas fa-question"></i> Unknown' +
                               '</span><br><small class="text-muted">Awaiting ESI sync</small>';
                    }
                    
                    // Map state integer to name and styling
                    var stateMap = {
                        0: { name: 'Unanchored', class: 'state-unanchored', icon: 'fa-times-circle' },
                        1: { name: 'Offline', class: 'state-offline', icon: 'fa-power-off' },
                        2: { name: 'Onlining', class: 'state-onlining', icon: 'fa-spinner' },
                        3: { name: 'Reinforced', class: 'state-reinforced', icon: 'fa-shield-alt' },
                        4: { name: 'Online', class: 'state-online', icon: 'fa-check-circle' }
                    };
                    
                    var state = stateMap[data] || { name: 'Unknown', class: 'badge-secondary', icon: 'fa-question' };
                    
                    var html = '<span class="status-badge ' + state.class + '" title="State ' + data + '">' +
                               '<i class="fas ' + state.icon + '"></i> ' + state.name +
                               '</span>';
                    
                    // Add warning for reinforced POSes
                    if (data === 3) {
                        html += '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Under Attack</small>';
                    }
                    
                    return html;
                }
            },
            { 
                data: 'actual_days_remaining',
                render: function(data, type, row) {
                    if (data === null || data === undefined) {
                        return '<span class="fuel-unknown">Unknown</span>';
                    }
                    
                    // Convert decimal days to "Xd Yh" format
                    var days = Math.floor(data);
                    var hours = Math.floor((data - days) * 24);
                    var timeStr = days > 0 ? days + 'd ' + hours + 'h' : hours + 'h';
                    
                    var fuelClass = data < 7 ? 'fuel-critical' : 
                                   (data < 14 ? 'fuel-warning' : 
                                   (data < 30 ? 'fuel-normal' : 'fuel-good'));
                    
                    var limiting = row.limiting_factor === 'charters' ? 
                                  '<br><small class="text-warning">(Limited by charters)</small>' : '';
                    
                    return '<span class="' + fuelClass + '">' + timeStr + '</span>' + limiting;
                }
            },
            { 
                data: 'strontium_hours_available',
                render: function(data, type, row) {
                    if (data === null || data === undefined) {
                        return '<span class="text-muted">N/A</span>';
                    }
                    
                    var status = row.strontium_status || 'unknown';
                    var badgeClass = status === 'critical' ? 'stront-critical' : 
                                    (status === 'low' || status === 'warning' ? 'stront-warning' : 'stront-good');
                    
                    return '<span class="' + badgeClass + '">' + 
                           Number(data).toFixed(1) + 'h</span>';
                }
            },
            { 
                data: 'charter_days_remaining',
                render: function(data, type, row) {
                    if (!row.requires_charters) {
                        return '<span class="text-muted">Not Required</span>';
                    }
                    
                    if (data === null || data === undefined) {
                        return '<span class="fuel-unknown">Unknown</span>';
                    }
                    
                    // Convert decimal days to "Xd Yh" format
                    var days = Math.floor(data);
                    var hours = Math.floor((data - days) * 24);
                    var timeStr = days > 0 ? days + 'd ' + hours + 'h' : hours + 'h';
                    
                    var charterClass = data < 7 ? 'fuel-critical' : 
                                      (data < 14 ? 'fuel-warning' : 'fuel-normal');
                    
                    return '<span class="' + charterClass + '">' + timeStr + '</span>';
                }
            },
            { data: 'corporation_name' },
            { 
                data: 'starbase_id',
                orderable: false,
                render: function(data, type, row) {
                    return '<a href="/structure-manager/pos/' + data + '" class="btn btn-sm btn-info">' +
                           '<i class="fas fa-eye"></i> View</a>';
                }
            }
        ],
        order: [[5, 'asc']], // Sort by fuel status (critical first)
        pageLength: 25,
        language: {
            emptyTable: "No POSes found. Ensure ESI sync is running and corporation has POSes deployed."
        }
    });
    
    // Reload table when corporation changes
    $('#corporation-select').on('change', function() {
        table.ajax.reload();
    });
});
</script>
@endpush
