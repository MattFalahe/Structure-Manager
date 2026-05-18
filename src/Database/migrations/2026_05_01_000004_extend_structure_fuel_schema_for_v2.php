<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — schema extensions for fuel forensics + external reserves.
 *
 * Consolidates two schema migrations into one logical "v2 fuel-tracking
 * schema upgrade":
 *
 *   PART A — Fuel event classification (Tier 1 + Tier 2 fuel forensics)
 *   --------------------------------------------------------------------
 *   v1.x labelled every negative bay delta as "refuel" and every positive
 *   delta as "consumption". There was no event class for "withdrawal", so
 *   a 5,000-block theft looked identical to a 4,032-block legitimate
 *   refuel. v2.0.0's FuelEventClassifier + WithdrawalForensicsService
 *   require new columns + a new table to back the classification +
 *   suspect-narrowing forensics.
 *
 *     • structure_fuel_history.event_type            (enum-like string)
 *     • structure_fuel_history.expected_consumption  (decimal)
 *     • structure_fuel_history.unexplained_delta     (integer)
 *     • structure_fuel_history.reserves_delta        (integer)
 *     • structure_fuel_event_candidates              (table — forensic candidates)
 *
 *   Hard ESI limit recorded for future maintainers: ESI does NOT expose
 *   actor identity for asset moves. structure_fuel_event_candidates stores
 *   PROBABILISTIC inferences from collateral SeAT data (who was online,
 *   who gained matching fuel in their personal hangar, who has the role,
 *   who sold the same fuel on the market shortly after). It is NOT a
 *   record of who actually moved the asset. Operator approval workflow
 *   (Tier 4, future) is the trust mechanism that lets the suite learn
 *   "this withdrawal is fine."
 *
 *   PART B — External reserves tracking
 *   ------------------------------------
 *   v1.x reserves tracking only saw CorpSAG fuel inside the corp's OWN
 *   structures. In reality corps stage fuel in NPC stations (rented
 *   Offices) and inside other corps' Upwells (rented Offices in
 *   alliance-mates' structures). v2.0.0 tracks all three; this migration
 *   adds the denormalized location columns the new sweep populates so
 *   the UI doesn't have to LEFT JOIN universe_structures / staStations
 *   on every render.
 *
 *     • structure_fuel_reserves.location_type        (enum-like string)
 *     • structure_fuel_reserves.location_name        (denormalized)
 *     • structure_fuel_reserves.location_system_id   (FK-ish, no constraint)
 *     • structure_fuel_reserves.location_system_name (denormalized)
 *
 *   Backwards compatibility: existing v1.x rows are set to
 *   `location_type='owned_structure'` so the model + UI keep working
 *   without any backfill of names (names get populated on the next poll
 *   for active rows). New rows from this poll onward get location_type
 *   populated at write time by TrackFuelConsumption.
 *
 * Backfill philosophy: existing rows in structure_fuel_history stay at
 * event_type='unclassified'. Backfilling retroactively would be
 * approximate (no historical service-state data to compute expected
 * consumption against) and would mislead operators who look at "old
 * withdrawal events" that weren't classified at write-time.
 *
 * Idempotency: every operation is guarded by Schema::hasTable +
 * Schema::hasColumn. Re-running is a no-op once columns exist.
 *
 * Filename → class name (Laravel migration resolver requirement):
 *   2026_05_01_000004_extend_structure_fuel_schema_for_v2
 *   → ExtendStructureFuelSchemaForV2
 */
class ExtendStructureFuelSchemaForV2 extends Migration {

    public function up(): void
    {
        // ============================================================
        // PART A.1 — structure_fuel_history columns + index
        // ============================================================
        // Columns + indexes are added in SEPARATE Schema::table calls so a
        // leftover index from a partial earlier state (e.g. a tester who
        // dropped columns but where multi-column index name persisted in
        // information_schema) doesn't block column re-creation. The index
        // existence check uses information_schema directly to survive
        // arbitrary partial states.
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                if (!Schema::hasColumn('structure_fuel_history', 'event_type')) {
                    $table->string('event_type', 40)->default('unclassified')->after('consumption_rate')
                        ->comment('Classification: consumption_normal/anomaly, refuel_internal/external, withdrawal_bay/reserves, unexplained_gain, unclassified');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'expected_consumption')) {
                    $table->decimal('expected_consumption', 10, 2)->nullable()->after('event_type')
                        ->comment('Blocks expected to be consumed during this interval, from active services');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'unexplained_delta')) {
                    $table->integer('unexplained_delta')->nullable()->after('expected_consumption')
                        ->comment('Actual bay delta minus expected_consumption. Positive = more burned/removed than expected');
                }
                if (!Schema::hasColumn('structure_fuel_history', 'reserves_delta')) {
                    $table->integer('reserves_delta')->nullable()->after('unexplained_delta')
                        ->comment('Net change across all CorpSAG hangars for the structure in the same poll window');
                }
            });

            if (Schema::hasColumn('structure_fuel_history', 'event_type')
                && !$this->indexExists('structure_fuel_history', 'sfh_structure_event_idx')) {
                Schema::table('structure_fuel_history', function (Blueprint $table) {
                    $table->index(['structure_id', 'event_type'], 'sfh_structure_event_idx');
                });
            }
        }

        // ============================================================
        // PART A.2 — structure_fuel_event_candidates table
        // ============================================================
        if (!Schema::hasTable('structure_fuel_event_candidates')) {
            Schema::create('structure_fuel_event_candidates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fuel_history_id')
                    ->comment('FK to structure_fuel_history.id - the withdrawal event being investigated');
                $table->bigInteger('character_id')
                    ->comment('Candidate character_id (corp member at time of event)');
                $table->string('character_name', 255)->nullable()
                    ->comment('Denormalized character name for display - resolved from character_infos at write time');
                $table->unsignedBigInteger('corporation_id')->nullable()
                    ->comment('Corp ID at time of event - in case character has since changed corps');
                $table->enum('confidence', ['HIGH', 'MEDIUM', 'LOW'])
                    ->comment('Bucketed score - HIGH >=60, MEDIUM 30-59, LOW 10-29');
                $table->unsignedTinyInteger('score')
                    ->comment('Raw score 0-100. Rows with score <10 are not stored');
                $table->json('signals')->nullable()
                    ->comment('Which signals matched: online_during_window, asset_gain_match, has_role, wallet_sale_match - JSON array with details');
                $table->timestamps();

                $table->index(['fuel_history_id', 'confidence', 'score'], 'sfec_event_confidence_idx');
                $table->index('character_id', 'sfec_character_idx');

                $table->foreign('fuel_history_id')
                    ->references('id')->on('structure_fuel_history')
                    ->onDelete('cascade');
            });
        }

        // ============================================================
        // PART B — structure_fuel_reserves location columns + indexes
        // ============================================================
        if (Schema::hasTable('structure_fuel_reserves')) {
            Schema::table('structure_fuel_reserves', function (Blueprint $table) {
                if (!Schema::hasColumn('structure_fuel_reserves', 'location_type')) {
                    // owned_structure   — corp owns the Upwell (v1.x behavior)
                    // foreign_structure — fuel sits in another corp's Upwell (resolved via universe_structures)
                    // npc_station       — fuel sits in an NPC station (resolved via staStations / mapDenormalize)
                    // unknown_location  — corporation_assets.location_type='other' or unresolvable
                    $table->string('location_type', 32)->default('owned_structure')->after('location_flag')
                        ->comment('Where the fuel sits: owned_structure / foreign_structure / npc_station / unknown_location');
                }
                if (!Schema::hasColumn('structure_fuel_reserves', 'location_name')) {
                    $table->string('location_name', 255)->nullable()->after('location_type')
                        ->comment('Denormalized location name resolved at write time — universe_structures.name / staStations.stationName / "Unknown Location"');
                }
                if (!Schema::hasColumn('structure_fuel_reserves', 'location_system_id')) {
                    $table->unsignedBigInteger('location_system_id')->nullable()->after('location_name')
                        ->comment('Solar system ID for grouping in UI - resolved from universe_structures.solar_system_id or staStations.solarSystemID');
                }
                if (!Schema::hasColumn('structure_fuel_reserves', 'location_system_name')) {
                    $table->string('location_system_name', 64)->nullable()->after('location_system_id')
                        ->comment('Denormalized solar system name for display - resolved from mapDenormalize at write time');
                }
            });

            // Create indexes separately so leftover index names from partial
            // states don't block column creation. Each idempotent.
            if (Schema::hasColumn('structure_fuel_reserves', 'location_type')
                && !$this->indexExists('structure_fuel_reserves', 'sfr_corp_loctype_idx')) {
                Schema::table('structure_fuel_reserves', function (Blueprint $table) {
                    $table->index(['corporation_id', 'location_type'], 'sfr_corp_loctype_idx');
                });
            }
            if (Schema::hasColumn('structure_fuel_reserves', 'location_system_id')
                && !$this->indexExists('structure_fuel_reserves', 'sfr_loc_system_idx')) {
                Schema::table('structure_fuel_reserves', function (Blueprint $table) {
                    $table->index('location_system_id', 'sfr_loc_system_idx');
                });
            }

            // Backfill: every row that existed BEFORE this migration is an owned
            // structure (v1.x reserves tracking only saw corp-owned Upwells).
            // Mark them so the new getExternalReserves() / model helpers branch
            // correctly. New rows from this poll onward get location_type
            // populated at write time by TrackFuelConsumption.
            DB::table('structure_fuel_reserves')
                ->whereNull('location_type')
                ->update(['location_type' => 'owned_structure']);
        }
    }

    /**
     * Check whether a named index exists on a table via information_schema.
     * Survives partial states where a multi-column index's column was dropped
     * but the index name was retained by the engine.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$table, $indexName]
        );
        return !empty($rows) && (int) $rows[0]->cnt > 0;
    }

    public function down(): void
    {
        // PART B reversal
        if (Schema::hasTable('structure_fuel_reserves')) {
            Schema::table('structure_fuel_reserves', function (Blueprint $table) {
                if (Schema::hasColumn('structure_fuel_reserves', 'location_system_name')) {
                    $table->dropColumn('location_system_name');
                }
                if (Schema::hasColumn('structure_fuel_reserves', 'location_system_id')) {
                    $table->dropIndex('sfr_loc_system_idx');
                    $table->dropColumn('location_system_id');
                }
                if (Schema::hasColumn('structure_fuel_reserves', 'location_name')) {
                    $table->dropColumn('location_name');
                }
                if (Schema::hasColumn('structure_fuel_reserves', 'location_type')) {
                    $table->dropIndex('sfr_corp_loctype_idx');
                    $table->dropColumn('location_type');
                }
            });
        }

        // PART A reversal
        if (Schema::hasTable('structure_fuel_event_candidates')) {
            Schema::drop('structure_fuel_event_candidates');
        }
        if (Schema::hasTable('structure_fuel_history')) {
            Schema::table('structure_fuel_history', function (Blueprint $table) {
                if (Schema::hasColumn('structure_fuel_history', 'reserves_delta')) {
                    $table->dropColumn('reserves_delta');
                }
                if (Schema::hasColumn('structure_fuel_history', 'unexplained_delta')) {
                    $table->dropColumn('unexplained_delta');
                }
                if (Schema::hasColumn('structure_fuel_history', 'expected_consumption')) {
                    $table->dropColumn('expected_consumption');
                }
                if (Schema::hasColumn('structure_fuel_history', 'event_type')) {
                    $table->dropIndex('sfh_structure_event_idx');
                    $table->dropColumn('event_type');
                }
            });
        }
    }
}
