<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model for webhook configurations
 *
 * Allows multiple webhooks with corporation filtering.
 *
 * Since v3.1 the role_mention column here is a LEGACY FALLBACK only.
 * Primary role mention resolution happens in NotificationCategory (category
 * default) and structure_manager_category_webhook.role_mention (per-binding
 * override). See WebhookDispatcher::resolveBindings for the precedence rules.
 */
class WebhookConfiguration extends Model
{
    protected $table = 'structure_manager_webhooks';
    
    protected $fillable = [
        'webhook_url',
        'corporation_id',
        'enabled',
        'description',
        'role_mention',
    ];
    
    protected $casts = [
        'corporation_id' => 'integer',
        'enabled' => 'boolean',
    ];

    /**
     * Notification categories this webhook is bound to (via the category_webhook pivot).
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            NotificationCategory::class,
            'structure_manager_category_webhook',
            'webhook_id',
            'category_id'
        )->withPivot(['id', 'enabled', 'role_mention', 'role_source', 'role_id'])
         ->withTimestamps();
    }

    /**
     * Get all enabled webhooks
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEnabled()
    {
        return self::where('enabled', true)->get();
    }
    
    /**
     * Get webhooks for a specific corporation
     * Includes both corporation-specific webhooks and "all corporations" webhooks
     * 
     * @param int $corporationId Corporation ID
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForCorporation($corporationId)
    {
        return self::where('enabled', true)
            ->where(function($query) use ($corporationId) {
                $query->whereNull('corporation_id') // All corporations
                      ->orWhere('corporation_id', $corporationId); // Specific corporation
            })
            ->get();
    }
    
    /**
     * Get display label for corporation filter
     * 
     * @return string
     */
    public function getCorporationLabel()
    {
        if ($this->corporation_id === null) {
            return 'All Corporations';
        }
        
        // Try to get corporation name from SeAT
        $corp = \DB::table('corporation_infos')
            ->where('corporation_id', $this->corporation_id)
            ->first();
            
        return $corp ? $corp->name : "Corporation #{$this->corporation_id}";
    }
    
    /**
     * Validate webhook URL format
     *
     * Requires:
     * - Parseable URL with https:// scheme (no http, file://, javascript:, etc.)
     * - Host ends in one of the allowlisted webhook domains (end-anchored, not substring)
     * - No embedded userinfo (user:pass@)
     * - Default ports only (no :22, :8080, etc.)
     *
     * This prevents SSRF via hostnames like evil.discord.com.attacker.example or
     * discord.com.evil.example which would pass a naive substring match.
     *
     * @param string $url
     * @return bool
     */
    public static function isValidWebhookUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        // Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // Enforce HTTPS only
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'https') {
            return false;
        }

        // Reject userinfo segments (e.g. https://user:pass@discord.com/...)
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        // Reject non-default ports (Discord/Slack only serve 443)
        if (isset($parsed['port']) && (int) $parsed['port'] !== 443) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // End-anchored host allowlist.
        // Matches discord.com / discord.com subdomains, slack.com / slack.com subdomains,
        // and discordapp.com (legacy). Does NOT match evil.discord.com.attacker.example.
        $allowedSuffixes = [
            'discord.com',
            'discordapp.com',
            'slack.com',
        ];

        foreach ($allowedSuffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
