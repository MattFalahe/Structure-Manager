<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFuelTrackingFields extends Migration
{
    public function up()
    {
        // Add tracking columns to history table for enhanced fuel bay tracking
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                if (!Schema::hasColumn('structure_fuel_history', 'consumption_rate')) {
                    $table->decimal('consumption_rate', 10, 4)->nullable()->after('daily_consumption');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'tracking_type')) {
                    $table->string('tracking_type', 50)->default('unknown')->after('consumption_rate');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'metadata')) {
                    $table->json('metadata')->nullable()->after('tracking_type');
                }
            });
        }
    }
    
    public function down()
    {
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                // Only drop columns if they exist
                $columnsToDrop = [];
                
                if (Schema::hasColumn('structure_fuel_history', 'consumption_rate')) {
                    $columnsToDrop[] = 'consumption_rate';
                }
                if (Schema::hasColumn('structure_fuel_history', 'tracking_type')) {
                    $columnsToDrop[] = 'tracking_type';
                }
                if (Schema::hasColumn('structure_fuel_history', 'metadata')) {
                    $columnsToDrop[] = 'metadata';
                }
                
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
}
