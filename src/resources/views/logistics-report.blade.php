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
    
    /* DARK THEME COMPATIBLE - Changed from #f8f9fa */
    .summary-box {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
    }
    
    /* Better contrast for dark themes */
    .fuel-critical { color: #ff6b6b; font-weight: bold; }
    .fuel-warning { color: #ffd43b; font-weight: bold; }
    .fuel-normal { color: #51cf66; }
    
    /* DARK THEME COMPATIBLE - Changed from #e9ecef */
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
    
    /* Metenox indicator in table */
    .metenox-row {
        background: rgba(156, 39, 176, 0.05) !important;
    }
    
    .metenox-badge {
        background-color: rgba(193, 114, 207, 0.2);
        color: #c04ed4;
        border: 1px solid rgba(156, 39, 176, 0.3);
        padding: 0.15rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: bold;
    }
    
    .gas-requirement {
        color: #c04ed4;
        font-style: italic;
        font-size: 0.85rem;
    }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Fuel Requirements Report - {{ \Carbon\Carbon::now()->format('Y-m-d H:i') }} EVE</h3>
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
            <h4>Overall Summary</h4>
            <div class="row">
                <div class="col-md-3">
                    <strong>Total Structures:</strong> <span id="total-structures">Loading...</span>
                </div>
                <div class="col-md-3">
                    <strong>Systems:</strong> <span id="total-systems">Loading...</span>
                </div>
                <div class="col-md-3">
                    <strong>30-Day Fuel Blocks:</strong> <span id="total-blocks">Loading...</span>
                </div>
                <div class="col-md-3">
                    <strong>Estimated Hauler Trips:</strong> <span id="hauler-trips">Loading...</span>
                    <small class="d-block">(60,000 m³ capacity)</small>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-3">
                    <strong>Total Volume:</strong> <span id="total-volume">Loading...</span> m³
                </div>
                <div class="col-md-3">
                    <strong>60-Day Blocks:</strong> <span id="total-blocks-60d">Loading...</span>
                </div>
                <div class="col-md-3">
                    <strong>90-Day Blocks:</strong> <span id="total-blocks-90d">Loading...</span>
                </div>
                <div class="col-md-3">
                    <strong>Magmatic Gas (30d):</strong> <span id="total-gas" class="gas-requirement">Loading...</span>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Note:</strong> Metenox Moon Drills require BOTH fuel blocks AND magmatic gas. Gas requirements are shown separately below.
        </div>
        
        <div id="logistics-data">
            <div class="text-center p-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Loading logistics data...</p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')

<script src="{{ asset('vendor/structure-manager/js/moment.min.js') }}"></script>
<script>
$(document).ready(function() {
    let reportData = null;
    
    console.log('Loading logistics report from:', '{{ route("structure-manager.logistics-data") }}');
    
    // Load logistics report
    $.get('{{ route("structure-manager.logistics-data") }}', function(data) {
        console.log('Received logistics data:', data);
        reportData = data;
        
        // Update summary
        $('#total-structures').text(data.summary.total_structures.toLocaleString());
        $('#total-systems').text(data.summary.total_systems);
        $('#total-blocks').text(data.summary.total_blocks_30d.toLocaleString());
        $('#total-volume').text(data.summary.total_volume_30d.toLocaleString());
        $('#hauler-trips').text(data.summary.total_hauler_trips);
        
        // Calculate 60d and 90d totals, plus gas requirements
        let total60d = 0;
        let total90d = 0;
        let totalGas30d = 0;
        let metenoxCount = 0;
        
        for (let system in data.systems) {
            total60d += data.systems[system].total_blocks_60d;
            total90d += data.systems[system].total_blocks_90d;
            
            // Count Metenox structures and calculate gas
            data.systems[system].structures.forEach(function(structure) {
                if (structure.type === 'Metenox Moon Drill') {
                    metenoxCount++;
                    totalGas30d += 4800 * 30; // 4,800 gas/day * 30 days
                }
            });
        }
        
        $('#total-blocks-60d').text(total60d.toLocaleString());
        $('#total-blocks-90d').text(total90d.toLocaleString());
        $('#total-gas').text(totalGas30d.toLocaleString() + ' units (' + metenoxCount + ' Metenox)');
        
        // Build system sections
        let html = '';
        let systemCount = 0;
        
        for (let system in data.systems) {
            systemCount++;
            let systemData = data.systems[system];
            
            // Count Metenox in this system
            let systemMetenoxCount = systemData.structures.filter(s => s.type === 'Metenox Moon Drill').length;
            let systemGas30d = systemMetenoxCount * 4800 * 30;
            
            html += `
                <div class="system-section">
                    <div class="system-header">
                        <h5 class="mb-0">
                            <i class="fas fa-map-marker-alt"></i> ${system}
                            <span class="badge badge-success ml-2">${systemData.structures.length} Structure${systemData.structures.length > 1 ? 's' : ''}</span>
            `;
            
            if (systemMetenoxCount > 0) {
                html += `<span class="metenox-badge ml-2"><i class="fas fa-wind"></i> ${systemMetenoxCount} Metenox</span>`;
            }
            
            html += `
                        </h5>
                        <div class="system-stats">
                            <span><strong>30-Day:</strong> ${systemData.total_blocks_30d.toLocaleString()} blocks (${(systemData.total_blocks_30d * 5).toLocaleString()} m³)</span>
                            <span><strong>60-Day:</strong> ${systemData.total_blocks_60d.toLocaleString()} blocks</span>
                            <span><strong>90-Day:</strong> ${systemData.total_blocks_90d.toLocaleString()} blocks</span>
            `;
            
            if (systemMetenoxCount > 0) {
                html += `<span class="gas-requirement"><strong>Gas (30d):</strong> ${systemGas30d.toLocaleString()} units</span>`;
            }
            
            html += `
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Structure</th>
                                    <th>Type</th>
                                    <th>Corporation</th>
                                    <th>Fuel Expires</th>
                                    <th class="text-center">Time Left</th>
                                    <th class="text-right">30d Blocks</th>
                                    <th class="text-right">60d Blocks</th>
                                    <th class="text-right">90d Blocks</th>
                                    <th class="text-right">Gas (30d)</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            systemData.structures.forEach(function(structure) {
                // Determine fuel status class based on hours
                let hoursLeft = structure.hours_remaining || (structure.days_remaining * 24);
                let daysClass = hoursLeft < 168 ? 'fuel-critical' : 
                               hoursLeft < 336 ? 'fuel-warning' : 'fuel-normal';
                
                // Format time display
                let timeDisplay = '';
                if (structure.days_remaining !== undefined && structure.remaining_hours !== undefined) {
                    timeDisplay = structure.days_remaining + 'd ' + structure.remaining_hours + 'h';
                } else {
                    timeDisplay = structure.days_remaining + ' days';
                }
                
                // Check if Metenox
                let isMetenox = structure.type === 'Metenox Moon Drill';
                let rowClass = isMetenox ? 'metenox-row' : '';
                let gasRequirement = isMetenox ? (4800 * 30).toLocaleString() : '-';
                
                // Add limiting factor badge for Metenox
                let typeBadge = '<span class="badge badge-secondary">' + structure.type + '</span>';
                if (isMetenox && structure.metenox_data) {
                    let limitingText = structure.metenox_data.limiting_factor === 'fuel_blocks' ? 'Fuel' : 'Gas';
                    typeBadge += ' <span class="metenox-badge">' + limitingText + ' Limiting</span>';
                }
                
                html += `
                    <tr class="${rowClass}">
                        <td>${structure.name}</td>
                        <td>${typeBadge}</td>
                        <td><small>${structure.corporation}</small></td>
                        <td>${moment(structure.fuel_expires).format('YYYY-MM-DD HH:mm')}</td>
                        <td class="text-center ${daysClass}">${timeDisplay}</td>
                        <td class="text-right">${structure.blocks_30d.toLocaleString()}</td>
                        <td class="text-right">${structure.blocks_60d.toLocaleString()}</td>
                        <td class="text-right">${structure.blocks_90d.toLocaleString()}</td>
                        <td class="text-right ${isMetenox ? 'gas-requirement' : ''}">${gasRequirement}</td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                            <tfoot class="font-weight-bold">
                                <tr style="background: rgba(0, 0, 0, 0.2); border-top: 2px solid rgba(255, 255, 255, 0.2);">
                                    <th colspan="5">System Total</th>
                                    <th class="text-right">${systemData.total_blocks_30d.toLocaleString()}</th>
                                    <th class="text-right">${systemData.total_blocks_60d.toLocaleString()}</th>
                                    <th class="text-right">${systemData.total_blocks_90d.toLocaleString()}</th>
                                    <th class="text-right gas-requirement">${systemGas30d > 0 ? systemGas30d.toLocaleString() : '-'}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            `;
        }
        
        if (systemCount === 0) {
            html = '<div class="alert alert-info">No structures found with fuel data.</div>';
        }
        
        $('#logistics-data').html(html);
        
    }).fail(function(xhr, status, error) {
        console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
        console.error('Response Text:', xhr.responseText);
        
        let errorMessage = 'Unknown error';
        if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
        } else if (xhr.responseText) {
            errorMessage = xhr.responseText;
        } else {
            errorMessage = error || status;
        }
        
        $('#logistics-data').html(`
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error loading logistics data:</strong>
                <p class="mb-0">${errorMessage}</p>
                <hr>
                <small>Status: ${xhr.status} ${xhr.statusText}</small><br>
                <small>Check browser console for more details</small>
            </div>
        `);
    });
    
    // Export to CSV
    $('#export-csv').on('click', function() {
        if (!reportData) {
            alert('Please wait for data to load before exporting.');
            return;
        }
        
        let csv = 'System,Structure,Type,Corporation,Fuel Expires,Days Left,Hours Left,30d Blocks,60d Blocks,90d Blocks,Gas (30d),Limiting Factor\n';
        
        for (let system in reportData.systems) {
            reportData.systems[system].structures.forEach(function(structure) {
                let hoursLeft = structure.hours_remaining || (structure.days_remaining * 24);
                let isMetenox = structure.type === 'Metenox Moon Drill';
                let gasRequirement = isMetenox ? (4800 * 30) : 0;
                let limitingFactor = (isMetenox && structure.metenox_data) ? structure.metenox_data.limiting_factor : 'N/A';
                
                csv += '"' + system + '","' + structure.name + '","' + structure.type + '","' + structure.corporation + '",';
                csv += '"' + structure.fuel_expires + '",' + structure.days_remaining + ',' + hoursLeft + ',';
                csv += structure.blocks_30d + ',' + structure.blocks_60d + ',' + structure.blocks_90d + ',';
                csv += gasRequirement + ',' + limitingFactor + '\n';
            });
        }
        
        // Add summary row
        csv += '\n';
        csv += 'SUMMARY\n';
        csv += 'Total Structures,' + reportData.summary.total_structures + '\n';
        csv += 'Total Systems,' + reportData.summary.total_systems + '\n';
        csv += 'Total 30-Day Blocks,' + reportData.summary.total_blocks_30d + '\n';
        csv += 'Total Volume (m³),' + reportData.summary.total_volume_30d + '\n';
        csv += 'Hauler Trips Needed,' + reportData.summary.total_hauler_trips + '\n';
        
        // Count Metenox and gas
        let metenoxCount = 0;
        let totalGas = 0;
        for (let system in reportData.systems) {
            reportData.systems[system].structures.forEach(function(structure) {
                if (structure.type === 'Metenox Moon Drill') {
                    metenoxCount++;
                    totalGas += 4800 * 30;
                }
            });
        }
        csv += 'Metenox Structures,' + metenoxCount + '\n';
        csv += 'Total Gas Required (30d),' + totalGas + '\n';
        
        // Create download link
        let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        let url = window.URL.createObjectURL(blob);
        let a = document.createElement('a');
        a.href = url;
        a.download = 'fuel-logistics-' + moment().format('YYYY-MM-DD-HHmm') + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    });
});
</script>
@endpush
