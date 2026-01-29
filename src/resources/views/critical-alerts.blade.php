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
    
    /* Force info-box backgrounds to show - Universal theme support */
    .info-box.bg-danger {
        background: #dc3545 !important;
        color: #fff !important;
        border: 2px solid #b71c1c !important;
        box-shadow: 0 3px 10px rgba(220, 53, 69, 0.3);
    }
    
    .info-box.bg-warning {
        background: #ff9800 !important;
        color: #000 !important;
        border: 2px solid #e65100 !important;
        box-shadow: 0 3px 10px rgba(255, 152, 0, 0.3);
    }
    
    .info-box.bg-info {
        background: #2196f3 !important;
        color: #fff !important;
        border: 2px solid #0d47a1 !important;
        box-shadow: 0 3px 10px rgba(33, 150, 243, 0.3);
    }
    
    .info-box-icon i {
        color: rgba(255, 255, 255, 0.5) !important;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }
    
    .info-box.bg-danger .info-box-icon i {
        color: rgba(255, 255, 255, 0.6) !important;
    }
    
    .info-box.bg-warning .info-box-icon i {
        color: rgba(0, 0, 0, 0.4) !important;
        text-shadow: 0 1px 2px rgba(255, 255, 255, 0.3);
    }
    
    .info-box.bg-info .info-box-icon i {
        color: rgba(255, 255, 255, 0.6) !important;
    }
    
    .info-box-content {
        color: inherit !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    
    .info-box.bg-warning .info-box-content {
        text-shadow: 0 1px 1px rgba(255, 255, 255, 0.5);
    }
    
    .info-box-text,
    .info-box-number {
        color: inherit !important;
        font-weight: bold;
    }
    
    /* Better contrast for dark themes - Universal colors */
    .fuel-critical { 
        color: #ff5252 !important;
        font-weight: bold;
        font-size: 1.2rem;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
    
    .fuel-warning { 
        color: #ffb300 !important;
        font-weight: bold;
        font-size: 1.2rem;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
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
    
    /* Metenox badges */
    .badge-metenox {
        background-color: #9c27b0 !important;
        color: #ffffff !important;
        font-weight: bold;
    }
    
    /* POS badges */
    .badge-pos {
        background-color: #e91e63 !important;
        color: #ffffff !important;
        font-weight: bold;
    }
    
    /* Metenox dual fuel display - Universal theme support */
    .metenox-dual-fuel {
        background: transparent;
        border: 2px solid #9c27b0;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-top: 0.75rem;
        box-shadow: 0 2px 8px rgba(156, 39, 176, 0.2);
    }
    
    /* POS dual fuel display - Universal theme support */
    .pos-dual-fuel {
        background: transparent;
        border: 2px solid #e91e63;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-top: 0.75rem;
        box-shadow: 0 2px 8px rgba(233, 30, 99, 0.2);
    }
    
    .pos-resource {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        margin: 0.25rem 0;
        border-radius: 0.25rem;
        background: rgba(0, 0, 0, 0.05);
    }
    
    .metenox-resource {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        margin: 0.25rem 0;
        border-radius: 0.25rem;
        background: rgba(0, 0, 0, 0.05);
    }
    
    /* Limiting factor badge - Small badge inside dual fuel display */
    .limiting-factor-badge {
        background-color: #dc3545 !important;
        color: #ffffff !important;
        border: 2px solid #ffffff !important;
        padding: 0.25rem 0.6rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: bold;
        animation: pulse 2s infinite;
        box-shadow: 0 2px 6px rgba(220, 53, 69, 0.4);
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    @keyframes pulse {
        0%, 100% { 
            opacity: 1;
            transform: scale(1);
        }
        50% { 
            opacity: 0.85;
            transform: scale(1.05);
        }
    }
    
    /* Prominent limiting factor indicator - Large badge next to structure name */
    .limiting-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: bold;
        font-size: 0.95rem;
        margin-left: 0.5rem;
        box-shadow: 0 2px 8px rgba(156, 39, 176, 0.4);
    }
    
    .limiting-indicator i {
        animation: pulse-rotate 2s infinite;
        font-size: 1.1rem;
    }
    
    @keyframes pulse-rotate {
        0%, 100% { 
            transform: rotate(0deg) scale(1);
        }
        25% { 
            transform: rotate(-10deg) scale(1.1);
        }
        75% { 
            transform: rotate(10deg) scale(1.1);
        }
    }
    
    /* Color variants for limiting indicator */
    .limiting-fuel {
        background: #2196f3 !important;
        border: 2px solid #0d47a1 !important;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    .limiting-gas {
        background: #ff9800 !important;
        border: 2px solid #e65100 !important;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    /* NEW: Unknown/None limiting factor styles */
    .limiting-unknown {
        background: #6c757d !important;
        border: 2px solid #495057 !important;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }
    
    .limiting-none {
        background: #dc3545 !important;
        border: 2px solid #b71c1c !important;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    /* Limiting Factor stat badge - special styling */
    .stat-badge.limiting-factor {
        border: 2px solid !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
    
    .stat-badge.limiting-fuel {
        background: rgba(33, 150, 243, 0.15) !important;
        border-color: #2196f3 !important;
    }
    
    .stat-badge.limiting-gas {
        background: rgba(255, 152, 0, 0.15) !important;
        border-color: #ff9800 !important;
    }
    
    .stat-badge.limiting-unknown {
        background: rgba(108, 117, 125, 0.15) !important;
        border-color: #6c757d !important;
    }
    
    .stat-badge.limiting-none {
        background: rgba(220, 53, 69, 0.15) !important;
        border-color: #dc3545 !important;
    }
</style>
@endpush

@section('content')
<div class="structure-manager-wrapper">

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

</div><!-- /.structure-manager-wrapper -->
@endsection

@push('javascript')
<script src="{{ asset('vendor/structure-manager/js/moment.min.js') }}"></script>

<script>
// Wait for jQuery and Moment.js to be fully loaded
(function checkLibraries() {
    if (typeof $ === 'undefined') {
        console.log('Waiting for jQuery...');
        setTimeout(checkLibraries, 50);
        return;
    }
    
    if (typeof moment === 'undefined') {
        console.log('Waiting for Moment.js...');
        setTimeout(checkLibraries, 50);
        return;
    }
    
    // Both jQuery and Moment.js are loaded, initialize
    initializeCriticalAlerts();
})();

function initializeCriticalAlerts() {
    console.log('Initializing Critical Alerts...');
    
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
                
                // Check if Metenox
                let isMetenox = alert.structure_type === 'Metenox Moon Drill' && alert.metenox_data;
                
                // Check if POS
                let isPOS = alert.structure_category === 'pos' && alert.pos_data;
                
                // Format time display
                let timeDisplay = '';
                if (isPOS && alert.pos_data && alert.pos_data.actual_days_remaining !== undefined) {
                    // For POS: Use the correct calculation from starbase_fuel_history
                    let totalHours = alert.pos_data.actual_days_remaining * 24;
                    let days = Math.floor(totalHours / 24);
                    let hours = Math.floor(totalHours % 24);  // Round DOWN - POS hasn't consumed the next hour yet!
                    timeDisplay = days + 'd ' + hours + 'h';
                } else if (alert.days_remaining !== undefined && alert.remaining_hours !== undefined) {
                    // For Upwell structures: Use the generic calculation
                    timeDisplay = alert.days_remaining + 'd ' + alert.remaining_hours + 'h';
                } else {
                    timeDisplay = alert.days_remaining + ' Days';
                }
                
                // Calculate hours remaining (use correct source for POS)
                let hoursLeft;
                if (isPOS && alert.pos_data && alert.pos_data.actual_days_remaining !== undefined) {
                    hoursLeft = alert.pos_data.actual_days_remaining * 24;
                } else {
                    hoursLeft = alert.hours_remaining || (alert.days_remaining * 24);
                }
                
                // Calculate urgency message
                let urgencyMsg = '';
                if (hoursLeft < 72) {
                    urgencyMsg = '<span class="badge badge-danger"><i class="fas fa-fire"></i> URGENT - Refuel immediately!</span>';
                } else if (hoursLeft < 168) {
                    urgencyMsg = '<span class="badge badge-danger">Critical - Refuel within 24 hours</span>';
                } else if (hoursLeft < 240) {
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
                `;
                
                // Use correct URL based on structure type
                let detailUrl = isPOS ? 
                    "{{ url('structure-manager/pos') }}/" + alert.structure_id :
                    "{{ url('structure-manager/structure') }}/" + alert.structure_id;
                
                html += `
                                                <a href="${detailUrl}">
                                                    ${alert.structure_name}
                                                </a>
                `;
                
                // Add PROMINENT limiting factor badge for Metenox - ALWAYS show
                if (isMetenox) {
                    let limitingFactor = 'unknown';
                    let limitingIcon = 'fa-question';
                    let limitingText = 'AWAITING FUEL DATA';
                    let limitingClass = 'limiting-unknown';
                    
                    if (alert.metenox_data && alert.metenox_data.limiting_factor) {
                        limitingFactor = alert.metenox_data.limiting_factor;
                        
                        if (limitingFactor === 'fuel_blocks') {
                            limitingIcon = 'fa-fire';
                            limitingText = 'FUEL BLOCKS LIMITING';
                            limitingClass = 'limiting-fuel';
                        } else if (limitingFactor === 'magmatic_gas') {
                            limitingIcon = 'fa-wind';
                            limitingText = 'MAGMATIC GAS LIMITING';
                            limitingClass = 'limiting-gas';
                        } else if (limitingFactor === 'none') {
                            limitingIcon = 'fa-exclamation-triangle';
                            limitingText = 'NO FUEL DETECTED';
                            limitingClass = 'limiting-none';
                        }
                    }
                    
                    html += `
                                                <span class="limiting-indicator ${limitingClass}">
                                                    <i class="fas ${limitingIcon}"></i>
                                                    ${limitingText}
                                                </span>
                    `;
                }
                
                // Add PROMINENT limiting factor badge for POS
                if (isPOS) {
                    let pd = alert.pos_data || {};
                    let limitingFactor = pd.limiting_factor || 'fuel';
                    let limitingIcon = 'fa-fire';
                    let limitingText = 'FUEL BLOCKS LIMITING';
                    let limitingClass = 'limiting-fuel';
                    
                    if (limitingFactor === 'charters') {
                        limitingIcon = 'fa-scroll';
                        limitingText = 'CHARTERS LIMITING';
                        limitingClass = 'limiting-gas'; // Reuse gas styling
                    } else if (limitingFactor === 'none') {
                        limitingIcon = 'fa-exclamation-triangle';
                        limitingText = 'NO FUEL DETECTED';
                        limitingClass = 'limiting-none';
                    }
                    
                    html += `
                                                <span class="limiting-indicator ${limitingClass}">
                                                    <i class="fas ${limitingIcon}"></i>
                                                    ${limitingText}
                                                </span>
                    `;
                }
                
                html += `
                                            </h5>
                                            <p class="mb-2">
                                                <span class="badge badge-secondary">${alert.structure_type}</span>
                                                <span class="badge badge-info ml-1"><i class="fas fa-map-marker-alt"></i> ${alert.system_name}</span>
                `;
                
                // Add Metenox badge
                if (isMetenox) {
                    html += `<span class="badge badge-metenox ml-1"><i class="fas fa-moon"></i> Metenox</span>`;
                }
                
                // Add POS badge
                if (isPOS) {
                    html += `<span class="badge badge-pos ml-1"><i class="fas fa-tower-broadcast"></i> POS</span>`;
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
                
                // Show Metenox dual fuel status - ALWAYS show for Metenox, even if no data
                if (isMetenox) {
                    let md = alert.metenox_data || {};
                    
                    // Provide defaults if data is missing
                    let fuelBlocksQty = md.fuel_blocks_quantity || 0;
                    let gasQty = md.magmatic_gas_quantity || 0;
                    let fuelDays = md.fuel_blocks_days || 0;
                    let gasDays = md.magmatic_gas_days || 0;
                    let limitingFactor = md.limiting_factor || 'unknown';
                    
                    let fuelBlocksClass = limitingFactor === 'fuel_blocks' ? 'fuel-critical' : '';
                    let gasClass = limitingFactor === 'magmatic_gas' ? 'fuel-critical' : '';
                    
                    // Determine status badges
                    let fuelStatus = '';
                    let gasStatus = '';
                    
                    if (limitingFactor === 'unknown') {
                        fuelStatus = '<span class="badge badge-secondary" style="background: #6c757d !important; color: #fff !important; font-weight: bold;">Unknown</span>';
                        gasStatus = '<span class="badge badge-secondary" style="background: #6c757d !important; color: #fff !important; font-weight: bold;">Unknown</span>';
                    } else if (limitingFactor === 'none') {
                        fuelStatus = '<span class="badge badge-danger" style="background: #dc3545 !important; color: #fff !important; font-weight: bold;">EMPTY</span>';
                        gasStatus = '<span class="badge badge-danger" style="background: #dc3545 !important; color: #fff !important; font-weight: bold;">EMPTY</span>';
                    } else {
                        fuelStatus = limitingFactor === 'fuel_blocks' ? 
                            '<span class="limiting-factor-badge"><i class="fas fa-exclamation-circle"></i> LIMITING</span>' : 
                            '<span class="badge badge-success" style="background: #4caf50 !important; color: #000 !important; font-weight: bold;">OK</span>';
                        gasStatus = limitingFactor === 'magmatic_gas' ? 
                            '<span class="limiting-factor-badge"><i class="fas fa-exclamation-circle"></i> LIMITING</span>' : 
                            '<span class="badge badge-success" style="background: #4caf50 !important; color: #000 !important; font-weight: bold;">OK</span>';
                    }
                    
                    html += `
                        <div class="metenox-dual-fuel">
                            <strong style="color: #9c27b0; font-size: 1.05rem;">
                                <i class="fas fa-exclamation-triangle"></i> Dual Fuel System Status
                            </strong>
                            <hr style="margin: 0.75rem 0; border: 0; border-top: 2px solid #9c27b0; opacity: 0.5;">
                            <div class="metenox-resource">
                                <span>
                                    <i class="fas fa-fire" style="color: #2196f3;"></i> 
                                    <strong>Fuel Blocks:</strong>
                                    <span class="${fuelBlocksClass}" style="font-size: 1.1rem;">${fuelDays > 0 ? fuelDays.toFixed(1) + ' days' : (limitingFactor === 'unknown' ? '?' : '0 days')}</span>
                                    <span style="opacity: 0.7; margin-left: 0.5rem;">(${fuelBlocksQty.toLocaleString()} blocks)</span>
                                </span>
                                ${fuelStatus}
                            </div>
                            <div class="metenox-resource">
                                <span>
                                    <i class="fas fa-wind" style="color: #ff9800;"></i> 
                                    <strong>Magmatic Gas:</strong>
                                    <span class="${gasClass}" style="font-size: 1.1rem;">${gasDays > 0 ? gasDays.toFixed(1) + ' days' : (limitingFactor === 'unknown' ? '?' : '0 days')}</span>
                                    <span style="opacity: 0.7; margin-left: 0.5rem;">(${gasQty.toLocaleString()} units)</span>
                                </span>
                                ${gasStatus}
                            </div>
                            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(156, 39, 176, 0.3); opacity: 0.85;">
                                <small>
                                    ${limitingFactor === 'unknown' ? 
                                        '<i class="fas fa-info-circle" style="color: #6c757d;"></i> Fuel data not yet available - check structure details' : 
                                        (limitingFactor === 'none' ? 
                                            '<i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> <strong style="color: #ff5722;">Structure has no fuel!</strong> Refuel immediately!' :
                                            '<i class="fas fa-info-circle" style="color: #2196f3;"></i> Structure will stop when <strong style="color: #ff5722;">' + (limitingFactor === 'fuel_blocks' ? 'fuel blocks' : 'magmatic gas') + '</strong> runs out'
                                        )
                                    }
                                </small>
                            </div>
                        </div>
                    `;
                }
                
                // Show POS fuel status - Fuel blocks, Charters (if needed), Strontium
                if (isPOS) {
                    let pd = alert.pos_data || {};
                    
                    let fuelBlocksQty = pd.fuel_blocks_quantity || 0;
                    let charterQty = pd.charter_quantity || 0;
                    let strontiumQty = pd.strontium_quantity || 0;
                    let requiresCharters = pd.requires_charters || false;
                    let limitingFactor = pd.limiting_factor || 'fuel';
                    let spaceType = pd.space_type || 'Unknown';
                    
                    // Use the CORRECT POS data from starbase_fuel_history (actual_days_remaining)
                    // This matches the POS tab display and is the correct calculation
                    let totalDaysRemaining = pd.actual_days_remaining || 0;
                    let totalHours = totalDaysRemaining * 24;
                    let fuelDays = Math.floor(totalHours / 24);
                    let fuelHours = Math.floor(totalHours % 24);  // Round DOWN - POS hasn't consumed the next hour yet!
                    
                    // For charters (if any)
                    let charterDays = Math.floor((pd.charter_days_remaining || 0));
                    let charterHours = Math.floor(((pd.charter_days_remaining || 0) % 1) * 24);  // Round DOWN
                    let strontiumHours = pd.strontium_hours_available || 0;
                    
                    // Format time displays - show only hours if < 1 day for clarity
                    let fuelTimeDisplay = fuelDays == 0 ? fuelHours + 'h' : fuelDays + 'd ' + fuelHours + 'h';
                    let charterTimeDisplay = requiresCharters ? 
                        (charterDays == 0 ? charterHours + 'h' : charterDays + 'd ' + charterHours + 'h') : '';
                    
                    let fuelClass = limitingFactor === 'fuel' ? 'fuel-critical' : '';
                    let charterClass = limitingFactor === 'charters' ? 'fuel-critical' : '';
                    
                    // Determine status badges
                    let fuelStatus = limitingFactor === 'fuel' ? 
                        '<span class="limiting-factor-badge"><i class="fas fa-exclamation-circle"></i> LIMITING</span>' : 
                        '<span class="badge badge-success" style="background: #4caf50 !important; color: #000 !important; font-weight: bold;">OK</span>';
                    
                    let charterStatus = limitingFactor === 'charters' ? 
                        '<span class="limiting-factor-badge"><i class="fas fa-exclamation-circle"></i> LIMITING</span>' : 
                        '<span class="badge badge-success" style="background: #4caf50 !important; color: #000 !important; font-weight: bold;">OK</span>';
                    
                    html += `
                        <div class="pos-dual-fuel">
                            <strong style="color: #e91e63; font-size: 1.05rem;">
                                <i class="fas fa-tower-broadcast"></i> POS Fuel System Status (${spaceType})
                            </strong>
                            <hr style="margin: 0.75rem 0; border: 0; border-top: 2px solid #e91e63; opacity: 0.5;">
                            
                            <div class="pos-resource">
                                <span>
                                    <i class="fas fa-fire" style="color: #2196f3;"></i> 
                                    <strong>Fuel Blocks:</strong>
                                    <span class="${fuelClass}" style="font-size: 1.1rem;">${fuelTimeDisplay}</span>
                                    <span style="opacity: 0.7; margin-left: 0.5rem;">(${fuelBlocksQty.toLocaleString()} blocks)</span>
                                </span>
                                ${fuelStatus}
                            </div>
                    `;
                    
                    // Show charters only if required (High-Sec)
                    if (requiresCharters) {
                        html += `
                            <div class="pos-resource">
                                <span>
                                    <i class="fas fa-scroll" style="color: #ff9800;"></i> 
                                    <strong>Starbase Charters:</strong>
                                    <span class="${charterClass}" style="font-size: 1.1rem;">${charterTimeDisplay}</span>
                                    <span style="opacity: 0.7; margin-left: 0.5rem;">(${charterQty.toLocaleString()} charters)</span>
                                </span>
                                ${charterStatus}
                            </div>
                        `;
                    }
                    
                    // Show strontium if available
                    if (strontiumQty > 0 || strontiumHours > 0) {
                        let strontiumStatus = strontiumHours < 6 ? 
                            '<span class="badge badge-warning" style="background: #ff9800 !important; color: #000 !important; font-weight: bold;">LOW</span>' :
                            '<span class="badge badge-info" style="background: #2196f3 !important; color: #fff !important; font-weight: bold;">OK</span>';
                        
                        html += `
                            <div class="pos-resource">
                                <span>
                                    <i class="fas fa-shield-alt" style="color: #9c27b0;"></i> 
                                    <strong>Strontium (Reinforcement):</strong>
                                    <span style="font-size: 1.1rem;">${strontiumHours > 0 ? strontiumHours.toFixed(1) + ' hours' : '0 hours'}</span>
                                    <span style="opacity: 0.7; margin-left: 0.5rem;">(${strontiumQty.toLocaleString()} units)</span>
                                </span>
                                ${strontiumStatus}
                            </div>
                        `;
                    }
                    
                    html += `
                            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(233, 30, 99, 0.3); opacity: 0.85;">
                                <small>
                                    <i class="fas fa-info-circle" style="color: #2196f3;"></i> 
                                    POS will go offline when <strong style="color: #ff5722;">${limitingFactor === 'charters' ? 'starbase charters' : 'fuel blocks'}</strong> run out
                                    ${requiresCharters ? ' <span class="badge badge-warning" style="font-size: 0.7rem; margin-left: 0.25rem;">High-Sec: Needs Charters</span>' : ''}
                                </small>
                            </div>
                        </div>
                    `;
                }
                
                // ========================================
                // ALERT STATS SECTION (appears for ALL structures)
                // ========================================
                html += `
                    <div class="alert-stats">
                        <div class="stat-badge">
                            <i class="far fa-clock text-${statusColor}"></i>
                            <strong>Fuel Expires:</strong><br>
                            <span>${expiresAt.format('YYYY-MM-DD HH:mm')}</span><br>
                            <small>(${timeUntilEmpty})</small>
                        </div>
                `;
                
                // Add Limiting Factor for Metenox ONLY (before weekly/monthly stats)
                if (isMetenox) {
                    let limitingFactor = 'unknown';
                    let limitingIcon = 'fa-question';
                    let limitingText = 'Awaiting Data';
                    let limitingColor = 'secondary';
                    let limitingBg = 'rgba(108, 117, 125, 0.15)';
                    let limitingBorder = '2px solid rgba(108, 117, 125, 0.3)';
                    let limitingDays = '?';
                    let limitingClass = 'limiting-unknown';
                    
                    if (alert.metenox_data && alert.metenox_data.limiting_factor) {
                        limitingFactor = alert.metenox_data.limiting_factor;
                        
                        if (limitingFactor === 'fuel_blocks') {
                            limitingIcon = 'fa-fire';
                            limitingText = 'Fuel Blocks';
                            limitingColor = 'info';
                            limitingBg = 'rgba(33, 150, 243, 0.15)';
                            limitingBorder = '2px solid rgba(33, 150, 243, 0.3)';
                            limitingDays = alert.metenox_data.fuel_blocks_days ? alert.metenox_data.fuel_blocks_days.toFixed(1) : '?';
                            limitingClass = 'limiting-fuel';
                        } else if (limitingFactor === 'magmatic_gas') {
                            limitingIcon = 'fa-wind';
                            limitingText = 'Magmatic Gas';
                            limitingColor = 'warning';
                            limitingBg = 'rgba(255, 152, 0, 0.15)';
                            limitingBorder = '2px solid rgba(255, 152, 0, 0.3)';
                            limitingDays = alert.metenox_data.magmatic_gas_days ? alert.metenox_data.magmatic_gas_days.toFixed(1) : '?';
                            limitingClass = 'limiting-gas';
                        } else if (limitingFactor === 'none') {
                            limitingIcon = 'fa-times-circle';
                            limitingText = 'No Fuel';
                            limitingColor = 'danger';
                            limitingBg = 'rgba(220, 53, 69, 0.15)';
                            limitingBorder = '2px solid rgba(220, 53, 69, 0.3)';
                            limitingDays = '0';
                            limitingClass = 'limiting-none';
                        }
                    }
                    
                    let textColor = limitingFactor === 'fuel_blocks' ? '#2196f3' : 
                                   (limitingFactor === 'magmatic_gas' ? '#ff9800' : 
                                   (limitingFactor === 'none' ? '#dc3545' : '#6c757d'));
                    
                    html += `
                        <div class="stat-badge limiting-factor ${limitingClass}" style="background: ${limitingBg} !important; border: ${limitingBorder} !important;">
                            <i class="fas ${limitingIcon} text-${limitingColor}"></i>
                            <strong style="color: ${textColor};">⚠️ Limiting Factor:</strong><br>
                            <span style="font-size: 1.1rem; font-weight: bold; color: ${textColor};">${limitingText}</span><br>
                            <small>${limitingDays !== '?' ? 'Runs out first at ' + limitingDays + ' days' : 'Check structure for details'}</small>
                        </div>
                    `;
                }
                
                // Weekly and Monthly requirement badges - DIFFERENT for POS vs Upwell
                if (alert.structure_category === 'pos' && alert.fuel_requirements) {
                    // POS: Use static pre-calculated values from backend
                    const weeklyBlocks = alert.fuel_requirements.fuel_per_week;
                    const monthlyBlocks = alert.fuel_requirements.fuel_per_month;
                    const weeklyVolume = alert.fuel_requirements.volume_per_week;
                    const monthlyVolume = alert.fuel_requirements.volume_per_month;
                    const factionType = alert.fuel_requirements.faction_type;
                    
                    html += `
                        <div class="stat-badge">
                            <i class="fas fa-gas-pump text-primary"></i>
                            <strong>Weekly Requirement:</strong><br>
                            <span>${weeklyBlocks.toLocaleString()} blocks</span><br>
                            <small>(${weeklyVolume.toLocaleString()} m³)</small>
                            ${factionType !== 'T1' ? '<br><span class="badge badge-info" style="font-size: 0.7rem;">' + factionType + ' Tower Bonus</span>' : ''}
                        </div>
                        <div class="stat-badge">
                            <i class="fas fa-cubes text-info"></i>
                            <strong>30-Day Need:</strong><br>
                            <span>${monthlyBlocks.toLocaleString()} blocks</span><br>
                            <small>(${monthlyVolume.toLocaleString()} m³)</small>
                        </div>
                    `;
                } else {
                    // Upwell structures: Use blocks_needed (already calculated weekly)
                    html += `
                        <div class="stat-badge">
                            <i class="fas fa-gas-pump text-primary"></i>
                            <strong>Weekly Requirement:</strong><br>
                            <span>${alert.blocks_needed.toLocaleString()} blocks</span><br>
                            <small>(${(alert.blocks_needed * 5).toLocaleString()} m³)</small>
                        </div>
                        <div class="stat-badge">
                            <i class="fas fa-cubes text-info"></i>
                            <strong>30-Day Need:</strong><br>
                            <span>${(alert.blocks_needed * 4.3).toFixed(0)} blocks</span><br>
                            <small>(${(alert.blocks_needed * 4.3 * 5).toFixed(0)} m³)</small>
                        </div>
                    `;
                }
                
                // Add magmatic gas requirements for Metenox
                if (isMetenox) {
                    html += `
                        <div class="stat-badge">
                            <i class="fas fa-wind text-warning"></i>
                            <strong>Gas Required:</strong><br>
                            <span>${(4800 * 7).toLocaleString()} units/week</span><br>
                            <small>${(4800 * 30).toLocaleString()} units/month</small>
                        </div>
                    `;
                }
                
                // Close alert-stats section
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
}
</script>
@endpush
