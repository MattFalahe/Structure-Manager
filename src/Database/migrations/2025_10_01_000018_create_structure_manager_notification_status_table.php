<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dedicated notification-state table for Upwell structure fuel alerts.
//
// Unlike the POS approach (where notification tracking fields live on the
// latest starbase_fuel_history row and must be "copied forward"), this table
// stores one row per fueled structure. This design is immune to history
// cleanup/pruning and avoids the latch-propagation fragility that caused
// bugs in the POS notification flow.
return new class extends Migration
{
    public function up()
    {
        Schema::create('structure_manager_notification_status', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id')->unique()
                ->comment('Upwell structure ID from corporation_structures');
            $table->bigInteger('corporation_id')->index()
                ->comment('Corporation ID for corp-scoped queries');

            // Fuel notification tracking
            $table->string('last_fuel_notification_status', 20)->nullable()
                ->comment('Last fuel notification status: good/warning/critical');
            $table->timestamp('last_fuel_notification_at')->nullable()
                ->comment('When last fuel notification was sent');
            $table->boolean('fuel_final_alert_sent')->default(false)
                ->comment('True if the 1-hour final alert was sent');

            // Gas notification tracking (Metenox only)
            $table->string('last_gas_notification_status', 20)->nullable()
                ->comment('Last magmatic gas notification status (Metenox only)');
            $table->timestamp('last_gas_notification_at')->nullable()
                ->comment('When last gas notification was sent');

            $table->timestamps();

            $table->index('last_fuel_notification_status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_manager_notification_status');
    }
};
