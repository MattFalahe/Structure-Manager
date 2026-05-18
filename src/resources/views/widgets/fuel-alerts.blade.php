{{-- Dashboard widget partial.
     This is included from another page (no @extends) — it does NOT
     own the @push('head') stack, so we cannot link the canonical
     structure-manager.css from here. The host page is responsible
     for that. We keep the existing .structure-manager-wrapper so
     widget styles still apply when included on dashboards that
     don't already provide one.
--}}
<div class="structure-manager-wrapper">

<div class="card card-danger" id="fuel-alerts-widget">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-exclamation-triangle"></i> Critical Fuel Alerts
        </h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-minus"></i>
            </button>
            <button type="button" class="btn btn-tool" id="refresh-fuel-alerts">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>Structure</th>
                        <th>System</th>
                        <th>Days Left</th>
                        <th>Weekly Need</th>
                    </tr>
                </thead>
                <tbody id="fuel-alerts-body">
                    <tr>
                        <td colspan="4" class="text-center">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ route('structure-manager.index') }}" class="btn btn-sm btn-primary">
            View All Structures
        </a>
        <button class="btn btn-sm btn-info float-right" id="generate-logistics-report">
            <i class="fas fa-file-export"></i> Logistics Report
        </button>
    </div>
</div>

</div><!-- /.structure-manager-wrapper -->

<style>
    /* === Fuel-alerts widget — scoped to the widget only ===
       This partial cannot link the canonical CSS (no @push('head')
       access), so we keep the small set of widget-specific tooltip
       primitives inline. SEMANTIC Metenox/gas colors — DO NOT CHANGE. */

    /* SEMANTIC Metenox indicator badge — DO NOT CHANGE */
    .metenox-widget-badge {
        background-color: rgba(156, 39, 176, 0.2);
        color: #ce93d8;
        border: 1px solid rgba(156, 39, 176, 0.3);
        padding: 0.1rem 0.3rem;
        border-radius: 0.25rem;
        font-size: 0.7rem;
        margin-left: 0.25rem;
    }

    /* SEMANTIC magmatic-gas warning icon — DO NOT CHANGE */
    .gas-icon {
        color: #ffd43b;
        margin-left: 0.25rem;
    }

    /* Hover-revealed tooltip for dual-fuel structures */
    .dual-fuel-tooltip {
        position: relative;
        cursor: help;
    }
    .dual-fuel-tooltip .tooltip-text {
        visibility: hidden;
        background-color: rgba(0, 0, 0, 0.9);
        color: #fff;
        text-align: left;
        border-radius: 0.25rem;
        padding: 0.5rem;
        position: absolute;
        z-index: 1000;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        width: 200px;
        opacity: 0;
        transition: opacity 0.3s;
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 0.85rem;
    }
    .dual-fuel-tooltip:hover .tooltip-text {
        visibility: visible;
        opacity: 1;
    }
</style>

<script>
$(document).ready(function() {
    function loadFuelAlerts() {
        $.get('{{ route("structure-manager.critical-alerts") }}', function(data) {
            let html = '';

            if (data.length === 0) {
                html = '<tr><td colspan="4" class="text-center text-success">All structures have sufficient fuel!</td></tr>';
            } else {
                data.forEach(function(structure) {
                    let statusClass = structure.status === 'critical' ? 'text-danger font-weight-bold' : 'text-warning';
                    let isMetenox = structure.structure_type === 'Metenox Moon Drill';

                    // Build structure name with badges
                    let structureName = `
                        <a href="{{ url('structure-manager/structure') }}/${structure.structure_id}">
                            ${structure.structure_name}
                        </a>
                    `;

                    // Add Metenox indicator
                    if (isMetenox) {
                        structureName += `<span class="metenox-widget-badge" title="Metenox: Needs fuel blocks + gas"><i class="fas fa-wind"></i></span>`;
                    }

                    structureName += `<br><small class="text-muted">${structure.structure_type}</small>`;

                    // Build weekly need column
                    let weeklyNeed = '';
                    if (isMetenox) {
                        weeklyNeed = `
                            <div class="dual-fuel-tooltip">
                                <strong>${structure.blocks_needed.toLocaleString()}</strong> blocks
                                <i class="fas fa-wind gas-icon" title="Also needs gas"></i>
                                <div class="tooltip-text">
                                    <strong>Dual Fuel System:</strong><br>
                                    • ${structure.blocks_needed.toLocaleString()} blocks/week<br>
                                    • 33,600 gas/week
                                </div>
                            </div>
                        `;
                    } else {
                        weeklyNeed = `<strong>${structure.blocks_needed.toLocaleString()}</strong> blocks`;
                    }

                    html += `
                        <tr>
                            <td>${structureName}</td>
                            <td>${structure.system_name}</td>
                            <td class="${statusClass}">${structure.days_remaining} days</td>
                            <td>${weeklyNeed}</td>
                        </tr>
                    `;
                });
            }

            $('#fuel-alerts-body').html(html);
        }).fail(function(xhr, status, error) {
            console.error('Error loading fuel alerts:', error);
            $('#fuel-alerts-body').html(`
                <tr>
                    <td colspan="4" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error loading alerts
                    </td>
                </tr>
            `);
        });
    }

    // Load on page load
    loadFuelAlerts();

    // Refresh button
    $('#refresh-fuel-alerts').on('click', function() {
        let icon = $(this).find('i');
        icon.addClass('fa-spin');
        loadFuelAlerts();
        setTimeout(function() {
            icon.removeClass('fa-spin');
        }, 1000);
    });

    // Auto-refresh every 5 minutes
    setInterval(loadFuelAlerts, 300000);

    // Generate logistics report
    $('#generate-logistics-report').on('click', function() {
        window.open('{{ route("structure-manager.logistics-report") }}', '_blank');
    });
});
</script>
