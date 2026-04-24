<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ESI key holder pool for fast structure notification polling.
//
// Admins assign director characters from SeAT to this pool. The polling
// job round-robins through enabled key holders, skipping any with expired
// tokens or recent failures. The more characters in the pool, the faster
// detection AND the more fault-tolerant the system.
class CreateStructureManagerEsiKeyHoldersTable extends Migration
{
    public function up()
    {
        Schema::create('structure_manager_esi_key_holders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('character_id')->unique()
                ->comment('SeAT character_id (matches refresh_tokens PK)');
            $table->bigInteger('corporation_id')->index()
                ->comment('Corporation the character belongs to');
            $table->string('character_name', 100)->nullable()
                ->comment('Cached name for display (from character_infos)');
            $table->boolean('enabled')->default(true)
                ->comment('Admin toggle: include in polling rotation');

            // Polling state (managed by the job, not admin)
            $table->dateTime('last_polled_at')->nullable()
                ->comment('When this character was last polled');
            $table->string('last_poll_status', 20)->nullable()
                ->comment('success, failed, token_expired, scope_missing, rate_limited');
            $table->text('last_error')->nullable()
                ->comment('Error message from last failed poll');
            $table->integer('consecutive_failures')->default(0)
                ->comment('Failures in a row — used for backoff');
            $table->integer('total_polls')->default(0)
                ->comment('Lifetime poll count for this character');
            $table->integer('total_notifications_found')->default(0)
                ->comment('Lifetime new notifications discovered via this character');

            $table->timestamps();

            $table->index(['enabled', 'last_polled_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_manager_esi_key_holders');
    }
}
