@extends('web::layouts.grids.12')

@section('title', 'Critical Fuel Alerts')
@section('page_header', 'Critical Fuel Alerts')

@push('head')
<style>
    .alert-card {
        border-left: 4px solid;
        margin-bottom: 1rem;
    }
    
    /* DARK THEME COMPATIBLE - Changed from light backgrounds */
    .alert-card.critical {
        border-left-color: #dc3545 !important;
        background: rgba(220, 53, 69, 0.1) !important;
    }
    
    .alert-card.warning {
        border-left-color: #ffc107 !important;
        background: rgba(255, 193, 7, 0.1) !important;
    }
    
    /* Force info-box backgrounds to show */
    .info-box.bg-danger {
        background: #dc3545 !important;
        color: #fff !important;
    }
    
    .info-box.bg-warning {
        background: #ffc107 !important;
        color: #000 !important;
    }
    
    .info-box.bg-info {
        background: #17a2b8 !important;
        color: #fff !important;
    }
    
    .info-box-icon i {
        color: rgba(255, 255, 255, 0.3) !important;
    }
    
    .info-box.bg-danger .info-box-icon i {
        color: rgba(255, 255, 255, 0.4) !important;
    }
    
    .info-box.bg-warning .info-box-icon i {
        color: #6c757d !important;
        opacity: 0.6 !important;
    }
    
    .info-box.bg-info .info-box-icon i {
        color: rgba(255, 255, 255, 0.4) !important;
    }
    
    .info-box-content {
        color: inherit !important;
    }
    
    .info-box-text,
    .info-box-number {
        color: inherit !important;
    }
    
    /* Better contrast for dark themes */
    .fuel-critical { 
        color: #ff6b6b !important;
        font-weight: bold;
        font-size: 1.1rem;
    }
    
    .fuel-warning { 
        color: #ffd43b !important;
        font-weight: bold;
        font-size: 1.1rem;
    }
    
    .structure-icon {
        font-size: 2rem;
        margin-right: 1rem;
    }
    
    .alert-stats {
        display: flex;
        gap: 2rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
    }
    
    /* DARK THEME COMPATIBLE - Changed from white background */
    .stat-badge {
        padding: 0.5rem 1rem;
        border-radius: 0.25rem;
        background: rgba(0, 0, 0, 0.2) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
    }
    
    .no-alerts {
        text-align: center;
        padding: 3rem;
    }
    
    .no-alerts i {
        font-size: 4rem;
        color: #51cf66;
        margin-bottom: 1rem;
    }
    
    /* Badge styling */
    .badge-danger {
        background-color: #dc3545 !important;
        color: #fff !important;
    }
    
    .badge-warning {
        background-color: #ffc107 !important;
        color: #000 !important;
    }
    
    .badge-info {
        background-color: #17a2b8 !important;
        color: #fff !important;
    }
    
    .badge-secondary {
        background-color: #6c757d !important;
        color: #fff !important;
    }
    
    /* Metenox dual fuel display */
    .metenox-dual-fuel {
        background: rgba(156, 39, 176, 0.1);
        border: 1px solid rgba(156, 39, 176, 0.3);
        border-radius: 0.25rem;
        padding: 0.75rem;
        margin-top: 0.5rem;
    }
    
    .metenox-resource {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.25rem 0;
    }
    
    .limiting-factor-badge {
        background-color: rgba(220, 53, 69, 0.2);
        color: #ff6b6b;
        border: 1px solid rgba(220, 53, 69, 0.3);
        padding: 0.15rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: bold;
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-exclamation-triangle text-danger"></i> 
                    Critical Fuel Status
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-sm btn-primary" id="refresh-alerts">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="info-box bg-danger">
                            <span class="info-box-icon"><i class="fas fa-exclamation-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Critical Alerts</span>
                                <span class="info-box-number" id="critical-count">0</span>
                                <span class="info-box-text"><small>Less than 7 days</small></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-warning">
                            <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Warning Alerts</span>
                                <span class="info-box-number" id="warning-count">0</span>
                                <span class="info-box-text"><small>7-14 days remaining</small></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-info">
                            <span class="info-box-icon"><i class="fas fa-gas-pump"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Fuel Needed</span>
                                <span class="info-box-number" id="total-blocks">0</span>
                                <span class="info-box-text"><small>Blocks per week</small></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="alerts-container">
                    <div class="text-center p-5">
                        <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                        <p class="mt-3">Loading alerts...</p>
                    </div>
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

<script src="{{ asset('vendor/structure-manager/js/moment.min.js') }}"></script>
<script>
$(document).ready(function() {
    
    function loadAlerts() {
        $('#alerts-container').html(`
            <div class="text-center p-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Loading alerts...</p>
            </div>
        `);
        
        $.get('{{ route("structure-manager.critical-alerts-data") }}', function(data) {
            let criticalCount = 0;
            let warningCount = 0;
            let totalBlocks = 0;
            
            if (!data || data.length === 0) {
                $('#alerts-container').html(`
                    <div class="no-alerts">
                        <i class="fas fa-check-circle"></i>
                        <h4>All Clear!</h4>
                        <p>No structures require immediate attention.</p>
                        <p>All structures have sufficient fuel (14+ days).</p>
                    </div>
                `);
                $('#critical-count').text('0');
                $('#warning-count').text('0');
                $('#total-blocks').text('0');
                return;
            }
            
            // Count alerts by type
            data.forEach(function(alert) {
                if (alert.status === 'critical') {
                    criticalCount++;
                } else if (alert.status === 'warning') {
                    warningCount++;
                }
                totalBlocks += alert.blocks_needed;
            });
            
            // Update counters
            $('#critical-count').text(criticalCount);
            $('#warning-count').text(warningCount);
            $('#total-blocks').text(totalBlocks.toLocaleString());
            
            // Build alerts HTML
            let html = '';
            
            // Sort by hours remaining (most critical first)
            data.sort(function(a, b) {
                let aHours = a.hours_remaining || (a.days_remaining * 24);
                let bHours = b.hours_remaining || (b.days_remaining * 24);
                return aHours - bHours;
            });
            
            data.forEach(function(alert) {
                let statusClass = alert.status === 'critical' ? 'critical' : 'warning';
                let statusIcon = alert.status === 'critical' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle';
                let statusColor = alert.status === 'critical' ? 'danger' : 'warning';
                let daysClass = alert.status === 'critical' ? 'fuel-critical' : 'fuel-warning';
                
                // Format time display
                let timeDisplay = '';
                if (alert.days_remaining !== undefined && alert.remaining_hours !== undefined) {
                    timeDisplay = alert.days_remaining + 'd ' + alert.remaining_hours + 'h';
                } else {
                    timeDisplay = alert.days_remaining + ' Days';
                }
                
                // Calculate hours remaining
                let hoursLeft = alert.hours_remaining || (alert.days_remaining * 24);
                
                // Calculate urgency message
                let urgencyMsg = '';
                if (hoursLeft < 72) { // < 3 days
                    urgencyMsg = '<span class="badge badge-danger"><i class="fas fa-fire"></i> URGENT - Refuel immediately!</span>';
                } else if (hoursLeft < 168) { // < 7 days
                    urgencyMsg = '<span class="badge badge-danger">Critical - Refuel within 24 hours</span>';
                } else if (hoursLeft < 240) { // < 10 days
                    urgencyMsg = '<span class="badge badge-warning">Warning - Schedule refuel soon</span>';
                } else {
                    urgencyMsg = '<span class="badge badge-warning">Monitor - Refuel within a week</span>';
                }
                
                // Fuel expires timestamp
                let expiresAt = moment(alert.fuel_expires);
                let timeUntilEmpty = expiresAt.fromNow();
                
                html += `
                    <div class="alert-card ${statusClass} card">
                        <div class="card-body">
                            <div class="d-flex align-items-start">
                                <div class="structure-icon text-${statusColor}">
                                    <i class="fas ${statusIcon}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="mb-1">
                                                <a href="{{ url('structure-manager/structure') }}/${alert.structure_id}">
                                                    ${alert.structure_name}
                                                </a>
                                            </h5>
                                            <p class="mb-2">
                                                <span class="badge badge-secondary">${alert.structure_type}</span>
                                                <span class="badge badge-info ml-1"><i class="fas fa-map-marker-alt"></i> ${alert.system_name}</span>
                `;
                
                // Add Metenox indicator if applicable
                if (alert.structure_type === 'Metenox Moon Drill' && alert.metenox_data) {
                    let limitingText = alert.metenox_data.limiting_factor === 'fuel_blocks' ? 'Fuel Limiting' : 'Gas Limiting';
                    html += `<span class="badge badge-warning ml-1"><i class="fas fa-exclamation-triangle"></i> ${limitingText}</span>`;
                }
                
                html += `
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <div class="${daysClass}">
                                                ${timeDisplay}
                                            </div>
                                            <small>Remaining</small>
                                        </div>
                                    </div>
                `;
                
                // Show Metenox dual fuel status
                if (alert.structure_type === 'Metenox Moon Drill' && alert.metenox_data) {
                    let md = alert.metenox_data;
                    let fuelBlocksClass = md.limiting_factor === 'fuel_blocks' ? 'fuel-critical' : 'fuel-normal';
                    let gasClass = md.limiting_factor === 'magmatic_gas' ? 'fuel-critical' : 'fuel-normal';
                    
                    html += `
                        <div class="metenox-dual-fuel">
                            <strong><i class="fas fa-exclamation-triangle"></i> Dual Fuel System</strong>
                            <hr style="margin: 0.5rem 0; border-color: rgba(255,255,255,0.2);">
                            <div class="metenox-resource">
                                <span>
                                    <i class="fas fa-battery-three-quarters"></i> <strong>Fuel Blocks:</strong>
                                    <span class="${fuelBlocksClass}">${md.fuel_blocks_days.toFixed(1)} days</span>
                                    (${md.fuel_blocks_quantity.toLocaleString()} blocks)
                                </span>
                                ${md.limiting_factor === 'fuel_blocks' ? '<span class="limiting-factor-badge">LIMITING</span>' : ''}
                            </div>
                            <div class="metenox-resource">
                                <span>
                                    <i class="fas fa-wind"></i> <strong>Magmatic Gas:</strong>
                                    <span class="${gasClass}">${md.magmatic_gas_days.toFixed(1)} days</span>
                                    (${md.magmatic_gas_quantity.toLocaleString()} units)
                                </span>
                                ${md.limiting_factor === 'magmatic_gas' ? '<span class="limiting-factor-badge">LIMITING</span>' : ''}
                            </div>
                        </div>
                    `;
                }
                
                html += `
                                    <div class="alert-stats">
                                        <div class="stat-badge">
                                            <i class="far fa-clock text-${statusColor}"></i>
                                            <strong>Fuel Expires:</strong><br>
                                            <span>${expiresAt.format('YYYY-MM-DD HH:mm')}</span><br>
                                            <small>(${timeUntilEmpty})</small>
                                        </div>
                                        <div class="stat-badge">
                                            <i class="fas fa-gas-pump text-primary"></i>
                                            <strong>Weekly Requirement:</strong><br>
                                            <span>${alert.blocks_needed.toLocaleString()} blocks</span><br>
                                            <small>(${(alert.blocks_needed * 5).toLocaleString()} m³)</small>
                                        </div>
                                        <div class="stat-badge">
                                            <i class="fas fa-cubes text-info"></i>
                                            <strong>30-Day Need:</strong><br>
                                            <span>${(alert.blocks_needed * 4.3).toFixed(0).toLocaleString()} blocks</span><br>
                                            <small>(${(alert.blocks_needed * 4.3 * 5).toFixed(0).toLocaleString()} m³)</small>
                                        </div>
                `;
                
                // Add magmatic gas requirements for Metenox
                if (alert.structure_type === 'Metenox Moon Drill') {
                    html += `
                                        <div class="stat-badge">
                                            <i class="fas fa-wind text-warning"></i>
                                            <strong>Gas Required:</strong><br>
                                            <span>${(4800 * 7).toLocaleString()} units/week</span><br>
                                            <small>${(4800 * 30).toLocaleString()} units/month</small>
                                        </div>
                    `;
                }
                
                html += `
                                    </div>
                                    
                                    <div class="mt-2">
                                        ${urgencyMsg}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            $('#alerts-container').html(html);
            
        }).fail(function(xhr, status, error) {
            console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
            console.error('Response Text:', xhr.responseText);
            
            $('#alerts-container').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error loading alerts:</strong> ${error}
                    <br><small>Please try refreshing the page.</small>
                </div>
            `);
        });
    }
    
    // Load alerts on page load
    loadAlerts();
    
    // Refresh button
    $('#refresh-alerts').on('click', function() {
        let btn = $(this);
        btn.find('i').addClass('fa-spin');
        
        loadAlerts();
        
        setTimeout(function() {
            btn.find('i').removeClass('fa-spin');
        }, 1000);
    });
    
    // Auto-refresh every 5 minutes
    setInterval(loadAlerts, 300000);
});
</script>
@endpush
