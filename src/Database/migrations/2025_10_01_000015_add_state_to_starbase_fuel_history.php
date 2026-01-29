<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration to add dedicated state column to starbase_fuel_history
 * 
 * Previously state was only stored in metadata JSON column.
 * This migration:
 * 1. Adds a dedicated state column for better querying
 * 2. Migrates existing state data from metadata->state to the new column
 * 3. Adds an index for performance
 */
class AddStateToStarbaseFuelHistory extends Migration
{
    public function up()
    {
        // Add state column to starbase_fuel_history
        Schema::table('starbase_fuel_history', function (Blueprint $table) {
            $table->tinyInteger('state')->nullable()->after('system_id')->comment('POS state: 0=unanchored, 1=offline, 2=onlining, 3=reinforced, 4=online');
            $table->index('state');
        });
        
        // Migrate existing state data from metadata to new column
        // Note: SeAT stores state as STRING in metadata (e.g., "online", "offline")
        // We need to convert these strings to integers
        try {
            $stateMap = [
                'unanchored' => 0,
                'offline' => 1,
                'onlining' => 2,
                'reinforced' => 3,
                'online' => 4,
                'unanchoring' => 5,
            ];
            
            // Get all records with state in metadata
            $records = DB::table('starbase_fuel_history')
                ->whereNotNull('metadata')
                ->whereRaw("JSON_EXTRACT(metadata, '$.state') IS NOT NULL")
                ->select('id', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state')) as state_string"))
                ->get();
            
            $updated = 0;
            foreach ($records as $record) {
                $stateString = strtolower(trim($record->state_string));
                $stateInt = $stateMap[$stateString] ?? null;
                
                if ($stateInt !== null) {
                    DB::table('starbase_fuel_history')
                        ->where('id', $record->id)
                        ->update(['state' => $stateInt]);
                    $updated++;
                }
            }
            
            \Illuminate\Support\Facades\Log::info("Migration: Migrated {$updated} state values from metadata to state column");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Migration: Could not migrate state from metadata: ' . $e->getMessage());
            // Don't fail migration if this fails - state will be populated on next tracking run
        }
    }

    public function down()
    {
        Schema::table('starbase_fuel_history', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropColumn('state');
        });
    }
}
