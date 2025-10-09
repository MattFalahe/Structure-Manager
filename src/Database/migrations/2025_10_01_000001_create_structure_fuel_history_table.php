<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStructureFuelHistoryTable extends Migration
{
    public function up()
    {
        Schema::create('structure_fuel_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id');
            $table->bigInteger('corporation_id');
            $table->dateTime('fuel_expires')->nullable();
            $table->integer('days_remaining')->nullable();
            $table->integer('fuel_blocks_used')->nullable();
            $table->decimal('daily_consumption', 10, 2)->nullable();
            $table->timestamps();
            
            $table->index(['structure_id', 'created_at']);
            $table->index('corporation_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_fuel_history');
    }
}
