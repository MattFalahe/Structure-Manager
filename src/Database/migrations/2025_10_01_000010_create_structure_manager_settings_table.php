<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for Structure Manager settings
 * 
 * Stores plugin configuration including:
 * - Webhook URLs for notifications
 * - Custom thresholds for alerts
 * - Feature toggles
 */
class CreateStructureManagerSettingsTable extends Migration
{
    public function up()
    {
        Schema::create('structure_manager_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Setting key (e.g., pos_webhook_url)');
            $table->text('value')->nullable()->comment('Setting value (JSON for complex values)');
            $table->string('type', 50)->default('string')->comment('Value type: string, integer, boolean, json');
            $table->string('category', 50)->default('general')->comment('Settings category: general, pos, notifications, thresholds');
            $table->text('description')->nullable()->comment('Human-readable description');
            $table->timestamps();
            
            $table->index('category');
        });
        
        // Insert default settings
        $defaults = [
            // POS Notification Webhooks
            [
                'key' => 'pos_webhook_url',
                'value' => null,
                'type' => 'string',
                'category' => 'notifications',
                'description' => 'Discord/Slack webhook URL for POS fuel alerts'
            ],
            [
                'key' => 'pos_webhook_enabled',
                'value' => '0',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'Enable/disable POS webhook notifications'
            ],
            
            // POS Strontium Thresholds
            [
                'key' => 'pos_strontium_critical_hours',
                'value' => '6',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Critical alert threshold for strontium (hours)'
            ],
            [
                'key' => 'pos_strontium_warning_hours',
                'value' => '12',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Warning alert threshold for strontium (hours)'
            ],
            [
                'key' => 'pos_strontium_good_hours',
                'value' => '24',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Good threshold for strontium (hours)'
            ],
            
            // POS Fuel Thresholds
            [
                'key' => 'pos_fuel_critical_days',
                'value' => '7',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Critical alert threshold for POS fuel (days)'
            ],
            [
                'key' => 'pos_fuel_warning_days',
                'value' => '14',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Warning alert threshold for POS fuel (days)'
            ],
            
            // Charter Thresholds (high-sec)
            [
                'key' => 'pos_charter_critical_days',
                'value' => '7',
                'type' => 'integer',
                'category' => 'thresholds',
                'description' => 'Critical alert threshold for charters (days)'
            ],
            
            // Discord Role Mention
            [
                'key' => 'pos_discord_role_mention',
                'value' => null,
                'type' => 'string',
                'category' => 'notifications',
                'description' => 'Discord role mention for critical alerts (e.g., <@&123456789>)'
            ],
            
            // Notification Intervals (0 = disabled, status change only)
            [
                'key' => 'pos_fuel_notification_interval',
                'value' => '0',
                'type' => 'integer',
                'category' => 'notifications',
                'description' => 'Hours between fuel/charter critical reminders (0 = status change only)'
            ],
            [
                'key' => 'pos_strontium_notification_interval',
                'value' => '0',
                'type' => 'integer',
                'category' => 'notifications',
                'description' => 'Hours between strontium critical reminders (0 = status change only)'
            ],
        ];
        
        foreach ($defaults as $default) {
            DB::table('structure_manager_settings')->insert($default);
        }
    }

    public function down()
    {
        Schema::dropIfExists('structure_manager_settings');
    }
}
