@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::menu.fuel_reserves'))
@section('page_header', trans('structure-manager::menu.fuel_reserves'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* === Fuel Reserves — page-specific chrome ===
       Generic card / button / table / fuel-status come from canonical
       structure-manager.css. The grouped system+structure layout boxes
       below are bespoke to this view. */

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
        flex-wrap: wrap;
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

    /* Reserve listing table — explicit dark variant for the per-structure
       inner table (canonical .table is a generic AdminLTE override). */
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

    /* SEMANTIC system-security badges — DO NOT CHANGE */
    .sec-high { background-color: var(--sm-success); color: #000; }
    .sec-low  { background-color: var(--sm-warning); color: #000; }
    .sec-null { background-color: var(--sm-danger);  color: #fff; }

    /* SEMANTIC Metenox identity badge — DO NOT CHANGE */
    .metenox-structure .structure-card-header {
        background: rgba(156, 39, 176, 0.15);
        border-color: rgba(156, 39, 176, 0.3);
    }
    .metenox-badge {
        background-color: rgba(193, 114, 207, 0.2);
        color: #c04ed4;
        border: 1px solid rgba(156, 39, 176, 0.3);
    }

    /* SEMANTIC magmatic-gas row tint — DO NOT CHANGE */
    .gas-row {
        background: rgba(255, 193, 7, 0.05);
    }

    .fuel-type-icon {
        width: 20px;
        text-align: center;
        margin-right: 0.25rem;
    }

    .reserve-totals {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.15);
        padding: 0.5rem;
        margin-top: 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }

    /* ===========================================================
       v2.0.0 — External reserves + Fuel Withdrawals pagination
       =========================================================== */

    /* External-location badge (NPC station, foreign citadel) */
    .sm-badge-external {
        background-color: rgba(99, 102, 241, 0.2);
        color: #a5b4fc;
        border: 1px solid rgba(99, 102, 241, 0.4);
    }

    /* Outline for external structure cards in the system-grouped view */
    .sm-card-external .structure-card-header {
        background: rgba(99, 102, 241, 0.1);
        border-color: rgba(99, 102, 241, 0.3);
    }

    /* Pagination bar below Fuel Withdrawals table */
    .sm-refuel-pagination {
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        padding-top: 0.75rem;
    }
    .sm-pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .sm-pagination-info {
        color: #94a3b8;
        font-size: 0.85rem;
    }
    .sm-pagination-info strong {
        color: #e2e8f0;
    }
    .sm-pagination-controls {
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .sm-pagination-current {
        padding: 0 0.75rem;
        color: #cbd5e1;
        font-size: 0.85rem;
    }
    .sm-pagination-current strong {
        color: #f1f5f9;
    }
    .sm-refuel-page-btn {
        min-width: 2.25rem;
    }
    .sm-refuel-page-btn:disabled {
        opacity: 0.35;
    }
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper">

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">Fuel Reserves by System</h3>
        <div class="card-tools d-flex align-items-center" style="gap:0.5rem;">
            @can('structure-manager.admin')
            <select id="sm-scope-filter" class="form-control form-control-sm" style="width:auto;" title="Corporation scope">
                <option value="mine">My Corporations</option>
                <option value="all">All Corporations</option>
            </select>
            @endcan
            <button type="button" class="btn btn-sm btn-sm-primary" id="refresh-reserves">
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

<div class="card card-dark refuel-event-card">
    <div class="card-header">
        <h3 class="card-title">Fuel Withdrawals</h3>
        <div class="card-tools d-flex align-items-center" style="gap: 0.5rem;">
            <select id="history-per-page" class="form-control form-control-sm" style="width: auto;">
                <option value="25">25 per page</option>
                <option value="50" selected>50 per page</option>
                <option value="100">100 per page</option>
                <option value="200">200 per page</option>
            </select>
            <select id="history-days" class="form-control form-control-sm" style="width: auto;">
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
                        <th>Location</th>
                        <th title="Signed delta: negative = withdrawal, positive = refuel. Sub-line shows the before → after quantities so phantom hourly rows are easy to spot.">Quantity Change</th>
                        <th>From Hangar</th>
                        <th>Fuel Type</th>
                    </tr>
                </thead>
                <tbody id="refuel-events-body">
                    <tr>
                        <td colspan="6" class="text-center py-3">
                            <i class="fas fa-spinner fa-spin"></i> Loading withdrawal events...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="refuel-events-pagination" class="sm-refuel-pagination mt-2"></div>
    </div>
</div>

</div><!-- /.structure-manager-wrapper -->
@endsection

@push('javascript')
<script>

$(document).ready(function() {
    // FIXED: Correct type ID for Magmatic Gas is 81143, not 16273!
    const fuelTypeNames = {
        4051: 'Nitrogen Fuel Block',
        4246: 'Hydrogen Fuel Block',
        4247: 'Helium Fuel Block',
        4312: 'Oxygen Fuel Block',
        81143: 'Magmatic Gas'  // ✅ FIXED: Was 16273, now correct!
    };

    const fuelTypeIcons = {
        4051: '<i class="fas fa-fire text-primary fuel-type-icon"></i>',
        4246: '<i class="fas fa-fire text-info fuel-type-icon"></i>',
        4247: '<i class="fas fa-fire text-success fuel-type-icon"></i>',
        4312: '<i class="fas fa-fire text-danger fuel-type-icon"></i>',
        81143: '<i class="fas fa-wind text-warning fuel-type-icon"></i>'  // ✅ FIXED: Was 16273
    };

    // Base route URLs
    const reservesUrl = '{{ route('structure-manager.reserves-data') }}';
    const refuelHistoryBaseUrl = '{{ route('structure-manager.refuel-history', ['days' => 'DAYS_PLACEHOLDER']) }}'.replace('DAYS_PLACEHOLDER', '');
    const structureDetailBaseUrl = '{{ route('structure-manager.detail', ['id' => 'ID_PLACEHOLDER']) }}'.replace('ID_PLACEHOLDER', '');

    // Corp scope ('mine' default; admins can pick 'all'). Non-admins
    // never see the selector, so this safely falls back to 'mine'.
    function smScope() {
        return $('#sm-scope-filter').val() || 'mine';
    }

    function loadReserves() {
        $('#reserves-loading').show();
        $('#reserves-content').hide();

        $.get(reservesUrl + '?scope=' + smScope(), function(data) {
            let html = '';

            if (Object.keys(data).length === 0) {
                html = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No fuel reserves detected in any structures.</div>';
            } else {
                for (const [system, systemData] of Object.entries(data)) {
                    // Determine security class
                    const secClass = systemData.security >= 0.5 ? 'sec-high' :
                                   systemData.security > 0 ? 'sec-low' : 'sec-null';

                    // Count Metenox structures
                    const metenoxCount = systemData.structures.filter(s => s.type === 'Metenox Moon Drill').length;

                    // v2.0.0 — count external locations (NPC stations, foreign citadels).
                    // POS towers intentionally NOT counted here — they don't have CorpSAG
                    // hangars (Stront/Fuel/Charter bays are operational consumables, not
                    // staged reserves) and live on the dedicated POS view.
                    const externalStructures = systemData.structures.filter(s => s.is_external);
                    const ownedCount = systemData.structures.length - externalStructures.length;

                    html += `
                        <div class="system-section">
                            <div class="system-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-map-marker-alt"></i> ${system}
                                    <span class="badge ${secClass} ml-2">${systemData.security.toFixed(1)}</span>
                    `;

                    if (ownedCount > 0) {
                        html += `<span class="badge badge-success ml-2">${ownedCount} Structure${ownedCount > 1 ? 's' : ''}</span>`;
                    }
                    if (externalStructures.length > 0) {
                        html += `<span class="badge sm-badge-external ml-2"><i class="fas fa-warehouse"></i> ${externalStructures.length} External</span>`;
                    }

                    if (metenoxCount > 0) {
                        html += `<span class="badge metenox-badge ml-2"><i class="fas fa-wind"></i> ${metenoxCount} Metenox</span>`;
                    }

                    html += `
                                </h5>
                                <div class="system-stats">
                                    <span><strong>Total Fuel Blocks:</strong> ${systemData.total_reserves.toLocaleString()} blocks</span>
                                    <span><strong>Volume:</strong> ${(systemData.total_reserves * 5).toLocaleString()} m³</span>
                    `;

                    // Add gas total if any Metenox structures
                    if (metenoxCount > 0) {
                        // Calculate total gas from all structures
                        let totalGas = 0;
                        systemData.structures.forEach(s => {
                            if (s.type === 'Metenox Moon Drill' && s.reserves) {
                                s.reserves.forEach(r => {
                                    // ✅ FIXED: Was checking r.fuel_type_id === 16273
                                    if (r.fuel_type_id === 81143) {
                                        totalGas += r.quantity;
                                    }
                                });
                            }
                        });

                        if (totalGas > 0) {
                            html += `<span class="text-warning"><strong>Total Magmatic Gas:</strong> ${totalGas.toLocaleString()} units</span>`;
                        }
                    }

                    html += `
                                </div>
                            </div>
                            <div class="row">
                    `;

                    for (const structure of systemData.structures) {
                        const isMetenox = structure.type === 'Metenox Moon Drill';
                        const isExternal = structure.is_external === true;
                        let cardClass = '';
                        if (isMetenox) cardClass = 'metenox-structure';
                        else if (isExternal) cardClass = 'sm-card-external';

                        // ✅ FIXED: Separate fuel blocks and gas using correct type ID
                        const fuelReserves = structure.reserves.filter(r => r.fuel_type_id !== 81143);
                        const gasReserves = structure.reserves.filter(r => r.fuel_type_id === 81143);

                        // Calculate totals
                        const totalFuelBlocks = fuelReserves.reduce((sum, r) => sum + r.quantity, 0);
                        const totalGas = gasReserves.reduce((sum, r) => sum + r.quantity, 0);

                        // v2.0.0 — pick the right icon for the location type
                        let locationIcon = '<i class="fas fa-building"></i>';
                        if (structure.location_type === 'npc_station') locationIcon = '<i class="fas fa-warehouse"></i>';
                        else if (structure.location_type === 'foreign_structure') locationIcon = '<i class="fas fa-building text-warning"></i>';
                        else if (structure.location_type === 'unknown_location') locationIcon = '<i class="fas fa-question-circle"></i>';

                        html += `
                            <div class="col-md-6 mb-3">
                                <div class="structure-card ${cardClass}">
                                    <div class="structure-card-header">
                                        ${isExternal ? locationIcon + ' ' : ''}<strong>${structure.name}</strong>
                        `;

                        if (isMetenox) {
                            html += `<span class="badge metenox-badge ml-2"><i class="fas fa-wind"></i> Metenox</span>`;
                        }

                        if (isExternal) {
                            html += `<span class="badge sm-badge-external ml-2" title="Fuel staged outside owned structures">${structure.type}</span>`;
                        }

                        html += `
                                        <br><small class="text-muted">${isExternal ? structure.corporation + ' (staged)' : structure.type + ' - ' + structure.corporation}</small>
                                    </div>
                        `;

                        // Show totals - for ANY structure that has gas reserves!
                        if (totalGas > 0) {
                            // This structure has gas - show dual totals even if not Metenox
                            html += `
                                <div class="reserve-totals">
                                    <div class="row">
                                        <div class="col-6">
                                            <i class="fas fa-fire text-primary"></i> <strong>Fuel Blocks:</strong>
                                            <span class="badge badge-info">${totalFuelBlocks.toLocaleString()}</span>
                                            <small class="text-muted">(${(totalFuelBlocks * 5).toLocaleString()} m³)</small>
                                        </div>
                                        <div class="col-6">
                                            <i class="fas fa-wind text-warning"></i> <strong>Magmatic Gas:</strong>
                                            <span class="badge badge-warning">${totalGas.toLocaleString()}</span>
                                            <small class="text-muted">(${(totalGas / 4800).toFixed(1)} days)</small>
                                        </div>
                                    </div>
                                </div>
                            `;
                        } else {
                            // No gas - just show fuel blocks
                            html += `
                                <div class="mb-2">
                                    <strong>Total Reserves:</strong>
                                    <span class="badge badge-info">${totalFuelBlocks.toLocaleString()} blocks</span>
                                    <small class="text-muted">(${(totalFuelBlocks * 5).toLocaleString()} m³)</small>
                                </div>
                            `;
                        }

                        if (structure.reserves.length > 0) {
                            html += `
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
                                // ✅ FIXED: Was checking === 16273
                                const isGas = reserve.fuel_type_id === 81143;
                                const rowClass = isGas ? 'gas-row' : '';
                                const icon = fuelTypeIcons[reserve.fuel_type_id] || '';

                                html += `
                                    <tr class="${rowClass}">
                                        <td>
                                            <strong>${reserve.division_name}</strong>
                                            <br><small class="text-muted">${reserve.location}</small>
                                        </td>
                                        <td class="text-right"><strong>${reserve.quantity.toLocaleString()}</strong></td>
                                        <td>${icon}<small>${fuelTypeNames[reserve.fuel_type_id] || 'Unknown'}</small></td>
                                    </tr>
                                `;
                            }

                            html += `
                                    </tbody>
                                </table>
                            `;
                        } else {
                            html += `<p class="text-muted mb-0">No reserves found</p>`;
                        }

                        html += `
                                </div>
                            </div>
                        `;
                    }

                    // POS towers intentionally NOT rendered here. They use
                    // Stront/Fuel/Charter bays (operational consumables, not
                    // staged reserves), and the dedicated POS view handles
                    // their resource tracking.

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

    // v2.0.0 — current page state for the Fuel Withdrawals pagination
    let refuelPage = 1;

    function loadRefuelEvents(days = 30, page = 1) {
        // Show loading state
        $('#refuel-events-body').html(`
            <tr>
                <td colspan="6" class="text-center py-3">
                    <i class="fas fa-spinner fa-spin"></i> Loading withdrawal events...
                </td>
            </tr>
        `);
        $('#refuel-events-pagination').html('');

        // Build URL by appending days to base + page/per_page query params
        const perPage = $('#history-per-page').val() || 50;
        const url = refuelHistoryBaseUrl + days + '?page=' + page + '&per_page=' + perPage + '&scope=' + smScope();

        $.get(url, function(response) {
            // v2.0.0 — server returns {data: [...], pagination: {...}}
            // Backwards-compatible: handle legacy array-only response too.
            const events = Array.isArray(response) ? response : (response.data || []);
            const pagination = Array.isArray(response) ? null : response.pagination;

            let html = '';

            if (events.length === 0) {
                html = '<tr><td colspan="6" class="text-center py-3"><i class="fas fa-info-circle"></i> No withdrawal events in this period.</td></tr>';
            } else {
                for (const event of events) {
                    const timestamp = new Date(event.timestamp);
                    const isGas = event.fuel_type_id === 81143;
                    const icon = fuelTypeIcons[event.fuel_type_id] || '';
                    const isExternal = event.is_external === true;

                    // Structure cell: owned = link to detail page, external = plain text with icon
                    let structureCell;
                    if (isExternal) {
                        let locIcon = '<i class="fas fa-warehouse"></i>';
                        if (event.location_type === 'foreign_structure') locIcon = '<i class="fas fa-building text-warning"></i>';
                        else if (event.location_type === 'unknown_location') locIcon = '<i class="fas fa-question-circle"></i>';
                        structureCell = `${locIcon} ${event.structure_name} <span class="badge sm-badge-external ml-1" title="Fuel staged outside owned structures">External</span>`;
                    } else {
                        const detailUrl = structureDetailBaseUrl + event.structure_id;
                        structureCell = `<a href="${detailUrl}" class="text-decoration-none">${event.structure_name}</a>`;
                    }

                    // From Hangar cell: show CORPSAG3 badge + in-game division name below
                    const divisionName = event.division_name || '';
                    const showDivName = divisionName && divisionName !== ('Division ' + (event.from_location.replace(/^CorpSAG/, '')));

                    // v2.0.0 — quantity cell now shows the signed change
                    // PLUS the before→after context so operators can tell
                    // a real withdrawal (58k → 1k = −57k blocks moved out)
                    // from a phantom hourly snapshot. Also surfaces the
                    // tracking_method so depletion-reconciliation rows are
                    // visually distinct from primary asset-poll detection.
                    const change = (typeof event.quantity_change === 'number') ? event.quantity_change : -event.blocks_moved;
                    const prev = (typeof event.previous_quantity === 'number') ? event.previous_quantity : null;
                    const curr = (typeof event.new_quantity === 'number') ? event.new_quantity : null;
                    const unit = isGas ? 'units' : 'blocks';
                    const sign = change > 0 ? '+' : (change < 0 ? '−' : '');
                    const changeMag = Math.abs(change).toLocaleString();
                    const changeClass = change > 0 ? 'text-success' : (change < 0 ? 'text-danger' : 'text-muted');
                    let beforeAfter = '';
                    if (prev !== null && curr !== null) {
                        beforeAfter = `<br><small class="text-muted">${prev.toLocaleString()} → ${curr.toLocaleString()}</small>`;
                    }
                    // Source badge — only highlight the synthetic
                    // "depletion_reconciliation" rows, which are written
                    // when fuel moves out of a tracked CorpSAG and the
                    // last snapshot must be closed to zero. Primary
                    // tracking methods (direct / nested_office / external
                    // / metenox_fuel_bay / days_remaining / assets_endpoint)
                    // are the normal path and get no badge.
                    const trackingMethod = event.tracking_method || 'detected';
                    let sourceBadge = '';
                    if (trackingMethod === 'depletion_reconciliation') {
                        sourceBadge = '<span class="badge badge-warning ml-1" title="Synthetic row — fuel moved out of this CorpSAG, so the last positive snapshot was closed to zero. Not a real ESI-detected withdrawal.">Reconciled</span>';
                    }

                    html += `
                        <tr class="${isGas ? 'gas-row' : ''}">
                            <td>${timestamp.toLocaleString()}</td>
                            <td>${event.system_name}</td>
                            <td>${structureCell}</td>
                            <td>
                                <strong class="${changeClass}">${sign}${changeMag}</strong> ${unit}
                                ${sourceBadge}
                                ${beforeAfter}
                            </td>
                            <td>
                                <span class="badge badge-info">${event.from_location}</span>
                                ${showDivName ? `<br><small class="text-muted">${divisionName}</small>` : ''}
                            </td>
                            <td>${icon}<small>${fuelTypeNames[event.fuel_type_id] || 'Unknown'}</small></td>
                        </tr>
                    `;
                }
            }

            $('#refuel-events-body').html(html);

            // Render pagination controls
            if (pagination && pagination.last_page > 1) {
                renderRefuelPagination(pagination);
            }
        }).fail(function(xhr, status, error) {
            console.error('Error loading withdrawal events:', error);
            $('#refuel-events-body').html(`
                <tr>
                    <td colspan="6" class="text-center text-danger py-3">
                        <i class="fas fa-exclamation-triangle"></i> Error loading withdrawal events: ${error}
                    </td>
                </tr>
            `);
        });
    }

    /**
     * Render pagination controls below the Fuel Withdrawals table.
     * Shows: First | Prev | Page X of Y (Z total) | Next | Last
     */
    function renderRefuelPagination(p) {
        const isFirst = p.current_page <= 1;
        const isLast = p.current_page >= p.last_page;
        const showingFrom = (p.current_page - 1) * p.per_page + 1;
        const showingTo = Math.min(showingFrom + p.per_page - 1, p.total);

        const html = `
            <div class="sm-pagination-bar">
                <div class="sm-pagination-info">
                    Showing <strong>${showingFrom.toLocaleString()}</strong>-<strong>${showingTo.toLocaleString()}</strong>
                    of <strong>${p.total.toLocaleString()}</strong> withdrawals
                </div>
                <div class="sm-pagination-controls">
                    <button class="btn btn-sm btn-outline-secondary sm-refuel-page-btn"
                            data-page="1" ${isFirst ? 'disabled' : ''}>
                        <i class="fas fa-angle-double-left"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary sm-refuel-page-btn"
                            data-page="${p.current_page - 1}" ${isFirst ? 'disabled' : ''}>
                        <i class="fas fa-angle-left"></i>
                    </button>
                    <span class="sm-pagination-current">
                        Page <strong>${p.current_page}</strong> of <strong>${p.last_page}</strong>
                    </span>
                    <button class="btn btn-sm btn-outline-secondary sm-refuel-page-btn"
                            data-page="${p.current_page + 1}" ${isLast ? 'disabled' : ''}>
                        <i class="fas fa-angle-right"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary sm-refuel-page-btn"
                            data-page="${p.last_page}" ${isLast ? 'disabled' : ''}>
                        <i class="fas fa-angle-double-right"></i>
                    </button>
                </div>
            </div>
        `;
        $('#refuel-events-pagination').html(html);
    }

    // Initial load
    loadReserves();
    loadRefuelEvents(30, 1);

    // Refresh button
    $('#refresh-reserves').click(function() {
        $(this).find('i').addClass('fa-spin');
        loadReserves();
        loadRefuelEvents($('#history-days').val(), refuelPage);

        setTimeout(() => {
            $(this).find('i').removeClass('fa-spin');
        }, 1000);
    });

    // Corp scope selector (admin only) — reload both panels
    $('#sm-scope-filter').on('change', function() {
        refuelPage = 1;
        loadReserves();
        loadRefuelEvents($('#history-days').val(), 1);
    });

    // History period selector — resets to page 1
    $('#history-days').change(function() {
        refuelPage = 1;
        loadRefuelEvents($(this).val(), 1);
    });

    // Per-page selector — resets to page 1
    $('#history-per-page').change(function() {
        refuelPage = 1;
        loadRefuelEvents($('#history-days').val(), 1);
    });

    // Pagination click handler — event delegated since buttons are dynamic
    $('#refuel-events-pagination').on('click', '.sm-refuel-page-btn:not([disabled])', function() {
        const page = parseInt($(this).data('page'), 10);
        if (!isNaN(page) && page > 0) {
            refuelPage = page;
            loadRefuelEvents($('#history-days').val(), page);
        }
    });

    // Auto-refresh every 5 minutes — preserves current page
    setInterval(function() {
        loadReserves();
        loadRefuelEvents($('#history-days').val(), refuelPage);
    }, 300000);
});
</script>
@endpush
