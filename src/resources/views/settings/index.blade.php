@extends('web::layouts.grids.12')

@section('title', 'Structure Manager Settings')
@section('page_header', 'Structure Manager Settings')

@push('head')
<style>
    /* Dark theme compatible styles */
    .settings-section {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .settings-section h4 {
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
    
    .form-group label {
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    
    .help-text {
        font-size: 0.875rem;
        color: #a0a0a0;
        margin-top: 0.25rem;
    }
    
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
    
    .btn-test-webhook {
        margin-top: 0.5rem;
    }
    
    .info-banner {
        background: rgba(23, 162, 184, 0.1);
        border-left: 4px solid #17a2b8;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border-radius: 0.25rem;
    }
    
    .warning-banner {
        background: rgba(255, 193, 7, 0.1);
        border-left: 4px solid #ffc107;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border-radius: 0.25rem;
    }
    
    /* Tab styling */
    .nav-tabs {
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 1.5rem;
    }
    
    .nav-tabs .nav-link {
        color: #a0a0a0;
        border: none;
        border-bottom: 2px solid transparent;
        padding: 0.75rem 1.5rem;
        transition: all 0.3s;
    }
    
    .nav-tabs .nav-link:hover {
        color: #17a2b8;
        border-bottom-color: rgba(23, 162, 184, 0.3);
    }
    
    .nav-tabs .nav-link.active {
        color: #17a2b8;
        background: transparent;
        border-bottom-color: #17a2b8;
    }
    
    .nav-tabs .nav-link i {
        margin-right: 0.5rem;
    }
    
    .tab-content {
        padding-top: 1rem;
    }
    
    .hangar-checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }
    
    .hangar-checkbox-item {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        padding: 0.75rem;
        transition: all 0.2s;
    }
    
    .hangar-checkbox-item:hover {
        background: rgba(0, 0, 0, 0.3);
        border-color: rgba(255, 255, 255, 0.2);
    }
    
    .hangar-checkbox-item input[type="checkbox"] {
        margin-right: 0.5rem;
    }
    
    .hangar-checkbox-item label {
        margin-bottom: 0;
        cursor: pointer;
        font-weight: normal;
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
    
    /* Webhook List Styles */
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

    /* Upwell Threshold Styles (matching POS threshold patterns) */
    .construction-placeholder {
        text-align: center;
        padding: 2rem;
    }
    
    .ascii-header {
        margin-bottom: 2rem;
        overflow-x: auto;
    }
    
    .ascii-art {
        font-family: 'Courier New', monospace;
        font-size: 0.6rem;
        color: #17a2b8;
        text-shadow: 0 0 10px rgba(23, 162, 184, 0.5);
        line-height: 1.2;
        margin: 0;
        display: inline-block;
    }
    
    /* Hamster Animation */
    .hamster-container {
        display: flex;
        justify-content: center;
        align-items: flex-end;
        gap: 2rem;
        margin: 2rem 0;
        height: 120px;
    }
    
    .hamster {
        animation: hamsterBounce 0.5s ease-in-out infinite;
    }
    
    .hamster-body {
        width: 60px;
        height: 50px;
        background: linear-gradient(145deg, #d4a373, #c4935f);
        border-radius: 50% 50% 45% 45%;
        position: relative;
    }
    
    .hamster-ear {
        width: 15px;
        height: 15px;
        background: #d4a373;
        border-radius: 50%;
        position: absolute;
        top: 5px;
    }
    
    .hamster-ear.left {
        left: 8px;
    }
    
    .hamster-ear.right {
        right: 8px;
    }
    
    .hamster-face {
        position: absolute;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
    }
    
    .hamster-eye {
        width: 5px;
        height: 5px;
        background: #000;
        border-radius: 50%;
        display: inline-block;
        animation: blink 3s infinite;
    }
    
    .hamster-eye.left {
        margin-right: 15px;
    }
    
    .hamster-nose {
        width: 4px;
        height: 4px;
        background: #ff69b4;
        border-radius: 50%;
        margin: 3px auto;
    }
    
    .hamster-paws {
        position: absolute;
        bottom: -5px;
        width: 100%;
        display: flex;
        justify-content: space-around;
    }
    
    .hamster-paw {
        width: 8px;
        height: 8px;
        background: #c4935f;
        border-radius: 50%;
        animation: pawType 0.3s ease-in-out infinite;
    }
    
    .hamster-paw.right {
        animation-delay: 0.15s;
    }
    
    @keyframes hamsterBounce {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-5px);
        }
    }
    
    @keyframes blink {
        0%, 100% {
            height: 5px;
        }
        98%, 99% {
            height: 1px;
        }
    }
    
    @keyframes pawType {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-3px);
        }
    }
    
    /* Tiny Computer */
    .tiny-computer {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
    }
    
    .computer-screen {
        width: 100px;
        height: 70px;
        background: #1a1a1a;
        border: 3px solid #333;
        border-radius: 3px;
        padding: 8px;
        box-shadow: 0 0 20px rgba(23, 162, 184, 0.3);
        position: relative;
        overflow: hidden;
    }
    
    .computer-screen::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(
            transparent 0%,
            rgba(23, 162, 184, 0.05) 50%,
            transparent 100%
        );
        animation: scanline 2s linear infinite;
    }
    
    @keyframes scanline {
        0% {
            transform: translateY(-100%);
        }
        100% {
            transform: translateY(100%);
        }
    }
    
    .code-lines {
        font-family: 'Courier New', monospace;
        font-size: 0.5rem;
        color: #00ff00;
        text-align: left;
        line-height: 1.4;
        display: flex;
        flex-direction: column;
    }
    
    .code-line {
        opacity: 0;
        animation: fadeInLine 0.5s ease-in forwards;
    }
    
    .code-line:nth-child(1) {
        animation-delay: 0.5s;
    }
    
    .code-line:nth-child(2) {
        animation-delay: 1s;
    }
    
    .code-line:nth-child(3) {
        animation-delay: 1.5s;
    }
    
    .code-line:nth-child(4) {
        animation-delay: 2s;
    }
    
    @keyframes fadeInLine {
        to {
            opacity: 1;
        }
    }
    
    .cursor {
        animation: blink-cursor 0.7s infinite;
    }
    
    @keyframes blink-cursor {
        0%, 50% {
            opacity: 1;
        }
        51%, 100% {
            opacity: 0;
        }
    }
    
    .computer-keyboard {
        width: 90px;
        height: 8px;
        background: #333;
        border-radius: 0 0 3px 3px;
        position: relative;
    }
    
    .computer-keyboard::before {
        content: '';
        position: absolute;
        top: 2px;
        left: 10px;
        right: 10px;
        height: 2px;
        background: #555;
        border-radius: 1px;
    }
    
    /* Status Section */
    .construction-status {
        margin: 3rem 0 2rem;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .status-text {
        font-size: 1.1rem;
        color: #17a2b8;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
    }
    
    .progress-bar {
        width: 100%;
        height: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #17a2b8, #20c997);
        border-radius: 10px;
        animation: progressLoad 3s ease-in-out infinite;
        box-shadow: 0 0 10px rgba(23, 162, 184, 0.5);
    }
    
    @keyframes progressLoad {
        0% {
            width: 0%;
        }
        50% {
            width: 73%;
        }
        100% {
            width: 0%;
        }
    }
    
    .eta-text {
        margin-top: 0.5rem;
        color: #6c757d;
        font-style: italic;
    }
    
    /* Developer Notes */
    .developer-notes {
        margin: 2rem auto;
        max-width: 600px;
        text-align: left;
    }
    
    .note-item {
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        background: rgba(0, 0, 0, 0.2);
        border-left: 3px solid #ffc107;
        border-radius: 0 0.25rem 0.25rem 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: all 0.3s;
    }
    
    .note-item:hover {
        background: rgba(0, 0, 0, 0.3);
        border-left-color: #17a2b8;
        transform: translateX(5px);
    }
    
    .note-item i {
        color: #ffc107;
        font-size: 1.1rem;
        min-width: 20px;
    }
    
    .note-item:hover i {
        color: #17a2b8;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .ascii-art {
            font-size: 0.4rem;
        }
        
        .hamster-container {
            gap: 1rem;
            height: 100px;
        }
        
        .hamster-body {
            width: 50px;
            height: 40px;
        }
        
        .computer-screen {
            width: 80px;
            height: 60px;
        }
    }
</style>
@endpush

@section('content')
<div class="structure-manager-wrapper">
<div class="structure-manager-settings">

    @if(session('success'))
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
    </div>
    @endif

    <form method="POST" action="{{ route('structure-manager.settings.update') }}">
        @csrf
        
        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="webhooks-tab" data-toggle="tab" href="#webhooks" role="tab">
                    <i class="fas fa-plug"></i> Webhook Configuration
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="pos-tab" data-toggle="tab" href="#pos" role="tab">
                    <i class="fas fa-broadcast-tower"></i> POS Settings
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="reserves-tab" data-toggle="tab" href="#reserves" role="tab">
                    <i class="fas fa-warehouse"></i> Reserves Tracking
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="upwell-tab" data-toggle="tab" href="#upwell" role="tab">
                    <i class="fas fa-building"></i> Upwell Structures
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="events-tab" data-toggle="tab" href="#events" role="tab">
                    <i class="fas fa-bolt"></i> Structure Events
                </a>
            </li>
        </ul>
        
        <!-- Tabs Content -->
        <div class="tab-content" id="settingsTabContent">

            <!-- Webhook Configuration Tab (NEW — shared across POS/Upwell/Events) -->
            <div class="tab-pane fade show active" id="webhooks" role="tabpanel">
                <div class="tab-description">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Central webhook registry. Add as many Discord or Slack webhook destinations as you need.
                        Each webhook is a <strong>delivery endpoint</strong> — where notifications are sent.
                        <strong>What</strong> gets sent to which webhook is controlled on the
                        <a href="{{ route('structure-manager.notifications.index') }}">Notifications page</a>,
                        where notification categories (POS Fuel, Structure Under Attack, Upwell Fuel, etc.) are bound to
                        specific webhooks with optional per-binding role mention overrides.
                    </p>
                </div>

                <div class="settings-section">
                    <h4><i class="fas fa-plug"></i> Configured Webhooks</h4>

                    <div class="info-banner">
                        <i class="fas fa-info-circle"></i>
                        <strong>Shared delivery endpoints:</strong> These webhooks serve every notification category in Structure Manager —
                        POS fuel/strontium/lifecycle alerts, Upwell fuel alerts, and ESI-driven structure events (attacks, anchoring, etc.).
                        Corporation filter scopes a webhook to a single corp (or leave as "All Corporations" for cross-corp delivery).
                        Role mention on a webhook row is a <em>legacy fallback</em> — prefer setting role mentions per notification category on the Notifications page.
                    </div>

                    <!-- Existing Webhooks List -->
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
                                            <span class="text-muted" style="font-size:0.78rem;"> (fallback — prefer category-level)</span>
                                        @else
                                            <span class="text-muted">None — uses category-level role from Notifications page</span>
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

                    <!-- Add New Webhook Button — no artificial cap, admins decide -->
                    <button type="button" class="btn btn-success" onclick="showAddWebhookModal()">
                        <i class="fas fa-plus"></i> Add Webhook ({{ $webhooks->count() }} configured)
                    </button>
                </div>

                <div class="settings-section">
                    <h4><i class="fas fa-arrow-right"></i> Next Step: Route Categories to Webhooks</h4>
                    <p>
                        After adding a webhook, go to the <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications page</strong></a>
                        to bind notification categories (POS Fuel, Structure Under Attack, etc.) to this webhook. That\'s where the master toggle, role
                        mention, and per-binding role overrides live.
                    </p>
                </div>
            </div>

            <!-- POS Settings Tab -->
            <div class="tab-pane fade" id="pos" role="tabpanel">
                <div class="tab-description">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Configure settings for Player Owned Starbases (Control Towers). These settings control notifications,
                        alert thresholds, and monitoring for legacy POS structures.
                    </p>
                </div>

                <!-- POS Webhooks: Pointer to the dedicated Webhook Configuration tab -->
                <div class="settings-section">
                    <h4><i class="fas fa-bell"></i> POS Notification Webhooks</h4>

                    <div class="info-banner" style="border-left:4px solid #3498db;">
                        <i class="fas fa-arrow-left"></i>
                        <strong>Moved:</strong> Webhooks are now managed on the
                        <a href="#webhooks" data-toggle="tab" onclick="document.getElementById('webhooks-tab').click(); return false;"><strong>Webhook Configuration</strong></a>
                        tab (first tab on this page). They\'re shared across POS, Upwell, and Structure Events — no longer POS-specific.
                        Choose <em>which</em> categories hit which webhooks on the
                        <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications page</strong></a>.
                    </div>
                </div>

                <!-- Additional Notification Settings -->
                <div class="settings-section">
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
                    
                    <!-- Zero Strontium Settings -->
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
                
                <!-- POS Alert Thresholds -->
                <div class="settings-section">
                    <h4><i class="fas fa-sliders-h"></i> POS Alert Thresholds</h4>
                    
                    <!-- Strontium Thresholds -->
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
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_critical_hours', 6) }}"
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
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_warning_hours', 12) }}"
                                           min="1" max="72" required>
                                    <div class="input-group-append">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                </div>
                                <small class="help-text">YELLOW alert when below this threshold</small>
                            </div>
                            
                            <div class="threshold-item good">
                                <label for="pos_strontium_good_hours">
                                    <i class="fas fa-check-circle text-success"></i> Good Threshold
                                </label>
                                <div class="input-group">
                                    <input type="number" 
                                           class="form-control" 
                                           id="pos_strontium_good_hours" 
                                           name="pos_strontium_good_hours" 
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_strontium_good_hours', 24) }}"
                                           min="1" max="72" required>
                                    <div class="input-group-append">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                </div>
                                <small class="help-text">GREEN status when at or above this threshold</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fuel & Charter Thresholds -->
                    <div class="settings-subsection">
                        <h5><i class="fas fa-gas-pump"></i> Fuel & Charter Thresholds</h5>
                        
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
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_fuel_critical_days', 7) }}"
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
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_fuel_warning_days', 14) }}"
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
                                           value="{{ StructureManager\Models\StructureManagerSettings::get('pos_charter_critical_days', 7) }}"
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
                
                <!-- POS Deprecation Notice -->
                <div class="warning-banner">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Note:</strong> Player Owned Starbases (POS) are legacy structures. If CCP removes POS from the game, 
                    this entire tab can be removed without affecting other Structure Manager features.
                </div>
            </div>
            
            <!-- Reserves Tracking Tab -->
            <div class="tab-pane fade" id="reserves" role="tabpanel">
                <div class="tab-description">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Configure general reserves tracking settings that apply to all asset types (Upwell Structures and POSes). 
                        Control which corporate hangars are included in fuel reserves calculations.
                    </p>
                </div>
                
                <div class="settings-section">
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
                                    <i class="fas fa-boxes"></i> Division {{ $i }}
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
            
            <!-- Upwell Structures Tab -->
            <div class="tab-pane fade" id="upwell" role="tabpanel">
                <div class="tab-description">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Configure fuel alert thresholds and notification behavior for Upwell Structures (Citadels, Engineering Complexes, Refineries, Metenox Moon Drills).
                        These thresholds are independent from POS settings.
                    </p>
                </div>

                <!-- Fuel Alert Thresholds -->
                <div class="settings-section">
                    <h4><i class="fas fa-gas-pump"></i> Upwell Fuel Alert Thresholds</h4>

                    <div class="info-banner mb-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>Proactive alerts:</strong> Structure Manager checks fuel levels every 10 minutes and sends
                        Discord/Slack notifications on status transitions (good &rarr; warning &rarr; critical).
                        These thresholds are separate from POS settings because Upwell structures have different operational risk profiles.
                    </div>

                    <div class="threshold-group">
                        <!-- Critical threshold -->
                        <div class="threshold-item critical">
                            <div class="form-group">
                                <label for="upwell_fuel_critical_days">
                                    <i class="fas fa-exclamation-circle text-danger"></i>
                                    Critical Threshold (days)
                                </label>
                                <input type="number"
                                       class="form-control"
                                       id="upwell_fuel_critical_days"
                                       name="upwell_fuel_critical_days"
                                       value="{{ \StructureManager\Models\StructureManagerSettings::get('upwell_fuel_critical_days', 7) }}"
                                       min="1" max="90">
                                <small class="form-text text-muted">
                                    RED alert when structure has fewer than this many days of fuel. Default: 7 days.
                                </small>
                            </div>
                        </div>

                        <!-- Warning threshold -->
                        <div class="threshold-item warning">
                            <div class="form-group">
                                <label for="upwell_fuel_warning_days">
                                    <i class="fas fa-exclamation-triangle text-warning"></i>
                                    Warning Threshold (days)
                                </label>
                                <input type="number"
                                       class="form-control"
                                       id="upwell_fuel_warning_days"
                                       name="upwell_fuel_warning_days"
                                       value="{{ \StructureManager\Models\StructureManagerSettings::get('upwell_fuel_warning_days', 14) }}"
                                       min="1" max="90">
                                <small class="form-text text-muted">
                                    YELLOW alert when structure has fewer than this many days of fuel. Default: 14 days.
                                </small>
                            </div>
                        </div>

                        <!-- Final alert (not configurable) -->
                        <div class="threshold-item" style="border-left: 3px solid #6c757d; padding-left: 15px; margin-bottom: 15px;">
                            <div class="form-group mb-0">
                                <label><i class="fas fa-bell text-secondary"></i> Final Alert</label>
                                <p class="form-text text-muted mb-0">
                                    A final urgent notification fires automatically when a structure has <strong>1 hour or less</strong> of fuel remaining.
                                    This alert fires once per fuel-depletion event and re-arms when the structure recovers above the critical threshold.
                                    Not configurable.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notification Behavior -->
                <div class="settings-section">
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
                        <a href="#webhooks" data-toggle="tab" onclick="document.getElementById('webhooks-tab').click(); return false;"><strong>Webhook Configuration</strong></a>
                        tab. Bind the <code>upwell.fuel</code> / <code>upwell.magmatic_gas</code> categories to those webhooks on the
                        <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications page</strong></a>.
                    </div>
                </div>

                <!-- Metenox Information -->
                <div class="settings-section">
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

            <!-- Structure Events Tab (ESI Notifications) -->
            <div class="tab-pane fade" id="events" role="tabpanel">
                <div class="tab-description">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Detection mode for ESI-driven structure events (attacks, anchoring, destroyed, low power).
                        Category toggles, webhook bindings and role mentions now live on the
                        <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications</strong></a> page.
                    </p>
                </div>

                <!-- Detection mode: explicit operator choice -->
                @php($mcAvailable    = \StructureManager\Integrations\ManagerCoreIntegration::isAvailable())
                @php($configuredMode = \StructureManager\Integrations\ManagerCoreIntegration::detectionMode())
                @php($effectiveFast  = \StructureManager\Integrations\ManagerCoreIntegration::isFastPollEnabled())
                @php($effectiveSweep = \StructureManager\Integrations\ManagerCoreIntegration::isNativeSweepEnabled())

                <div class="settings-section">
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
                </div>

                <!-- Notification categories moved to Notifications page -->
                <div class="settings-section">
                    <h4><i class="fas fa-bell"></i> Notification Categories &amp; Webhooks</h4>
                    <div class="info-banner mb-3" style="border-left:4px solid #3498db;">
                        <i class="fas fa-arrow-right"></i>
                        Moved to
                        <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications</strong></a>.
                        That page shows per-category master toggles, default role mentions (with dropdown from
                        seat-connector / seat-discord-connector when installed), and per-webhook role overrides.
                    </div>
                </div>

                <!-- Attack Role Mention -->
                <div class="settings-section">
                    <h4><i class="fas fa-at"></i> Attack Alert Role Mention</h4>

                    <div class="form-group">
                        <label for="esi_attack_role_mention">Discord Role Mention for Attack Alerts</label>
                        <input type="text" class="form-control" id="esi_attack_role_mention"
                               name="esi_attack_role_mention"
                               value="{{ \StructureManager\Models\StructureManagerSettings::get('esi_attack_role_mention', '') }}"
                               placeholder="<@&123456789> or raw role ID"
                               style="max-width:400px;">
                        <small class="form-text text-muted">
                            Separate role mention for attack alerts (structure under attack, destroyed).
                            If empty, falls back to each webhook's own role mention setting.
                            Format: <code>&lt;@&amp;ROLE_ID&gt;</code> or just the numeric role ID.
                        </small>
                    </div>
                </div>

                <div class="info-banner">
                    <i class="fas fa-link"></i>
                    <strong>Shared webhooks:</strong> Structure event notifications use the same webhooks configured on the
                    <a href="#webhooks" data-toggle="tab" onclick="document.getElementById('webhooks-tab').click(); return false;"><strong>Webhook Configuration</strong></a>
                    tab. Bind the <code>events.*</code> categories to those webhooks on the
                    <a href="{{ route('structure-manager.notifications.index') }}"><strong>Notifications page</strong></a>,
                    where per-category role mentions override any legacy webhook role.
                </div>
            </div>

        </div>

        <!-- Save Buttons -->
        <div class="form-group mt-4">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Save Settings
            </button>
            <button type="button"
                    class="btn btn-warning"
                    onclick="if (confirm('Are you sure you want to reset all settings to defaults?')) { document.getElementById('structure-manager-reset-form').submit(); }">
                <i class="fas fa-undo"></i> Reset to Defaults
            </button>
            <a href="{{ route('structure-manager.index') }}" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>

    {{-- Separate reset form kept outside the main settings form because nested forms are invalid HTML --}}
    <form id="structure-manager-reset-form" method="POST" action="{{ route('structure-manager.settings.reset') }}" style="display:none;">
        @csrf
    </form>

</div>
</div>

<!-- Modal for Add/Edit Webhook -->
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
                        <input type="text" 
                               class="form-control" 
                               id="webhook_role_mention" 
                               name="role_mention" 
                               maxlength="100"
                               placeholder="<@&123456789> or 123456789">
                        <small class="text-muted">
                            Discord role mention for critical alerts. Format: <code>&lt;@&amp;ROLE_ID&gt;</code> or just the role ID number.<br>
                            <strong>Tip:</strong> Enable Developer Mode in Discord, right-click role → Copy ID
                        </small>
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

@endsection

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

// Remember active tab
$(document).ready(function() {
    // Check if there's a saved tab in localStorage
    const activeTab = localStorage.getItem('structure_manager_active_tab');
    if (activeTab) {
        $('#settingsTabs a[href="' + activeTab + '"]').tab('show');
    }
    
    // Save active tab when changed
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('structure_manager_active_tab', $(e.target).attr('href'));
    });
});

// ESI key holder pool management moved to Manager Core v1.x.
// See route('manager-core.esi-key-pool.index') for the admin UI.
</script>
@endpush
