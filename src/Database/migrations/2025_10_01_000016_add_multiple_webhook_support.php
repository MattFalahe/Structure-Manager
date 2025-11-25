<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add support for multiple webhooks with corporation filtering
 * 
 * This migration:
 * 1. Creates a new table for webhook configurations
 * 2. Migrates existing single webhook to the new table
 * 3. Adds corporation_id filtering support
 */
class AddMultipleWebhookSupport extends Migration
{
    public function up()
    {
        // Create new webhooks table
        Schema::create('structure_manager_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_url', 500)->comment('Discord/Slack webhook URL');
            $table->bigInteger('corporation_id')->nullable()->comment('Corporation ID filter (null = all corporations)');
            $table->boolean('enabled')->default(true)->comment('Whether this webhook is active');
            $table->string('description', 255)->nullable()->comment('Optional description/label for this webhook');
            $table->timestamps();
            
            $table->index('corporation_id');
            $table->index('enabled');
        });
        
        // Migrate existing webhook to new table if it exists
        $existingWebhook = DB::table('structure_manager_settings')
            ->where('key', 'pos_webhook_url')
            ->first();
            
        $webhookEnabled = DB::table('structure_manager_settings')
            ->where('key', 'pos_webhook_enabled')
            ->first();
        
        if ($existingWebhook && $existingWebhook->value) {
            DB::table('structure_manager_webhooks')->insert([
                'webhook_url' => $existingWebhook->value,
                'corporation_id' => null, // Null = all corporations (preserve existing behavior)
                'enabled' => $webhookEnabled ? (bool)$webhookEnabled->value : true,
                'description' => 'Migrated from legacy webhook',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        
        // Add new settings for strontium notification behavior
        $settings = [
            [
                'key' => 'pos_strontium_zero_notify_once',
                'value' => '1',
                'type' => 'boolean',
                'category' => 'notifications',
                'description' => 'For online POSes with 0 strontium, only notify once instead of on interval'
            ],
            [
                'key' => 'pos_strontium_zero_grace_period',
                'value' => '2',
                'type' => 'integer',
                'category' => 'notifications',
                'description' => 'Grace period in hours before considering 0 strontium as "accepted risk" (default: 2h)'
            ],
        ];
        
        foreach ($settings as $setting) {
            DB::table('structure_manager_settings')->insertOrIgnore($setting);
        }
    }

    public function down()
    {
        // Migrate first webhook back to settings if exists
        $firstWebhook = DB::table('structure_manager_webhooks')
            ->where('enabled', true)
            ->first();
            
        if ($firstWebhook) {
            DB::table('structure_manager_settings')
                ->where('key', 'pos_webhook_url')
                ->update(['value' => $firstWebhook->webhook_url]);
                
            DB::table('structure_manager_settings')
                ->where('key', 'pos_webhook_enabled')
                ->update(['value' => '1']);
        }
        
        // Drop the webhooks table
        Schema::dropIfExists('structure_manager_webhooks');
        
        // Remove new settings
        DB::table('structure_manager_settings')
            ->whereIn('key', [
                'pos_strontium_zero_notify_once',
                'pos_strontium_zero_grace_period'
            ])
            ->delete();
    }
}
