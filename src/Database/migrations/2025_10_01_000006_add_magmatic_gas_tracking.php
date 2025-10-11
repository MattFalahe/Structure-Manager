<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMagmaticGasTracking extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds magmatic gas tracking fields for Metenox Moon Drill support
     */
    public function up()
    {
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                // Add magmatic gas quantity tracking
                if (!Schema::hasColumn('structure_fuel_history', 'magmatic_gas_quantity')) {
                    $table->integer('magmatic_gas_quantity')->nullable()->after('metadata')
                          ->comment('Magmatic gas quantity for Metenox Moon Drills');
                }
                
                // Add magmatic gas days remaining
                if (!Schema::hasColumn('structure_fuel_history', 'magmatic_gas_days')) {
                    $table->decimal('magmatic_gas_days', 10, 2)->nullable()->after('magmatic_gas_quantity')
                          ->comment('Days of magmatic gas remaining for Metenox');
                }
            });
        }
    }
    
    /**
     * Reverse the migrations.
     */
    public function down()
    {
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                $columnsToDrop = [];
                
                if (Schema::hasColumn('structure_fuel_history', 'magmatic_gas_quantity')) {
                    $columnsToDrop[] = 'magmatic_gas_quantity';
                }
                if (Schema::hasColumn('structure_fuel_history', 'magmatic_gas_days')) {
                    $columnsToDrop[] = 'magmatic_gas_days';
                }
                
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
}
