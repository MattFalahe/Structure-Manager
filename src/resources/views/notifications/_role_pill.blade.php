{{--
    Resolved-role pill.

    Renders a stored role-mention value as a readable name when it resolves
    against an installed Discord role source, instead of showing a bare
    snowflake ID. Used by the Notification Routing Map and the Webhooks
    Summary inside the Settings notifications panel.

    Expects:
      $desc — result of DiscordRoleResolver::describeRoleMention(), or null
              for an empty / unset mention.
    Inherited from the parent (Settings notifications panel):
      $roleProviderAvailable — bool, whether any Discord role source exists.
--}}
@php($rp_hasProvider = $roleProviderAvailable ?? false)
@if(empty($desc))
    <span class="sm-role-none">No mention</span>
@elseif(!empty($desc['known']))
    {{-- Resolved against an installed role source — show the name + color. --}}
    <span class="sm-role-pill" title="Discord role ID {{ $desc['id'] }}">
        @if(!empty($desc['color']) && preg_match('/^#[0-9a-f]{6}$/i', $desc['color']))
            <span class="role-color-dot" style="background:{{ $desc['color'] }};"></span>
        @endif
        <span>{{ '@' . ($desc['name'] ?: ('Role ' . $desc['id'])) }}</span>
    </span>
@elseif(($desc['kind'] ?? '') === 'user')
    {{-- A user mention (<@ID>), not a role. Valid, just not a role ping. --}}
    <span class="sm-role-pill is-user" title="{{ $desc['raw'] }}">
        <i class="fas fa-user"></i>
        <span>User mention{{ !empty($desc['id']) ? ' (' . $desc['id'] . ')' : '' }}</span>
    </span>
@elseif(($desc['kind'] ?? '') === 'role')
    {{-- Looks like a role ID but didn't resolve. Only flag it as a problem
         when a role source is installed (without one, a raw ID is the
         expected manual format and there is nothing to resolve against). --}}
    @if($rp_hasProvider)
        <span class="sm-role-pill is-unknown" title="{{ $desc['raw'] }}">
            <i class="fas fa-question-circle"></i>
            <span>Role {{ $desc['id'] }} (not in any installed role list)</span>
        </span>
    @else
        <code style="font-size:0.75rem;">{{ $desc['raw'] }}</code>
    @endif
@else
    {{-- Not numeric and not a recognised mention shape — WebhookDispatcher
         drops malformed mentions, so this would never ping anyone. --}}
    <span class="sm-role-pill is-unknown" title="{{ $desc['raw'] }}">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Unrecognized (will not ping)</span>
    </span>
@endif
