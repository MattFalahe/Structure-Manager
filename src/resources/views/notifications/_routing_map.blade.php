{{--
    Notification Routing Map — read-only delivery snapshot.

    Rendered as its own Settings tab (#routing-map-section). For every
    notification category it shows each bound webhook and the Discord role
    that will actually be mentioned, resolved through the same three-tier
    precedence WebhookDispatcher::resolveBindings() applies at delivery time.

    Required variables (from SettingsController::index, inherited via the
    settings view scope):
      $categories            — NotificationCategory grouped by namespace
      $webhooks              — collection of WebhookConfiguration
      $bindings              — pivot rows grouped by category_id
      $namespaces            — namespace metadata (label / legacy / description)
      $smRoleLookup          — DiscordRoleResolver::roleLookupMap() result
      $roleProviderAvailable — bool (consumed by the _role_pill partial)

    Styling note: the resolved-role pill (.sm-role-pill family) is styled by
    the unscoped rules in notifications/_panel.blade.php, which is always
    rendered alongside this partial on the Settings page.
--}}
<style>
    .sm-routing-map .routing-map-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0.8rem 0 1.1rem;
    }
    .sm-routing-map .routing-stat {
        background: #1e222b;
        border: 1px solid #3a4049;
        border-radius: 6px;
        padding: 0.45rem 0.8rem;
        font-size: 0.8rem;
        color: #c2c7d0;
    }
    .sm-routing-map .routing-stat strong {
        color: #fff;
        font-size: 1.05rem;
        margin-right: 4px;
    }
    .sm-routing-map .routing-stat.warn {
        background: #3a2e16;
        border-color: #6b5424;
        color: #d4c69a;
    }
    .sm-routing-map .routing-stat.warn strong { color: #ffd96a; }
    .sm-routing-map .routing-ns-label {
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #8b95a5;
        font-weight: 600;
        margin: 1.1rem 0 0.4rem;
    }
    .sm-routing-map .routing-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.83rem;
        margin-bottom: 0.4rem;
    }
    .sm-routing-map .routing-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.35rem 0.6rem;
        border-bottom: 1px solid #3a4049;
    }
    .sm-routing-map .routing-table td {
        padding: 0.5rem 0.6rem;
        border-bottom: 1px solid #2a2f3a;
        vertical-align: top;
    }
    .sm-routing-map .routing-table tr:last-child td { border-bottom: none; }
    .sm-routing-map .routing-cat-cell {
        background: #20242e;
        border-right: 1px solid #313845;
        min-width: 170px;
    }
    .sm-routing-map .routing-cat-name { color: #fff; font-weight: 600; }
    .sm-routing-map .routing-cat-key {
        font-size: 0.69rem;
        color: #666c76;
        font-family: 'Courier New', monospace;
        margin-top: 2px;
    }
    .sm-routing-map .routing-row-disabled { opacity: 0.55; }
    .sm-routing-map .routing-dest { color: #e2e8f0; }
    .sm-routing-map .routing-arrow { color: #667eea; margin-right: 5px; }
    .sm-routing-map .routing-via {
        display: inline-block;
        font-size: 0.66rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        font-weight: 600;
        padding: 1px 6px;
        border-radius: 8px;
        margin-left: 6px;
        white-space: nowrap;
    }
    .sm-routing-map .routing-via.via-binding  { background: #4338ca; color: #e0e7ff; }
    .sm-routing-map .routing-via.via-category { background: #1c6f3e; color: #d4f4e2; }
    .sm-routing-map .routing-via.via-webhook  { background: #52616b; color: #fff; }
    .sm-routing-map .routing-none { color: #666c76; font-style: italic; }
    .sm-routing-map .routing-unrouted { color: #d4c69a; }
    .sm-routing-map .routing-status { font-size: 0.72rem; color: #8b95a5; margin-top: 2px; }
    .sm-routing-map .routing-status .off { color: #e0683c; }
    .sm-routing-map .routing-empty {
        color: #8b95a5;
        font-style: italic;
        padding: 0.6rem 0;
    }
</style>

@php
    // Manager Core availability — flags MC-dormant categories in the map.
    // Computed as a statement inside this block rather than as a separate
    // inline Blade php directive on the line above. An inline php directive
    // placed immediately before a php block confuses Blade's non-greedy
    // block-extraction regex and corrupts the compiled view.
    $mcAvailable = \StructureManager\Integrations\ManagerCoreIntegration::isAvailable();

    // Build the routing snapshot. For each category we resolve every bound
    // webhook to the role that would actually be mentioned, using the same
    // three-tier precedence WebhookDispatcher::resolveBindings() applies.
    $routingNamespaces = ['upwell', 'events', 'pos'];
    $routeStatTotal    = 0;
    $routeStatEnabled  = 0;
    $routeStatRouted   = 0;   // enabled + at least one live destination
    $routeStatSilent   = 0;   // enabled but no live destination (fires nowhere)
    $routingData       = [];

    foreach ($routingNamespaces as $rns) {
        $rnsCats = $categories[$rns] ?? collect();
        if ($rnsCats->isEmpty()) {
            continue;
        }
        $rnsRows = [];
        foreach ($rnsCats as $rcat) {
            $routeStatTotal++;
            if ($rcat->enabled) {
                $routeStatEnabled++;
            }
            // pre_timer_* categories ride Manager Core's EventBus. Without
            // MC installed they never fire, no matter how they are bound.
            $catMcBlocked = str_starts_with($rcat->category_key, 'pre_timer_') && !$mcAvailable;
            $rcatBindings = $bindings[$rcat->id] ?? collect();
            $rdests = [];
            foreach ($rcatBindings as $rb) {
                $rwh = $webhooks->firstWhere('id', $rb->webhook_id);
                if (!$rwh) {
                    continue;
                }
                // Three-tier precedence — mirrors WebhookDispatcher::resolveBindings().
                if ($rb->role_mention) {
                    $reff = $rb->role_mention;   $rvia = 'binding';
                } elseif ($rcat->role_mention) {
                    $reff = $rcat->role_mention; $rvia = 'category';
                } elseif ($rwh->role_mention) {
                    $reff = $rwh->role_mention;  $rvia = 'webhook';
                } else {
                    $reff = null;                $rvia = 'none';
                }
                $rdests[] = [
                    'webhook' => $rwh,
                    'binding' => $rb,
                    'via'     => $rvia,
                    'role'    => \StructureManager\Services\DiscordRoleResolver::describeRoleMention($reff, $smRoleLookup ?? []),
                    // Live = the alert actually reaches this channel: the
                    // category, the binding and the webhook must all be on,
                    // and (for pre_timer_* categories) Manager Core present.
                    'live'    => $rcat->enabled && $rb->enabled && $rwh->enabled && !$catMcBlocked,
                ];
            }
            $rliveDests = collect($rdests)->where('live', true)->count();
            if ($rcat->enabled) {
                if ($rliveDests > 0) {
                    $routeStatRouted++;
                } else {
                    $routeStatSilent++;
                }
            }
            $rnsRows[] = ['cat' => $rcat, 'dests' => $rdests, 'mcBlocked' => $catMcBlocked];
        }
        if (!empty($rnsRows)) {
            $routingData[$rns] = $rnsRows;
        }
    }
@endphp

<div class="sm-routing-map">
    <div class="settings-block">
        <h4><i class="fas fa-project-diagram"></i> Notification Routing Map</h4>

        <div class="routing-map-summary">
            <div class="routing-stat"><strong>{{ $routeStatTotal }}</strong> categories</div>
            <div class="routing-stat"><strong>{{ $routeStatEnabled }}</strong> enabled</div>
            <div class="routing-stat"><strong>{{ $routeStatRouted }}</strong> delivering</div>
            @if($routeStatSilent > 0)
                <div class="routing-stat warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{ $routeStatSilent }}</strong> enabled but firing nowhere
                </div>
            @endif
        </div>

        @forelse($routingData as $rns => $rnsRows)
            <div class="routing-ns-label">
                @if($rns === 'upwell') <i class="fas fa-building"></i>
                @elseif($rns === 'events') <i class="fas fa-bolt"></i>
                @else <i class="fas fa-broadcast-tower"></i>
                @endif
                {{ $namespaces[$rns]['label'] }}
            </div>
            <table class="routing-table">
                <thead>
                    <tr>
                        <th style="width:24%;">Category</th>
                        <th style="width:30%;">Delivers to</th>
                        <th style="width:18%;">Corporation</th>
                        <th style="width:28%;">Will mention</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rnsRows as $rrow)
                        @php($rcat = $rrow['cat'])
                        @php($rdests = $rrow['dests'])
                        @php($rcatMcBlocked = $rrow['mcBlocked'])
                        @if(count($rdests) === 0)
                            <tr class="{{ $rcat->enabled ? '' : 'routing-row-disabled' }}">
                                <td class="routing-cat-cell">
                                    <div class="routing-cat-name">
                                        <i class="fas fa-circle" style="font-size:0.5rem; vertical-align:middle; color:{{ $rcat->enabled ? '#28a745' : '#52616b' }};"></i>
                                        {{ $rcat->display_name }}
                                    </div>
                                    <div class="routing-cat-key">{{ $rcat->namespace }}.{{ $rcat->category_key }}</div>
                                </td>
                                <td colspan="3">
                                    @if(!$rcat->enabled)
                                        <span class="routing-none">Category disabled (no delivery).</span>
                                    @elseif($rcatMcBlocked)
                                        <span class="routing-unrouted">
                                            <i class="fas fa-puzzle-piece"></i>
                                            Requires Manager Core to fire (not installed); pre-timer reminders run on its EventBus. Not bound to any webhook either.
                                        </span>
                                    @else
                                        <span class="routing-unrouted">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            Enabled but not bound to any webhook (this category fires nowhere).
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            @foreach($rdests as $ri => $rd)
                                @php($rwh = $rd['webhook'])
                                <tr class="{{ $rd['live'] ? '' : 'routing-row-disabled' }}">
                                    @if($ri === 0)
                                        <td class="routing-cat-cell" rowspan="{{ count($rdests) }}">
                                            <div class="routing-cat-name">
                                                <i class="fas fa-circle" style="font-size:0.5rem; vertical-align:middle; color:{{ $rcat->enabled ? '#28a745' : '#52616b' }};"></i>
                                                {{ $rcat->display_name }}
                                            </div>
                                            <div class="routing-cat-key">{{ $rcat->namespace }}.{{ $rcat->category_key }}</div>
                                            @if(!$rcat->enabled)
                                                <div class="routing-status"><span class="off">Category disabled</span></div>
                                            @elseif($rcatMcBlocked)
                                                <div class="routing-status"><span class="off">Dormant: needs Manager Core (not installed)</span></div>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="routing-dest">
                                        <i class="fas fa-arrow-right routing-arrow"></i>{{ $rwh->description ?: 'Webhook #' . $rwh->id }}
                                        @if(!$rwh->enabled)
                                            <div class="routing-status"><span class="off">webhook disabled</span></div>
                                        @elseif(!$rd['binding']->enabled)
                                            <div class="routing-status"><span class="off">binding paused</span></div>
                                        @endif
                                    </td>
                                    <td>{{ $rwh->corporation_id ? $rwh->getCorporationLabel() : 'All corps' }}</td>
                                    <td>
                                        @include('structure-manager::notifications._role_pill', ['desc' => $rd['role']])
                                        @if($rd['via'] === 'binding')
                                            <span class="routing-via via-binding" title="L1 Binding override: role set on this webhook's binding row">L1 override</span>
                                        @elseif($rd['via'] === 'category')
                                            <span class="routing-via via-category" title="L2 Category default role mention">L2 category</span>
                                        @elseif($rd['via'] === 'webhook')
                                            <span class="routing-via via-webhook" title="L3 Webhook legacy role (set in Webhook Configuration)">L3 webhook</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
            </table>
        @empty
            <div class="routing-empty">No notification categories found.</div>
        @endforelse
    </div>
</div>
