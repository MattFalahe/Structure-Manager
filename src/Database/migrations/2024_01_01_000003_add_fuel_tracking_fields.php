<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFuelTrackingFields extends Migration
{
    public function up()
    {
        // Add tracking columns to existing table if needed
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                if (!Schema::hasColumn('structure_fuel_history', 'consumption_rate')) {
                    $table->decimal('consumption_rate', 10, 4)->nullable()->after('daily_consumption');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'tracking_type')) {
                    $table->string('tracking_type', 50)->default('snapshot')->after('consumption_rate');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'metadata')) {
                    $table->json('metadata')->nullable()->after('tracking_type');
                }
            });
        }
        
        // Don't create the table again if it exists from previous migration
        if (!Schema::hasTable('structure_fuel_consumption')) {
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
    }
    
    public function down()
    {
        Schema::dropIfExists('structure_fuel_consumption');
        
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                $table->dropColumn(['consumption_rate', 'tracking_type', 'metadata']);
            });
        }
    }
}
