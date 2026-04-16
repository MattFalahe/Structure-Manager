<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fast-polled ESI notification deduplication and audit trail table.
//
// Structure Manager polls ESI's notifications endpoint directly from director
// characters in a round-robin pattern, bypassing SeAT's bucket system for
// 10-15x faster detection of structure attacks. This table stores every
// structure-related notification the plugin has seen (from either fast-poll
// or SeAT fallback), deduplicates by CCP's unique notification_id, and
// tracks whether a webhook dispatch has been sent.
return new class extends Migration
{
    public function up()
    {
        Schema::create('structure_manager_esi_notifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('notification_id')->unique()
                ->comment('CCP globally unique notification ID — dedup key');
            $table->bigInteger('character_id')
                ->comment('Director character we polled this from');
            $table->bigInteger('corporation_id')->index()
                ->comment('Corporation this notification pertains to');
            $table->string('type', 100)->index()
                ->comment('CCP notification type string (e.g. StructureUnderAttack)');
            $table->bigInteger('sender_id')->nullable()
                ->comment('Entity that sent the notification');
            $table->string('sender_type', 50)->nullable()
                ->comment('character, corporation, alliance, faction, other');
            $table->dateTime('timestamp')
                ->comment('When CCP generated the notification');
            $table->text('text')->nullable()
                ->comment('Raw YAML payload from CCP');
            $table->json('parsed_data')->nullable()
                ->comment('Plugin-extracted key fields for quick access');
            $table->string('source', 20)->default('fast_poll')
                ->comment('fast_poll or seat_fallback');
            $table->boolean('processed')->default(false)
                ->comment('True after webhook dispatch sent');
            $table->dateTime('processed_at')->nullable()
                ->comment('When webhook dispatch was sent');
            $table->timestamps();

            $table->index(['corporation_id', 'type']);
            $table->index(['processed', 'type']);
            $table->index('timestamp');
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_manager_esi_notifications');
    }
};
