<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for POS (Player Owned Starbase) fuel consumption tracking
 * 
 * Aggregates daily consumption data for fuel blocks, strontium, and charters
 * Detects anomalies in consumption patterns (service changes, offline periods)
 */
class CreateStarbaseFuelConsumptionTable extends Migration
{
    public function up()
    {
        Schema::create('starbase_fuel_consumption', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('starbase_id');
            $table->bigInteger('corporation_id');
            $table->date('date');
            
            // Fuel block consumption
            $table->decimal('fuel_daily_consumption', 10, 2)->comment('Fuel blocks consumed per day');
            $table->decimal('fuel_hourly_rate', 10, 4)->comment('Average fuel per hour');
            $table->integer('fuel_refuel_amount')->nullable()->comment('Fuel blocks added (if refueled)');
            
            // Strontium consumption (during reinforced)
            $table->decimal('strontium_consumption', 10, 2)->nullable()->comment('Strontium used (if reinforced)');
            $table->boolean('was_reinforced')->default(false)->comment('True if POS was reinforced');
            $table->integer('strontium_refuel_amount')->nullable()->comment('Strontium added');
            
            // Charter consumption (high-sec only)
            $table->decimal('charter_consumption', 10, 2)->nullable()->comment('Charters consumed per day');
            $table->integer('charter_refuel_amount')->nullable()->comment('Charters added');
            $table->boolean('required_charters')->default(false)->comment('True if in high-sec');
            
            // Anomaly detection
            $table->boolean('has_anomaly')->default(false)->comment('Unexpected consumption detected');
            $table->json('anomaly_details')->nullable()->comment('Details about the anomaly');
            
            // Metadata
            $table->json('metadata')->nullable()->comment('Additional tracking data');
            $table->timestamps();
            
            // Indexes
            $table->unique(['starbase_id', 'date']);
            $table->index('starbase_id');
            $table->index('corporation_id');
            $table->index('date');
            $table->index('has_anomaly');
            $table->index('was_reinforced');
        });
    }

    public function down()
    {
        Schema::dropIfExists('starbase_fuel_consumption');
    }
}
