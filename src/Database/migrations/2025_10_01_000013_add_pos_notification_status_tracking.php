<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add notification status tracking to starbase fuel history
 * 
 * Tracks last notification state per POS to enable status-change-based alerts
 * instead of time-interval-based spam
 */
class AddPosNotificationStatusTracking extends Migration
{
    public function up()
    {
        Schema::table('starbase_fuel_history', function (Blueprint $table) {
            // Fuel/Charter notification tracking
            $table->string('last_fuel_notification_status', 20)->nullable()
                ->after('limiting_factor')
                ->comment('Last notification status: good/warning/critical');
            
            $table->timestamp('last_fuel_notification_at')->nullable()
                ->after('last_fuel_notification_status')
                ->comment('When last fuel/charter notification was sent');
            
            $table->boolean('fuel_final_alert_sent')->default(false)
                ->after('last_fuel_notification_at')
                ->comment('True if final 30-min alert was sent');
            
            // Strontium notification tracking (separate)
            $table->string('last_strontium_notification_status', 20)->nullable()
                ->after('strontium_status')
                ->comment('Last strontium notification status: good/warning/critical');
            
            $table->timestamp('last_strontium_notification_at')->nullable()
                ->after('last_strontium_notification_status')
                ->comment('When last strontium notification was sent');
            
            $table->boolean('strontium_final_alert_sent')->default(false)
                ->after('last_strontium_notification_at')
                ->comment('True if strontium final alert was sent');
            
            // Index for efficient queries
            $table->index('last_fuel_notification_status');
            $table->index('last_strontium_notification_status');
        });
    }

    public function down()
    {
        Schema::table('starbase_fuel_history', function (Blueprint $table) {
            $table->dropIndex(['last_fuel_notification_status']);
            $table->dropIndex(['last_strontium_notification_status']);
            
            $table->dropColumn([
                'last_fuel_notification_status',
                'last_fuel_notification_at',
                'fuel_final_alert_sent',
                'last_strontium_notification_status',
                'last_strontium_notification_at',
                'strontium_final_alert_sent',
            ]);
        });
    }
}
