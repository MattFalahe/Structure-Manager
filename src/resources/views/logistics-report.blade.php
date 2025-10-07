@extends('web::layouts.grids.12')

@section('title', 'Fuel Logistics Report')
@section('page_header', 'Fuel Logistics Report')

@push('head')
<style>
    @media print {
        .no-print { display: none !important; }
        .card { border: 1px solid #000 !important; }
    }
    
    .system-section {
        page-break-inside: avoid;
        margin-bottom: 2rem;
    }
    
    .summary-box {
        background: #f8f9fa;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .fuel-critical { color: #dc3545; font-weight: bold; }
    .fuel-warning { color: #ffc107; font-weight: bold; }
    .fuel-normal { color: #28a745; }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Fuel Requirements Report - {{ Carbon\Carbon::now()->format('Y-m-d H:i') }} EVE</h3>
        <div class="card-tools no-print">
            <button onclick="window.print()" class="btn btn-sm btn-primary">
                <i class="fas fa-print"></i> Print
            </button>
            <button id="export-csv" class="btn btn-sm btn-success">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="summary-box">
            <h4>Summary</h4>
            <div class="row">
                <div class="col-md-3">
                    <strong>Total Structures:</strong> <span id="total-structures">0</span>
                </div>
                <div class="col-md-3">
                    <strong>Systems:</strong> <span id="total-systems">0</span>
                </div>
                <div class="col-md-3">
                    <strong>30-Day Blocks:</strong> <span id="total-blocks">0</span>
                </div>
                <div class="col-md-3">
                    <strong>Hauler Trips:</strong> <span id="hauler-trips">0</span>
                </div>
            </div>
        </div>
        
        <div id="logistics-data">
            <p class="text-center">Loading report data...</p>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
$(document).ready(function() {
    let reportData = null;
    
    // Load logistics report
    $.get('{{ route("structure-manager.logistics-data") }}', function(data) {
        reportData = data;
        
        // Update summary
        $('#total-structures').text(data.summary.total_structures);
        $('#total-systems').text(data.summary.total_systems);
        $('#total-blocks').text(data.summary.total_blocks_30d.toLocaleString());
        $('#hauler-trips').text(data.summary.total_hauler_trips);
        
        // Build system sections
        let html = '';
        
        for (let system in data.systems) {
            let systemData = data.systems[system];
            
            html += `
                <div class="system-section">
                    <h5>${system}</h5>
                    <p class="text-muted">
                        Structures: ${systemData.structures.length} | 
                        30-Day Requirement: ${systemData.total_blocks_30d.toLocaleString()} blocks
                    </p>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Structure</th>
                                <th>Type</th>
                                <th>Corporation</th>
                                <th>Fuel Expires</th>
                                <th>Days Left</th>
                                <th>30d Blocks</th>
                                <th>60d Blocks</th>
                                <th>90d Blocks</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            systemData.structures.forEach(function(structure) {
                let daysClass = structure.days_remaining < 7 ? 'fuel-critical' : 
                               structure.days_remaining < 14 ? 'fuel-warning' : 'fuel-normal';
                
                html += `
                    <tr>
                        <td>${structure.name}</td>
                        <td>${structure.type}</td>
                        <td>${structure.corporation}</td>
                        <td>${moment(structure.fuel_expires).format('YYYY-MM-DD')}</td>
                        <td class="${daysClass}">${structure.days_remaining}</td>
                        <td>${structure.blocks_30d.toLocaleString()}</td>
                        <td>${structure.blocks_60d.toLocaleString()}</td>
                        <td>${structure.blocks_90d.toLocaleString()}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5">System Total</th>
                                <th>${systemData.total_blocks_30d.toLocaleString()}</th>
                                <th>${systemData.total_blocks_60d.toLocaleString()}</th>
                                <th>${systemData.total_blocks_90d.toLocaleString()}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            `;
        }
        
        $('#logistics-data').html(html);
    });
    
    // Export to CSV
    $('#export-csv').on('click', function() {
        if (!reportData) return;
        
        let csv = 'System,Structure,Type,Corporation,Fuel Expires,Days Left,30d Blocks,60d Blocks,90d Blocks\n';
        
        for (let system in reportData.systems) {
            reportData.systems[system].structures.forEach(function(structure) {
                csv += `"${system}","${structure.name}","${structure.type}","${structure.corporation}",`;
                csv += `"${structure.fuel_expires}",${structure.days_remaining},`;
                csv += `${structure.blocks_30d},${structure.blocks_60d},${structure.blocks_90d}\n`;
            });
        }
        
        // Create download link
        let blob = new Blob([csv], { type: 'text/csv' });
        let url = window.URL.createObjectURL(blob);
        let a = document.createElement('a');
        a.href = url;
        a.download = `fuel-logistics-${moment().format('YYYY-MM-DD')}.csv`;
        a.click();
    });
});
</script>
@endpush
