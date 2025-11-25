@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::help.help_documentation'))
@section('page_header', trans('structure-manager::help.help_documentation'))

@push('head')
<style>
    .help-wrapper {
        display: flex;
        gap: 20px;
    }
    
    .help-sidebar {
        flex: 0 0 280px;
        position: sticky;
        top: 20px;
        max-height: calc(100vh - 120px);
        overflow-y: auto;
    }
    
    .help-content {
        flex: 1;
        min-width: 0;
    }
    
    .help-nav .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 10px 15px;
        transition: all 0.3s;
        font-size: 0.95rem;
    }
    
    .help-nav .nav-link:hover {
        background: rgba(23, 162, 184, 0.2);
    }
    
    .help-nav .nav-link.active {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    }
    
    .help-nav .nav-link i {
        width: 24px;
        text-align: center;
        margin-right: 10px;
    }
    
    .help-section {
        display: none;
        animation: fadeIn 0.3s;
    }
    
    .help-section.active {
        display: block;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .help-card {
        background: #2d3748;
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 20px;
        border: 1px solid rgba(23, 162, 184, 0.2);
    }
    
    .help-card h3 {
        color: #17a2b8;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .help-card h4 {
        color: #9ca3af;
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 1.1rem;
    }

    .help-card h5 {
        color: #9ca3af;
        margin-top: 15px;
        margin-bottom: 8px;
        font-size: 1rem;
    }
    
    .help-card p {
        color: #d1d5db;
        line-height: 1.6;
        margin-bottom: 1rem;
    }
    
    .help-card ul, .help-card ol {
        color: #d1d5db;
        line-height: 1.8;
        margin-left: 20px;
        margin-bottom: 1rem;
    }
    
    .help-card ul li, .help-card ol li {
        margin-bottom: 0.5rem;
    }
    
    .help-card code {
        background: rgba(0, 0, 0, 0.3);
        padding: 2px 6px;
        border-radius: 3px;
        color: #fbbf24;
        font-size: 0.9em;
    }
    
    .help-card pre {
        background: rgba(0, 0, 0, 0.3);
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        color: #d1d5db;
    }
    
    .step-by-step {
        counter-reset: step-counter;
        list-style: none;
        padding-left: 0;
    }
    
    .step-by-step li {
        counter-increment: step-counter;
        margin-bottom: 20px;
        padding-left: 50px;
        position: relative;
    }
    
    .step-by-step li::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
        width: 35px;
        height: 35px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.1rem;
    }
    
    .info-box {
        background: rgba(23, 162, 184, 0.15);
        border-left: 4px solid #17a2b8;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
        color: #d1d5db;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    .info-box i {
        margin-right: 8px;
        vertical-align: middle;
    }
    
    .info-box strong {
        display: inline-block;
        margin-right: 4px;
    }
    
    .info-box br {
        display: block;
        margin-top: 8px;
    }
    
    .warning-box {
        background: rgba(255, 193, 7, 0.15);
        border-left: 4px solid #ffc107;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
        color: #d1d5db;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    .warning-box i {
        margin-right: 8px;
        vertical-align: middle;
    }
    
    .warning-box strong {
        display: inline-block;
        margin-right: 4px;
    }
    
    .warning-box br {
        display: block;
        margin-top: 8px;
    }
    
    .success-box {
        background: rgba(28, 200, 138, 0.15);
        border-left: 4px solid #1cc88a;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
        color: #d1d5db;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    .success-box i {
        margin-right: 8px;
        vertical-align: middle;
    }
    
    .success-box strong {
        display: inline-block;
        margin-right: 4px;
    }
    
    .success-box br {
        display: block;
        margin-top: 8px;
    }

    .purple-box {
        background: rgba(156, 39, 176, 0.15);
        border-left: 4px solid #9c27b0;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
        color: #d1d5db;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    
    .purple-box i {
        margin-right: 8px;
        vertical-align: middle;
    }
    
    .purple-box strong {
        display: inline-block;
        margin-right: 4px;
    }
    
    .purple-box br {
        display: block;
        margin-top: 8px;
    }
    
    .feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }
    
    .feature-item {
        background: rgba(23, 162, 184, 0.1);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid rgba(23, 162, 184, 0.3);
    }
    
    .feature-item i {
        font-size: 2rem;
        color: #17a2b8;
        margin-bottom: 10px;
    }
    
    .feature-item h5 {
        color: #e2e8f0;
        margin-bottom: 8px;
    }
    
    .feature-item p {
        color: #9ca3af;
        font-size: 0.9rem;
        margin: 0;
    }
    
    .search-box {
        position: relative;
        margin-bottom: 20px;
    }
    
    .search-box input {
        width: 100%;
        padding: 12px 45px 12px 15px;
        background: #2d3748;
        border: 1px solid rgba(23, 162, 184, 0.3);
        border-radius: 8px;
        color: #e2e8f0;
    }
    
    .search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
    }
    
    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin: 20px 0;
    }
    
    .quick-link {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        padding: 15px;
        border-radius: 8px;
        text-align: center;
        color: white;
        text-decoration: none;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .quick-link:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .quick-link i {
        font-size: 2rem;
        margin-bottom: 8px;
        display: block;
    }
    
    .faq-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        margin-bottom: 15px;
        overflow: hidden;
        transition: all 0.3s;
    }
    
    .faq-item:hover {
        border-color: rgba(23, 162, 184, 0.3);
    }
    
    .faq-question {
        padding: 15px 20px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        user-select: none;
    }
    
    .faq-question:hover {
        background: rgba(23, 162, 184, 0.1);
    }
    
    .faq-question i {
        transition: transform 0.3s;
    }
    
    .faq-item.open .faq-question i {
        transform: rotate(180deg);
    }
    
    .faq-answer {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
        padding: 0 20px;
    }
    
    .faq-item.open .faq-answer {
        max-height: 500px;
        padding: 0 20px 20px;
    }
    
    .version-badge {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: bold;
        display: inline-block;
        margin-left: 10px;
    }

    .fuel-type-indicator {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: bold;
        margin-left: 8px;
    }

    .fuel-blocks {
        background: rgba(23, 162, 184, 0.2);
        color: #17a2b8;
        border: 1px solid rgba(23, 162, 184, 0.4);
    }

    .magmatic-gas {
        background: rgba(156, 39, 176, 0.2);
        color: #9c27b0;
        border: 1px solid rgba(156, 39, 176, 0.4);
    }

    .plugin-info {
        background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(23, 162, 184, 0.3);
    }

    .plugin-info .info-row {
        color: #9ca3af;
        margin: 5px 0;
    }

    .plugin-info .author {
        color: #17a2b8;
        margin: 10px 0;
    }

    .plugin-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        margin-top: 15px;
    }

    .plugin-link {
        background: rgba(23, 162, 184, 0.1);
        padding: 10px;
        border-radius: 5px;
        border: 1px solid rgba(23, 162, 184, 0.3);
        color: #17a2b8;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s;
    }

    .plugin-link:hover {
        background: rgba(23, 162, 184, 0.2);
        color: #40d3ff;
        text-decoration: none;
        transform: translateX(5px);
    }
</style>
@endpush

@section('content')
<div class="help-wrapper">
    {{-- Sidebar Navigation --}}
    <div class="help-sidebar">
        <div class="search-box">
            <input type="text" id="helpSearch" placeholder="{{ trans('structure-manager::help.search_placeholder') }}">
            <i class="fas fa-search"></i>
        </div>
        
        <nav class="help-nav">
            <a href="#" class="nav-link active" data-section="overview">
                <i class="fas fa-home"></i>
                {{ trans('structure-manager::help.overview') }}
            </a>
            <a href="#" class="nav-link" data-section="getting-started">
                <i class="fas fa-rocket"></i>
                {{ trans('structure-manager::help.getting_started') }}
            </a>
            <a href="#" class="nav-link" data-section="features">
                <i class="fas fa-star"></i>
                {{ trans('structure-manager::help.features') }}
            </a>
            <a href="#" class="nav-link" data-section="fuel-mechanics">
                <i class="fas fa-gas-pump"></i>
                {{ trans('structure-manager::help.fuel_mechanics') }}
            </a>
            <a href="#" class="nav-link" data-section="metenox">
                <i class="fas fa-moon"></i>
                {{ trans('structure-manager::help.metenox_drills') }}
            </a>
            <a href="#" class="nav-link" data-section="pos">
                <i class="fas fa-satellite-dish"></i>
                {{ trans('structure-manager::help.pos_management') }}
            </a>
            <a href="#" class="nav-link" data-section="notifications">
                <i class="fas fa-bell"></i>
                {{ trans('structure-manager::help.notifications') }}
            </a>
            <a href="#" class="nav-link" data-section="settings">
                <i class="fas fa-cog"></i>
                {{ trans('structure-manager::help.settings') }}
            </a>
            <a href="#" class="nav-link" data-section="pages">
                <i class="fas fa-th-large"></i>
                {{ trans('structure-manager::help.pages_guide') }}
            </a>
            <a href="#" class="nav-link" data-section="commands">
                <i class="fas fa-terminal"></i>
                {{ trans('structure-manager::help.commands') }}
            </a>
            <a href="#" class="nav-link" data-section="faq">
                <i class="fas fa-question-circle"></i>
                {{ trans('structure-manager::help.faq') }}
            </a>
            <a href="#" class="nav-link" data-section="troubleshooting">
                <i class="fas fa-wrench"></i>
                {{ trans('structure-manager::help.troubleshooting') }}
            </a>
        </nav>
    </div>
    
    {{-- Main Content --}}
    <div class="help-content">
        {{-- Overview Section --}}
        <div id="overview" class="help-section active">
            {{-- Plugin Information --}}
            <div class="plugin-info">
                <h3 style="color: #17a2b8; margin-bottom: 15px;">
                    <i class="fas fa-info-circle"></i> {{ trans('structure-manager::help.plugin_info_title') }}
                </h3>
                <div class="info-row">
                    <strong>{{ trans('structure-manager::help.version') }}:</strong> 
                    <img src="https://img.shields.io/github/v/release/MattFalahe/Structure-Manager" alt="Version" style="vertical-align: middle;">
                    <img src="https://img.shields.io/badge/SeAT-5.0-green" alt="SeAT" style="vertical-align: middle;">
                </div>
                <div class="info-row">
                    <strong>{{ trans('structure-manager::help.license') }}:</strong> GPL-2.0
                </div>
                
                <div class="author">
                    <i class="fas fa-user"></i> <strong>{{ trans('structure-manager::help.author') }}:</strong> Matt Falahe
                    <br>
                    <i class="fas fa-envelope"></i> <a href="mailto:mattfalahe@gmail.com" style="color: #17a2b8;">mattfalahe@gmail.com</a>
                </div>

                <div class="plugin-links">
                    <a href="https://github.com/MattFalahe/Structure-Manager" target="_blank" class="plugin-link">
                        <i class="fab fa-github"></i> {{ trans('structure-manager::help.github_repo') }}
                    </a>
                    <a href="https://github.com/MattFalahe/Structure-Manager/blob/main/CHANGELOG.MD" target="_blank" class="plugin-link">
                        <i class="fas fa-list"></i> {{ trans('structure-manager::help.changelog') }}
                    </a>
                    <a href="https://github.com/MattFalahe/Structure-Manager/issues" target="_blank" class="plugin-link">
                        <i class="fas fa-bug"></i> {{ trans('structure-manager::help.report_issues') }}
                    </a>
                    <a href="https://github.com/MattFalahe/Structure-Manager/blob/main/README.md" target="_blank" class="plugin-link">
                        <i class="fas fa-book"></i> {{ trans('structure-manager::help.readme') }}
                    </a>
                </div>

                <div class="success-box" style="margin-top: 15px;">
                    <i class="fas fa-heart"></i>
                    <strong>{{ trans('structure-manager::help.support_project') }}:</strong>
                    {!! trans('structure-manager::help.support_list') !!}
                </div>
            </div>

            <div class="help-card">
                <h3>
                    <i class="fas fa-building"></i>
                    {{ trans('structure-manager::help.welcome_title') }}
                </h3>
                <p class="lead">{{ trans('structure-manager::help.welcome_desc') }}</p>
            </div>

            <div class="help-card">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    {{ trans('structure-manager::help.what_is_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.what_is_desc') }}</p>
                
                <div class="info-box">
                    <i class="fas fa-lightbulb"></i>
                    <strong>{{ trans('structure-manager::help.key_benefit') }}:</strong>
                    {{ trans('structure-manager::help.key_benefit_desc') }}
                </div>
            </div>

            <div class="help-card">
                <h3>
                    <i class="fas fa-star"></i>
                    {{ trans('structure-manager::help.key_features') }}
                </h3>
                
                <div class="feature-grid">
                    <div class="feature-item">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h5>{{ trans('structure-manager::help.feature_alerts_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_alerts_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-chart-line"></i>
                        <h5>{{ trans('structure-manager::help.feature_analytics_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_analytics_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-warehouse"></i>
                        <h5>{{ trans('structure-manager::help.feature_reserves_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_reserves_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-truck"></i>
                        <h5>{{ trans('structure-manager::help.feature_logistics_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_logistics_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-moon"></i>
                        <h5>{{ trans('structure-manager::help.feature_metenox_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_metenox_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-satellite-dish"></i>
                        <h5>{{ trans('structure-manager::help.feature_pos_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_pos_desc') }}</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-clock"></i>
                        <h5>{{ trans('structure-manager::help.feature_automated_title') }}</h5>
                        <p>{{ trans('structure-manager::help.feature_automated_desc') }}</p>
                    </div>
                </div>
            </div>

            <div class="help-card">
                <h3>
                    <i class="fas fa-rocket"></i>
                    {{ trans('structure-manager::help.quick_links_title') }}
                </h3>
                
                <div class="quick-links">
                    <a href="{{ route('structure-manager.index') }}" class="quick-link">
                        <i class="fas fa-th-large"></i>
                        {{ trans('structure-manager::help.view_dashboard') }}
                    </a>
                    <a href="{{ route('structure-manager.critical-alerts') }}" class="quick-link">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('structure-manager::help.view_alerts') }}
                    </a>
                    <a href="{{ route('structure-manager.logistics-report') }}" class="quick-link">
                        <i class="fas fa-truck"></i>
                        {{ trans('structure-manager::help.view_logistics') }}
                    </a>
                </div>
            </div>
        </div>

        {{-- Getting Started Section --}}
        <div id="getting-started" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-rocket"></i>
                    {{ trans('structure-manager::help.getting_started_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.getting_started_desc') }}</p>

                <h4>{{ trans('structure-manager::help.installation_title') }}</h4>
                <ol class="step-by-step">
                    <li>
                        <strong>{{ trans('structure-manager::help.install_step1_title') }}</strong><br>
                        {{ trans('structure-manager::help.install_step1_desc') }}
                        <pre><code>composer require mattfalahe/seat-structure-manager</code></pre>
                    </li>
                    <li>
                        <strong>{{ trans('structure-manager::help.install_step2_title') }}</strong><br>
                        {{ trans('structure-manager::help.install_step2_desc') }}
                        <pre><code>php artisan migrate</code></pre>
                    </li>
                    <li>
                        <strong>{{ trans('structure-manager::help.install_step3_title') }}</strong><br>
                        {{ trans('structure-manager::help.install_step3_desc') }}
                    </li>
                </ol>

                <h4>{{ trans('structure-manager::help.first_time_setup_title') }}</h4>
                <ol class="step-by-step">
                    <li>
                        <strong>{{ trans('structure-manager::help.setup_step1_title') }}</strong><br>
                        {{ trans('structure-manager::help.setup_step1_desc') }}
                    </li>
                    <li>
                        <strong>{{ trans('structure-manager::help.setup_step2_title') }}</strong><br>
                        {{ trans('structure-manager::help.setup_step2_desc') }}
                    </li>
                    <li>
                        <strong>{{ trans('structure-manager::help.setup_step3_title') }}</strong><br>
                        {{ trans('structure-manager::help.setup_step3_desc') }}
                    </li>
                </ol>

                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>{{ trans('structure-manager::help.success_tip') }}:</strong>
                    {{ trans('structure-manager::help.success_desc') }}
                </div>
            </div>
        </div>

        {{-- Features Section --}}
        <div id="features" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-star"></i>
                    {{ trans('structure-manager::help.features_overview') }}
                </h3>
                <p>{{ trans('structure-manager::help.features_intro') }}</p>

                <h4><i class="fas fa-exclamation-triangle text-warning"></i> {{ trans('structure-manager::help.real_time_alerts') }}</h4>
                {!! trans('structure-manager::help.real_time_alerts_desc') !!}

                <h4><i class="fas fa-chart-line text-info"></i> {{ trans('structure-manager::help.consumption_analytics') }}</h4>
                {!! trans('structure-manager::help.consumption_analytics_desc') !!}

                <h4><i class="fas fa-warehouse text-success"></i> {{ trans('structure-manager::help.reserve_management') }}</h4>
                {!! trans('structure-manager::help.reserve_management_desc') !!}

                <h4><i class="fas fa-truck text-primary"></i> {{ trans('structure-manager::help.logistics_planning') }}</h4>
                {!! trans('structure-manager::help.logistics_planning_desc') !!}

                <h4><i class="fas fa-history"></i> {{ trans('structure-manager::help.historical_tracking') }}</h4>
                {!! trans('structure-manager::help.historical_tracking_desc') !!}

                <h4><i class="fas fa-calculator"></i> {{ trans('structure-manager::help.accurate_calculations') }}</h4>
                {!! trans('structure-manager::help.accurate_calculations_desc') !!}
            </div>
        </div>

        {{-- Fuel Mechanics Section --}}
        <div id="fuel-mechanics" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-gas-pump"></i>
                    {{ trans('structure-manager::help.fuel_mechanics_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.fuel_mechanics_intro') }}</p>

                <h4>{{ trans('structure-manager::help.base_rules_title') }}</h4>
                {!! trans('structure-manager::help.base_rules_list') !!}

                <h4>{{ trans('structure-manager::help.service_modules_title') }}</h4>
                <p>{{ trans('structure-manager::help.service_modules_desc') }}</p>
                {!! trans('structure-manager::help.service_modules_list') !!}

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <strong>{{ trans('structure-manager::help.important_note') }}:</strong>
                    {{ trans('structure-manager::help.multi_service_note') }}
                </div>

                <h4>{{ trans('structure-manager::help.moon_drills_title') }}</h4>
                {!! trans('structure-manager::help.moon_drills_desc') !!}

                <h4>{{ trans('structure-manager::help.fuel_bonuses_title') }}</h4>
                <p>{{ trans('structure-manager::help.fuel_bonuses_intro') }}</p>
                {!! trans('structure-manager::help.fuel_bonuses_list') !!}

                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{ trans('structure-manager::help.common_mistake') }}:</strong>
                    {{ trans('structure-manager::help.common_mistake_desc') }}
                </div>

                <h4>{{ trans('structure-manager::help.calculation_examples_title') }}</h4>
                {!! trans('structure-manager::help.calculation_examples') !!}
            </div>
        </div>

        {{-- Metenox Section --}}
        <div id="metenox" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-moon"></i>
                    {{ trans('structure-manager::help.metenox_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.metenox_intro') }}</p>

                <div class="purple-box">
                    <i class="fas fa-info-circle"></i>
                    <strong>{{ trans('structure-manager::help.whats_different') }}:</strong>
                    {{ trans('structure-manager::help.metenox_difference') }}
                </div>

                <h4>{{ trans('structure-manager::help.dual_fuel_system_title') }}</h4>
                {!! trans('structure-manager::help.dual_fuel_system_desc') !!}

                <h4>{{ trans('structure-manager::help.limiting_factor_title') }}</h4>
                <p>{{ trans('structure-manager::help.limiting_factor_intro') }}</p>
                {!! trans('structure-manager::help.limiting_factor_desc') !!}

                <h4>{{ trans('structure-manager::help.gas_tracking_title') }}</h4>
                <p>{{ trans('structure-manager::help.gas_tracking_intro') }}</p>
                {!! trans('structure-manager::help.gas_tracking_features') !!}

                <h4>{{ trans('structure-manager::help.visual_indicators_title') }}</h4>
                {!! trans('structure-manager::help.visual_indicators_desc') !!}

                <h4>{{ trans('structure-manager::help.logistics_metenox_title') }}</h4>
                <p>{{ trans('structure-manager::help.logistics_metenox_desc') }}</p>

                <div class="info-box">
                    <i class="fas fa-calculator"></i>
                    <strong>{{ trans('structure-manager::help.example_calculation') }}:</strong>
                    {{ trans('structure-manager::help.metenox_example') }}
                </div>
            </div>
        </div>

        {{-- POS Management Section --}}
        {{-- POS Management Section --}}
        <div id="pos" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-satellite-dish"></i>
                    {{ trans('structure-manager::help.pos_management_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.pos_management_intro') }}</p>

                <h4>{{ trans('structure-manager::help.pos_features') }}</h4>
                {!! trans('structure-manager::help.pos_features_desc') !!}

                <h4>{{ trans('structure-manager::help.pos_resources') }}</h4>
                
                <h5><i class="fas fa-gas-pump"></i> {{ trans('structure-manager::help.pos_fuel_blocks_title') }}</h5>
                <p>{{ trans('structure-manager::help.pos_fuel_blocks_desc') }}</p>
                
                <h5><i class="fas fa-shield-alt"></i> {{ trans('structure-manager::help.pos_strontium_title') }}</h5>
                <p>{{ trans('structure-manager::help.pos_strontium_desc') }}</p>
                
                <h5><i class="fas fa-file-invoice"></i> {{ trans('structure-manager::help.pos_charters_title') }}</h5>
                <p>{{ trans('structure-manager::help.pos_charters_desc') }}</p>

                <h4>{{ trans('structure-manager::help.pos_consumption_rates') }}</h4>
                {!! trans('structure-manager::help.pos_consumption_table') !!}

                <h4>{{ trans('structure-manager::help.pos_limiting_factor') }}</h4>
                <p>{!! trans('structure-manager::help.pos_limiting_desc') !!}</p>

                <h4>{{ trans('structure-manager::help.pos_security_space') }}</h4>
                <p>{{ trans('structure-manager::help.pos_security_desc') }}</p>
                {!! trans('structure-manager::help.pos_security_list') !!}

                <h4>{{ trans('structure-manager::help.pos_dashboard_features') }}</h4>
                {!! trans('structure-manager::help.pos_dashboard_desc') !!}

                <h4>{{ trans('structure-manager::help.pos_detail_page') }}</h4>
                {!! trans('structure-manager::help.pos_detail_features') !!}

                <div class="info-box">
                    <i class="fas fa-clock"></i>
                    <strong>Automation:</strong>
                    POS fuel tracking runs hourly at :20, consumption analysis runs daily at 01:00, and notifications check every 10 minutes with smart cooldown awareness.
                </div>

                <div class="success-box">
                    <i class="fas fa-bell"></i>
                    <strong>Smart Notifications:</strong>
                    Separate cooldowns for fuel (6 hours) and strontium (2 hours) prevent alert fatigue while ensuring critical issues aren't missed.
                </div>
            </div>
        </div>

        {{-- Notifications Section --}}
        <div id="notifications" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-bell"></i>
                    {{ trans('structure-manager::help.notifications_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.notifications_intro') }}</p>

                <h4>{{ trans('structure-manager::help.webhook_features') }}</h4>
                {!! trans('structure-manager::help.webhook_features_desc') !!}

                <h4>{{ trans('structure-manager::help.notification_types') }}</h4>
                
                <h5><i class="fas fa-gas-pump"></i> {{ trans('structure-manager::help.fuel_charter_notifications') }}</h5>
                <p>{{ trans('structure-manager::help.fuel_charter_desc') }}</p>
                
                <h5><i class="fas fa-shield-alt"></i> {{ trans('structure-manager::help.strontium_notifications') }}</h5>
                <p>{{ trans('structure-manager::help.strontium_desc') }}</p>

                <h4>{{ trans('structure-manager::help.notification_thresholds') }}</h4>
                
                <h5>{{ trans('structure-manager::help.critical_thresholds') }}</h5>
                {!! trans('structure-manager::help.critical_thresholds_list') !!}
                
                <h5>{{ trans('structure-manager::help.warning_thresholds') }}</h5>
                {!! trans('structure-manager::help.warning_thresholds_list') !!}

                <h4>{{ trans('structure-manager::help.notification_cooldowns') }}</h4>
                <p>{{ trans('structure-manager::help.cooldown_explanation') }}</p>
                {!! trans('structure-manager::help.cooldown_list') !!}

                <h4>{{ trans('structure-manager::help.discord_role_mentions') }}</h4>
                <p>{{ trans('structure-manager::help.role_mention_desc') }}</p>
                {!! trans('structure-manager::help.role_mention_steps') !!}

                <h4>{{ trans('structure-manager::help.notification_examples') }}</h4>
                <div class="warning-box">
                    {!! trans('structure-manager::help.critical_example') !!}
                </div>
                <div class="info-box">
                    {!! trans('structure-manager::help.warning_example') !!}
                </div>

                <h4><i class="fas fa-radiation"></i> {{ trans('structure-manager::help.zero_strontium_title') }}</h4>
                <p>{{ trans('structure-manager::help.zero_strontium_intro') }}</p>
                {!! trans('structure-manager::help.zero_strontium_scenarios') !!}
                {!! trans('structure-manager::help.zero_strontium_notification_behavior') !!}
                {!! trans('structure-manager::help.zero_strontium_use_cases') !!}

                <h4><i class="fas fa-project-diagram"></i> {{ trans('structure-manager::help.multiple_webhooks_title') }}</h4>
                <p>{{ trans('structure-manager::help.multiple_webhooks_intro') }}</p>
                {!! trans('structure-manager::help.multiple_webhooks_features') !!}
                {!! trans('structure-manager::help.multiple_webhooks_use_cases') !!}
                <div class="purple-box">
                    {!! trans('structure-manager::help.multiple_webhooks_configuration') !!}
                </div>
                <div class="info-box">
                    {!! trans('structure-manager::help.multiple_webhooks_example') !!}
                </div>

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <strong>{{ trans('structure-manager::help.upwell_notifications_note') }}:</strong>
                    {{ trans('structure-manager::help.upwell_notifications_desc') }}
                </div>
            </div>
        </div>

        {{-- Settings Section --}}
        <div id="settings" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-cog"></i>
                    {{ trans('structure-manager::help.settings_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.settings_intro') }}</p>

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    {!! trans('structure-manager::help.settings_notification_note') !!}
                </div>

                <h4>{{ trans('structure-manager::help.webhook_settings') }}</h4>
                <p>{{ trans('structure-manager::help.webhook_settings_desc') }}</p>

                <h5>{{ trans('structure-manager::help.webhook_url_setting') }}</h5>
                <p>{{ trans('structure-manager::help.webhook_url_desc') }}</p>
                {!! trans('structure-manager::help.webhook_url_steps') !!}

                <h5>{{ trans('structure-manager::help.enable_notifications') }}</h5>
                <p>{{ trans('structure-manager::help.enable_desc') }}</p>

                <h4>{{ trans('structure-manager::help.notification_intervals') }}</h4>
                <p>{!! trans('structure-manager::help.fuel_interval_desc') !!}</p>
                <p>{!! trans('structure-manager::help.strontium_interval_desc') !!}</p>

                <h4>{{ trans('structure-manager::help.threshold_settings') }}</h4>
                <p>{{ trans('structure-manager::help.threshold_desc') }}</p>

                <h5>{{ trans('structure-manager::help.fuel_thresholds') }}</h5>
                <p>{!! trans('structure-manager::help.fuel_critical_setting') !!}</p>
                <p>{!! trans('structure-manager::help.fuel_warning_setting') !!}</p>

                <h5>{{ trans('structure-manager::help.strontium_thresholds') }}</h5>
                <p>{!! trans('structure-manager::help.strontium_critical_setting') !!}</p>
                <p>{!! trans('structure-manager::help.strontium_warning_setting') !!}</p>

                <h4>{{ trans('structure-manager::help.test_webhook_button') }}</h4>
                <p>{{ trans('structure-manager::help.test_webhook_desc') }}</p>

                <h4>{{ trans('structure-manager::help.reserves_tracking_settings') }}</h4>
                <p>{{ trans('structure-manager::help.reserves_tracking_desc') }}</p>

                <h5>{{ trans('structure-manager::help.hangar_exclusion_title') }}</h5>
                <p>{{ trans('structure-manager::help.hangar_exclusion_desc') }}</p>
                {!! trans('structure-manager::help.hangar_exclusion_uses') !!}
                <p>{!! trans('structure-manager::help.hangar_exclusion_note') !!}</p>

                <h4>{{ trans('structure-manager::help.settings_tips') }}</h4>
                {!! trans('structure-manager::help.settings_tips_list') !!}

                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>Quick Access:</strong>
                    Access settings from the Structure Manager menu in the sidebar → Settings
                </div>
            </div>
        </div>

        {{-- Pages Guide Section --}}
        <div id="pages" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-th-large"></i>
                    {{ trans('structure-manager::help.pages_guide') }}
                </h3>
                <p>{{ trans('structure-manager::help.pages_intro') }}</p>

                <h4><i class="fas fa-home"></i> {{ trans('structure-manager::help.dashboard_page_title') }}</h4>
                {!! trans('structure-manager::help.dashboard_page_desc') !!}

                <h4><i class="fas fa-exclamation-triangle"></i> {{ trans('structure-manager::help.critical_alerts_page_title') }}</h4>
                {!! trans('structure-manager::help.critical_alerts_page_desc') !!}

                <h4><i class="fas fa-warehouse"></i> {{ trans('structure-manager::help.reserves_page_title') }}</h4>
                {!! trans('structure-manager::help.reserves_page_desc') !!}

                <h4><i class="fas fa-truck"></i> {{ trans('structure-manager::help.logistics_page_title') }}</h4>
                {!! trans('structure-manager::help.logistics_page_desc') !!}

                <h4><i class="fas fa-chart-line"></i> {{ trans('structure-manager::help.detail_page_title') }}</h4>
                {!! trans('structure-manager::help.detail_page_desc') !!}

                <h4><i class="fas fa-satellite-dish"></i> {{ trans('structure-manager::help.pos_page_title') }}</h4>
                {!! trans('structure-manager::help.pos_page_desc') !!}

                <div class="info-box">
                    <i class="fas fa-lightbulb"></i>
                    <strong>{{ trans('structure-manager::help.pro_tip') }}:</strong>
                    {{ trans('structure-manager::help.pages_pro_tip') }}
                </div>
            </div>
        </div>

        {{-- Commands Section --}}
        <div id="commands" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-terminal"></i>
                    {{ trans('structure-manager::help.commands_title') }}
                </h3>
                <p>{{ trans('structure-manager::help.commands_intro') }}</p>

                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    {!! trans('structure-manager::help.commands_notification_note') !!}
                </div>

                <h4>{{ trans('structure-manager::help.track_fuel_cmd_title') }}</h4>
                <p>{{ trans('structure-manager::help.track_fuel_cmd_desc') }}</p>
                <pre><code>php artisan structure-manager:track-fuel</code></pre>
                <p>{{ trans('structure-manager::help.track_fuel_cmd_note') }}</p>

                <h4>{{ trans('structure-manager::help.analyze_fuel_cmd_title') }}</h4>
                <p>{{ trans('structure-manager::help.analyze_fuel_cmd_desc') }}</p>
                <pre><code>php artisan structure-manager:analyze-consumption</code></pre>
                <p>{{ trans('structure-manager::help.analyze_fuel_cmd_note') }}</p>
                {!! trans('structure-manager::help.analyze_fuel_cmd_options') !!}

                <h4>{{ trans('structure-manager::help.cleanup_history_cmd_title') }}</h4>
                <p>{{ trans('structure-manager::help.cleanup_history_cmd_desc') }}</p>
                <pre><code>php artisan structure-manager:cleanup-history</code></pre>
                <p>{{ trans('structure-manager::help.cleanup_history_cmd_note') }}</p>
                {!! trans('structure-manager::help.cleanup_history_cmd_options') !!}

                <h4>{{ trans('structure-manager::help.pos_track_fuel_title') }}</h4>
                <p>{{ trans('structure-manager::help.pos_track_fuel_desc') }}</p>
                <pre><code>php artisan structure-manager:track-poses-fuel</code></pre>

                <h4>{{ trans('structure-manager::help.pos_analyze_title') }}</h4>
                <p>{{ trans('structure-manager::help.pos_analyze_desc') }}</p>
                <pre><code>php artisan structure-manager:analyze-pos-consumption</code></pre>

                <h4>{{ trans('structure-manager::help.pos_notify_title') }}</h4>
                <p>{{ trans('structure-manager::help.pos_notify_desc') }}</p>
                <pre><code>{{ trans('structure-manager::help.pos_notify_cmd') }}</code></pre>

                <h4><i class="fas fa-flask"></i> {{ trans('structure-manager::help.simulate_consumption_title') }}</h4>
                <p>{{ trans('structure-manager::help.simulate_consumption_desc') }}</p>
                <pre><code>{{ trans('structure-manager::help.simulate_consumption_cmd') }}</code></pre>
                {!! trans('structure-manager::help.simulate_consumption_options') !!}

                <h4><i class="fas fa-flask"></i> {{ trans('structure-manager::help.create_test_poses_title') }}</h4>
                <p>{{ trans('structure-manager::help.create_test_poses_desc') }}</p>
                <pre><code>{{ trans('structure-manager::help.create_test_poses_cmd') }}</code></pre>
                {!! trans('structure-manager::help.create_test_poses_features') !!}
                
                <h5>{{ trans('structure-manager::help.create_test_poses_usage_title') }}</h5>
                {!! trans('structure-manager::help.create_test_poses_usage') !!}
                
                <div class="purple-box">
                    <i class="fas fa-vial"></i>
                    {!! trans('structure-manager::help.create_test_poses_use_cases') !!}
                </div>

                <h4><i class="fas fa-flask"></i> {{ trans('structure-manager::help.create_test_metenox_cmd_title') }}</h4>
                <p>{{ trans('structure-manager::help.create_test_metenox_cmd_desc') }}</p>
                <p>{{ trans('structure-manager::help.create_test_metenox_cmd_note') }}</p>
                {!! trans('structure-manager::help.create_test_metenox_features') !!}

                <p><strong>{{ trans('structure-manager::help.create_test_metenox_usage') }}</strong></p>
                {!! trans('structure-manager::help.create_test_metenox_create') !!}
                {!! trans('structure-manager::help.create_test_metenox_cleanup') !!}

                <div class="purple-box">
                    <i class="fas fa-flask"></i>
                    {!! trans('structure-manager::help.create_test_metenox_uses') !!}
                </div>

                <div class="info-box">
                    <i class="fas fa-clock"></i>
                    <strong>{{ trans('structure-manager::help.automation') }}:</strong>
                    {{ trans('structure-manager::help.automation_note') }}
                </div>

                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{ trans('structure-manager::help.note') }}:</strong>
                    {{ trans('structure-manager::help.commands_warning') }}
                </div>
            </div>
        </div>

        {{-- FAQ Section --}}
        <div id="faq" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-question-circle"></i>
                    {{ trans('structure-manager::help.frequently_asked') }}
                </h3>
                
                @for ($i = 1; $i <= 15; $i++)
                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans("structure-manager::help.faq_q{$i}") }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>{!! trans("structure-manager::help.faq_a{$i}") !!}</p>
                    </div>
                </div>
                @endfor
            </div>
        </div>

        {{-- Troubleshooting Section --}}
        <div id="troubleshooting" class="help-section">
            <div class="help-card">
                <h3>
                    <i class="fas fa-wrench"></i>
                    {{ trans('structure-manager::help.troubleshooting_guide') }}
                </h3>
                <p>{{ trans('structure-manager::help.troubleshooting_intro') }}</p>

                <h4>{{ trans('structure-manager::help.common_issues') }}</h4>
                
                <h5>{{ trans('structure-manager::help.issue1_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue1_desc') }}</p>
                {!! trans('structure-manager::help.issue1_solutions') !!}

                <h5>{{ trans('structure-manager::help.issue2_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue2_desc') }}</p>
                {!! trans('structure-manager::help.issue2_solutions') !!}

                <h5>{{ trans('structure-manager::help.issue3_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue3_desc') }}</p>
                {!! trans('structure-manager::help.issue3_solutions') !!}

                <h5>{{ trans('structure-manager::help.issue4_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue4_desc') }}</p>
                {!! trans('structure-manager::help.issue4_solutions') !!}

                <h5>{{ trans('structure-manager::help.issue5_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue5_desc') }}</p>
                {!! trans('structure-manager::help.issue5_solutions') !!}

                <h5>{{ trans('structure-manager::help.issue6_title') }}</h5>
                <p>{{ trans('structure-manager::help.issue6_desc') }}</p>
                {!! trans('structure-manager::help.issue6_solutions') !!}

                <div class="info-box">
                    <i class="fas fa-life-ring"></i>
                    <strong>{{ trans('structure-manager::help.need_help') }}:</strong>
                    {{ trans('structure-manager::help.support_message') }}
                </div>
            </div>
        </div>

    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Navigation
    $('.help-nav .nav-link').on('click', function(e) {
        e.preventDefault();
        
        const section = $(this).data('section');
        
        // Update nav
        $('.help-nav .nav-link').removeClass('active');
        $(this).addClass('active');
        
        // Update content
        $('.help-section').removeClass('active');
        $(`#${section}`).addClass('active');
        
        // Update URL hash
        window.location.hash = section;
        
        // Scroll to top of content
        $('.help-content').scrollTop(0);
    });
    
    // Load section from URL hash
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        $(`.help-nav .nav-link[data-section="${hash}"]`).click();
    }
    
    // FAQ Accordion
    $('.faq-question').on('click', function() {
        $(this).closest('.faq-item').toggleClass('open');
    });
    
    // Search functionality
    let searchTimeout;
    $('#helpSearch').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().toLowerCase().trim();
        
        if (query.length < 2) {
            // Reset: show all sections and cards
            $('.help-section').show();
            $('.help-card').show();
            $('.plugin-info').show();
            return;
        }
        
        searchTimeout = setTimeout(() => {
            let foundAny = false;
            
            // Search through all sections
            $('.help-section').each(function() {
                const $section = $(this);
                let sectionHasMatch = false;
                
                // Check all cards in this section
                $section.find('.help-card, .plugin-info').each(function() {
                    const text = $(this).text().toLowerCase();
                    const hasMatch = text.includes(query);
                    
                    $(this).toggle(hasMatch);
                    
                    if (hasMatch) {
                        sectionHasMatch = true;
                        foundAny = true;
                    }
                });
                
                // Show/hide entire section based on matches
                $section.toggle(sectionHasMatch);
            });
            
            // If no results found, show a message
            if (!foundAny) {
                $('.help-section').first().show().html(
                    '<div class="help-card"><p style="text-align: center; color: #9ca3af;">No results found for "' + 
                    $('<div>').text(query).html() + '". Try a different search term.</p></div>'
                );
            }
        }, 300);
    });
});
</script>
@endpush
@endsection
