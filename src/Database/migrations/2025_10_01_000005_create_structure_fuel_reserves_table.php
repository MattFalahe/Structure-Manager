<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStructureFuelReservesTable extends Migration
{
    public function up()
    {
        Schema::create('structure_fuel_reserves', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id');
            $table->bigInteger('corporation_id');
            $table->integer('fuel_type_id'); // Which fuel block type
            $table->integer('reserve_quantity'); // Blocks in CorpSAG hangars
            $table->string('location_flag', 50); // Which hangar division
            $table->integer('previous_quantity')->nullable(); // For tracking movements
            $table->integer('quantity_change')->nullable(); // Positive = added, negative = moved to bay
            $table->boolean('is_refuel_event')->default(false); // True when moved to fuel bay
            $table->json('metadata')->nullable(); // Additional tracking info
            $table->timestamps();
            
            $table->index(['structure_id', 'created_at']);
            $table->index('corporation_id');
            $table->index('is_refuel_event');
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_fuel_reserves');
    }
}
