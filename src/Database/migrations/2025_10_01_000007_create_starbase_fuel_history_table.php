<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for POS (Player Owned Starbase) fuel tracking history
 * 
 * Tracks fuel blocks, strontium clathrates, and starbase charters over time
 * Similar to structure_fuel_history but for legacy control towers
 */
class CreateStarbaseFuelHistoryTable extends Migration
{
    public function up()
    {
        Schema::create('starbase_fuel_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('starbase_id'); // POS ID from corporation_starbases
            $table->bigInteger('corporation_id');
            $table->integer('tower_type_id'); // Control tower type (for fuel calculations)
            $table->string('starbase_name')->nullable()->comment('Custom POS name from game (can be renamed)');
            $table->bigInteger('system_id')->nullable()->comment('System ID for reference');
            
            // Fuel block tracking
            $table->integer('fuel_blocks_quantity')->nullable()->comment('Current fuel blocks in tower');
            $table->decimal('fuel_days_remaining', 10, 2)->nullable()->comment('Days of fuel remaining');
            $table->integer('fuel_blocks_used')->nullable()->comment('Fuel blocks consumed since last check');
            $table->decimal('fuel_hourly_consumption', 10, 4)->nullable()->comment('Actual fuel consumption rate');
            
            // Strontium tracking (reinforced mode)
            $table->integer('strontium_quantity')->nullable()->comment('Current strontium clathrates');
            $table->decimal('strontium_hours_available', 10, 2)->nullable()->comment('Hours of reinforcement timer');
            $table->string('strontium_status', 20)->nullable()->comment('critical/warning/fair/good');
            
            // Charter tracking (high-sec only)
            $table->integer('charter_quantity')->nullable()->comment('Current starbase charters');
            $table->decimal('charter_days_remaining', 10, 2)->nullable()->comment('Days of charters remaining');
            $table->boolean('requires_charters')->default(false)->comment('True if in high-sec');
            
            // Calculated data
            $table->decimal('actual_days_remaining', 10, 2)->nullable()->comment('Limiting factor (fuel/charters)');
            $table->string('limiting_factor', 20)->nullable()->comment('fuel/charters - which runs out first');
            $table->dateTime('estimated_fuel_expiry')->nullable()->comment('When fuel/charters run out');
            
            // System context
            $table->decimal('system_security', 8, 6)->nullable()->comment('System security level');
            $table->string('space_type', 20)->nullable()->comment('High-Sec/Low-Sec/Null-Sec');
            
            // Metadata
            $table->json('metadata')->nullable()->comment('Additional tracking data');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['starbase_id', 'created_at']);
            $table->index('corporation_id');
            $table->index('strontium_status');
            $table->index('estimated_fuel_expiry');
        });
    }

    public function down()
    {
        Schema::dropIfExists('starbase_fuel_history');
    }
}
