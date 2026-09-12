<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the fuel reserve "latest row per tuple" lookup and for the
 * retention prune.
 *
 * Two separate access patterns were doing full scans:
 *
 * 1. The tracking jobs look up the most recent reserve row for a
 *    (starbase|structure, location, resource) tuple. The only usable index
 *    led on starbase_id / structure_id, so MySQL scanned every row that
 *    tower had ever written and then filesorted it ("Creating sort index").
 *    On a 28-POS install that was ~113k rows scanned per lookup, a few
 *    hundred times per run.
 *
 * 2. Retention deletes filter on a bare created_at, and no index led on
 *    created_at, so every prune was a full table scan.
 *
 * All index creation is guarded. Operators who already added the lookup
 * index by hand while diagnosing this (the name below is the one that was
 * circulated) will simply have it skipped.
 *
 * Note for large installs: ADD INDEX on a multi-million-row reserves table
 * is an online (INPLACE) operation on InnoDB but still takes time. Expect
 * the container restart that runs this migration to take longer than usual.
 */
class AddStructureManagerReserveLookupIndexes extends Migration
{
    /**
     * table => [index name => columns]
     */
    private const INDEXES = [
        'starbase_fuel_reserves' => [
            'sbfr_lookup_latest_idx' => ['starbase_id', 'location_id', 'resource_type_id', 'created_at'],
            'sbfr_created_idx'       => ['created_at'],
        ],
        'structure_fuel_reserves' => [
            'sfr_lookup_latest_idx' => ['structure_id', 'fuel_type_id', 'location_flag', 'created_at'],
            'sfr_created_idx'       => ['created_at'],
        ],
        'starbase_fuel_history' => [
            'sbfh_created_idx'    => ['created_at'],
            'sbfh_latest_per_idx' => ['starbase_id', 'id'],
        ],
        'structure_fuel_history' => [
            'sfh_created_idx'    => ['created_at'],
            'sfh_latest_per_idx' => ['structure_id', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if ($this->indexExists($table, $indexName)) {
                    continue;
                }

                // A column can be missing on an install that never ran a
                // later schema extension. Skip rather than fail the boot.
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue 2;
                    }
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                    $blueprint->index($columns, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $indexName) {
                if (! $this->indexExists($table, $indexName)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }
        }
    }

    /**
     * Check whether a named index exists on a table via information_schema.
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

        return ! empty($rows) && (int) $rows[0]->cnt > 0;
    }
}
