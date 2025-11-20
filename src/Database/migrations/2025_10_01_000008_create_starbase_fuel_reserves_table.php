<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for POS (Player Owned Starbase) fuel reserves tracking
 * 
 * Tracks fuel blocks, strontium, and charters stored in:
 * - Upwell structures (Citadels, Refineries, Engineering Complexes)
 * - NPC stations
 * - Any location with corporate hangars
 * 
 * POSes don't have hangars - reserves must be stored elsewhere!
 * Detects refuel events when reserves decrease (moved to POS fuel bay)
 */
class CreateStarbaseFuelReservesTable extends Migration
{
    public function up()
    {
        Schema::create('starbase_fuel_reserves', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('starbase_id')->nullable()->comment('Nearest/associated POS (null if general corp reserves)');
            $table->bigInteger('corporation_id');
            $table->bigInteger('location_id')->comment('Structure/Station ID where reserves are stored (NOT the POS)');
            
            // Resource tracking
            $table->integer('resource_type_id')->comment('Type ID (fuel blocks, strontium, or charters)');
            $table->string('resource_category', 20)->comment('fuel/strontium/charter');
            $table->integer('reserve_quantity')->comment('Quantity in hangar');
            $table->string('location_flag', 50)->comment('Which hangar division');
            
            // Change tracking
            $table->integer('previous_quantity')->nullable()->comment('Previous scan quantity');
            $table->integer('quantity_change')->nullable()->comment('Change since last scan');
            $table->boolean('is_refuel_event')->default(false)->comment('True when moved to POS');
            $table->dateTime('refuel_detected_at')->nullable()->comment('When refuel was detected');
            
            // Context
            $table->json('metadata')->nullable()->comment('Additional tracking info');
            $table->timestamps();
            
            // Indexes (custom names to avoid MySQL 64-char limit)
            $table->index(['starbase_id', 'resource_category', 'created_at'], 'sbfr_sb_rc_created_idx');
            $table->index('corporation_id', 'sbfr_corp_idx');
            $table->index('location_id', 'sbfr_location_idx');
            $table->index('resource_type_id', 'sbfr_resource_type_idx');
            $table->index('is_refuel_event', 'sbfr_refuel_event_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('starbase_fuel_reserves');
    }
}
