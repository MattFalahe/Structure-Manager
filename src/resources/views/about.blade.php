@extends('web::layouts.grids.12')

@section('title', 'About Structure Manager')
@section('page_header', 'About Structure Manager')

@push('head')
<style>
    /* DARK THEME COMPATIBLE - Changed from #fff */
    .about-section {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }
    
    .about-section h4 {
        color: #17a2b8;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    }
    
    .feature-list {
        list-style: none;
        padding: 0;
    }
    
    .feature-list li {
        padding: 0.5rem 0;
        padding-left: 2rem;
        position: relative;
    }
    
    .feature-list li:before {
        content: "\f00c";
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        left: 0;
        color: #51cf66;
    }
    
    .feature-list li.new-feature:after {
        content: "NEW";
        background: #51cf66;
        color: #000;
        font-size: 0.65rem;
        font-weight: bold;
        padding: 0.15rem 0.4rem;
        border-radius: 0.25rem;
        margin-left: 0.5rem;
    }
    
    .badge-custom {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        margin-right: 0.5rem;
    }
    
    /* DARK THEME COMPATIBLE - Changed from #f8f9fa */
    .link-card {
        display: block;
        padding: 1rem;
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        margin-bottom: 0.5rem;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s;
    }
    
    .link-card:hover {
        background: rgba(0, 0, 0, 0.4);
        border-color: rgba(255, 255, 255, 0.2);
        text-decoration: none;
        transform: translateX(5px);
        color: inherit;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    
    /* DARK THEME COMPATIBLE - Changed from #f8f9fa */
    .stat-card {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1rem;
        border-radius: 0.25rem;
        text-align: center;
    }
    
    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        color: #17a2b8;
    }
    
    .stat-label {
        color: #a0a0a0;
        font-size: 0.875rem;
    }
    
    .changelog-section {
        background: rgba(23, 162, 184, 0.1);
        border-left: 4px solid #17a2b8;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .changelog-section h5 {
        color: #17a2b8;
        margin-bottom: 0.5rem;
    }
    
    .changelog-list {
        list-style: none;
        padding-left: 1.5rem;
    }
    
    .changelog-list li {
        position: relative;
        padding: 0.25rem 0;
    }
    
    .changelog-list li:before {
        content: "▸";
        position: absolute;
        left: -1.2rem;
        color: #17a2b8;
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="about-section">
            <h4><i class="fas fa-info-circle"></i> About Structure Manager</h4>
            <p class="lead">
                A comprehensive fuel management plugin for EVE Online corporation structures in SeAT.
            </p>
            <p>
                Structure Manager helps you monitor and track fuel levels across all your corporation's structures, 
                providing real-time alerts, consumption analytics, reserve management, and logistics planning tools 
                to ensure your structures never run out of fuel.
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-rocket"></i> What's New in v1.0.2</h4>
            
            <div class="changelog-section">
                <h5><i class="fas fa-wrench"></i> Critical Bug Fixes</h5>
                <ul class="changelog-list">
                    <li><strong>Fixed Moon Drill fuel consumption</strong> - Now correctly uses 120 blocks/day (5 blocks/hour) on ALL refineries</li>
                    <li><strong>Corrected fuel reduction bonuses</strong> - Properly apply only to Reprocessing and Reaction service modules
                        <ul style="list-style: disc; margin-left: 2rem; margin-top: 0.5rem;">
                            <li>Athanor: 20% reduction (96 blocks/day for reprocessing/reactions)</li>
                            <li>Tatara: 25% reduction (90 blocks/day for reprocessing/reactions)</li>
                            <li>Moon Drill: NO reduction (always 120 blocks/day)</li>
                        </ul>
                    </li>
                </ul>
            </div>
            
            <div class="changelog-section">
                <h5><i class="fas fa-warehouse"></i> New Feature: Fuel Reserves Management</h5>
                <ul class="changelog-list">
                    <li>Track staged fuel blocks in CorpSAG hangars</li>
                    <li>Nested Office container support for reserve detection</li>
                    <li>Refuel event logging when fuel moves from reserves to bay</li>
                    <li>Custom division name display for organized fuel storage</li>
                    <li>Reserve recommendations based on consumption patterns</li>
                    <li>System-grouped reserve overview with volume calculations</li>
                </ul>
            </div>
            
            <div class="changelog-section">
                <h5><i class="fas fa-paint-brush"></i> UI/UX Improvements</h5>
                <ul class="changelog-list">
                    <li>Dark theme optimization with better contrast and readability</li>
                    <li>Enhanced color schemes for improved visibility</li>
                    <li>Better visual hierarchy across all pages</li>
                    <li>Responsive tables for improved mobile experience</li>
                    <li>Consistent styling across Fuel Status, Reserves, and Logistics pages</li>
                </ul>
            </div>
            
            <div class="changelog-section">
                <h5><i class="fas fa-tachometer-alt"></i> Performance Enhancements</h5>
                <ul class="changelog-list">
                    <li>Optimized fuel bay tracking with more efficient database queries</li>
                    <li>Improved consumption predictions using actual service data</li>
                    <li>Better anomaly detection for service activation/deactivation events</li>
                    <li>Enhanced refuel event logging for both bay and reserve movements</li>
                </ul>
            </div>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-star"></i> Key Features</h4>
            <ul class="feature-list">
                <li><strong>Real-time Fuel Bay Monitoring</strong> - Track fuel levels with precise day and hour accuracy</li>
                <li class="new-feature"><strong>Fuel Reserves Management</strong> - Monitor staged fuel in CorpSAG hangars and Office containers</li>
                <li><strong>Critical Alerts</strong> - Get notified about structures running low on fuel</li>
                <li><strong>Consumption Analytics</strong> - Analyze historical fuel usage patterns with hourly tracking</li>
                <li class="new-feature"><strong>Refuel Event Tracking</strong> - Automatic detection when fuel is moved to structures</li>
                <li><strong>Logistics Reports</strong> - Plan fuel hauling with detailed system-by-system breakdowns</li>
                <li><strong>Visual Status Indicators</strong> - Color-coded fuel status (Critical, Warning, Normal, Good)</li>
                <li><strong>Service Tracking</strong> - Monitor online services and their accurate fuel consumption rates</li>
                <li><strong>Multi-Corporation Support</strong> - Filter and view structures by corporation</li>
                <li><strong>Export Capabilities</strong> - Export logistics data to CSV for planning</li>
                <li class="new-feature"><strong>Dual Tracking Method</strong> - Primary fuel bay monitoring with days-remaining fallback</li>
            </ul>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-question-circle"></i> How to Use</h4>
            
            <h5 class="mt-3"><i class="fas fa-gas-pump"></i> Fuel Status</h5>
            <p>
                View all your structures with their current fuel levels. Use filters to see only critical structures 
                or filter by corporation. Click on any structure name to see detailed information, fuel history, 
                and consumption analytics.
            </p>

            <h5 class="mt-3"><i class="fas fa-warehouse"></i> Fuel Reserves <span class="badge badge-success badge-sm">NEW</span></h5>
            <p>
                Monitor staged fuel blocks across all your structures. See reserves organized by system and structure, 
                track which hangar divisions contain fuel, and view recent refuel events. Custom division names make 
                it easy to identify where your fuel is stored.
            </p>

            <h5 class="mt-3"><i class="fas fa-truck"></i> Logistics Report</h5>
            <p>
                Generate comprehensive fuel requirements reports organized by system. See 30, 60, and 90-day fuel 
                needs, calculate hauler trips required, and export data for your logistics team. Perfect for planning 
                large-scale fuel operations.
            </p>

            <h5 class="mt-3"><i class="fas fa-exclamation-triangle"></i> Critical Alerts</h5>
            <p>
                Quick overview of structures requiring immediate attention. Shows structures with less than 14 days 
                of fuel remaining, with urgent indicators for structures below 7 days. Includes fuel requirements 
                to help prioritize refueling operations.
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-clock"></i> Automated Tracking</h4>
            <p>
                Structure Manager automatically tracks fuel consumption every hour and analyzes patterns every 30 minutes. 
                The system monitors both fuel bay levels (what's actively being consumed) and reserves (staged fuel in 
                CorpSAG hangars). Historical data is retained for 6 months for fuel bay tracking and 3 months for 
                reserve movements, providing comprehensive consumption analytics.
            </p>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>Note:</strong> The plugin only reads from SeAT's core 
                database and maintains its own tracking tables. It never modifies SeAT's structure data and works 
                entirely independently from game mechanics.
            </div>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-cogs"></i> Accurate Fuel Calculations</h4>
            <p>
                Structure Manager uses correct EVE Online fuel mechanics:
            </p>
            <ul>
                <li><strong>Upwell structures</strong> consume ZERO fuel themselves</li>
                <li><strong>Only online service modules</strong> consume fuel blocks</li>
                <li><strong>Moon Drills</strong> always use 120 blocks/day (5 blocks/hour) - NO bonuses</li>
                <li><strong>Reprocessing & Reactions</strong> get fuel reduction bonuses:
                    <ul style="list-style: disc; margin-left: 2rem;">
                        <li>Athanor: -20% reduction (96 blocks/day)</li>
                        <li>Tatara: -25% reduction (90 blocks/day)</li>
                    </ul>
                </li>
                <li><strong>Consumption tracking</strong> uses actual fuel bay data when available, with intelligent fallback methods</li>
            </ul>
        </div>
    </div>

    <div class="col-md-4">
        <div class="about-section">
            <h4><i class="fas fa-code-branch"></i> Version</h4>
            <div class="text-center">
                <span class="badge badge-primary badge-custom">v1.0.2</span>
                <span class="badge badge-success badge-custom">Stable</span>
            </div>
            <p class="mt-3 text-center">
                <small>Compatible with SeAT 5.x</small><br>
                <small class="text-muted">Released: October 2025</small>
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-user"></i> Author</h4>
            <p>
                <strong>Matt Falahe</strong><br>
                <a href="mailto:mattfalahe@gmail.com">
                    <i class="fas fa-envelope"></i> mattfalahe@gmail.com
                </a>
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-link"></i> Links</h4>
            
            <a href="https://github.com/MattFalahe/Structure-Manager" target="_blank" class="link-card">
                <i class="fab fa-github"></i> <strong>GitHub Repository</strong><br>
                <small>View source code and documentation</small>
            </a>
            
            <a href="https://github.com/MattFalahe/Structure-Manager/issues" target="_blank" class="link-card">
                <i class="fas fa-bug"></i> <strong>Report Issues</strong><br>
                <small>Found a bug? Let us know!</small>
            </a>
            
            <a href="https://github.com/MattFalahe/Structure-Manager/wiki" target="_blank" class="link-card">
                <i class="fas fa-book"></i> <strong>Documentation</strong><br>
                <small>Read the full documentation</small>
            </a>
            
            <a href="https://github.com/MattFalahe/Structure-Manager/blob/main/README.md" target="_blank" class="link-card">
                <i class="fas fa-file-alt"></i> <strong>README</strong><br>
                <small>Installation and usage guide</small>
            </a>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-heart"></i> Support</h4>
            <p>
                If you find this plugin helpful, consider:
            </p>
            <ul>
                <li>⭐ Starring the GitHub repository</li>
                <li>🐛 Reporting bugs and issues</li>
                <li>💡 Suggesting new features</li>
                <li>🤝 Contributing code improvements</li>
                <li>📢 Sharing with other SeAT users</li>
            </ul>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-balance-scale"></i> License</h4>
            <p>
                This plugin is open source software licensed under the 
                <strong>GNU General Public License v2.0</strong>.
            </p>
            <p>
                <small>
                    Free to use, modify, and distribute. This program comes with ABSOLUTELY NO WARRANTY. 
                    See the LICENSE file in the GitHub repository for full details.
                </small>
            </p>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="about-section">
            <h4><i class="fas fa-chart-line"></i> Quick Stats</h4>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('corporation_structures')->count() }}</div>
                    <div class="stat-label">Total Structures</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('corporation_structures')->whereNotNull('fuel_expires')->count() }}</div>
                    <div class="stat-label">Fueled Structures</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('structure_fuel_history')->count() }}</div>
                    <div class="stat-label">Fuel History Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('structure_fuel_reserves')->count() }}</div>
                    <div class="stat-label">Reserve Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('corporation_structures')->whereNotNull('fuel_expires')->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), fuel_expires) < 168')->count() }}</div>
                    <div class="stat-label">Critical Alerts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">
                        {{ number_format(DB::table('structure_fuel_reserves')
                            ->whereIn('id', function($query) {
                                $query->selectRaw('MAX(id)')
                                    ->from('structure_fuel_reserves')
                                    ->groupBy('structure_id', 'fuel_type_id', 'location_flag');
                            })
                            ->sum('reserve_quantity')) }}
                    </div>
                    <div class="stat-label">Total Reserve Blocks</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
