@extends('web::layouts.grids.12')

@section('title', trans('structure-manager::help.help_documentation'))
@section('page_header', trans('structure-manager::help.help_documentation'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
@endpush

@section('full')
<div class="structure-manager-wrapper">

    <div class="help-wrapper">
        {{-- Sidebar Navigation --}}
        <div class="help-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-compass"></i>
                        Navigation
                    </h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column help-nav">
                        <li class="nav-item">
                            <a href="#" class="nav-link active" data-section="overview">
                                <i class="fas fa-home"></i>
                                {{ trans('structure-manager::help.overview') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="getting-started">
                                <i class="fas fa-rocket"></i>
                                {{ trans('structure-manager::help.getting_started') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="features">
                                <i class="fas fa-star"></i>
                                {{ trans('structure-manager::help.features') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="fuel-mechanics">
                                <i class="fas fa-gas-pump"></i>
                                {{ trans('structure-manager::help.fuel_mechanics') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="metenox">
                                <i class="fas fa-moon"></i>
                                {{ trans('structure-manager::help.metenox_drills') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="pos">
                                <i class="fas fa-satellite-dish"></i>
                                {{ trans('structure-manager::help.pos_management') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="upwell-notifications">
                                <i class="fas fa-building"></i>
                                Upwell Notifications
                                <span class="v2-badge v2-badge-nav">{{ trans('structure-manager::help.v2_badge') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="manager-core">
                                <i class="fas fa-calculator"></i>
                                Manager Core
                                <span class="v2-badge v2-badge-nav">{{ trans('structure-manager::help.v2_badge') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="economics">
                                <i class="fas fa-coins"></i>
                                Fuel Economics
                                <span class="v2-badge v2-badge-nav">{{ trans('structure-manager::help.v2_badge') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="notifications">
                                <i class="fas fa-bell"></i>
                                {{ trans('structure-manager::help.notifications') }}
                                <span class="v2-badge v2-badge-nav">{{ trans('structure-manager::help.v2_badge') }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="settings">
                                <i class="fas fa-cog"></i>
                                {{ trans('structure-manager::help.settings') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="pages">
                                <i class="fas fa-th-large"></i>
                                {{ trans('structure-manager::help.pages_guide') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="commands">
                                <i class="fas fa-terminal"></i>
                                {{ trans('structure-manager::help.commands') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="custom-styling">
                                <i class="fas fa-paint-brush"></i>
                                {{ trans('structure-manager::help.custom_styling') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="faq">
                                <i class="fas fa-question-circle"></i>
                                {{ trans('structure-manager::help.faq') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="troubleshooting">
                                <i class="fas fa-wrench"></i>
                                {{ trans('structure-manager::help.troubleshooting') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="admin-diagnostics">
                                <i class="fas fa-stethoscope"></i>
                                {{ trans('structure-manager::help.admin_diagnostics_nav') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content Area --}}
        <div class="help-content">

            {{-- Search Box --}}
            <div class="search-box">
                <input type="text"
                       id="helpSearch"
                       placeholder="{{ trans('structure-manager::help.search_placeholder') }}"
                       class="form-control">
                <i class="fas fa-search"></i>
            </div>

            {{-- Overview Section --}}
            <div id="overview" class="help-section active">
                {{-- Plugin Information --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('structure-manager::help.plugin_info_title') }}
                    </h3>
                    <p>
                        Version:
                        <img src="https://img.shields.io/github/v/release/MattFalahe/Structure-Manager?label=release&color=667eea" alt="Version" style="vertical-align: middle;">
                        <img src="https://img.shields.io/badge/SeAT-5.0-764ba2" alt="SeAT 5.0" style="vertical-align: middle;">
                    </p>
                    <p>License: GPL-2.0</p>
                    <p>
                        <i class="fas fa-user"></i> <strong>{{ trans('structure-manager::help.author') }}:</strong> Matt Falahe<br>
                        <i class="fas fa-envelope"></i> <a href="mailto:mattfalahe@gmail.com" style="color: #667eea;">mattfalahe@gmail.com</a>
                    </p>

                    <div class="quick-links" style="margin-top: 15px;">
                        <a href="https://github.com/MattFalahe/Structure-Manager" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fab fa-github" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('structure-manager::help.github_repo') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Structure-Manager/blob/main/CHANGELOG.MD" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-list" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('structure-manager::help.changelog') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Structure-Manager/issues" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-bug" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('structure-manager::help.report_issues') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Structure-Manager/blob/main/README.md" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-book" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('structure-manager::help.readme') }}
                        </a>
                    </div>

                    <div class="success-box" style="margin-top: 20px;">
                        <i class="fas fa-heart"></i>
                        <div>
                            <strong>{{ trans('structure-manager::help.support_project') }}:</strong>
                            {!! trans('structure-manager::help.support_list') !!}
                        </div>
                    </div>
                </div>

                {{-- Welcome --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-building"></i>
                        {{ trans('structure-manager::help.welcome_title') }}
                    </h3>
                    <p>{{ trans('structure-manager::help.welcome_desc') }}</p>
                </div>

                {{-- v2 upgrade highlights --}}
                <div class="whats-new-box">
                    <h4><i class="fas fa-sparkles"></i> {{ trans('structure-manager::help.whats_new_v2_title') }}</h4>
                    <p>{!! trans('structure-manager::help.whats_new_v2_intro') !!}</p>
                    {!! trans('structure-manager::help.whats_new_v2_list') !!}
                    <p style="margin-top:12px; margin-bottom:0; font-size:0.88rem; color:#8b95a5;">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('structure-manager::help.whats_new_v2_upgrade_note') !!}
                    </p>
                </div>

                {{-- What is Structure Manager? --}}
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

                {{-- Key Features --}}
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
                            <p>{!! trans('structure-manager::help.feature_reserves_desc') !!}</p>
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
                        <div class="feature-item">
                            <i class="fas fa-user-secret"></i>
                            <h5>{!! trans('structure-manager::help.feature_forensics_title') !!}</h5>
                            <p>{!! trans('structure-manager::help.feature_forensics_desc') !!}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-paper-plane"></i>
                            <h5>{!! trans('structure-manager::help.feature_webhook_delivery_title') !!}</h5>
                            <p>{!! trans('structure-manager::help.feature_webhook_delivery_desc') !!}</p>
                        </div>
                    </div>
                </div>

                {{-- Quick Links --}}
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
                    <p>{!! trans('structure-manager::help.getting_started_desc') !!}</p>

                    <h4>{{ trans('structure-manager::help.first_time_setup_title') }}</h4>
                    <ol class="step-by-step">
                        <li>
                            <strong>{{ trans('structure-manager::help.setup_step1_title') }}</strong><br>
                            {!! trans('structure-manager::help.setup_step1_desc') !!}
                        </li>
                        <li>
                            <strong>{{ trans('structure-manager::help.setup_step2_title') }}</strong><br>
                            {!! trans('structure-manager::help.setup_step2_desc') !!}
                            <pre><code>php artisan structure-manager:track-fuel
php artisan structure-manager:track-poses-fuel</code></pre>
                        </li>
                        <li>
                            <strong>{{ trans('structure-manager::help.setup_step3_title') }}</strong><br>
                            {!! trans('structure-manager::help.setup_step3_desc') !!}
                        </li>
                        <li>
                            <strong>{{ trans('structure-manager::help.setup_step4_title') }}</strong><br>
                            {!! trans('structure-manager::help.setup_step4_desc') !!}
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

                    <h4><i class="fas fa-shield-alt text-danger"></i> {{ trans('structure-manager::help.structure_events_feature') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span></h4>
                    {!! trans('structure-manager::help.structure_events_feature_desc') !!}

                    <h4><i class="fas fa-chart-line text-info"></i> {{ trans('structure-manager::help.consumption_analytics') }}</h4>
                    {!! trans('structure-manager::help.consumption_analytics_desc') !!}

                    <h4><i class="fas fa-search text-warning"></i> {{ trans('structure-manager::help.fuel_forensics_feature') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span></h4>
                    {!! trans('structure-manager::help.fuel_forensics_feature_desc') !!}

                    <h4><i class="fas fa-warehouse text-success"></i> {{ trans('structure-manager::help.reserve_management') }}</h4>
                    {!! trans('structure-manager::help.reserve_management_desc') !!}

                    <h4><i class="fas fa-coins text-warning"></i> {{ trans('structure-manager::help.fuel_economics_feature') }}
                        <span class="mc-badge">{{ trans('structure-manager::help.mc_required_badge') }}</span></h4>
                    {!! trans('structure-manager::help.fuel_economics_feature_desc') !!}

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

            {{-- Upwell Notifications Section (v2) --}}
            <div id="upwell-notifications" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-building"></i>
                        {{ trans('structure-manager::help.upwell_detailed_title') }}
                        <span class="v2-badge">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h3>
                    <p>{!! trans('structure-manager::help.upwell_detailed_intro') !!}</p>

                    <h4>{{ trans('structure-manager::help.upwell_what_tracked_title') }}</h4>
                    {!! trans('structure-manager::help.upwell_what_tracked_list') !!}

                    <h4>{{ trans('structure-manager::help.upwell_detection_title') }}</h4>
                    {!! trans('structure-manager::help.upwell_detection_list') !!}

                    <h4>{{ trans('structure-manager::help.upwell_status_flow_title') }}</h4>
                    <p>{!! trans('structure-manager::help.upwell_status_flow_desc') !!}</p>
                    {!! trans('structure-manager::help.upwell_status_flow_table') !!}

                    <h4><i class="fas fa-moon"></i> {{ trans('structure-manager::help.upwell_metenox_dual_fuel_title') }}</h4>
                    <p>{{ trans('structure-manager::help.upwell_metenox_dual_fuel_desc') }}</p>
                    {!! trans('structure-manager::help.upwell_metenox_dual_fuel_math') !!}

                    <h4>{{ trans('structure-manager::help.upwell_config_title') }}</h4>
                    <div class="info-box">
                        {!! trans('structure-manager::help.upwell_config_thresholds') !!}
                    </div>
                    <div class="purple-box">
                        {!! trans('structure-manager::help.upwell_config_webhooks') !!}
                    </div>

                    <h4>{{ trans('structure-manager::help.upwell_vs_pos_title') }}</h4>
                    {!! trans('structure-manager::help.upwell_vs_pos_table') !!}

                    <h4>{{ trans('structure-manager::help.upwell_embed_example_title') }}</h4>
                    {!! trans('structure-manager::help.upwell_embed_example') !!}
                </div>
            </div>

            {{-- Manager Core Section (v2) --}}
            <div id="manager-core" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-calculator"></i>
                        {{ trans('structure-manager::help.mc_overview_title') }}
                        <span class="v2-badge">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h3>

                    <div class="success-box">
                        {!! trans('structure-manager::help.mc_overview_positioning') !!}
                    </div>

                    <h4>{{ trans('structure-manager::help.mc_what_it_is_title') }}</h4>
                    <p>{{ trans('structure-manager::help.mc_what_it_is_desc') }}</p>
                    {!! trans('structure-manager::help.mc_what_it_is_list') !!}

                    <h4>{{ trans('structure-manager::help.mc_benefits_for_sm_title') }}</h4>
                    {!! trans('structure-manager::help.mc_benefits_for_sm_list') !!}

                    <h4>{{ trans('structure-manager::help.mc_without_title') }}</h4>
                    <div class="info-box">
                        {!! trans('structure-manager::help.mc_without_desc') !!}
                    </div>

                    <h4>{{ trans('structure-manager::help.mc_install_title') }}</h4>
                    {!! trans('structure-manager::help.mc_install_steps') !!}

                    <h4>{{ trans('structure-manager::help.mc_ecosystem_title') }}</h4>
                    <p>{{ trans('structure-manager::help.mc_ecosystem_desc') }}</p>
                    {!! trans('structure-manager::help.mc_ecosystem_list') !!}
                </div>
            </div>

            {{-- Fuel Economics Section (requires Manager Core) --}}
            <div id="economics" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-coins"></i>
                        {{ trans('structure-manager::help.economics_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h3>
                    <p>{{ trans('structure-manager::help.economics_intro') }}</p>

                    <h4>{{ trans('structure-manager::help.economics_what_it_shows_title') }}</h4>
                    {!! trans('structure-manager::help.economics_what_it_shows_html') !!}

                    <h4>{{ trans('structure-manager::help.economics_pricing_title') }}</h4>
                    {!! trans('structure-manager::help.economics_pricing_html') !!}

                    <h4>{{ trans('structure-manager::help.economics_substitutable_title') }}</h4>
                    {!! trans('structure-manager::help.economics_substitutable_html') !!}

                    <h4>{{ trans('structure-manager::help.economics_offline_title') }}</h4>
                    {!! trans('structure-manager::help.economics_offline_html') !!}

                    <h4>{{ trans('structure-manager::help.economics_settings_title') }}</h4>
                    {!! trans('structure-manager::help.economics_settings_html') !!}

                    <h4>{{ trans('structure-manager::help.economics_diagnostic_title') }}</h4>
                    {!! trans('structure-manager::help.economics_diagnostic_html') !!}
                </div>
            </div>

            {{-- Notifications Section --}}
            <div id="notifications" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-bell"></i>
                        {{ trans('structure-manager::help.notifications_title') }}
                    </h3>
                    <p>{!! trans('structure-manager::help.notifications_intro') !!}</p>

                    {{-- v3.1: dedicated Notifications page overview --}}
                    <div class="success-box" style="margin-top:15px;">
                        <div>
                            <h4 style="margin-top:0;">
                                <i class="fas fa-sparkles"></i>
                                {{ trans('structure-manager::help.v31_redesign_title') }}
                                <span class="v2-badge">{{ trans('structure-manager::help.v2_badge') }}</span>
                            </h4>
                            <p>{{ trans('structure-manager::help.v31_redesign_intro') }}</p>
                            {!! trans('structure-manager::help.v31_redesign_concepts') !!}
                        </div>
                    </div>

                    <h4>
                        {{ trans('structure-manager::help.v31_category_namespaces_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{{ trans('structure-manager::help.v31_category_namespaces_desc') }}</p>
                    {!! trans('structure-manager::help.v31_category_namespaces_list') !!}

                    <h4>
                        {{ trans('structure-manager::help.v31_category_list_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{{ trans('structure-manager::help.v31_category_list_desc') }}</p>
                    {!! trans('structure-manager::help.v31_category_list') !!}

                    <h4>
                        {{ trans('structure-manager::help.v31_role_precedence_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{{ trans('structure-manager::help.v31_role_precedence_desc') }}</p>
                    {!! trans('structure-manager::help.v31_role_precedence_list') !!}

                    <h4>
                        <i class="fas fa-hashtag"></i>
                        {{ trans('structure-manager::help.v31_role_picker_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{{ trans('structure-manager::help.v31_role_picker_desc') }}</p>
                    {!! trans('structure-manager::help.v31_role_picker_sources') !!}
                    {!! trans('structure-manager::help.v31_role_picker_behavior') !!}

                    <h4>
                        <i class="fas fa-bolt"></i>
                        {{ trans('structure-manager::help.esi_events_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{{ trans('structure-manager::help.esi_events_intro') }}</p>
                    <div class="success-box">
                        {!! trans('structure-manager::help.esi_events_with_mc') !!}
                    </div>
                    <div class="warning-box">
                        {!! trans('structure-manager::help.esi_events_standalone') !!}
                    </div>
                    <div class="purple-box">
                        {!! trans('structure-manager::help.esi_events_how_to_enable') !!}
                    </div>
                    <p style="margin-top:10px;">{!! trans('structure-manager::help.esi_events_detection_mode') !!}</p>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-fingerprint"></i>
                        {{ trans('structure-manager::help.esi_events_paths_title') }}
                    </h5>
                    <p>{!! trans('structure-manager::help.esi_events_paths_intro') !!}</p>
                    {!! trans('structure-manager::help.esi_events_paths_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-vial"></i>
                        {{ trans('structure-manager::help.esi_events_paths_testing_title') }}
                    </h5>
                    <div class="info-box">
                        {!! trans('structure-manager::help.esi_events_paths_testing') !!}
                    </div>

                    {{-- 2026-05-17 (v2.1): Pre-timer reminder pings. Placed
                         after the ESI detection-path block because it builds
                         on the same vocabulary (timer.upcoming_* events fire
                         from the same publishing job that ESI detection
                         drives) but is a distinct feature: scheduled lead-time
                         pings, not under-attack alerts. --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-stopwatch"></i>
                        {{ trans('structure-manager::help.pre_timer_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.pre_timer_v21_badge') }}</span>
                    </h4>
                    <p>{!! trans('structure-manager::help.pre_timer_intro') !!}</p>
                    <div class="warning-box">
                        {!! trans('structure-manager::help.pre_timer_requires_mc') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-list-check"></i>
                        {{ trans('structure-manager::help.pre_timer_event_types_title') }}
                    </h5>
                    <p>{{ trans('structure-manager::help.pre_timer_event_types_desc') }}</p>
                    {!! trans('structure-manager::help.pre_timer_event_types_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-route"></i>
                        {{ trans('structure-manager::help.pre_timer_routing_title') }}
                    </h5>
                    <p>{!! trans('structure-manager::help.pre_timer_routing_desc') !!}</p>
                    {!! trans('structure-manager::help.pre_timer_categories_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-clock"></i>
                        {{ trans('structure-manager::help.pre_timer_cadence_title') }}
                    </h5>
                    <p>{!! trans('structure-manager::help.pre_timer_cadence_desc') !!}</p>
                    {!! trans('structure-manager::help.pre_timer_cadence_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-sliders-h"></i>
                        {{ trans('structure-manager::help.pre_timer_settings_title') }}
                    </h5>
                    {!! trans('structure-manager::help.pre_timer_settings_list') !!}

                    {{-- 2026-05-17 (v2.2): Attacker threat intel. Placed
                         after pre-timer reminders (both are notification-
                         family features) and before opsec section (which is
                         about data boundaries — relevant context for the
                         "what zKB sees" subsection below). --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-search"></i>
                        {{ trans('structure-manager::help.threat_intel_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.threat_intel_v22_badge') }}</span>
                    </h4>
                    <p>{!! trans('structure-manager::help.threat_intel_intro') !!}</p>
                    <div class="info-box">
                        {!! trans('structure-manager::help.threat_intel_opt_in') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-bolt"></i>
                        {{ trans('structure-manager::help.threat_intel_async_title') }}
                    </h5>
                    <p>{{ trans('structure-manager::help.threat_intel_async_desc') }}</p>
                    {!! trans('structure-manager::help.threat_intel_async_list') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-shield-alt"></i>
                        {{ trans('structure-manager::help.threat_intel_what_zkb_sees') }}
                    </h5>
                    {!! trans('structure-manager::help.threat_intel_data_flow') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-fire"></i>
                        {{ trans('structure-manager::help.threat_intel_tiers_title') }}
                    </h5>
                    <p>{{ trans('structure-manager::help.threat_intel_tiers_desc') }}</p>
                    {!! trans('structure-manager::help.threat_intel_tiers_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-cog"></i>
                        {{ trans('structure-manager::help.threat_intel_setup_title') }}
                    </h5>
                    {!! trans('structure-manager::help.threat_intel_setup_list') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-database"></i>
                        {{ trans('structure-manager::help.threat_intel_caching_title') }}
                    </h5>
                    <p>{!! trans('structure-manager::help.threat_intel_caching_desc') !!}</p>

                    {{-- 2026-05-17 (v2.0.0): FINAL TIMER awareness. Placed
                         after the alert-side blocks (under-attack alert,
                         threat intel, pre-timer reminders) since it modifies
                         all three. --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('structure-manager::help.final_timer_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.final_timer_v23_badge') }}</span>
                    </h4>
                    <p>{!! trans('structure-manager::help.final_timer_intro') !!}</p>
                    {!! trans('structure-manager::help.final_timer_surfaces') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-list-check"></i>
                        Which structures get the FINAL TIMER marker
                    </h5>
                    {!! trans('structure-manager::help.final_timer_classification') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-cog"></i>
                        Design rationale
                    </h5>
                    <p>{!! trans('structure-manager::help.final_timer_design') !!}</p>

                    {{-- 2026-05-12 opsec section explaining why ICS / external
                         calendar export is explicitly not on the roadmap.
                         Added after an operator pointed out the security risk
                         in a brainstorm session - rather than litigate it
                         every time someone asks, the reasoning lives here. --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-shield-alt"></i>
                        {{ trans('structure-manager::help.opsec_title') }}
                    </h4>
                    <p>{!! trans('structure-manager::help.opsec_intro') !!}</p>
                    <div class="info-box">
                        {!! trans('structure-manager::help.opsec_trust_zones') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-ban"></i>
                        {{ trans('structure-manager::help.opsec_no_external_export_title') }}
                    </h5>
                    <div class="warning-box">
                        {!! trans('structure-manager::help.opsec_no_external_export') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-check-circle"></i>
                        {{ trans('structure-manager::help.opsec_alternatives_title') }}
                    </h5>
                    <div class="success-box">
                        {!! trans('structure-manager::help.opsec_alternatives') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-lock"></i>
                        {{ trans('structure-manager::help.opsec_other_data_title') }}
                    </h5>
                    <div class="info-box">
                        {!! trans('structure-manager::help.opsec_other_data') !!}
                    </div>

                    {{-- 2026-05-12 IdResolver feature subsection.
                         Sits between the opsec block (so readers understand
                         that public ESI lookups are SAFE under the trust-zone
                         framework) and the tactical events contract. --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-user-tag"></i>
                        {{ trans('structure-manager::help.id_resolver_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h4>
                    <p>{!! trans('structure-manager::help.id_resolver_intro') !!}</p>

                    <div class="info-box">
                        {!! trans('structure-manager::help.id_resolver_chain') !!}
                    </div>

                    <div class="success-box" style="margin-top:12px;">
                        {!! trans('structure-manager::help.id_resolver_what_resolves') !!}
                    </div>

                    <div class="info-box" style="margin-top:12px;">
                        <i class="fas fa-tachometer-alt"></i>
                        {!! trans('structure-manager::help.id_resolver_performance') !!}
                    </div>

                    <div class="success-box" style="margin-top:12px;">
                        <i class="fas fa-shield-alt"></i>
                        {!! trans('structure-manager::help.id_resolver_opsec') !!}
                    </div>

                    <div class="info-box" style="margin-top:12px;">
                        <i class="fas fa-sync-alt"></i>
                        {!! trans('structure-manager::help.id_resolver_admin_force_refresh') !!}
                    </div>

                    {{-- 2026-05-12 tactical-planning events contract.
                         Documents the structure.alert.* event family for
                         Discord Pings (and future fleet-planning consumers)
                         to subscribe to. Sits below the opsec section so
                         readers understand the trust-zone discipline before
                         reading "and here's the pub/sub contract you can
                         subscribe to". --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-broadcast-tower"></i>
                        {{ trans('structure-manager::help.tactical_events_title') }}
                    </h4>
                    <p>{!! trans('structure-manager::help.tactical_events_intro') !!}</p>
                    <div class="info-box">
                        {!! trans('structure-manager::help.tactical_events_design') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-list"></i>
                        Events SM publishes
                    </h5>
                    {!! trans('structure-manager::help.tactical_events_table') !!}

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-file-code"></i>
                        Payload schema
                    </h5>
                    <div class="info-box">
                        {!! trans('structure-manager::help.tactical_events_payload') !!}
                    </div>

                    <h5 style="margin-top:18px;">
                        <i class="fas fa-book"></i>
                        Subscriber guide
                    </h5>
                    <div class="purple-box">
                        {!! trans('structure-manager::help.tactical_events_subscriber_guide') !!}
                    </div>

                    <div class="success-box" style="margin-top:12px;">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Trust zone:</strong> {{ trans('structure-manager::help.tactical_events_opsec_note') }}
                    </div>

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
                        Access settings from the Structure Manager menu in the sidebar &rarr; Settings
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

                    {{-- Order mirrors the SeAT sidebar (Config/Menu/package.sidebar.php):
                         Upwell Structures → Control Towers → Fuel Reserves → Logistics
                         → Fuel Economics → Critical Alerts → Structure Board → Settings.
                         Structure Detail is not a sidebar entry — it sits right after
                         Upwell Structures as the click-through child page. --}}

                    <h4><i class="fas fa-gas-pump"></i> {{ trans('structure-manager::help.dashboard_page_title') }}</h4>
                    {!! trans('structure-manager::help.dashboard_page_desc') !!}

                    <h4><i class="fas fa-chart-line"></i> {{ trans('structure-manager::help.detail_page_title') }}</h4>
                    {!! trans('structure-manager::help.detail_page_desc') !!}

                    <h4><i class="fas fa-broadcast-tower"></i> {{ trans('structure-manager::help.pos_page_title') }}</h4>
                    {!! trans('structure-manager::help.pos_page_desc') !!}

                    <h4><i class="fas fa-warehouse"></i> {{ trans('structure-manager::help.reserves_page_title') }}</h4>
                    {!! trans('structure-manager::help.reserves_page_desc') !!}

                    <h4><i class="fas fa-truck"></i> {{ trans('structure-manager::help.logistics_page_title') }}</h4>
                    {!! trans('structure-manager::help.logistics_page_desc') !!}

                    <h4><i class="fas fa-coins"></i> {{ trans('structure-manager::help.economics_page_title') }}</h4>
                    {!! trans('structure-manager::help.economics_page_desc') !!}

                    <h4><i class="fas fa-exclamation-triangle"></i> {{ trans('structure-manager::help.critical_alerts_page_title') }}</h4>
                    {!! trans('structure-manager::help.critical_alerts_page_desc') !!}

                    <h4><i class="fas fa-chess"></i> {{ trans('structure-manager::help.command_board_page_title') }}</h4>
                    {!! trans('structure-manager::help.command_board_page_desc') !!}

                    <h4><i class="fas fa-cog"></i> {{ trans('structure-manager::help.settings_page_title') }}</h4>
                    {!! trans('structure-manager::help.settings_page_desc') !!}

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

                    {{-- 2026-05-12: previously undocumented commands (7 in total).
                         Operational background jobs (cron-driven, operators see in
                         logs and Horizon) + test-data commands (operator-invoked
                         during verification). Sits at the bottom of the Commands
                         section so the main user-facing commands stay up top. --}}
                    <h3 style="margin-top:32px;">
                        <i class="fas fa-cogs"></i>
                        {{ trans('structure-manager::help.commands_additional_title') }}
                        <span class="v2-badge v2-badge-inline">{{ trans('structure-manager::help.v2_badge') }}</span>
                    </h3>
                    <p>{{ trans('structure-manager::help.commands_additional_intro') }}</p>

                    <h4 style="margin-top:16px;">
                        <i class="fas fa-calendar-check"></i>
                        {{ trans('structure-manager::help.commands_operational_title') }}
                    </h4>
                    <p>{{ trans('structure-manager::help.commands_operational_intro') }}</p>

                    <h5>{{ trans('structure-manager::help.process_notifications_title') }}</h5>
                    <p>{!! trans('structure-manager::help.process_notifications_desc') !!}</p>
                    <pre><code>php artisan structure-manager:process-notifications</code></pre>
                    <p class="text-muted" style="font-size:0.9em;">{!! trans('structure-manager::help.process_notifications_cron') !!}</p>

                    <h5>{{ trans('structure-manager::help.track_structure_presence_title') }}</h5>
                    <p>{!! trans('structure-manager::help.track_structure_presence_desc') !!}</p>
                    <pre><code>php artisan structure-manager:track-structure-presence</code></pre>
                    <p class="text-muted" style="font-size:0.9em;">{!! trans('structure-manager::help.track_structure_presence_cron') !!}</p>

                    <h5>{{ trans('structure-manager::help.publish_timer_schedule_events_title') }}</h5>
                    <p>{!! trans('structure-manager::help.publish_timer_schedule_events_desc') !!}</p>
                    <pre><code>php artisan structure-manager:publish-timer-schedule-events</code></pre>
                    <p class="text-muted" style="font-size:0.9em;">{!! trans('structure-manager::help.publish_timer_schedule_events_cron') !!}</p>

                    <h5>{{ trans('structure-manager::help.prune_structure_board_timers_title') }}</h5>
                    <p>{!! trans('structure-manager::help.prune_structure_board_timers_desc') !!}</p>
                    <pre><code>php artisan structure-manager:prune-structure-board-timers</code></pre>
                    <p class="text-muted" style="font-size:0.9em;">{!! trans('structure-manager::help.prune_structure_board_timers_cron') !!}</p>

                    <h4 style="margin-top:24px;">
                        <i class="fas fa-vial"></i>
                        {{ trans('structure-manager::help.commands_test_title') }}
                    </h4>
                    <p>{{ trans('structure-manager::help.commands_test_intro') }}</p>

                    <h5>{{ trans('structure-manager::help.create_test_upwell_structures_title') }}</h5>
                    <p>{!! trans('structure-manager::help.create_test_upwell_structures_desc') !!}</p>
                    {!! trans('structure-manager::help.create_test_upwell_structures_usage') !!}

                    <h5>{{ trans('structure-manager::help.inject_test_notification_title') }}</h5>
                    <p>{!! trans('structure-manager::help.inject_test_notification_desc') !!}</p>
                    {!! trans('structure-manager::help.inject_test_notification_usage') !!}
                    <div class="info-box" style="margin-top:8px;">
                        <i class="fas fa-shield-alt"></i>
                        {!! trans('structure-manager::help.inject_test_notification_safety') !!}
                    </div>

                    <h5>{{ trans('structure-manager::help.cleanup_test_data_title') }}</h5>
                    <p>{!! trans('structure-manager::help.cleanup_test_data_desc') !!}</p>
                    {!! trans('structure-manager::help.cleanup_test_data_usage') !!}
                    <div class="info-box" style="margin-top:8px;">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('structure-manager::help.cleanup_test_data_output') !!}
                    </div>

                    <div class="warning-box" style="margin-top:24px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('structure-manager::help.note') }}:</strong>
                        {{ trans('structure-manager::help.commands_warning') }}
                    </div>
                </div>
            </div>

            {{-- Custom Styling Section --}}
            <div id="custom-styling" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-paint-brush"></i>
                        {{ trans('structure-manager::help.custom_styling_guide') }}
                    </h3>
                    <p>{{ trans('structure-manager::help.custom_styling_intro') }}</p>

                    <h4>{{ trans('structure-manager::help.css_class_hierarchy') }}</h4>
                    <p>{{ trans('structure-manager::help.css_class_hierarchy_desc') }}</p>
                    <ul>
                        <li>{!! trans('structure-manager::help.css_base_class') !!}</li>
                        <li>{!! trans('structure-manager::help.css_settings_class') !!}</li>
                        <li>{!! trans('structure-manager::help.css_diagnostic_class') !!}</li>
                    </ul>

                    <h4>{{ trans('structure-manager::help.css_components_title') }}</h4>
                    <p>{{ trans('structure-manager::help.css_components_desc') }}</p>
                    <ul>
                        <li>{!! trans('structure-manager::help.css_component_card') !!}</li>
                        <li>{!! trans('structure-manager::help.css_component_cardtitle') !!}</li>
                        <li>{!! trans('structure-manager::help.css_component_cardtools') !!}</li>
                        <li>{!! trans('structure-manager::help.css_component_infobox') !!}</li>
                        <li>{!! trans('structure-manager::help.css_component_btn') !!}</li>
                    </ul>

                    <h4>{{ trans('structure-manager::help.css_example_title') }}</h4>

                    <h5>{{ trans('structure-manager::help.css_example_global') }}</h5>
                    <pre><code>{{ trans('structure-manager::help.css_example_global_code') }}</code></pre>

                    <h5>{{ trans('structure-manager::help.css_example_specific') }}</h5>
                    <pre><code>{{ trans('structure-manager::help.css_example_specific_code') }}</code></pre>

                    <h5>{{ trans('structure-manager::help.css_example_icon') }}</h5>
                    <pre><code>{{ trans('structure-manager::help.css_example_icon_code') }}</code></pre>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('structure-manager::help.css_where_to_add') }}:</strong> {!! trans('structure-manager::help.css_where_to_add_desc') !!}
                    </div>
                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        {{ trans('structure-manager::help.custom_styling_note') }}
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

                    @for ($i = 1; $i <= 18; $i++)
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

            {{-- Admin Diagnostics & Test Notification Lab --}}
            <div id="admin-diagnostics" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-stethoscope"></i>
                        {{ trans('structure-manager::help.admin_diagnostics_title') }}
                    </h3>
                    <p>{{ trans('structure-manager::help.admin_diagnostics_intro') }}</p>

                    <h4>{{ trans('structure-manager::help.admin_diagnostics_url_title') }}</h4>
                    <p>{!! trans('structure-manager::help.admin_diagnostics_url_desc') !!}</p>

                    <h4>{{ trans('structure-manager::help.admin_diagnostics_what_title') }}</h4>
                    {!! trans('structure-manager::help.admin_diagnostics_what_list') !!}

                    <h4>{{ trans('structure-manager::help.test_lab_title') }}</h4>
                    <p>{{ trans('structure-manager::help.test_lab_intro') }}</p>

                    <h5>{{ trans('structure-manager::help.test_lab_workflow_title') }}</h5>
                    {!! trans('structure-manager::help.test_lab_workflow_list') !!}

                    <h5>{{ trans('structure-manager::help.test_lab_paths_title') }}</h5>
                    <p>{{ trans('structure-manager::help.test_lab_paths_desc') }}</p>
                    {!! trans('structure-manager::help.test_lab_paths_list') !!}

                    <h5>{{ trans('structure-manager::help.test_lab_supported_types_title') }}</h5>
                    <p>{{ trans('structure-manager::help.test_lab_supported_types_desc') }}</p>
                    {!! trans('structure-manager::help.test_lab_supported_types_list') !!}

                    <h5>{{ trans('structure-manager::help.test_lab_safety_title') }}</h5>
                    {!! trans('structure-manager::help.test_lab_safety_list') !!}

                    <h4>{{ trans('structure-manager::help.admin_diagnostics_when_to_use_title') }}</h4>
                    {!! trans('structure-manager::help.admin_diagnostics_when_to_use_list') !!}
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
        const query = $(this).val().toLowerCase();

        if (query.length < 2) {
            $('.help-card').show();
            return;
        }

        searchTimeout = setTimeout(() => {
            $('.help-card').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(query));
            });
        }, 300);
    });
});
</script>
@endpush
@endsection
