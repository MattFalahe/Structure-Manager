<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use StructureManager\Models\StructureManagerSettings;

/**
 * Add reserves tracking settings
 * 
 * This migration adds default settings for hangar exclusion
 * and ensures proper categorization of settings
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add reserves tracking settings if they don't exist
        if (!StructureManagerSettings::where('key', 'excluded_hangars')->exists()) {
            StructureManagerSettings::create([
                'key' => 'excluded_hangars',
                'value' => '',
                'type' => 'string',
                'category' => 'reserves',
                'description' => 'Comma-separated list of hangar divisions to exclude from fuel reserves tracking',
            ]);
        }
        
        // Ensure existing settings have proper categories
        $settingCategories = [
            // Notification settings
            'pos_webhook_enabled' => 'notifications',
            'pos_webhook_url' => 'notifications',
            'pos_discord_role_mention' => 'notifications',
            'pos_fuel_notification_interval' => 'notifications',
            'pos_strontium_notification_interval' => 'notifications',
            
            // Threshold settings
            'pos_strontium_critical_hours' => 'thresholds',
            'pos_strontium_warning_hours' => 'thresholds',
            'pos_strontium_good_hours' => 'thresholds',
            'pos_fuel_critical_days' => 'thresholds',
            'pos_fuel_warning_days' => 'thresholds',
            'pos_charter_critical_days' => 'thresholds',
        ];
        
        foreach ($settingCategories as $key => $category) {
            StructureManagerSettings::where('key', $key)
                ->update(['category' => $category]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the reserves tracking setting
        StructureManagerSettings::where('key', 'excluded_hangars')->delete();
        
        // Optionally reset categories to 'general'
        StructureManagerSettings::whereIn('key', [
            'pos_webhook_enabled',
            'pos_webhook_url',
            'pos_discord_role_mention',
            'pos_fuel_notification_interval',
            'pos_strontium_notification_interval',
            'pos_strontium_critical_hours',
            'pos_strontium_warning_hours',
            'pos_strontium_good_hours',
            'pos_fuel_critical_days',
            'pos_fuel_warning_days',
            'pos_charter_critical_days',
        ])->update(['category' => 'general']);
    }
};
