<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for webhook configurations
 * 
 * Allows multiple webhooks with corporation filtering
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
     * @param string $url
     * @return bool
     */
    public static function isValidWebhookUrl($url)
    {
        // Check if it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check if it's a Discord or Slack webhook
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        
        return stripos($host, 'discord.com') !== false || 
               stripos($host, 'slack.com') !== false ||
               stripos($host, 'hooks.slack.com') !== false;
    }
}
