<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latch for the fuel events other plugins listen for.
 *
 * structure.alert.fuel_critical and fuel_recovered used to go out only
 * alongside Structure Manager's own Discord fuel alert, so a structure with
 * no fuel webhook bound never told Mining Manager it was running dry. They
 * now follow the fuel itself, and this column remembers what subscribers
 * were last told, so each drop into critical is published once and not on
 * every poll.
 *
 * It lives in a column rather than the cache because the recovery it owes
 * can be days away: a structure that runs out of fuel drops off SeAT's
 * structure list until it is refuelled, and seat:cache:clear empties Redis.
 *
 * Additive and guarded. Existing rows are left as they are, so a structure
 * that is already critical when this lands is published on the first run
 * after the upgrade.
 */
class AddStructureManagerFuelEventStatus extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('structure_notification_status')
            || Schema::hasColumn('structure_notification_status', 'fuel_event_status')
        ) {
            return;
        }

        Schema::table('structure_notification_status', function (Blueprint $table) {
            $table->string('fuel_event_status', 20)->nullable()
                ->comment('Fuel state other plugins were last told: critical, warning while a fuel_recovered is still owed, null when nothing is');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('structure_notification_status')
            || ! Schema::hasColumn('structure_notification_status', 'fuel_event_status')
        ) {
            return;
        }

        Schema::table('structure_notification_status', function (Blueprint $table) {
            $table->dropColumn('fuel_event_status');
        });
    }
}
