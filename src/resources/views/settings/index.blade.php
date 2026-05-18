@extends('web::layouts.grids.12')

@section('title', 'Structure Manager Settings')
@section('page_header', 'Structure Manager Settings')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/structure-manager/css/structure-manager.css') }}?v=17">
<style>
    /* === Settings page layout (sidebar + content) === */
    .settings-wrapper {
        display: flex;
        gap: 20px;
    }

    .settings-sidebar {
        flex: 0 0 250px;
    }

    .settings-content {
        flex: 1;
    }

    /* Settings nav-pills — local override (canonical CSS only styles .help-nav) */
    .settings-sidebar .nav-pills .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 8px 14px;
        font-size: 0.875rem;
        line-height: 1.4;
        transition: all 0.3s;
    }

    .settings-sidebar .nav-pills .nav-link:hover {
        background: rgba(102, 126, 234, 0.2);
    }

    .settings-sidebar .nav-pills .nav-link.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
    }

    .settings-sidebar .nav-pills .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 10px;
    }

    /* Show/hide sections like the help page */
    .settings-section-pane {
        display: none;
    }

    .settings-section-pane.active {
        display: block;
    }

    /* Sticky save/reset/cancel footer */
    .action-buttons {
        position: sticky;
        bottom: 0;
        background: #2d3748;
        padding: 20px;
        border-top: 2px solid rgba(102, 126, 234, 0.3);
        margin: 0 -20px -20px -20px;
        border-radius: 0 0 10px 10px;
        z-index: 5;
    }

    /* === Settings-specific content blocks not in canonical CSS === */
    /* Section block inside the content panel (re-uses old `.settings-section`
       visual treatment so existing tab-pane markup keeps its boxed look). */
    .settings-block {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .settings-block h4 {
        color: #17a2b8;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .settings-subsection {
        background: rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 0.25rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .settings-subsection h5 {
        color: #6c757d;
        font-size: 1rem;
        margin-bottom: 0.75rem;
        font-weight: 600;
    }

    .help-text {
        font-size: 0.875rem;
        color: #a0a0a0;
        margin-top: 0.25rem;
    }

    .tab-description {
        background: rgba(0, 0, 0, 0.15);
        border-left: 3px solid #17a2b8;
        padding: 1rem;
        margin-bottom: 1.5rem;
        border-radius: 0.25rem;
    }

    .tab-description p {
        margin-bottom: 0;
        color: #b0b0b0;
    }

    /* Threshold cards (POS + Upwell sections) */
    .threshold-group {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .threshold-item {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1rem;
    }

    .threshold-item.critical {
        border-left: 4px solid #dc3545;
    }

    .threshold-item.warning {
        border-left: 4px solid #ffc107;
    }

    .threshold-item.good {
        border-left: 4px solid #28a745;
    }

    /* Hangar checkbox grid (Reserves section) */
    .hangar-checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .hangar-checkbox-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 0.75rem;
        transition: all 0.2s;
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .hangar-checkbox-item:hover {
        background: rgba(0, 0, 0, 0.3);
        border-color: rgba(255, 255, 255, 0.2);
    }

    .hangar-checkbox-item input[type="checkbox"] {
        margin-top: 0.25rem;
        flex-shrink: 0;
    }

    .hangar-checkbox-item label {
        margin-bottom: 0;
        cursor: pointer;
        font-weight: normal;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        line-height: 1.3;
        flex: 1;
        min-width: 0;
    }

    .hangar-checkbox-item .hangar-label-main {
        color: #e2e8f0;
        font-weight: 500;
    }

    .hangar-checkbox-item .hangar-label-main i {
        color: #94a3b8;
        margin-right: 0.25rem;
    }

    .hangar-checkbox-item .hangar-custom-name {
        font-size: 0.85rem;
        color: #a5b4fc;
        font-style: italic;
        word-break: break-word;
    }

    .hangar-checkbox-item .hangar-sag-label {
        font-size: 0.75rem;
        color: #6b7280;
        font-family: 'Courier New', monospace;
        letter-spacing: 0.02em;
    }

    /* Webhook list styling (Webhooks section) */
    .webhook-list {
        margin-top: 1rem;
    }

    .webhook-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1rem;
        margin-bottom: 1rem;
        transition: all 0.2s;
    }

    .webhook-item:hover {
        background: rgba(0, 0, 0, 0.3);
        border-color: rgba(255, 255, 255, 0.2);
    }

    .webhook-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .webhook-info {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .webhook-status {
        font-size: 0.75rem;
    }

    .webhook-status.enabled {
        color: #28a745;
    }

    .webhook-status.disabled {
        color: #6c757d;
    }

    .webhook-actions {
        display: flex;
        gap: 0.5rem;
    }

    .webhook-details {
        padding-top: 0.75rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .webhook-url {
        background: rgba(0, 0, 0, 0.3);
        padding: 0.25rem 0.5rem;
        border-radius: 0.2rem;
        display: inline-block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .webhook-test-result {
        margin-left: 0.5rem;
    }

    .btn-test-webhook {
        margin-top: 0.5rem;
    }

    /* Form-group label tweak (kept since canonical CSS doesn't bold form labels by default) */
    .settings-content .form-group label {
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    /* Responsive — stack sidebar above content on small screens */
    @media (max-width: 768px) {
        .settings-wrapper {
            flex-direction: column;
        }

        .settings-sidebar {
            flex: 1;
        }
    }
</style>
@endpush

@section('full')
<div class="structure-manager-wrapper settings-page">

    {{-- Success/Error Messages --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i>
        {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle"></i>
        {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Validation errors:</strong>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    <form method="POST" action="{{ route('structure-manager.settings.update') }}">
        @csrf

        <div class="settings-wrapper">

            {{-- Sidebar --}}
            <div class="settings-sidebar">
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-cog"></i>
                            Settings Menu
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <ul class="nav nav-pills flex-column">
                            {{-- Global Settings group --}}
                            <li class="nav-header px-3 py-2 text-muted small text-uppercase" style="background: rgba(40, 167, 69, 0.1); border-left: 3px solid #28a745;">
                                <i class="fas fa-globe"></i> Global Settings
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="#" data-section="webhooks">
                                    <i class="fas fa-plug"></i>
                                    Webhook Configuration
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="notifications">
                                    <i class="fas fa-bell"></i>
                                    Notifications
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="routing-map">
                                    <i class="fas fa-project-diagram"></i>
                                    Routing Map
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="pos">
                                    <i class="fas fa-broadcast-tower"></i>
                                    POS Settings
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="reserves">
                                    <i class="fas fa-warehouse"></i>
                                    Reserves Tracking
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="upwell">
                                    <i class="fas fa-building"></i>
                                    Upwell Structures
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="events">
                                    <i class="fas fa-bolt"></i>
                                    Structure Events
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" data-section="economics">
                                    <i class="fas fa-coins"></i>
                                    Economics
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                {{-- Quick links --}}
                <div class="card card-dark mt-3">
                    <div class="card-header py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-external-link-alt"></i> Quick Links
                        </h6>
                    </div>
                    <div class="card-body py-2">
                        <a href="{{ route('structure-manager.index') }}" class="d-block py-1">
                            <i class="fas fa-th-large"></i> Structure Board
                        </a>
                    </div>
                </div>
            </div>

            {{-- Content --}}
            <div class="settings-content">
                <div class="card card-dark">
                    <div class="card-body">

                        {{-- Webhook Configuration Section --}}
                        <div id="webhooks-section" class="settings-section-pane active">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    Central webhook registry. Add as many Discord or Slack webhook destinations as you need.
                                    Each webhook is a <strong>delivery endpoint</strong> &mdash; where notifications are sent.
                                    <strong>What</strong> gets sent to which webhook is controlled in the
                                    <a href="#" class="nav-section-link" data-section="notifications">Notifications</a>
                                    section, where notification categories (POS Fuel, Structure Under Attack, Upwell Fuel, etc.) are bound to
                                    specific webhooks with optional per-binding role mention overrides.
                                </p>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-plug"></i> Configured Webhooks</h4>

                                <div class="info-banner">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Shared delivery endpoints:</strong> These webhooks serve every notification category in Structure Manager &mdash;
                                    POS fuel/strontium/lifecycle alerts, Upwell fuel alerts, and ESI-driven structure events (attacks, anchoring, etc.).
                                    Corporation filter scopes a webhook to a single corp (or leave as "All Corporations" for cross-corp delivery).
                                    Role mention on a webhook row is a <em>legacy fallback</em> &mdash; prefer setting role mentions per notification category on the Notifications page.
                                </div>

                                {{-- Existing Webhooks List --}}
                                @if($webhooks->count() > 0)
                                <div class="webhook-list mb-4">
                                    @foreach($webhooks as $webhook)
                                    <div class="webhook-item" data-webhook-id="{{ $webhook->id }}">
                                        <div class="webhook-header">
                                            <div class="webhook-info">
                                                <span class="webhook-status {{ $webhook->enabled ? 'enabled' : 'disabled' }}">
                                                    <i class="fas fa-circle"></i>
                                                </span>
                                                <strong>{{ $webhook->description ?: 'Webhook #' . $webhook->id }}</strong>
                                            </div>
                                            <div class="webhook-actions">
                                                <button type="button" class="btn btn-sm btn-info" onclick="testWebhook({{ $webhook->id }})">
                                                    <i class="fas fa-paper-plane"></i> Test
                                                </button>
                                                <button type="button" class="btn btn-sm btn-primary" onclick="editWebhook({{ $webhook->id }})">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteWebhook({{ $webhook->id }})">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>

                                        <div class="webhook-details">
                                            <div class="row">
                                                <div class="col-md-12 mb-2">
                                                    <small class="text-muted">URL:</small>
                                                    <code class="webhook-url">{{ substr($webhook->webhook_url, 0, 60) }}...</code>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted">Corporation Filter:</small>
                                                    <strong>{{ $webhook->getCorporationLabel() }}</strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted">Legacy Role Mention:</small>
                                                    @if($webhook->role_mention)
                                                        <code>{{ $webhook->role_mention }}</code>
                                                        <span class="text-muted" style="font-size:0.78rem;"> (fallback &mdash; prefer category-level)</span>
                                                    @else
                                                        <span class="text-muted">None &mdash; uses category-level role from Notifications page</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                                @else
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    No webhooks configured. Add at least one webhook to receive notifications.
                                </div>
                                @endif

                                {{-- Add New Webhook Button --}}
                                <button type="button" class="btn btn-success" onclick="showAddWebhookModal()">
                                    <i class="fas fa-plus"></i> Add Webhook ({{ $webhooks->count() }} configured)
                                </button>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-arrow-right"></i> Next Step: Route Categories to Webhooks</h4>
                                <p>
                                    After adding a webhook, go to the <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a>
                                    section to bind notification categories (POS Fuel, Structure Under Attack, etc.) to this webhook. That's where the master toggle, role
                                    mention, and per-binding role overrides live.
                                </p>
                            </div>
                        </div>

                        {{-- Notifications Section --}}
                        <div id="notifications-section" class="settings-section-pane">
                            @include('structure-manager::notifications._panel')
                        </div>

                        {{-- Notification Routing Map Section --}}
                        <div id="routing-map-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    A read-only snapshot of <strong>where every notification category delivers and which Discord role it pings</strong>,
                                    computed from your current configuration. The role shown is resolved exactly as it is when an alert fires
                                    (per-binding override first, then the category default, then the webhook's own legacy role).
                                    Adjust routing in the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a>
                                    and
                                    <a href="#" class="nav-section-link" data-section="webhooks"><strong>Webhook Configuration</strong></a>
                                    tabs.
                                </p>
                            </div>
                            @include('structure-manager::notifications._routing_map')
                        </div>

                        {{-- POS Settings Section --}}
                        <div id="pos-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    Configure settings for Player Owned Starbases (Control Towers). These settings control notifications,
                                    alert thresholds, and monitoring for legacy POS structures.
                                </p>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-bell"></i> POS Notification Webhooks</h4>

                                <div class="info-banner" style="border-left:4px solid #3498db;">
                                    <i class="fas fa-arrow-left"></i>
                                    <strong>Moved:</strong> Webhooks are now managed on the
                                    <a href="#" class="nav-section-link" data-section="webhooks"><strong>Webhook Configuration</strong></a>
                                    section (first item in the sidebar). They're shared across POS, Upwell, and Structure Events &mdash; no longer POS-specific.
                                    Choose <em>which</em> categories hit which webhooks in the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> section.
                                </div>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-cog"></i> Notification Behavior</h4>

                                <div class="info-banner mb-3">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Note:</strong> Discord role mentions are now configured per-webhook above. Each webhook can have its own role mention for flexible notification routing.
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="pos_fuel_notification_interval">
                                                <i class="fas fa-gas-pump"></i> Fuel/Charter Alert Interval
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_fuel_notification_interval"
                                                       name="pos_fuel_notification_interval"
                                                       value="{{ StructureManager\Models\StructureManagerSettings::get('pos_fuel_notification_interval', 0) }}"
                                                       min="0" max="24" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">hours</span>
                                                </div>
                                            </div>
                                            <small class="help-text">How often to send reminders during critical stage (0 = disabled, status change only)</small>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="pos_strontium_notification_interval">
                                                <i class="fas fa-shield-alt"></i> Strontium Alert Interval
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_strontium_notification_interval"
                                                       name="pos_strontium_notification_interval"
                                                       value="{{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_notification_interval', 0) }}"
                                                       min="0" max="12" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">hours</span>
                                                </div>
                                            </div>
                                            <small class="help-text">How often to send reminders during critical stage (0 = disabled, status change only)</small>
                                        </div>
                                    </div>
                                </div>

                                {{-- Zero Strontium Settings --}}
                                <div class="settings-subsection mt-3">
                                    <h5><i class="fas fa-shield-alt"></i> Zero Strontium Behavior</h5>

                                    <div class="info-banner">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Zero Strontium:</strong> Some POSes are intentionally run with 0 strontium. These settings prevent notification spam.
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <input type="checkbox"
                                                   id="pos_strontium_zero_notify_once"
                                                   name="pos_strontium_zero_notify_once"
                                                   value="1"
                                                   {{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_zero_notify_once', true) ? 'checked' : '' }}>
                                            Only notify once for prolonged zero strontium
                                        </label>
                                        <small class="help-text">
                                            For online POSes with 0 strontium longer than the grace period, only send notifications on status changes (not on intervals)
                                        </small>
                                    </div>

                                    <div class="form-group">
                                        <label for="pos_strontium_zero_grace_period">
                                            <i class="fas fa-clock"></i> Zero Strontium Grace Period
                                        </label>
                                        <div class="input-group" style="max-width: 300px;">
                                            <input type="number"
                                                   class="form-control"
                                                   id="pos_strontium_zero_grace_period"
                                                   name="pos_strontium_zero_grace_period"
                                                   value="{{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_zero_grace_period', 2) }}"
                                                   min="1" max="24" required>
                                            <div class="input-group-append">
                                                <span class="input-group-text">hours</span>
                                            </div>
                                        </div>
                                        <small class="help-text">
                                            Time to wait after first 0 strontium notification before treating it as "intentional" (default: 2 hours)
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-sliders-h"></i> POS Alert Thresholds</h4>

                                <div class="info-banner">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>POS thresholds are configurable per install.</strong>
                                    Wormhole and null-sec POSes often need extended response time
                                    (hours of scouting before defense can form), so defaults may
                                    not fit your ops. Adjust below; every display surface
                                    (list, detail, board, webhooks) reads these live.
                                </div>

                                {{-- Strontium Thresholds --}}
                                <div class="settings-subsection">
                                    <h5><i class="fas fa-shield-alt"></i> Strontium Thresholds</h5>

                                    <div class="info-banner">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Strontium:</strong> Controls reinforcement timer for POSes under attack. Critical threshold should be low enough to respond before destruction risk.
                                    </div>

                                    <div class="threshold-group">
                                        <div class="threshold-item critical">
                                            <label for="pos_strontium_critical_hours">
                                                <i class="fas fa-exclamation-triangle text-danger"></i> Critical Threshold
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_strontium_critical_hours"
                                                       name="pos_strontium_critical_hours"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posStrontiumCritical() }}"
                                                       min="1" max="72" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">hours</span>
                                                </div>
                                            </div>
                                            <small class="help-text">RED alert when below this threshold</small>
                                        </div>

                                        <div class="threshold-item warning">
                                            <label for="pos_strontium_warning_hours">
                                                <i class="fas fa-exclamation text-warning"></i> Warning Threshold
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_strontium_warning_hours"
                                                       name="pos_strontium_warning_hours"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posStrontiumWarning() }}"
                                                       min="1" max="72" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">hours</span>
                                                </div>
                                            </div>
                                            <small class="help-text">YELLOW alert when below this threshold</small>
                                        </div>

                                        <div class="threshold-item good">
                                            <label for="pos_strontium_good_hours">
                                                <i class="fas fa-check-circle text-success"></i> Good Target
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_strontium_good_hours"
                                                       name="pos_strontium_good_hours"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posStrontiumGood() }}"
                                                       min="1" max="72" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">hours</span>
                                                </div>
                                            </div>
                                            <small class="help-text">Target stockpile level for percentage display + recommendations (not an alert gate)</small>
                                        </div>
                                    </div>
                                </div>

                                {{-- Fuel & Charter Thresholds --}}
                                <div class="settings-subsection">
                                    <h5><i class="fas fa-gas-pump"></i> Fuel &amp; Charter Thresholds</h5>

                                    <div class="threshold-group">
                                        <div class="threshold-item critical">
                                            <label for="pos_fuel_critical_days">
                                                <i class="fas fa-exclamation-triangle text-danger"></i> Fuel Critical
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_fuel_critical_days"
                                                       name="pos_fuel_critical_days"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posFuelCritical() }}"
                                                       min="1" max="90" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </div>
                                            <small class="help-text">RED alert for fuel blocks</small>
                                        </div>

                                        <div class="threshold-item warning">
                                            <label for="pos_fuel_warning_days">
                                                <i class="fas fa-exclamation text-warning"></i> Fuel Warning
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_fuel_warning_days"
                                                       name="pos_fuel_warning_days"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posFuelWarning() }}"
                                                       min="1" max="90" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </div>
                                            <small class="help-text">YELLOW alert for fuel blocks</small>
                                        </div>

                                        <div class="threshold-item critical">
                                            <label for="pos_charter_critical_days">
                                                <i class="fas fa-exclamation-triangle text-danger"></i> Charter Critical
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="pos_charter_critical_days"
                                                       name="pos_charter_critical_days"
                                                       value="{{ \StructureManager\Helpers\FuelThresholds::posCharterCritical() }}"
                                                       min="1" max="90" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </div>
                                            <small class="help-text">RED alert for charters (high-sec)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- POS Deprecation Notice --}}
                            <div class="warning-banner">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Note:</strong> Player Owned Starbases (POS) are legacy structures. If CCP removes POS from the game,
                                this entire section can be removed without affecting other Structure Manager features.
                            </div>
                        </div>

                        {{-- Reserves Tracking Section --}}
                        <div id="reserves-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    Configure general reserves tracking settings that apply to all asset types (Upwell Structures and POSes).
                                    Control which corporate hangars are included in fuel reserves calculations.
                                </p>
                            </div>

                            <div class="settings-block">
                                <h4><i class="fas fa-warehouse"></i> Hangar Tracking Configuration</h4>

                                <div class="info-banner">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Hangar Exclusion:</strong> Select which corporate hangars should be EXCLUDED from fuel reserves tracking.
                                    This applies to both Upwell Structures and POSes. Useful for excluding hangars used for market trading, logistics, or other non-fuel purposes.
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-archive"></i> Exclude These Hangars from Reserves Tracking</label>
                                    <small class="help-text d-block mb-2">
                                        Uncheck any hangar that should NOT be counted toward your fuel reserves.
                                        Fuel in excluded hangars will not appear in reserves reports or calculations for ANY asset type.
                                    </small>

                                    @php
                                        $excludedHangars = StructureManager\Models\StructureManagerSettings::get('excluded_hangars', []);
                                        if (is_string($excludedHangars)) {
                                            $excludedHangars = array_filter(array_map('trim', explode(',', $excludedHangars)));
                                        }
                                        $excludedHangars = is_array($excludedHangars) ? $excludedHangars : [];

                                        // Resolve in-game hangar names from corporation_divisions for the
                                        // current user's corp(s). One operator may have characters in
                                        // multiple corps, so we collect distinct names per division and
                                        // join them with " / " in the label.
                                        $hangarNames = [];
                                        try {
                                            $userCorpIds = \StructureManager\Models\Timer::getUserCorpIds(auth()->user());
                                            if (!empty($userCorpIds)) {
                                                $divisionRows = \Illuminate\Support\Facades\DB::table('corporation_divisions')
                                                    ->whereIn('corporation_id', $userCorpIds)
                                                    ->where('type', 'hangar')
                                                    ->select('division', 'name')
                                                    ->get();
                                                foreach ($divisionRows as $row) {
                                                    $div = (int) $row->division;
                                                    if (!isset($hangarNames[$div])) {
                                                        $hangarNames[$div] = [];
                                                    }
                                                    if (!empty($row->name) && !in_array($row->name, $hangarNames[$div], true)) {
                                                        $hangarNames[$div][] = $row->name;
                                                    }
                                                }
                                            }
                                        } catch (\Throwable $e) {
                                            // Graceful fallback — labels just show "Division X / CorpSAGX".
                                        }
                                    @endphp

                                    <div class="hangar-checkbox-group">
                                        @for($i = 1; $i <= 7; $i++)
                                        <div class="hangar-checkbox-item">
                                            <input type="checkbox"
                                                   id="hangar_{{ $i }}"
                                                   name="excluded_hangars[]"
                                                   value="{{ $i }}"
                                                   {{ in_array((string)$i, $excludedHangars) ? '' : 'checked' }}>
                                            <label for="hangar_{{ $i }}">
                                                <span class="hangar-label-main">
                                                    <i class="fas fa-boxes"></i> Division {{ $i }}
                                                </span>
                                                @if(!empty($hangarNames[$i]))
                                                    <span class="hangar-custom-name" title="In-game hangar name">
                                                        {{ implode(' / ', $hangarNames[$i]) }}
                                                    </span>
                                                @endif
                                                <span class="hangar-sag-label" title="Location flag used by ESI">CorpSAG{{ $i }}</span>
                                            </label>
                                        </div>
                                        @endfor
                                    </div>

                                    <small class="help-text mt-2">
                                        <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> If a hangar is checked, its fuel will be tracked.
                                        If unchecked, the hangar will be excluded from tracking for all asset types.
                                    </small>
                                </div>

                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Note:</strong> Changes to hangar tracking will apply on the next fuel tracking update (runs every hour).
                                    Excluded hangars will still show in your corporation assets, they just won't count toward fuel reserves.
                                </div>
                            </div>
                        </div>

                        {{-- Upwell Structures Section --}}
                        <div id="upwell-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    Configure fuel alert thresholds and notification behavior for Upwell Structures (Citadels, Engineering Complexes, Refineries, Metenox Moon Drills).
                                    These thresholds are independent from POS settings.
                                </p>
                            </div>

                            {{-- Fuel Alert Thresholds (locked) --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-gas-pump"></i> Upwell Fuel Alert Thresholds</h4>

                                <div class="info-banner mb-3">
                                    <i class="fas fa-lock"></i>
                                    <strong>Alert thresholds are locked at sane defaults</strong> so every display surface
                                    (Upwell list, structure detail, Critical Alerts, Structure Board, webhooks) agrees on
                                    what counts as critical / warning. Cadence settings (interval between repeats during
                                    critical) stay configurable below.
                                </div>

                                <div class="settings-subsection">
                                    <dl class="row mb-0">
                                        <dt class="col-sm-6"><i class="fas fa-exclamation-circle text-danger"></i> Critical</dt>
                                        <dd class="col-sm-6"><strong>&lt; {{ \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS }} days</strong> of fuel remaining</dd>
                                        <dt class="col-sm-6"><i class="fas fa-exclamation-triangle text-warning"></i> Warning</dt>
                                        <dd class="col-sm-6"><strong>&lt; {{ \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_WARNING_DAYS }} days</strong> of fuel remaining</dd>
                                        <dt class="col-sm-6"><i class="fas fa-bell text-secondary"></i> Final Alert</dt>
                                        <dd class="col-sm-6">Fires when fuel drops to <strong>1 hour or less</strong>. Re-arms on recovery.</dd>
                                    </dl>
                                </div>
                            </div>

                            {{-- Stale Structure Visibility --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-eye-slash"></i> Stale Structure Visibility</h4>

                                <div class="info-banner mb-3">
                                    <i class="fas fa-info-circle"></i>
                                    When a corporation removes its ESI key, SeAT can no longer refresh
                                    that corp's structures, so their data freezes and the UI would
                                    otherwise show a nonsensical "-142 days remaining" alert. Upwell
                                    structures whose ESI data is older than the threshold below are
                                    hidden from the Upwell Structures list, Critical Alerts, and the
                                    Logistics Report.
                                </div>

                                <div class="info-banner mb-3" style="border-left:4px solid #3498db;">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>POS towers are exempt.</strong> A starbase row only changes
                                    when the tower's state or settings change, so a stable or offline
                                    POS keeps a static row and its "last refreshed" timestamp freezes
                                    even while ESI polling is perfectly healthy. Control Towers stay
                                    visible regardless of age; use the tower's state (online / offline
                                    / reinforced) to judge activity instead.
                                </div>

                                <div class="form-group">
                                    <label for="stale_structure_threshold_days">
                                        <i class="fas fa-clock"></i> Hide Upwell structures not refreshed in
                                    </label>
                                    <div class="input-group" style="max-width: 300px;">
                                        <input type="number"
                                               class="form-control"
                                               id="stale_structure_threshold_days"
                                               name="stale_structure_threshold_days"
                                               value="{{ StructureManager\Models\StructureManagerSettings::get('stale_structure_threshold_days', \StructureManager\Helpers\FuelThresholds::STALE_STRUCTURE_THRESHOLD_DAYS_DEFAULT) }}"
                                               min="0" max="365">
                                        <div class="input-group-append">
                                            <span class="input-group-text">days</span>
                                        </div>
                                    </div>
                                    <small class="help-text">
                                        Default: 30 days. Set to <strong>0</strong> to disable hiding (show every Upwell structure no matter how stale its data is). POS towers are never hidden by this setting.
                                    </small>
                                </div>
                            </div>

                            {{-- Notification Behavior --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-bell"></i> Notification Behavior</h4>

                                <div class="form-group">
                                    <label for="upwell_fuel_notification_interval">
                                        <i class="fas fa-clock"></i>
                                        Critical Stage Reminder Interval (hours)
                                    </label>
                                    <input type="number"
                                           class="form-control"
                                           id="upwell_fuel_notification_interval"
                                           name="upwell_fuel_notification_interval"
                                           value="{{ \StructureManager\Models\StructureManagerSettings::get('upwell_fuel_notification_interval', 0) }}"
                                           min="0" max="24">
                                    <small class="form-text text-muted">
                                        How often to send reminder alerts while a structure is in critical stage. Set to <strong>0</strong> to disable
                                        interval reminders and only receive status-change alerts (recommended). Range: 0-24 hours.
                                    </small>
                                </div>

                                <div class="info-banner">
                                    <i class="fas fa-link"></i>
                                    <strong>Shared webhooks:</strong> Upwell notifications use the same webhooks configured on the
                                    <a href="#" class="nav-section-link" data-section="webhooks"><strong>Webhook Configuration</strong></a>
                                    section. Bind the <code>upwell.fuel</code> / <code>upwell.magmatic_gas</code> categories to those webhooks in the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> section.
                                </div>
                            </div>

                            {{-- Metenox Information --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-moon"></i> Metenox Dual-Fuel Intelligence</h4>

                                <div class="info-banner" style="border-left-color: #9b59b6;">
                                    <i class="fas fa-info-circle" style="color: #9b59b6;"></i>
                                    <strong>Automatic Metenox support:</strong> Metenox Moon Drills consume both fuel blocks and magmatic gas.
                                    Notifications automatically detect which resource is the limiting factor and will alert based on whichever runs out first.
                                    The embed shows both resources with remaining days and highlights the limiting factor.
                                    <strong>No additional configuration is needed</strong> &mdash; Metenox structures are detected by their type ID and
                                    handled with dual-fuel logic automatically.
                                </div>
                            </div>
                        </div>

                        {{-- Structure Events Section (ESI Notifications) --}}
                        <div id="events-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    Detection mode for ESI-driven structure events (attacks, anchoring, destroyed, low power).
                                    Category toggles, webhook bindings and role mentions live in the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> section.
                                </p>
                            </div>

                            {{-- Detection mode: explicit operator choice --}}
                            @php($mcAvailable    = \StructureManager\Integrations\ManagerCoreIntegration::isAvailable())
                            @php($configuredMode = \StructureManager\Integrations\ManagerCoreIntegration::detectionMode())
                            @php($effectiveFast  = \StructureManager\Integrations\ManagerCoreIntegration::isFastPollEnabled())
                            @php($effectiveSweep = \StructureManager\Integrations\ManagerCoreIntegration::isNativeSweepEnabled())

                            <div class="settings-block">
                                <h4><i class="fas fa-satellite-dish"></i> Detection Mode</h4>

                                @if($mcAvailable)
                                    <div class="info-banner mb-3" style="border-left:4px solid #28a745;">
                                        <i class="fas fa-bolt"></i>
                                        <strong>Manager Core detected</strong> &mdash; fast ESI polling is available.
                                        By default it's used (~2 minute detection). You can opt out below if you prefer SeAT's native flow.
                                        <div class="mt-2">
                                            <a href="{{ route('manager-core.esi-key-pool.index') }}" class="btn btn-primary btn-sm">
                                                <i class="fas fa-key"></i> Manage shared key pool in Manager Core
                                            </a>
                                        </div>
                                    </div>
                                @else
                                    <div class="info-banner mb-3" style="border-left:4px solid #ffc107;">
                                        <i class="fas fa-clock"></i>
                                        <strong>Manager Core not installed</strong> &mdash; using SeAT's native notification flow.
                                        Detection speed: ~20&ndash;30 minutes (SeAT's bucket cadence).
                                        Install Manager Core to unlock 2-minute detection and share the key pool with other plugins.
                                        <div class="mt-2">
                                            <a href="https://github.com/MattFalahe/Manager-Core" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                                                <i class="fab fa-github"></i> Install Manager Core
                                            </a>
                                        </div>
                                    </div>
                                @endif

                                <div class="form-group">
                                    <label for="esi_detection_mode">ESI detection mode</label>
                                    <select class="form-control" id="esi_detection_mode" name="esi_detection_mode" style="max-width:480px;">
                                        <option value="auto"        @if($configuredMode === 'auto') selected @endif>
                                            Auto &mdash; use Manager Core fast-poll when available, fall back to SeAT native otherwise (recommended)
                                        </option>
                                        <option value="seat_native" @if($configuredMode === 'seat_native') selected @endif>
                                            SeAT native only &mdash; ignore Manager Core fast-poll even if installed (slower; ~20&ndash;30 min)
                                        </option>
                                        <option value="off"         @if($configuredMode === 'off') selected @endif>
                                            Off &mdash; do not detect ESI structure events (disables shield/armor/hull/destroyed alerts)
                                        </option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Effective mode right now:
                                        @if($effectiveFast)
                                            <span class="badge badge-success">fast_poll (Manager Core, ~2 min)</span>
                                        @elseif($effectiveSweep)
                                            <span class="badge badge-warning">native_sweep (SeAT, ~20&ndash;30 min)</span>
                                        @else
                                            <span class="badge badge-danger">off (no detection)</span>
                                        @endif
                                        @if($mcAvailable && $configuredMode === 'seat_native')
                                            <br><strong>Note:</strong> Manager Core is installed but you've opted out of fast-poll. SM falls back to SeAT's native sweep, which is slower but keeps all polling inside SeAT's own infrastructure (no director keys in MC's shared pool).
                                        @endif
                                        @if($configuredMode === 'off')
                                            <br><strong class="text-danger">Warning:</strong> All ESI-driven structure event detection is disabled. Fuel alerts (poll-based) still fire, but shield/armor/hull/destroyed events from CCP notifications will not be processed.
                                        @endif
                                    </small>
                                </div>

                                {{-- Worker-restart caveat. SM registers its ESI handlers with Manager Core's
                                     EsiNotificationRegistry at framework boot, and that registry lives in
                                     each worker process's memory (per-process singleton). Changing the
                                     detection mode here updates the DB instantly, but the queue worker
                                     already booted with the previous mode and won't re-evaluate the gate
                                     until it boots fresh. There is no in-memory unregister path either,
                                     so a flag-flip alone cannot revoke an already-registered handler.
                                     A restart of the SeAT containers is the cleanest way to apply mode
                                     changes uniformly across all worker processes. Documented in Help
                                     & Documentation > Notifications. --}}
                                <div class="alert alert-warning mt-3" style="max-width:680px;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Restart the SeAT stack after changing mode.</strong>
                                    Mode changes save to the database instantly, but the queue worker
                                    process already booted with the previous mode in memory. Take the
                                    stack down and back up to apply the new mode uniformly (pass all
                                    three compose files, or use your container orchestrator's equivalent):
                                    <pre style="margin:6px 0; white-space:pre-wrap;">docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d</pre>
                                    <details style="margin-top:6px;">
                                        <summary style="cursor:pointer; font-size:0.9em;">Why isn't there a "re-register" button for this?</summary>
                                        <p style="margin-top:6px; font-size:0.9em;">
                                            The pricing preference under the Economics tab has a re-register
                                            button because pricing preferences live in a Manager Core database
                                            table (persistent across processes). ESI notification handlers, by
                                            contrast, register into an in-memory registry that is per-process.
                                            A button in this web request would only re-register inside the web
                                            process, not the queue workers that actually run the polling jobs.
                                            Manager Core also does not expose an unregister call, so flipping
                                            modes from Auto to SeAT Native cannot revoke a registration
                                            previously made at boot. Worker restart is the cleanest answer.
                                        </p>
                                    </details>
                                </div>
                            </div>

                            {{-- Notification categories moved to Notifications section --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-bell"></i> Notification Categories &amp; Webhooks</h4>
                                <div class="info-banner mb-3" style="border-left:4px solid #3498db;">
                                    <i class="fas fa-arrow-right"></i>
                                    Moved to the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> section.
                                    That section shows per-category master toggles, default role mentions (with dropdown from
                                    seat-connector / seat-discord-connector when installed), and per-webhook role overrides.
                                </div>
                            </div>

                            {{-- Attack Role Mention --}}
                            <div class="settings-block">
                                <h4><i class="fas fa-at"></i> Attack Alert Role Mention</h4>

                                <div class="form-group" style="max-width:560px;">
                                    <label for="esi_attack_role_mention">Discord Role Mention for Attack Alerts</label>
                                    <div style="display:flex; gap:6px; align-items:stretch;">
                                        <input type="text" class="form-control" id="esi_attack_role_mention"
                                               name="esi_attack_role_mention"
                                               value="{{ \StructureManager\Models\StructureManagerSettings::get('esi_attack_role_mention', '') }}"
                                               placeholder="<@&123456789> or raw role ID"
                                               style="flex:1;">
                                        @if($roleProviderAvailable)
                                            <button type="button"
                                                    class="btn btn-sm btn-secondary js-toggle-inline-role-picker"
                                                    data-picker-id="inlineRolePickerEsiAttack"
                                                    data-input-id="esi_attack_role_mention"
                                                    title="Pick from {{ $roleProviderLabel }}"
                                                    style="white-space:nowrap;">
                                                <i class="fas fa-tag"></i> Pick from Discord
                                            </button>
                                        @endif
                                    </div>
                                    <small class="form-text text-muted">
                                        Separate role mention for attack alerts (structure under attack, destroyed).
                                        If empty, falls back to each webhook's own role mention setting.
                                        Format: <code>&lt;@&amp;ROLE_ID&gt;</code> or just the numeric role ID.
                                        @if($roleProviderAvailable)
                                            <br><strong>Detected providers:</strong> {{ $roleProviderLabel }} &mdash; use the picker to grab a role without manual ID copy.
                                        @endif
                                    </small>

                                    {{-- Inline role picker for the Events tab. Same pattern as the
                                         webhook modal's inline picker (commit 0a12a9c) so we get
                                         consistent UX across all role-mention inputs in SM. --}}
                                    @if($roleProviderAvailable)
                                        <div id="inlineRolePickerEsiAttack" class="inline-role-picker"
                                             style="display:none; margin-top:10px; padding:12px; background:#1e222b;
                                                    border:1px solid #454d55; border-radius:4px; max-height:380px;
                                                    overflow-y:auto;">
                                            <div class="inline-role-picker-body">
                                                <div style="text-align:center; color:#8b95a5; padding:1rem;">
                                                    <i class="fas fa-spinner fa-spin"></i> Loading roles...
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- =========================================================
                                 Pre-Timer Reminders (v2.1)
                                 Scheduled T-24h / T-6h / T-1h Discord pings before a
                                 reinforce or sov timer expires. Requires Manager Core
                                 (handler subscribes to MC's EventBus for scheduled
                                 timer.upcoming_* events).

                                 Per-event-type routing lives in the Notifications
                                 panel via six dedicated categories:
                                   events.pre_timer_armor    — armor reinforced
                                   events.pre_timer_hull     — hull reinforced
                                   events.pre_timer_sov      — sov reinforced
                                   events.pre_timer_nodes    — command nodes spawning
                                   events.pre_timer_hostile  — hostile op (opt-in)
                                   events.pre_timer_defense  — defense op (opt-in)

                                 Each category has its own master toggle, default
                                 role mention, and per-binding overrides through
                                 the existing Notifications UI — so an admin can
                                 send sov reminders to a sov-fleet channel with
                                 @SovFC and armor reminders to a different channel
                                 with @StructureFC without any new UI surface.

                                 This block keeps only the master kill-switch
                                 (pre_timer_reminders_enabled) for "all reminders
                                 off in one click" — granular routing is on the
                                 Notifications panel.

                                 See Help & Documentation > Notifications >
                                 "Pre-Timer Reminder Pings" for the full reasoning.
                            ========================================================= --}}
                            @php($preTimerEnabled = (bool) \StructureManager\Models\StructureManagerSettings::get('pre_timer_reminders_enabled', true))

                            <div class="settings-block">
                                <h4><i class="fas fa-stopwatch"></i> Pre-Timer Reminder Pings
                                    <span class="badge" style="background:#4338ca; color:#e0e7ff; font-size:0.7em; padding:0.2em 0.5em; vertical-align:middle;">v2.0.0</span>
                                </h4>

                                @if($mcAvailable)
                                    <div class="info-banner mb-3" style="border-left:4px solid #4338ca;">
                                        <i class="fas fa-bell"></i>
                                        Fires scheduled Discord pings at <strong>T-24h</strong>, <strong>T-6h</strong>, and <strong>T-1h</strong>
                                        before a structure timer expires. Lets fleet leadership plan doctrine, roster, and ammo
                                        on a predictable schedule.
                                        Reminders are <strong>separate from the under-attack alert</strong> (which still fires immediately).
                                    </div>
                                @else
                                    <div class="info-banner mb-3" style="border-left:4px solid #ffc107;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Manager Core required.</strong> Pre-timer reminders use MC's EventBus to schedule the pings.
                                        Install Manager Core to unlock this feature; under-attack alerts still work without it.
                                    </div>
                                @endif

                                <div class="form-group" style="max-width:560px;">
                                    <div class="custom-control custom-switch">
                                        {{-- Plain checkbox — no hidden-input shadow. Controller
                                             explicitly maps absence-from-request to "0" for this
                                             key in the booleanToggleKeys block in update(). --}}
                                        <input type="checkbox" class="custom-control-input"
                                               id="pre_timer_reminders_enabled"
                                               name="pre_timer_reminders_enabled"
                                               value="1"
                                               {{ $preTimerEnabled ? 'checked' : '' }}
                                               {{ $mcAvailable ? '' : 'disabled' }}>
                                        <label class="custom-control-label" for="pre_timer_reminders_enabled">
                                            <strong>Enable pre-timer reminders</strong> (master switch)
                                        </label>
                                    </div>
                                    <small class="form-text text-muted">
                                        When off, no reminders fire regardless of category bindings. Default: on.
                                        For granular control over which event types fire reminders and where they go, see the per-category
                                        toggles + webhook bindings below.
                                    </small>
                                </div>

                                <div class="info-banner" style="border-left:4px solid #3498db;">
                                    <i class="fas fa-route"></i>
                                    <strong>Per-event-type routing:</strong> Each timer event type has its own
                                    notification category, so you can route sov reminders to <code>#sov-fleet</code>
                                    with <code>@SovFC</code>, hull reminders to <code>#all-hands</code> with
                                    <code>@everyone</code>, and so on. Configure on the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a>
                                    section — look for the six categories prefixed <code>Pre-Timer Reminder:</code>.
                                    <br>
                                    <small style="display:block; margin-top:6px; color:#9ca3af;">
                                        Defaults: combat reminders (armor / hull / sov / command nodes) are <strong>enabled</strong> and
                                        auto-bound to your existing structure_attack webhooks. Manual-op reminders (hostile / defense) are
                                        <strong>disabled</strong> — toggle them on if you schedule manual ops and want auto-pre-pings.
                                    </small>
                                </div>
                            </div>

                            {{-- =========================================================
                                 Attacker Threat Intel (v2.2)
                                 Opt-in async zKillboard enrichment. When ON, each
                                 under-attack alert dispatches a fire-and-forget job
                                 that queries zKB for the attacker's profile and posts
                                 a separate "who is shooting you" embed via the
                                 events.attacker_threat_intel category. Standalone
                                 (no MC required). Default OFF — operators opt in
                                 explicitly because of the external HTTP call to zKB.
                            ========================================================= --}}
                            @php($threatIntelEnabled = (bool) \StructureManager\Models\StructureManagerSettings::get('attacker_threat_intel_enabled', false))

                            <div class="settings-block">
                                <h4><i class="fas fa-search"></i> Attacker Threat Intel (zKillboard)
                                    <span class="badge" style="background:#4338ca; color:#e0e7ff; font-size:0.7em; padding:0.2em 0.5em; vertical-align:middle;">v2.0.0</span>
                                    <span class="badge" style="background:#1c6f3e; color:#d4f4e2; font-size:0.7em; padding:0.2em 0.5em; vertical-align:middle;">STANDALONE</span>
                                </h4>

                                <div class="info-banner mb-3" style="border-left:4px solid #4338ca;">
                                    <i class="fas fa-info-circle"></i>
                                    When enabled, every attack notification (structure under attack, shield/armor reinforce, sov reinforce, entosis) fires
                                    a <strong>separate follow-up Discord embed</strong> ~1-2 seconds after the primary alert
                                    with the attacker's threat profile from zKillboard: recent kill count, top-flown ship,
                                    danger ratio, and a tier label ("Professional", "Active", "Dormant", etc.).
                                    The follow-up is dispatched <strong>asynchronously</strong>; it never delays the primary alert.
                                    Operators bind it to a separate Discord channel (e.g. <code>#intel</code>) on the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a>
                                    tab via the <code>events.attacker_threat_intel</code> category.
                                </div>

                                <div class="form-group" style="max-width:560px;">
                                    <div class="custom-control custom-switch">
                                        {{-- Plain checkbox — no hidden-input shadow. Controller
                                             explicitly maps absence-from-request to "0" for this
                                             key in the booleanToggleKeys block in update(). --}}
                                        <input type="checkbox" class="custom-control-input"
                                               id="attacker_threat_intel_enabled"
                                               name="attacker_threat_intel_enabled"
                                               value="1"
                                               {{ $threatIntelEnabled ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="attacker_threat_intel_enabled">
                                            <strong>Enable attacker threat intel</strong> (queries zKillboard on each attack alert)
                                        </label>
                                    </div>
                                    <small class="form-text text-muted">
                                        Default: off. When on, each attack alert triggers an external HTTP call to <code>zkillboard.com</code>.
                                        Profiles cache for 7 days, so repeat attackers don't re-hit zKB. Fails open: if zKB is unreachable, the primary alert is unaffected and the threat intel embed is skipped silently.
                                        <br><br>
                                        <strong>What zKB receives:</strong> only the attacker's character ID (public information, already shown on every killmail).
                                        <strong>What zKB returns:</strong> public stats from their public stats endpoint. No defender or corp data leaves SeAT.
                                    </small>
                                </div>

                                <div class="info-banner" style="border-left:4px solid #3498db;">
                                    <i class="fas fa-route"></i>
                                    <strong>Routing:</strong> Bind <code>events.attacker_threat_intel</code> to your intel channel(s) on the
                                    <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> tab.
                                    The category is <em>disabled</em> and <em>unbound</em> by default — operator decides where intel embeds land.
                                </div>
                            </div>

                            <div class="info-banner">
                                <i class="fas fa-link"></i>
                                <strong>Shared webhooks:</strong> Structure event notifications use the same webhooks configured on the
                                <a href="#" class="nav-section-link" data-section="webhooks"><strong>Webhook Configuration</strong></a>
                                section. Bind the <code>events.*</code> categories to those webhooks in the
                                <a href="#" class="nav-section-link" data-section="notifications"><strong>Notifications</strong></a> section,
                                where per-category role mentions override any legacy webhook role.
                            </div>
                        </div>

                        {{-- =================== ECONOMICS SECTION =================== --}}
                        <div id="economics-section" class="settings-section-pane">
                            <div class="tab-description">
                                <p>
                                    <i class="fas fa-info-circle"></i>
                                    The Fuel Economics page shows weekly / monthly / quarterly / yearly fuel ISK across your structures,
                                    with breakdowns per system, per structure, and per fuel type. Requires Manager Core for pricing.
                                </p>
                            </div>

                            @php($mcPricingAvailable = \StructureManager\Integrations\ManagerCoreIntegration::isPricingAvailable())
                            @php($economicsMode      = \StructureManager\Integrations\ManagerCoreIntegration::economicsPricingMode())
                            @php($economicsEnabled   = \StructureManager\Integrations\ManagerCoreIntegration::isEconomicsEnabled())
                            @php($currentPref        = null)
                            @php($adminOverridden    = false)
                            @if($mcPricingAvailable && class_exists('\ManagerCore\Models\PricingPreference'))
                                @php($currentPref = \ManagerCore\Models\PricingPreference::forPlugin('structure-manager'))
                                @php($adminOverridden = $currentPref ? (bool) $currentPref->admin_overridden : false)
                            @endif

                            {{-- ============== WHEN MANAGER CORE IS NOT INSTALLED ============== --}}
                            @if(!$mcPricingAvailable)
                                <div class="settings-block">
                                    <h4><i class="fas fa-puzzle-piece"></i> Manager Core required</h4>

                                    <div class="info-banner mb-3" style="border-left:4px solid #ffc107;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Manager Core is not installed.</strong>
                                        Without it, Structure Manager has no way to price fuel blocks, magmatic gas, strontium, or charters in ISK.
                                        The Fuel Economics page is hidden from your sidebar until Manager Core is installed.
                                    </div>

                                    <div class="settings-block" style="background: rgba(102, 126, 234, 0.05); border-left: 3px solid #667eea; padding: 1rem;">
                                        <h5 style="margin-top:0;"><i class="fas fa-coins"></i> What Manager Core adds for Structure Manager</h5>
                                        <ul style="margin-bottom: 0.5rem;">
                                            <li><strong>Fuel Economics page</strong> with weekly / monthly / quarterly / yearly ISK projections.</li>
                                            <li><strong>Per-system, per-structure, per-fuel-type</strong> cost breakdowns to find your most expensive deployments.</li>
                                            <li><strong>Daily ISK trend chart</strong> across the look-back window (90 / 180 / 365 days).</li>
                                            <li><strong>Services-offline detection</strong> with ISK-equivalent of unused capacity per structure.</li>
                                            <li><strong>2-minute ESI fast-poll</strong> for structure attack alerts (instead of SeAT's ~15-20 min native cadence). Optional, separate from pricing.</li>
                                            <li><strong>Shared key pool + cross-plugin event bus</strong> used by other Mining Manager / Pings / future plugins.</li>
                                        </ul>
                                        <p style="margin-bottom:0; color:#9aa3b3; font-size:0.85rem;">
                                            Manager Core is a free SeAT plugin. Structure Manager works fully without it (notifications, fuel tracking, structure board)
                                            but the Economics page and fast-poll require it.
                                        </p>
                                    </div>

                                    <div class="mt-3">
                                        <a href="https://github.com/MattFalahe/Manager-Core" target="_blank" rel="noopener" class="btn btn-primary">
                                            <i class="fab fa-github"></i> Install Manager Core
                                        </a>
                                    </div>
                                </div>
                            @else
                            {{-- ============== WHEN MANAGER CORE IS INSTALLED ============== --}}
                                <div class="settings-block">
                                    <h4><i class="fas fa-coins"></i> Pricing Integration</h4>

                                    <div class="info-banner mb-3" style="border-left:4px solid #28a745;">
                                        <i class="fas fa-check-circle"></i>
                                        <strong>Manager Core pricing detected.</strong>
                                        Structure Manager can compute fuel costs in ISK for the Economics page.
                                    </div>

                                    {{-- Current registration status --}}
                                    <div class="settings-block" style="background:#1f242c; border:1px solid #454d55; padding: 0.8rem 1rem; border-radius: 0.3rem;">
                                        <h5 style="margin-top:0; font-size:1rem;"><i class="fas fa-tag"></i> Current registration</h5>
                                        @if($currentPref)
                                            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                                                <span style="font-family:monospace; color:#dfe3eb;">
                                                    {{ strtoupper($currentPref->price_type) }} on {{ strtoupper($currentPref->market) }}
                                                </span>
                                                @if($adminOverridden)
                                                    <span class="badge" style="background:#7a5a0f; color:#fff1c7; padding:0.25rem 0.5rem;">
                                                        <i class="fas fa-user-shield"></i> ADMIN OVERRIDE
                                                    </span>
                                                @else
                                                    <span class="badge" style="background:#1c6f3e; color:#d4f4e2; padding:0.25rem 0.5rem;">
                                                        <i class="fas fa-cube"></i> PLUGIN DEFAULT
                                                    </span>
                                                @endif
                                                <a href="{{ url('manager-core/pricing-preferences') }}" style="margin-left:auto; font-size:0.85rem; color:#667eea;">
                                                    <i class="fas fa-external-link-alt"></i> Override in Manager Core
                                                </a>
                                            </div>
                                            @if($currentPref->notes)
                                                <small class="text-muted d-block mt-2">{{ $currentPref->notes }}</small>
                                            @endif
                                        @else
                                            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                                                <span class="badge" style="background:#7a1d2b; color:#fbd5db; padding:0.25rem 0.5rem;">
                                                    <i class="fas fa-times-circle"></i> NOT REGISTERED
                                                </span>
                                                <span style="color:#9aa3b3; font-size:0.9rem;">
                                                    SM hasn't registered a preference yet. Click "Re-register now" below.
                                                </span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Re-register button (separate form so it doesn't submit the main settings form) --}}
                                    <form method="POST" action="{{ route('structure-manager.settings.economics.re-register') }}" class="mt-3" style="display:inline-block;">
                                        @csrf
                                        <button type="submit" class="btn btn-primary"
                                                @if($economicsMode === \StructureManager\Integrations\ManagerCoreIntegration::ECONOMICS_MODE_DISABLED) disabled title="Switch mode to Auto and save to enable" @endif>
                                            <i class="fas fa-sync-alt"></i> Re-register now
                                        </button>
                                        <small class="text-muted d-block mt-2">
                                            Manually re-fires the registration call. Useful if the boot-time registration silently failed
                                            (e.g. SM service provider booted before Manager Core's). Idempotent: re-clicking is harmless.
                                            Admin overrides set in Manager Core's Pricing Preferences page are preserved.
                                        </small>
                                    </form>
                                </div>

                                {{-- Mode dropdown (lives inside the main settings form, gets saved on Save Settings) --}}
                                <div class="settings-block">
                                    <h4><i class="fas fa-toggle-on"></i> Integration Mode</h4>

                                    <div class="form-group">
                                        <label for="economics_pricing_mode">Economics page integration</label>
                                        <select class="form-control" id="economics_pricing_mode" name="economics_pricing_mode" style="max-width:480px;">
                                            <option value="auto"     @if($economicsMode === 'auto') selected @endif>
                                                Auto &mdash; register with Manager Core at boot, show Economics page in sidebar (recommended)
                                            </option>
                                            <option value="disabled" @if($economicsMode === 'disabled') selected @endif>
                                                Disabled &mdash; do not register, hide Economics page even though Manager Core is installed
                                            </option>
                                        </select>
                                        <small class="form-text text-muted">
                                            Effective right now:
                                            @if($economicsEnabled)
                                                <span class="badge" style="background:#1c6f3e; color:#d4f4e2; padding:0.2rem 0.4rem;">ENABLED</span>
                                                Sidebar shows the entry, registration runs at boot.
                                            @else
                                                <span class="badge" style="background:#7a5a0f; color:#fff1c7; padding:0.2rem 0.4rem;">DISABLED</span>
                                                Sidebar hides the entry, registration is skipped. Existing MC row (if any) is left untouched.
                                            @endif
                                        </small>
                                    </div>
                                </div>

                                <div class="info-banner">
                                    <i class="fas fa-link"></i>
                                    <strong>Pricing market and price type</strong> (Jita / Amarr / etc., sell / buy / avg)
                                    are configured per consumer plugin in
                                    <a href="{{ url('manager-core/pricing-preferences') }}"><strong>Manager Core &rsaquo; Pricing Preferences</strong></a>.
                                    Structure Manager's plugin default is <strong>Jita SELL</strong>.
                                </div>
                            @endif
                        </div>

                    </div>{{-- /.card-body --}}

                    <div class="card-footer action-buttons">
                        <button type="submit" class="btn btn-sm-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <button type="button"
                                class="btn btn-warning"
                                onclick="if (confirm('Are you sure you want to reset all settings to defaults?')) { document.getElementById('structure-manager-reset-form').submit(); }">
                            <i class="fas fa-undo"></i> Reset to Defaults
                        </button>
                        <a href="{{ route('structure-manager.index') }}" class="btn btn-sm-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>{{-- /.card.card-dark --}}
            </div>{{-- /.settings-content --}}
        </div>{{-- /.settings-wrapper --}}
    </form>

    {{-- Separate reset form kept outside the main settings form because nested forms are invalid HTML --}}
    <form id="structure-manager-reset-form" method="POST" action="{{ route('structure-manager.settings.reset') }}" style="display:none;">
        @csrf
    </form>

</div>{{-- /.structure-manager-wrapper --}}

{{-- Modal for Add/Edit Webhook --}}
<div class="modal fade" id="webhookModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="webhookModalTitle">Add Webhook</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form id="webhookForm" method="POST">
                @csrf
                <input type="hidden" name="_method" id="webhookMethod" value="POST">

                <div class="modal-body">
                    <div class="form-group">
                        <label for="webhook_url">Webhook URL *</label>
                        <input type="url"
                               class="form-control"
                               id="webhook_url"
                               name="webhook_url"
                               required
                               placeholder="https://discord.com/api/webhooks/...">
                        <small class="text-muted">Discord or Slack webhook URL</small>
                    </div>

                    <div class="form-group">
                        <label for="webhook_corporation_id">Corporation Filter</label>
                        <select class="form-control" id="webhook_corporation_id" name="corporation_id">
                            <option value="">All Corporations</option>
                            @foreach($corporations as $corp)
                                <option value="{{ $corp->corporation_id }}">
                                    {{ $corp->name }} (ID: {{ $corp->corporation_id }})
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">
                            Select a corporation to only receive notifications for that corporation, or leave as "All Corporations"
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="webhook_description">Description (Optional)</label>
                        <input type="text"
                               class="form-control"
                               id="webhook_description"
                               name="description"
                               maxlength="255"
                               placeholder="e.g., Main Alliance Discord">
                        <small class="text-muted">A label to help you identify this webhook</small>
                    </div>

                    <div class="form-group">
                        <label for="webhook_role_mention">Discord Role Mention (Optional)</label>
                        <div style="display:flex; gap:6px; align-items:stretch;">
                            <input type="text"
                                   class="form-control"
                                   id="webhook_role_mention"
                                   name="role_mention"
                                   maxlength="100"
                                   placeholder="<@&123456789> or 123456789"
                                   style="flex:1;">
                            @if($roleProviderAvailable)
                                <button type="button"
                                        class="btn btn-sm btn-secondary js-toggle-inline-role-picker"
                                        data-picker-id="inlineRolePickerWebhook"
                                        data-input-id="webhook_role_mention"
                                        title="Pick from {{ $roleProviderLabel }}"
                                        style="white-space:nowrap;">
                                    <i class="fas fa-tag"></i> Pick from Discord
                                </button>
                            @endif
                        </div>
                        <small class="text-muted">
                            Discord role mention for critical alerts. Format: <code>&lt;@&amp;ROLE_ID&gt;</code> or just the role ID number.<br>
                            @if($roleProviderAvailable)
                                <strong>Detected providers:</strong> {{ $roleProviderLabel }} &mdash; click <em>Pick from Discord</em> to choose a role without typing the ID.<br>
                            @endif
                            <strong>Manual entry tip:</strong> Enable Developer Mode in Discord, right-click role &rarr; Copy ID.
                        </small>

                        {{-- Inline role picker — lives INSIDE the webhook modal so we avoid
                             the Bootstrap 4 stacked-modal trap entirely (no modal-on-modal,
                             no z-index gymnastics, no backdrop conflicts). Click the
                             "Pick from Discord" button above to expand; click a role to
                             write its mention back into the role_mention input. Loads
                             roles via the same AJAX endpoint as the categories panel
                             picker (one source of truth for role data). --}}
                        @if($roleProviderAvailable)
                            <div id="inlineRolePickerWebhook" class="inline-role-picker"
                                 style="display:none; margin-top:10px; padding:12px; background:#1e222b;
                                        border:1px solid #454d55; border-radius:4px; max-height:380px;
                                        overflow-y:auto;">
                                <div class="inline-role-picker-body">
                                    <div style="text-align:center; color:#8b95a5; padding:1rem;">
                                        <i class="fas fa-spinner fa-spin"></i> Loading roles...
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox"
                                   id="webhook_enabled"
                                   name="enabled"
                                   checked>
                            Enabled
                        </label>
                        <small class="text-muted">Uncheck to disable this webhook without deleting it</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Webhook
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('javascript')
<script>
function showAddWebhookModal() {
    $('#webhookModalTitle').text('Add Webhook');
    $('#webhookForm').attr('action', '{{ route("structure-manager.webhook.add") }}');
    $('#webhookMethod').val('POST');
    $('#webhook_url').val('');
    $('#webhook_corporation_id').val('');
    $('#webhook_description').val('');
    $('#webhook_role_mention').val('');
    $('#webhook_enabled').prop('checked', true);
    $('#webhookModal').modal('show');
}

function editWebhook(id) {
    // Get webhook data via AJAX
    $.get('{{ route("structure-manager.webhook.get", ":id") }}'.replace(':id', id), function(webhook) {
        $('#webhookModalTitle').text('Edit Webhook');
        $('#webhookForm').attr('action', '{{ route("structure-manager.webhook.update", ":id") }}'.replace(':id', id));
        $('#webhookMethod').val('PUT');
        $('#webhook_url').val(webhook.webhook_url);
        $('#webhook_corporation_id').val(webhook.corporation_id || '');
        $('#webhook_description').val(webhook.description || '');
        $('#webhook_role_mention').val(webhook.role_mention || '');
        $('#webhook_enabled').prop('checked', webhook.enabled);
        $('#webhookModal').modal('show');
    });
}

function deleteWebhook(id) {
    if (!confirm('Are you sure you want to delete this webhook?')) {
        return;
    }

    // Build form explicitly; @csrf inside a JS string literal does not produce a
    // usable token input, so we inline csrf_token() here and append a real _token input.
    const action = '{{ route("structure-manager.webhook.delete", ":id") }}'.replace(':id', id);
    const $form = $('<form method="POST"></form>').attr('action', action);
    $('<input type="hidden" name="_token">').val('{{ csrf_token() }}').appendTo($form);
    $('<input type="hidden" name="_method" value="DELETE">').appendTo($form);
    $form.appendTo('body').submit();
}

function testWebhook(id) {
    const button = $('[data-webhook-id="' + id + '"] .btn-info');
    const originalText = button.html();

    button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

    $.post('{{ route("structure-manager.webhook.test", ":id") }}'.replace(':id', id), {
        _token: '{{ csrf_token() }}'
    })
    .done(function(response) {
        if (response.success) {
            button.html('<i class="fas fa-check"></i> Success');
            setTimeout(() => button.html(originalText).prop('disabled', false), 2000);
        } else {
            button.html('<i class="fas fa-times"></i> Failed');
            alert('Test failed: ' + response.message);
            setTimeout(() => button.html(originalText).prop('disabled', false), 2000);
        }
    })
    .fail(function() {
        button.html('<i class="fas fa-times"></i> Error');
        alert('Failed to send test notification');
        setTimeout(() => button.html(originalText).prop('disabled', false), 2000);
    });
}

(function($) {
    // === Section switcher (replaces Bootstrap tab switching) ===
    function activateSection(section) {
        if (!section) return;

        // Update sidebar
        $('.settings-sidebar .nav-link').removeClass('active');
        $('.settings-sidebar .nav-link[data-section="' + section + '"]').addClass('active');

        // Update content
        $('.settings-section-pane').removeClass('active');
        $('#' + section + '-section').addClass('active');

        // Persist + URL hash
        try { localStorage.setItem('structure_manager_active_section', section); } catch(e) {}
        history.replaceState(null, '', '#' + section);
    }

    $(document).on('click', '.settings-sidebar .nav-link[data-section]', function(e) {
        e.preventDefault();
        activateSection($(this).data('section'));
    });

    // In-content links that jump to another sidebar section
    $(document).on('click', '.nav-section-link[data-section]', function(e) {
        e.preventDefault();
        activateSection($(this).data('section'));
    });

    $(document).ready(function() {
        // Restore from URL hash first, then localStorage, then default
        var initial = null;

        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            if ($('#' + hash + '-section').length) {
                initial = hash;
            }
        }

        if (!initial) {
            try {
                var saved = localStorage.getItem('structure_manager_active_section');
                if (saved && $('#' + saved + '-section').length) {
                    initial = saved;
                }
            } catch(e) {}
        }

        if (initial) {
            activateSection(initial);
        }
    });
})(jQuery);

// ESI key holder pool management moved to Manager Core v1.x.
// See route('manager-core.esi-key-pool.index') for the admin UI.

// === Notifications section JS (category toggle, binding upsert/remove/toggle, role picker) ===
// Targets .category-row / .js-binding-* / #rolePickerModal — does not collide with the
// settings sidebar / #webhookModal handlers above.
(function($) {
    'use strict';

    const CSRF = '{{ csrf_token() }}';
    const NOTIF_ROUTES = {
        updateCategory:  '{{ url('/structure-manager/settings/notifications/category/:id') }}',
        upsertBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid') }}',
        removeBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid') }}',
        toggleBinding:   '{{ url('/structure-manager/settings/notifications/category/:cid/bind/:wid/toggle') }}',
        listRoles:       '{{ route('structure-manager.notifications.roles') }}',
    };

    // --- Role-name translation -------------------------------------------
    // The notifications panel embeds {available, roles:{id:{name,color}}} as
    // a JSON data island. We use it to translate raw role-mention values
    // (snowflakes / <@&ID> strings) into readable names beneath each role
    // input, so operators don't have to recognise bare numbers.
    let SM_ROLE_MAP = {};
    let SM_ROLE_HAS_PROVIDER = false;
    try {
        const _mapEl = document.getElementById('sm-role-map');
        if (_mapEl) {
            const _parsed = JSON.parse(_mapEl.textContent || '{}');
            SM_ROLE_MAP = _parsed.roles || {};
            SM_ROLE_HAS_PROVIDER = !!_parsed.available;
        }
    } catch (e) {
        SM_ROLE_MAP = {};
    }

    function smExtractSnowflake(raw) {
        if (!raw) return null;
        const m = String(raw).match(/(\d{2,})/);
        return m ? m[1] : null;
    }

    // Paint the resolved-role pill into the .role-name-display under an input.
    function refreshRoleName($input) {
        const $field = $input.closest('.role-field');
        if (!$field.length) return;
        const $display = $field.find('.role-name-display').first();
        if (!$display.length) return;

        const raw = ($input.val() || '').trim();
        if (raw === '') { $display.empty(); return; }

        const isRole = /^<@&\d+>$/.test(raw) || /^\d+$/.test(raw);
        const isUser = /^<@!?\d+>$/.test(raw);
        const id = smExtractSnowflake(raw);
        const role = (isRole && id) ? SM_ROLE_MAP[id] : null;

        const $pill = $('<span class="sm-role-pill"></span>');

        if (role) {
            if (role.color && /^#[0-9a-f]{6}$/i.test(role.color)) {
                $pill.append($('<span class="role-color-dot"></span>').css('background', role.color));
            }
            $pill.append($('<span></span>').text('@' + (role.name || ('Role ' + id))));
        } else if (isUser) {
            $pill.addClass('is-user')
                 .append($('<i class="fas fa-user"></i>'))
                 .append($('<span></span>').text('User mention' + (id ? ' (' + id + ')' : '')));
        } else if (isRole && SM_ROLE_HAS_PROVIDER) {
            // Looks like a role ID but isn't in any installed role list —
            // most likely a typo or a role deleted in Discord.
            $pill.addClass('is-unknown')
                 .append($('<i class="fas fa-question-circle"></i>'))
                 .append($('<span></span>').text('Role ' + id + ' (not in any installed role list)'));
        } else if (!isRole) {
            // Not a number and not a recognised mention shape — WebhookDispatcher
            // drops malformed mentions, so warn before the operator saves.
            $pill.addClass('is-unknown')
                 .append($('<i class="fas fa-exclamation-triangle"></i>'))
                 .append($('<span></span>').text('Unrecognized mention (will not ping anyone)'));
        } else {
            // isRole but no provider installed — nothing to resolve against,
            // and a raw ID is the expected manual format. Show nothing.
            $display.empty();
            return;
        }

        $display.empty().append($pill);
    }

    function notifAjax(method, url, data, onSuccess, onError) {
        $.ajax({
            url: url,
            method: method,
            data: Object.assign({ _token: CSRF }, data || {}),
            dataType: 'json',
        })
        .done(function (res) { onSuccess && onSuccess(res); })
        .fail(function (xhr) {
            const msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Request failed';
            if (onError) { onError(msg); } else { alert(msg); }
        });
    }

    // -------- Category: toggle enabled / save role --------

    $(document).on('change', '.js-category-enabled', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const enabled = $(this).is(':checked');
        const role = $row.find('.js-category-role').val();

        $row.attr('data-enabled', enabled ? '1' : '0');

        notifAjax('POST', NOTIF_ROUTES.updateCategory.replace(':id', catId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        });
    });

    $(document).on('blur', '.js-category-role', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const enabled = $row.find('.js-category-enabled').is(':checked');
        const role = $(this).val();

        notifAjax('POST', NOTIF_ROUTES.updateCategory.replace(':id', catId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        });
    });

    // -------- Binding: add / remove / toggle / save role override --------

    $(document).on('change', '.js-add-binding', function () {
        const $row = $(this).closest('.category-row');
        $row.find('.js-do-add-binding').prop('disabled', !$(this).val());
    });

    $(document).on('click', '.js-do-add-binding', function () {
        const $row = $(this).closest('.category-row');
        const catId = $row.data('category-id');
        const webhookId = $row.find('.js-add-binding').val();

        if (!webhookId) return;

        notifAjax('POST', NOTIF_ROUTES.upsertBinding.replace(':cid', catId).replace(':wid', webhookId),
            { enabled: 1 },
            function () { location.reload(); }
        );
    });

    $(document).on('change', '.js-binding-enabled', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');

        notifAjax('POST', NOTIF_ROUTES.toggleBinding.replace(':cid', catId).replace(':wid', whId));
    });

    $(document).on('click', '.js-save-binding', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');
        const enabled = $tr.find('.js-binding-enabled').is(':checked');
        const role = $tr.find('.js-binding-role').val();

        notifAjax('POST', NOTIF_ROUTES.upsertBinding.replace(':cid', catId).replace(':wid', whId), {
            enabled: enabled ? 1 : 0,
            role_mention: role,
        }, function () {
            $tr.find('.js-save-binding').removeClass('btn-info').addClass('btn-success')
                .html('<i class="fas fa-check"></i>');
            setTimeout(() => {
                $tr.find('.js-save-binding').removeClass('btn-success').addClass('btn-info')
                    .html('<i class="fas fa-save"></i>');
            }, 1200);
        });
    });

    $(document).on('click', '.js-remove-binding', function () {
        const $tr = $(this).closest('tr');
        const catId = $tr.data('binding-category');
        const whId = $tr.data('binding-webhook');

        if (!confirm('Unbind this webhook from the category? The webhook itself stays configured; only this category stops firing to it.')) return;

        notifAjax('DELETE', NOTIF_ROUTES.removeBinding.replace(':cid', catId).replace(':wid', whId),
            {},
            function () { location.reload(); }
        );
    });

    // -------- Role-name translation: keep the resolved-name pills in sync --------

    $(document).on('input blur', '.js-category-role, .js-binding-role', function () {
        refreshRoleName($(this));
    });

    // Initial paint for every role input already on the page. The picker
    // writes via .val(...).trigger('blur'), so picks are covered by the
    // handler above; adding a binding does a full page reload, so new rows
    // are covered by this initial pass after the reload.
    $(function () {
        $('.js-category-role, .js-binding-role').each(function () {
            refreshRoleName($(this));
        });
    });

    // -------- Role picker modal (connector dropdown) --------

    let activeRoleTarget = null;

    $(document).on('click', '.js-pick-role', function () {
        const $row = $(this).closest('.category-row');
        activeRoleTarget = $row.find('.js-category-role');
        openRolePicker();
    });

    // Per-binding role override picker
    $(document).on('click', '.js-pick-role-binding', function () {
        const $tr = $(this).closest('tr');
        activeRoleTarget = $tr.find('.js-binding-role');
        openRolePicker();
    });

    // INLINE picker — supports multiple instances on the same page via
    // data-picker-id (the inline picker div) + data-input-id (the input
    // that should receive the mention). One handler covers all pickers.
    //
    // Why inline vs. modal: rolePickerModal (used by categories panel and
    // ESI attack on regular page contexts) works fine when there's no
    // parent modal. But the webhook config is itself a modal, and Bootstrap
    // 4 can't reliably stack modals (commit 0f7ac5b tried the z-index fix,
    // still locked the page on Matt's install). The Events tab also has
    // the picker break on his install — switching it to inline too so
    // every modal-based picker site uses the same proven path. The
    // categories panel keeps the modal picker because it's the simplest
    // UI and works correctly there.
    //
    // Caching: the role list is the same for every picker instance, so we
    // cache the AJAX response after the first fetch and reuse it for any
    // subsequent picker open. Avoids 2x AJAX hits if an operator opens
    // both the webhook modal picker and the Events tab picker.
    let inlineRolesCache = null;       // cached AJAX response (res object)
    const inlineRolesLoadedFor = {};   // pickerId -> bool (rendered into that body yet)

    $(document).on('click', '.js-toggle-inline-role-picker', function () {
        const pickerId = $(this).data('picker-id');
        const inputId  = $(this).data('input-id');
        const $picker  = $('#' + pickerId);
        if (!$picker.length) return;

        if ($picker.is(':visible')) {
            $picker.slideUp(150);
            return;
        }

        // Point the shared .js-role-pick-btn handler at the right input.
        // Same activeRoleTarget mechanism the modal picker uses — keeps the
        // existing role-pick click handler unchanged.
        activeRoleTarget = $('#' + inputId);

        $picker.slideDown(150);

        // Render into this picker's body if we haven't already.
        // (If we have the cache but not yet rendered into THIS picker's
        // body, render straight from cache without re-AJAX.)
        if (inlineRolesLoadedFor[pickerId]) {
            return;
        }
        if (inlineRolesCache) {
            renderInlineRolePickerBody($picker.find('.inline-role-picker-body'), inlineRolesCache, pickerId);
            inlineRolesLoadedFor[pickerId] = true;
            return;
        }
        loadInlineRolePicker(pickerId);
    });

    function loadInlineRolePicker(pickerId) {
        const $picker = $('#' + pickerId);
        const $body   = $picker.find('.inline-role-picker-body');
        $body.html('<div style="text-align:center; color:#8b95a5; padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading roles...</div>');

        $.getJSON(NOTIF_ROUTES.listRoles, function (res) {
            inlineRolesCache = res;
            renderInlineRolePickerBody($body, res, pickerId);
            inlineRolesLoadedFor[pickerId] = true;
        }).fail(function () {
            $body.html('<div class="alert alert-danger" style="margin-bottom:0;">Failed to load roles from Discord provider(s).</div>');
        });
    }

    function renderInlineRolePickerBody($body, res, pickerId) {
        if (!res.roles || res.roles.length === 0) {
            $body.html(`
                <div class="alert alert-warning" style="margin-bottom:0;">
                    <strong>No roles returned from ${res.label || 'provider'}.</strong><br>
                    Enter the mention manually as <code>&lt;@&amp;ROLE_ID&gt;</code> or raw role ID.
                </div>`);
            return;
        }

        const perSource = {};
        res.roles.forEach(function (r) { perSource[r.source] = (perSource[r.source] || 0) + 1; });
        const sourceLabels = {
            'discord-roles-table':  'SeAT Broadcast',
            'seat-connector':       'SeAT Connector',
            'warlof-discord':       'Warlof (legacy)',
        };
        const sourceColors = {
            'discord-roles-table':  '#28a745',
            'seat-connector':       '#3498db',
            'warlof-discord':       '#95a5a6',
        };
        const badgeStyle = 'color:#000; font-weight:700; font-size:0.7rem; padding:2px 6px;';

        // Picker-instance-scoped IDs for the filter inputs so multiple
        // pickers on the same page don't collide.
        const filterId = pickerId + '-filter';
        const sourceFilterId = pickerId + '-source-filter';
        const listId = pickerId + '-list';

        let html = '';
        html += '<div style="font-size:0.78rem; color:#8b95a5; margin-bottom:0.5rem;">';
        html += `${res.roles.length} unique role(s) from ${Object.keys(perSource).length} source(s): `;
        html += Object.keys(perSource).map(function (s) {
            return `<span class="badge" style="background:${sourceColors[s]||'#666'}; ${badgeStyle} margin-left:3px;">${sourceLabels[s]||s}: ${perSource[s]}</span>`;
        }).join(' ');
        html += '</div>';

        html += '<div style="display:flex; gap:0.4rem; margin-bottom:0.6rem;">';
        html += `<input type="text" id="${filterId}" class="form-control form-control-sm inline-role-filter" placeholder="Search roles..." style="background:#1e222b; border:1px solid #454d55; color:#fff; flex:1;">`;
        if (Object.keys(perSource).length > 1) {
            html += `<select id="${sourceFilterId}" class="form-control form-control-sm inline-source-filter" style="background:#1e222b; border:1px solid #454d55; color:#fff; max-width:160px;">`;
            html += '<option value="">All sources</option>';
            Object.keys(perSource).forEach(function (s) {
                html += `<option value="${s}">${sourceLabels[s]||s}</option>`;
            });
            html += '</select>';
        }
        html += '</div>';

        html += `<div id="${listId}" class="inline-role-list" style="display:flex; flex-wrap:wrap; gap:4px;">`;
        res.roles.forEach(function (r) {
            const hex = r.color && /^#[0-9a-f]{6}$/i.test(r.color) ? r.color : '';
            const dot = hex
                ? `<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${hex}; margin-right:6px; vertical-align:middle;"></span>`
                : '';
            const format = (r.mention_format || ('<@&' + r.id + '>')).replace(/"/g, '&quot;');
            const primarySrc = r.source;
            const alsoIn = (r.sources || []).filter(s => s !== primarySrc);
            const primaryBadge = `<span class="badge" style="background:${sourceColors[primarySrc]||'#666'}; color:#000; font-weight:700; font-size:0.65rem; padding:2px 6px; margin-left:4px; vertical-align:middle;">${sourceLabels[primarySrc]||primarySrc}</span>`;
            const extraBadge = alsoIn.length > 0
                ? `<span class="badge badge-secondary" style="color:#fff; font-weight:600; font-size:0.65rem; padding:2px 6px; margin-left:2px;" title="Also in: ${alsoIn.map(s => sourceLabels[s]||s).join(', ')}">+${alsoIn.length}</span>`
                : '';
            html += `<button type="button" class="btn btn-sm btn-outline-primary js-role-pick-btn"
                data-role-id="${r.id}"
                data-role-name="${r.name}"
                data-mention-format="${format}"
                data-source="${primarySrc}"
                style="text-align:left;">
                ${dot}${r.name}
                <small style="opacity:0.55; margin-left:4px;">#${r.id.slice(-6)}</small>
                ${primaryBadge}${extraBadge}
            </button>`;
        });
        html += '</div>';

        $body.html(html);

        // Live filter — name+id text search plus source dropdown. Scoped to
        // this picker's list element so each picker's filters work
        // independently.
        const applyFilter = function () {
            const textV = ($('#' + filterId).val() || '').toLowerCase();
            const srcV  = $('#' + sourceFilterId).val() || '';
            $('#' + listId + ' .js-role-pick-btn').each(function () {
                const n = ($(this).data('role-name') + ' ' + $(this).data('role-id')).toLowerCase();
                const s = $(this).data('source');
                $(this).toggle(n.includes(textV) && (!srcV || s === srcV));
            });
        };
        $('#' + filterId).on('input', applyFilter);
        $('#' + sourceFilterId).on('change', applyFilter);
    }

    // ESI attack-alert global role mention picker — now uses the inline
    // picker (data-picker-id=inlineRolePickerEsiAttack) via the unified
    // .js-toggle-inline-role-picker handler above. No separate handler
    // needed. Matt reported the modal picker locked the page on the
    // Events tab too (similar Bootstrap behaviour), so switching to the
    // inline pattern for consistency.

    function openRolePicker() {
        const $modal = $('#rolePickerModal');
        if (!$modal.length) {
            // Fallback if connector isn't detected
            return;
        }
        const $body = $('#rolePickerBody');
        $body.html('<div class="text-center" style="padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
        $modal.modal('show');

        $.getJSON(NOTIF_ROUTES.listRoles, function (res) {
            if (!res.roles || res.roles.length === 0) {
                $body.html(`
                    <div class="alert alert-warning">
                        <strong>No roles returned from ${res.label || 'provider'}.</strong><br>
                        Enter the mention manually as <code>&lt;@&amp;ROLE_ID&gt;</code> or raw role ID.
                    </div>`);
                return;
            }

            // Count roles per source (unique by snowflake, but counted per primary tag)
            const perSource = {};
            res.roles.forEach(function (r) {
                perSource[r.source] = (perSource[r.source] || 0) + 1;
            });
            const sourceLabels = {
                // Display names follow the project's canonical naming convention:
                // "SeAT Broadcast" is the display name for the seat-discord-pings
                // package (operators see "SeAT Broadcast", not "Pings"). Internal
                // identifiers like discord-roles-table stay unchanged.
                'discord-roles-table':  'SeAT Broadcast',
                'seat-connector':       'SeAT Connector',
                'warlof-discord':       'Warlof (legacy)',
            };
            const sourceColors = {
                'discord-roles-table':  '#28a745',
                'seat-connector':       '#3498db',
                'warlof-discord':       '#95a5a6',
            };

            // Badge styling — black text on the bright accent colors gives
            // ~7:1 contrast ratio vs ~3:1 for white text. The bright tones
            // act as visual category markers and keep their color identity,
            // but the label stays readable.
            const badgeStyle = 'color:#000; font-weight:700; font-size:0.7rem; padding:2px 6px;';

            let html = '<div style="max-height:460px; overflow-y:auto;">';
            html += '<div style="font-size:0.78rem; color:#8b95a5; margin-bottom:0.5rem;">';
            html += `${res.roles.length} unique role(s) from ${Object.keys(perSource).length} source(s): `;
            html += Object.keys(perSource).map(function (s) {
                return `<span class="badge" style="background:${sourceColors[s]||'#666'}; ${badgeStyle} margin-left:3px;">${sourceLabels[s]||s}: ${perSource[s]}</span>`;
            }).join(' ');
            html += '</div>';

            // Filter controls row: search + source filter
            html += '<div style="display:flex; gap:0.4rem; margin-bottom:0.8rem;">';
            html += '<input type="text" id="roleFilter" class="form-control" placeholder="Search roles..." style="background:#1e222b; border:1px solid #454d55; color:#fff; flex-grow:1;">';
            if (Object.keys(perSource).length > 1) {
                html += '<select id="sourceFilter" class="form-control" style="background:#1e222b; border:1px solid #454d55; color:#fff; max-width:180px;">';
                html += '<option value="">All sources</option>';
                Object.keys(perSource).forEach(function (s) {
                    html += `<option value="${s}">${sourceLabels[s]||s}</option>`;
                });
                html += '</select>';
            }
            html += '</div>';

            html += '<div id="roleList" style="display:flex; flex-wrap:wrap; gap:4px;">';
            res.roles.forEach(function (r) {
                const hex = r.color && /^#[0-9a-f]{6}$/i.test(r.color) ? r.color : '';
                const dot = hex
                    ? `<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${hex}; margin-right:6px; vertical-align:middle;"></span>`
                    : '';
                const format = (r.mention_format || ('<@&' + r.id + '>')).replace(/"/g, '&quot;');
                // Source badge: small colored indicator. If role is in multiple sources, show a "+N" tag.
                // Black text + bold + larger padding keeps the label readable on
                // the bright accent colors (vs the older near-invisible thin white).
                const primarySrc = r.source;
                const alsoIn = (r.sources || []).filter(s => s !== primarySrc);
                const primaryBadge = `<span class="badge" style="background:${sourceColors[primarySrc]||'#666'}; color:#000; font-weight:700; font-size:0.65rem; padding:2px 6px; margin-left:4px; vertical-align:middle;">${sourceLabels[primarySrc]||primarySrc}</span>`;
                const extraBadge = alsoIn.length > 0
                    ? `<span class="badge badge-secondary" style="color:#fff; font-weight:600; font-size:0.65rem; padding:2px 6px; margin-left:2px;" title="Also in: ${alsoIn.map(s => sourceLabels[s]||s).join(', ')}">+${alsoIn.length}</span>`
                    : '';
                html += `<button type="button" class="btn btn-sm btn-outline-primary js-role-pick-btn"
                    data-role-id="${r.id}"
                    data-role-name="${r.name}"
                    data-mention-format="${format}"
                    data-source="${primarySrc}"
                    style="text-align:left;">
                    ${dot}${r.name}
                    <small style="opacity:0.55; margin-left:4px;">#${r.id.slice(-6)}</small>
                    ${primaryBadge}${extraBadge}
                </button>`;
            });
            html += '</div></div>';
            $body.html(html);

            const applyFilter = function () {
                const textV = ($('#roleFilter').val() || '').toLowerCase();
                const srcV  = $('#sourceFilter').val() || '';
                $('#roleList .js-role-pick-btn').each(function () {
                    const n = ($(this).data('role-name') + ' ' + $(this).data('role-id')).toLowerCase();
                    const s = $(this).data('source');
                    const matchesText = n.includes(textV);
                    const matchesSrc = !srcV || s === srcV;
                    $(this).toggle(matchesText && matchesSrc);
                });
            };
            $('#roleFilter').on('input', applyFilter);
            $('#sourceFilter').on('change', applyFilter);
        }).fail(function () {
            $body.html('<div class="alert alert-danger">Failed to load roles from Discord provider(s).</div>');
        });
    }

    $(document).on('click', '.js-role-pick-btn', function () {
        const mentionFormat = $(this).data('mention-format') || ('<@&' + $(this).data('role-id') + '>');
        if (activeRoleTarget) {
            activeRoleTarget.val(mentionFormat).trigger('blur');
        }
        // Close whichever picker UI was used:
        //   - Modal picker (#rolePickerModal) for the categories panel
        //   - ANY inline picker on the page (each .inline-role-picker
        //     instance — covers webhook modal + Events tab + any future
        //     ones added with the same class)
        $('#rolePickerModal').modal('hide');
        $('.inline-role-picker:visible').slideUp(150);
    });

})(jQuery);
</script>
@endpush

@stop
