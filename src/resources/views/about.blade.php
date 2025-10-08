@extends('web::layouts.grids.12')

@section('title', 'About Structure Manager')
@section('page_header', 'About Structure Manager')

@push('head')
<style>
    .about-section {
        background: #fff;
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .about-section h4 {
        color: #17a2b8;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e9ecef;
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
        color: #28a745;
    }
    
    .badge-custom {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        margin-right: 0.5rem;
    }
    
    .link-card {
        display: block;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 0.25rem;
        margin-bottom: 0.5rem;
        text-decoration: none;
        color: #333;
        transition: all 0.2s;
    }
    
    .link-card:hover {
        background: #e9ecef;
        text-decoration: none;
        transform: translateX(5px);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    
    .stat-card {
        background: #f8f9fa;
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
        color: #6c757d;
        font-size: 0.875rem;
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
                providing real-time alerts, consumption analytics, and logistics planning tools to ensure your 
                structures never run out of fuel.
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-star"></i> Key Features</h4>
            <ul class="feature-list">
                <li><strong>Real-time Fuel Monitoring</strong> - Track fuel levels with precise day and hour accuracy</li>
                <li><strong>Critical Alerts</strong> - Get notified about structures running low on fuel</li>
                <li><strong>Consumption Analytics</strong> - Analyze historical fuel usage patterns</li>
                <li><strong>Logistics Reports</strong> - Plan fuel hauling with detailed system-by-system breakdowns</li>
                <li><strong>Visual Status Indicators</strong> - Color-coded fuel status (Critical, Warning, Normal, Good)</li>
                <li><strong>Service Tracking</strong> - Monitor online services and their fuel impact</li>
                <li><strong>Multi-Corporation Support</strong> - Filter and view structures by corporation</li>
                <li><strong>Export Capabilities</strong> - Export logistics data to CSV for planning</li>
            </ul>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-question-circle"></i> How to Use</h4>
            
            <h5 class="mt-3">Fuel Status</h5>
            <p>
                View all your structures with their current fuel levels. Use filters to see only critical structures 
                or filter by corporation. Click on any structure name to see detailed information and fuel history.
            </p>

            <h5 class="mt-3">Logistics Report</h5>
            <p>
                Generate comprehensive fuel requirements reports organized by system. See 30, 60, and 90-day fuel 
                needs, calculate hauler trips required, and export data for your logistics team.
            </p>

            <h5 class="mt-3">Critical Alerts</h5>
            <p>
                Quick overview of structures requiring immediate attention. Shows structures with less than 14 days 
                of fuel remaining, with urgent indicators for structures below 7 days.
            </p>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-clock"></i> Automated Tracking</h4>
            <p>
                Structure Manager automatically tracks fuel consumption every hour and analyzes patterns every 30 minutes. 
                Historical data is retained for 180 days by default, providing comprehensive consumption analytics.
            </p>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>Note:</strong> The plugin only reads from SeAT's core 
                database and maintains its own tracking tables. It never modifies SeAT's structure data.
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="about-section">
            <h4><i class="fas fa-code-branch"></i> Version</h4>
            <div class="text-center">
                <span class="badge badge-primary badge-custom">v1.0.0</span>
                <span class="badge badge-success badge-custom">Stable</span>
            </div>
            <p class="mt-3 text-center text-muted">
                <small>Compatible with SeAT 5.x</small>
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
                <small class="text-muted">View source code and documentation</small>
            </a>
            
            <a href="https://github.com/MattFalahe/Structure-Manager/issues" target="_blank" class="link-card">
                <i class="fas fa-bug"></i> <strong>Report Issues</strong><br>
                <small class="text-muted">Found a bug? Let us know!</small>
            </a>
            
            <a href="https://github.com/MattFalahe/Structure-Manager/wiki" target="_blank" class="link-card">
                <i class="fas fa-book"></i> <strong>Documentation</strong><br>
                <small class="text-muted">Read the full documentation</small>
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
            </ul>
        </div>

        <div class="about-section">
            <h4><i class="fas fa-balance-scale"></i> License</h4>
            <p>
                This plugin is open source software licensed under the 
                <strong>GPL-2.0-or-later</strong> license.
            </p>
            <p class="text-muted">
                <small>
                    Free to use, modify, and distribute. See the LICENSE file in the 
                    GitHub repository for full details.
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
                    <div class="stat-label">History Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ DB::table('corporation_structures')->whereNotNull('fuel_expires')->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), fuel_expires) < 168')->count() }}</div>
                    <div class="stat-label">Critical Alerts</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
