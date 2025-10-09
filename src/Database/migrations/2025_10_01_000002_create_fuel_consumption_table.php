<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFuelConsumptionTable extends Migration
{
    public function up()
    {
        // Create aggregated consumption table
        Schema::create('structure_fuel_consumption', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id');
            $table->date('date');
            $table->decimal('actual_daily_consumption', 10, 2);
            $table->decimal('average_hourly_rate', 10, 4);
            $table->integer('refuel_amount')->nullable();
            $table->boolean('has_anomaly')->default(false);
            $table->json('anomaly_details')->nullable();
            $table->timestamps();
            
            $table->unique(['structure_id', 'date']);
            $table->index('structure_id');
            $table->index('date');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('structure_fuel_consumption');
    }
}
