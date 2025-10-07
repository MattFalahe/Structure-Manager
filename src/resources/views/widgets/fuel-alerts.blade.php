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
                        <th>Blocks Needed</th>
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
                    html += `
                        <tr>
                            <td>
                                <a href="{{ url('structure-manager/structure') }}/${structure.structure_id}">
                                    ${structure.structure_name}
                                </a>
                                <br>
                                <small class="text-muted">${structure.structure_type}</small>
                            </td>
                            <td>${structure.system_name}</td>
                            <td class="${statusClass}">${structure.days_remaining} days</td>
                            <td>${structure.blocks_needed.toLocaleString()} blocks/week</td>
                        </tr>
                    `;
                });
            }
            
            $('#fuel-alerts-body').html(html);
        });
    }
    
    // Load on page load
    loadFuelAlerts();
    
    // Refresh button
    $('#refresh-fuel-alerts').on('click', function() {
        loadFuelAlerts();
    });
    
    // Auto-refresh every 5 minutes
    setInterval(loadFuelAlerts, 300000);
    
    // Generate logistics report
    $('#generate-logistics-report').on('click', function() {
        window.open('{{ route("structure-manager.logistics-report") }}', '_blank');
    });
});
</script>
