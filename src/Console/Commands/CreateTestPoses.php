<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Create test POSes for development and testing
 * 
 * This command creates fake POSes in multiple corporations with fuel reserves
 * for testing webhook filtering, notifications, and fuel consumption
 * 
 * Usage:
 * php artisan structure-manager:create-test-poses
 * php artisan structure-manager:create-test-poses --corporations=5 --poses-per-corp=3
 * php artisan structure-manager:create-test-poses --fast-consumption
 */
class CreateTestPoses extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'structure-manager:create-test-poses
                            {--corporations=3 : Number of test corporations to create (max 10)}
                            {--poses-per-corp=2 : Number of POSes per corporation (max 10)}
                            {--fast-consumption : Enable 20-minute consumption cycles for testing}
                            {--cleanup : Remove all test data}';

    /**
     * The console command description.
     */
    protected $description = 'Create test POSes with fuel for testing webhooks and notifications (SAFE ranges: corps 2.1B+, POSes 2.2B+, far outside EVE allocation)';


    /**
     * POS tower type IDs and their properties
     */
    private $towerTypes = [
        // Amarr Towers
        12235 => ['name' => 'Amarr Control Tower', 'fuel_per_hour' => 40, 'strontium_capacity' => 50000],
        16213 => ['name' => 'Amarr Control Tower Medium', 'fuel_per_hour' => 20, 'strontium_capacity' => 25000],
        20059 => ['name' => 'Amarr Control Tower Small', 'fuel_per_hour' => 10, 'strontium_capacity' => 12500],
        
        // Caldari Towers
        16214 => ['name' => 'Caldari Control Tower', 'fuel_per_hour' => 40, 'strontium_capacity' => 50000],
        16217 => ['name' => 'Caldari Control Tower Medium', 'fuel_per_hour' => 20, 'strontium_capacity' => 25000],
        20060 => ['name' => 'Caldari Control Tower Small', 'fuel_per_hour' => 10, 'strontium_capacity' => 12500],
        
        // Gallente Towers
        12236 => ['name' => 'Gallente Control Tower', 'fuel_per_hour' => 40, 'strontium_capacity' => 50000],
        16221 => ['name' => 'Gallente Control Tower Medium', 'fuel_per_hour' => 20, 'strontium_capacity' => 25000],
        20061 => ['name' => 'Gallente Control Tower Small', 'fuel_per_hour' => 10, 'strontium_capacity' => 12500],
        
        // Minmatar Towers
        16216 => ['name' => 'Minmatar Control Tower', 'fuel_per_hour' => 40, 'strontium_capacity' => 50000],
        16220 => ['name' => 'Minmatar Control Tower Medium', 'fuel_per_hour' => 20, 'strontium_capacity' => 25000],
        20062 => ['name' => 'Minmatar Control Tower Small', 'fuel_per_hour' => 10, 'strontium_capacity' => 12500],
    ];

    /**
     * Test corporation names
     */
    private $corpNames = [
        'Test Corporation Alpha',
        'Test Corporation Beta',
        'Test Corporation Gamma',
        'Test Corporation Delta',
        'Test Corporation Epsilon',
        'Test Corporation Zeta',
        'Test Corporation Eta',
        'Test Corporation Theta',
    ];

    /**
     * Test solar system IDs (various security levels)
     */
    private $testSystems = [
        // High-sec
        30000142 => ['name' => 'Jita', 'security' => 0.9, 'requires_charters' => true],
        30002187 => ['name' => 'Amarr', 'security' => 1.0, 'requires_charters' => true],
        
        // Low-sec
        30002053 => ['name' => 'Amamake', 'security' => 0.4, 'requires_charters' => false],
        30002187 => ['name' => 'Tama', 'security' => 0.3, 'requires_charters' => false],
        
        // Null-sec (using some random IDs for testing)
        30004970 => ['name' => 'Test Null System 1', 'security' => -0.5, 'requires_charters' => false],
        30004971 => ['name' => 'Test Null System 2', 'security' => -0.8, 'requires_charters' => false],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $corpCount = (int) $this->option('corporations');
        $posesPerCorp = (int) $this->option('poses-per-corp');
        $fastConsumption = $this->option('fast-consumption');
        
        // Enforce safety limits
        $maxCorps = 10;
        $maxPosesPerCorp = 10;
        
        if ($corpCount > $maxCorps) {
            $this->warn("Limiting corporations to {$maxCorps} (safety limit to avoid real EVE corp IDs)");
            $corpCount = $maxCorps;
        }
        
        if ($posesPerCorp > $maxPosesPerCorp) {
            $this->warn("Limiting POSes per corp to {$maxPosesPerCorp} (safety limit)");
            $posesPerCorp = $maxPosesPerCorp;
        }

        $this->info("Creating test data...");
        $this->info("Corporations: {$corpCount}");
        $this->info("POSes per corp: {$posesPerCorp}");
        $this->info("Fast consumption: " . ($fastConsumption ? 'YES (20-min cycles)' : 'NO (normal hourly)'));
        $this->newLine();
        
        $this->info("ℹ️  SAFE ID ranges (far outside EVE's normal allocation):");
        $this->info("  Corporations: 2100000000-2100000099 (2.1 billion range)");
        $this->info("  POSes: 2200000000-2200000999 (2.2 billion range)");
        $this->info("  Assets: 22000000000+ (based on POS IDs)");
        $this->line("  ✅ These ranges do NOT overlap with any real EVE data");
        $this->newLine();

        // Create test corporations (no character linking due to PRIMARY KEY constraint)
        $corporations = $this->createTestCorporations($corpCount, null);
        
        $totalPoses = 0;
        foreach ($corporations as $corp) {
            $this->info("Creating POSes for {$corp['name']} (ID: {$corp['corporation_id']})...");
            
            $poses = $this->createTestPosesForCorp($corp, $posesPerCorp, $fastConsumption);
            $totalPoses += count($poses);
            
            foreach ($poses as $pos) {
                $this->line("  ✓ {$pos['name']} - Fuel: {$pos['fuel_days']}d, Strontium: {$pos['strontium_hours']}h");
            }
            $this->newLine();
        }

        $this->info("✓ Created {$totalPoses} test POSes in {$corpCount} corporations");
        
        if ($fastConsumption) {
            $this->warn("⚡ Fast consumption enabled - fuel will be consumed every 20 minutes");
            $this->info("Run the fuel tracking job to see consumption:");
            $this->line("  php artisan structure-manager:track-poses-fuel");
        }
        
        $this->newLine();
        $this->info("Test webhooks with these corporations to verify filtering!");
        $this->info("To remove test data, run: php artisan structure-manager:create-test-poses --cleanup");
    }
    
    /**
     * Get character ID for linking test corporations
     * 
     * @return int|null
     */

    /**
     * Create test corporations
     */
    private function createTestCorporations($count, $characterId = null)
    {
        $corporations = [];
        // Use 2.1 billion range - FAR outside EVE's normal corp ID allocation
        // Real EVE corps are in 98million-99million range
        $baseCorpId = 2100000000; // 2.1 billion - safe from real corps
        $maxTestCorps = 100; // Maximum test corporations
        
        // Safety check
        if ($count > $maxTestCorps) {
            $this->error("Cannot create more than {$maxTestCorps} test corporations (safety limit)");
            $count = $maxTestCorps;
        }
        
        for ($i = 0; $i < $count; $i++) {
            $corpId = $baseCorpId + $i;
            $corpName = $this->corpNames[$i % count($this->corpNames)] . " #{$i}";
            
            // Check if corporation already exists in corporation_infos
            $existingCorp = DB::table('corporation_infos')
                ->where('corporation_id', $corpId)
                ->first();
            
            if (!$existingCorp) {
                DB::table('corporation_infos')->insert([
                    'corporation_id' => $corpId,
                    'name' => $corpName,
                    'ticker' => 'TST' . $i,
                    'member_count' => rand(10, 100),
                    'ceo_id' => 1,
                    'creator_id' => 1, // Required field
                    'alliance_id' => null,
                    'description' => 'Test corporation for webhook and notification testing',
                    'tax_rate' => 0.1,
                    'url' => 'https://test.corp',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                
                // Note: character_affiliations uses character_id as PRIMARY KEY
                // Each character can only belong to ONE corporation
                // We cannot link multiple test corporations without breaking the user's real affiliation
                // So we skip this and rely on admin permissions instead
                
                if ($characterId && $i == 0) {
                    // Only show this message once
                    $this->newLine();
                    $this->warn("⚠️  Note: Due to SeAT's permission model, we cannot link test corporations to your character.");
                    $this->warn("Each character can only belong to ONE corporation in character_affiliations.");
                    $this->newLine();
                    $this->info("Test corporations will be created but may not be visible in some UI pages.");
                    $this->info("If you have admin/superuser access, you may still be able to see them.");
                    $this->newLine();
                }
            }
            
            $corporations[] = [
                'corporation_id' => $corpId,
                'name' => $corpName,
            ];
        }
        
        return $corporations;
    }

    /**
     * Create test POSes for a corporation
     */
    private function createTestPosesForCorp($corp, $count, $fastConsumption)
    {
        $poses = [];
        // Use 2.2 billion range - FAR outside EVE's normal starbase ID allocation
        // Calculate offset based on corporation's position (0-99)
        $corpOffset = ($corp['corporation_id'] - 2100000000) * 10; // Each corp gets 10 POS IDs  
        $baseStarbaseId = 2200000000 + $corpOffset; // 2.2 billion base
        
        // Safety check - don't exceed our safe POS ID range
        if ($baseStarbaseId + $count > 2200001000) {
            $this->error("POS ID range exceeded! Reduce corporations or POSes per corp.");
            return [];
        }
        
        $towerTypeIds = array_keys($this->towerTypes);
        $systemIds = array_keys($this->testSystems);
        
        for ($i = 0; $i < $count; $i++) {
            $starbaseId = $baseStarbaseId + $i;
            $towerTypeId = $towerTypeIds[array_rand($towerTypeIds)];
            $towerInfo = $this->towerTypes[$towerTypeId];
            $systemId = $systemIds[array_rand($systemIds)];
            $systemInfo = $this->testSystems[$systemId];
            
            // Random fuel levels for variety
            $fuelScenarios = [
                ['days' => 30, 'strontium' => 72], // Good
                ['days' => 10, 'strontium' => 8], // Warning
                ['days' => 5, 'strontium' => 4], // Critical
                ['days' => 1, 'strontium' => 0], // Very critical / Zero strontium
                ['days' => 15, 'strontium' => 48], // Normal
            ];
            
            $scenario = $fuelScenarios[$i % count($fuelScenarios)];
            
            // Calculate quantities
            $fuelPerHour = $fastConsumption ? $towerInfo['fuel_per_hour'] * 3 : $towerInfo['fuel_per_hour'];
            $fuelQuantity = (int) ($scenario['days'] * 24 * $fuelPerHour);
            $strontiumQuantity = (int) ($scenario['strontium'] * 200); // ~200 per hour consumption
            
            $posName = "{$towerInfo['name']} - Test {$i}";
            
            // Create in corporation_starbases
            DB::table('corporation_starbases')->updateOrInsert(
                [
                    'starbase_id' => $starbaseId,
                    'corporation_id' => $corp['corporation_id']
                ],
                [
                    'moon_id' => null,
                    'system_id' => $systemId,
                    'type_id' => $towerTypeId,
                    'state' => 'online', // ENUM: 'offline','online','onlining','reinforced','unanchoring'
                    'onlined_since' => Carbon::now()->subDays(30),
                    'reinforced_until' => null,
                    'unanchor_at' => null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
            
            // Create fuel history
            $metadata = [
                'system_name' => $systemInfo['name'],
                'tower_type' => $towerInfo['name'],
                'fuel_per_hour' => $fuelPerHour,
            ];
            
            DB::table('starbase_fuel_history')->insert([
                'starbase_id' => $starbaseId,
                'corporation_id' => $corp['corporation_id'],
                'tower_type_id' => $towerTypeId,
                'starbase_name' => $posName,
                'system_id' => $systemId,
                'state' => 4, // Online
                'fuel_blocks_quantity' => $fuelQuantity,
                'fuel_days_remaining' => $scenario['days'],
                'fuel_blocks_used' => 0,
                'fuel_hourly_consumption' => $fuelPerHour,
                'strontium_quantity' => $strontiumQuantity,
                'strontium_hours_available' => $scenario['strontium'],
                'strontium_status' => $scenario['strontium'] < 6 ? 'critical' : ($scenario['strontium'] < 12 ? 'warning' : 'good'),
                'charter_quantity' => $systemInfo['requires_charters'] ? 1000 : null,
                'charter_days_remaining' => $systemInfo['requires_charters'] ? 30 : null,
                'requires_charters' => $systemInfo['requires_charters'],
                'actual_days_remaining' => $scenario['days'],
                'limiting_factor' => 'fuel',
                'estimated_fuel_expiry' => Carbon::now()->addDays($scenario['days']),
                'system_security' => $systemInfo['security'],
                'space_type' => $systemInfo['security'] >= 0.5 ? 'High-Sec' : ($systemInfo['security'] > 0 ? 'Low-Sec' : 'Null-Sec'),
                'metadata' => json_encode($metadata),
                'last_fuel_notification_status' => null,
                'last_fuel_notification_at' => null,
                'fuel_final_alert_sent' => false,
                'last_strontium_notification_status' => null,
                'last_strontium_notification_at' => null,
                'strontium_final_alert_sent' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Add fuel to corporation hangar (for consumption tracking)
            $hangarDivision = rand(1, 7); // Random hangar
            
            // Fuel blocks (type 4051)
            DB::table('corporation_assets')->insert([
                'corporation_id' => $corp['corporation_id'],
                'item_id' => $starbaseId * 10 + 1, // Unique item ID
                'type_id' => 4051, // Fuel Block type
                'quantity' => $fuelQuantity + 10000, // Extra fuel for consumption
                'location_id' => $systemId,
                'location_type' => 'station',
                'location_flag' => 'CorpSAG' . $hangarDivision,
                'is_singleton' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Strontium Clathrates (type 16275)
            if ($strontiumQuantity > 0 || rand(0, 1)) { // Add strontium even if POS has 0 (in hangar)
                DB::table('corporation_assets')->insert([
                    'corporation_id' => $corp['corporation_id'],
                    'item_id' => $starbaseId * 10 + 2, // Unique item ID
                    'type_id' => 16275, // Strontium Clathrates
                    'quantity' => max($strontiumQuantity, 5000), // At least some strontium
                    'location_id' => $systemId,
                    'location_type' => 'station',
                    'location_flag' => 'CorpSAG' . $hangarDivision,
                    'is_singleton' => false,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
            
            // Add charters if high-sec
            if ($systemInfo['requires_charters']) {
                DB::table('corporation_assets')->insert([
                    'corporation_id' => $corp['corporation_id'],
                    'item_id' => $starbaseId * 10 + 3, // Unique item ID
                    'type_id' => 24592, // Sovereignty Charter (high-sec)
                    'quantity' => 2000,
                    'location_id' => $systemId,
                    'location_type' => 'station',
                    'location_flag' => 'CorpSAG' . $hangarDivision,
                    'is_singleton' => false,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
            
            $poses[] = [
                'starbase_id' => $starbaseId,
                'name' => $posName,
                'fuel_days' => $scenario['days'],
                'strontium_hours' => $scenario['strontium'],
                'system' => $systemInfo['name'],
            ];
        }
        
        return $poses;
    }

    /**
     * Cleanup test data
     */
    private function cleanup()
    {
        $this->warn("⚠️  CLEANUP MODE - Removing test data");
        $this->newLine();
        
        // Define CURRENT SAFE test ID ranges (2.1-2.3 billion - outside EVE's normal allocation)
        $testCorpMin = 2100000000;
        $testCorpMax = 2100000099;  // 100 test corp IDs
        $testPosMin = 2200000000;
        $testPosMax = 2200000999;   // 1000 test POS IDs (100 corps * 10 POSes)
        $testAssetMin = 22000000000;
        $testAssetMax = 22000009999; // Asset IDs based on POS IDs
        
        // Define LEGACY test ID ranges (from older versions - will also be cleaned)
        $legacyRanges = [
            // Very old range (99 billion)
            ['type' => 'pos', 'min' => 99000000000, 'max' => 99999999999],
            // Old range (1 billion)
            ['type' => 'pos', 'min' => 1000000000, 'max' => 1000000099],
            ['type' => 'asset', 'min' => 10000000000, 'max' => 10000000099],
            // Unsafe corp range (DO NOT USE FOR NEW DATA!)
            ['type' => 'corp', 'min' => 98000000, 'max' => 98000099],
        ];
        
        // Check current test data
        $corpsToDelete = DB::table('corporation_infos')
            ->whereBetween('corporation_id', [$testCorpMin, $testCorpMax])
            ->get();
        
        $posesToDelete = DB::table('corporation_starbases')
            ->whereBetween('starbase_id', [$testPosMin, $testPosMax])
            ->count();
        
        // Check for legacy data
        $legacyPoses = 0;
        $legacyCorps = collect();
        
        foreach ($legacyRanges as $range) {
            if ($range['type'] === 'pos') {
                $legacyPoses += DB::table('corporation_starbases')
                    ->whereBetween('starbase_id', [$range['min'], $range['max']])
                    ->count();
            } elseif ($range['type'] === 'corp') {
                $found = DB::table('corporation_infos')
                    ->whereBetween('corporation_id', [$range['min'], $range['max']])
                    ->get();
                $legacyCorps = $legacyCorps->merge($found);
            }
        }
        
        if ($corpsToDelete->isEmpty() && $posesToDelete == 0 && $legacyPoses == 0 && $legacyCorps->isEmpty()) {
            $this->info("No test data found to clean up.");
            return 0;
        }
        
        $this->info("Found test data to remove:");
        $this->newLine();
        
        if ($corpsToDelete->count() > 0) {
            $this->line("  • {$corpsToDelete->count()} test corporations (IDs: {$testCorpMin}-{$testCorpMax})");
            $this->table(
                ['Corporation ID', 'Name'],
                $corpsToDelete->map(fn($c) => [$c->corporation_id, $c->name])->toArray()
            );
        }
        
        if ($legacyCorps->count() > 0) {
            $this->line("  • {$legacyCorps->count()} LEGACY test corporations (IDs: 98000000-98000099)");
            $this->warn("    ⚠️  These are from an older UNSAFE version of the test command");
            $this->table(
                ['Corporation ID', 'Name'],
                $legacyCorps->map(fn($c) => [$c->corporation_id, $c->name])->toArray()
            );
        }
        
        if ($posesToDelete > 0) {
            $this->line("  • {$posesToDelete} test POSes (IDs: {$testPosMin}-{$testPosMax})");
        }
        
        if ($legacyPoses > 0) {
            $this->line("  • {$legacyPoses} LEGACY test POSes (various old ID ranges)");
            $this->warn("    ⚠️  These are from older versions of the test command");
        }
        
        $this->line("  • Related fuel history and assets");
        $this->newLine();
        
        // Confirm deletion
        if (!$this->confirm('⚠️  Delete this test data?', false)) {
            $this->info("Cleanup cancelled.");
            return 0;
        }
        
        $this->newLine();
        $this->info("Deleting test data...");
        
        // Delete CURRENT test data
        $deletedCorps = DB::table('corporation_infos')
            ->whereBetween('corporation_id', [$testCorpMin, $testCorpMax])
            ->delete();
        
        $deletedPoses = DB::table('corporation_starbases')
            ->whereBetween('starbase_id', [$testPosMin, $testPosMax])
            ->delete();
        
        $deletedFuel = DB::table('starbase_fuel_history')
            ->whereBetween('starbase_id', [$testPosMin, $testPosMax])
            ->delete();
        
        $deletedAssets = DB::table('corporation_assets')
            ->whereBetween('item_id', [$testAssetMin, $testAssetMax])
            ->delete();
        
        // Delete LEGACY test data from all old ranges
        $deletedLegacyCorps = 0;
        $deletedLegacyPoses = 0;
        $deletedLegacyFuel = 0;
        $deletedLegacyAssets = 0;
        
        foreach ($legacyRanges as $range) {
            if ($range['type'] === 'corp') {
                $deletedLegacyCorps += DB::table('corporation_infos')
                    ->whereBetween('corporation_id', [$range['min'], $range['max']])
                    ->delete();
            } elseif ($range['type'] === 'pos') {
                $deletedLegacyPoses += DB::table('corporation_starbases')
                    ->whereBetween('starbase_id', [$range['min'], $range['max']])
                    ->delete();
                $deletedLegacyFuel += DB::table('starbase_fuel_history')
                    ->whereBetween('starbase_id', [$range['min'], $range['max']])
                    ->delete();
                // Assets using starbase_id as location_id
                $deletedLegacyAssets += DB::table('corporation_assets')
                    ->whereBetween('location_id', [$range['min'], $range['max']])
                    ->delete();
            } elseif ($range['type'] === 'asset') {
                $deletedLegacyAssets += DB::table('corporation_assets')
                    ->whereBetween('item_id', [$range['min'], $range['max']])
                    ->delete();
            }
        }
        
        // Report results
        $this->newLine();
        if ($deletedCorps > 0) {
            $this->info("✓ Deleted {$deletedCorps} test corporations (2.1 billion range)");
        }
        if ($deletedLegacyCorps > 0) {
            $this->info("✓ Deleted {$deletedLegacyCorps} LEGACY test corporations (old ranges)");
        }
        if ($deletedPoses > 0) {
            $this->info("✓ Deleted {$deletedPoses} test POSes (2.2 billion range)");
        }
        if ($deletedLegacyPoses > 0) {
            $this->info("✓ Deleted {$deletedLegacyPoses} LEGACY test POSes (old ranges)");
        }
        if ($deletedFuel > 0 || $deletedLegacyFuel > 0) {
            $this->info("✓ Deleted " . ($deletedFuel + $deletedLegacyFuel) . " fuel history records");
        }
        if ($deletedAssets > 0 || $deletedLegacyAssets > 0) {
            $this->info("✓ Deleted " . ($deletedAssets + $deletedLegacyAssets) . " test assets");
        }
        $this->newLine();
        $this->info("Cleanup complete!");
        
        return 0;
    }
}
