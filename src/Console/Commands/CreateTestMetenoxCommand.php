<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CreateTestMetenoxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:create-test-metenox 
                            {--cleanup : Remove test structures instead of creating}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create test Metenox Moon Drill + Astrahus for dual fuel tracking';
    
    /**
     * Test Metenox structure ID - high number to avoid conflicts
     */
    const TEST_METENOX_ID = 9999999999;
    
    /**
     * Test Astrahus structure ID
     */
    const TEST_ASTRAHUS_ID = 9999999998;
    
    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;
    
    /**
     * Astrahus type ID
     */
    const ASTRAHUS_TYPE_ID = 35832;
    
    /**
     * Magmatic Gas type ID
     */
    const MAGMATIC_GAS_TYPE_ID = 81143;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }
        
        $this->info('Creating test structures for Metenox dual fuel testing...');
        $this->newLine();
        
        // Get a valid corporation, system, and profile from existing structures
        $existingStructure = DB::table('corporation_structures')->first();
        
        if (!$existingStructure) {
            $this->error('No existing structures found! You need at least one real structure first.');
            return 1;
        }
        
        $corpId = $existingStructure->corporation_id;
        $systemId = $existingStructure->system_id;
        $profileId = $existingStructure->profile_id;
        
        $this->line("Using corporation ID: {$corpId}");
        $this->line("Using system ID: {$systemId}");
        $this->line("Using profile ID: {$profileId}");
        $this->newLine();
        
        // ===== Create Metenox Moon Drill =====
        $this->info('📍 Creating Metenox Moon Drill...');
        $this->createMetenoxStructure($corpId, $systemId, $profileId);
        $this->createMetenoxName($systemId);
        $this->createMetenoxFuelBay($corpId);
        $this->createMetenoxService();
        $this->createMetenoxFuelHistory($corpId);
        
        $this->newLine();
        
        // ===== Create Astrahus (for reserves) =====
        $this->info('🏰 Creating Astrahus (Reserve Storage)...');
        $this->createAstrahusStructure($corpId, $systemId, $profileId);
        $this->createAstrahusName($systemId);
        $this->createAstrahusReserves($corpId);
        $this->createAstrahusService();
        
        // Verify
        $this->newLine();
        $this->info('✓ Test structures created successfully!');
        $this->newLine();
        $this->verifyCreation();
        
        $this->newLine();
        $this->info('View your test structures at:');
        $this->line('  Metenox: ' . url('structure-manager/structure/' . self::TEST_METENOX_ID));
        $this->line('  Astrahus: ' . url('structure-manager/structure/' . self::TEST_ASTRAHUS_ID));
        $this->newLine();
        $this->comment('When done testing, cleanup with:');
        $this->line('  php artisan structure-manager:create-test-metenox --cleanup');
        
        return 0;
    }
    
    /**
     * Create the Metenox structure record
     */
    private function createMetenoxStructure($corpId, $systemId, $profileId)
    {
        $this->line('  Creating structure record...');
        
        DB::table('corporation_structures')->updateOrInsert(
            ['structure_id' => self::TEST_METENOX_ID],
            [
                'corporation_id' => $corpId,
                'profile_id' => $profileId,
                'system_id' => $systemId,
                'type_id' => self::METENOX_TYPE_ID,
                'fuel_expires' => Carbon::now()->addDays(14),
                'state' => 'shield_vulnerable',
                'state_timer_end' => null,
                'state_timer_start' => null,
                'unanchors_at' => null,
                'reinforce_hour' => 18,
                'next_reinforce_hour' => null,
                'next_reinforce_apply' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create the Metenox structure name
     */
    private function createMetenoxName($systemId)
    {
        $this->line('  Creating structure name...');
        
        DB::table('universe_structures')->updateOrInsert(
            ['structure_id' => self::TEST_METENOX_ID],
            [
                'name' => 'TEST - My Metenox Moon Drill',
                'solar_system_id' => $systemId,
                'type_id' => self::METENOX_TYPE_ID,
                'x' => 0,
                'y' => 0,
                'z' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create Metenox fuel bay contents
     */
    private function createMetenoxFuelBay($corpId)
    {
        $this->line('  Adding fuel blocks to fuel bay (1860 blocks = 15.5 days)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_METENOX_ID + 1,
            ],
            [
                'location_id' => self::TEST_METENOX_ID,
                'location_type' => 'item',
                'type_id' => 4312, // Oxygen Fuel Block
                'quantity' => 1860, // 15.5 days worth
                'location_flag' => 'StructureFuel',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        $this->line('  Adding magmatic gas to fuel bay (59040 units = 12.3 days) [LIMITING]...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_METENOX_ID + 2,
            ],
            [
                'location_id' => self::TEST_METENOX_ID,
                'location_type' => 'item',
                'type_id' => self::MAGMATIC_GAS_TYPE_ID,
                'quantity' => 59040, // 12.3 days worth - LIMITING FACTOR!
                'location_flag' => 'StructureFuel',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create Metenox service
     */
    private function createMetenoxService()
    {
        $this->line('  Creating Automatic Moon Drilling service...');
        
        DB::table('corporation_structure_services')->updateOrInsert(
            [
                'structure_id' => self::TEST_METENOX_ID,
                'name' => 'Automatic Moon Drilling',
            ],
            [
                'state' => 'online',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create Metenox fuel history
     */
    private function createMetenoxFuelHistory($corpId)
    {
        $this->line('  Creating initial fuel history snapshot...');
        
        DB::table('structure_fuel_history')->insert([
            'structure_id' => self::TEST_METENOX_ID,
            'corporation_id' => $corpId,
            'fuel_expires' => Carbon::now()->addDays(14),
            'days_remaining' => 12, // Actual days (limited by gas)
            'fuel_blocks_used' => null,
            'daily_consumption' => null,
            'consumption_rate' => null,
            'tracking_type' => 'metenox_fuel_bay',
            'metadata' => json_encode([
                'tracking_method' => 'metenox_fuel_bay',
                'fuel_blocks' => 1860,
                'magmatic_gas' => 59040,
                'fuel_days_remaining' => 15.5,
                'gas_days_remaining' => 12.3,
                'actual_days_remaining' => 12.3,
                'limiting_factor' => 'magmatic_gas',
                'fuel_bay_available' => true,
                'is_metenox' => true,
            ]),
            'magmatic_gas_quantity' => 59040,
            'magmatic_gas_days' => 12.3,
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);
    }
    
    /**
     * Create the Astrahus structure record
     */
    private function createAstrahusStructure($corpId, $systemId, $profileId)
    {
        $this->line('  Creating structure record...');
        
        DB::table('corporation_structures')->updateOrInsert(
            ['structure_id' => self::TEST_ASTRAHUS_ID],
            [
                'corporation_id' => $corpId,
                'profile_id' => $profileId,
                'system_id' => $systemId,
                'type_id' => self::ASTRAHUS_TYPE_ID,
                'fuel_expires' => Carbon::now()->addDays(30),
                'state' => 'shield_vulnerable',
                'state_timer_end' => null,
                'state_timer_start' => null,
                'unanchors_at' => null,
                'reinforce_hour' => 18,
                'next_reinforce_hour' => null,
                'next_reinforce_apply' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create the Astrahus structure name
     */
    private function createAstrahusName($systemId)
    {
        $this->line('  Creating structure name...');
        
        DB::table('universe_structures')->updateOrInsert(
            ['structure_id' => self::TEST_ASTRAHUS_ID],
            [
                'name' => 'TEST - Reserve Storage Astrahus',
                'solar_system_id' => $systemId,
                'type_id' => self::ASTRAHUS_TYPE_ID,
                'x' => 0,
                'y' => 0,
                'z' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create Astrahus reserves (fuel for the Metenox)
     */
    private function createAstrahusReserves($corpId)
    {
        $this->line('  Adding fuel block reserves to CorpSAG3 (5000 blocks)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_ASTRAHUS_ID + 1,
            ],
            [
                'location_id' => self::TEST_ASTRAHUS_ID,
                'location_type' => 'item',
                'type_id' => 4312, // Oxygen Fuel Block
                'quantity' => 5000,
                'location_flag' => 'CorpSAG3',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        $this->line('  Adding magmatic gas reserves to CorpSAG4 (144000 units = 30 days)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_ASTRAHUS_ID + 2,
            ],
            [
                'location_id' => self::TEST_ASTRAHUS_ID,
                'location_type' => 'item',
                'type_id' => self::MAGMATIC_GAS_TYPE_ID,
                'quantity' => 144000, // 30 days worth
                'location_flag' => 'CorpSAG4',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        $this->line('  Adding some fuel blocks to Astrahus fuel bay (400 blocks)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_ASTRAHUS_ID + 3,
            ],
            [
                'location_id' => self::TEST_ASTRAHUS_ID,
                'location_type' => 'item',
                'type_id' => 4312,
                'quantity' => 400,
                'location_flag' => 'StructureFuel',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create Astrahus service
     */
    private function createAstrahusService()
    {
        $this->line('  Creating Clone Bay service...');
        
        DB::table('corporation_structure_services')->updateOrInsert(
            [
                'structure_id' => self::TEST_ASTRAHUS_ID,
                'name' => 'Clone Bay',
            ],
            [
                'state' => 'online',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Verify creation
     */
    private function verifyCreation()
    {
        $this->info('Verification:');
        $this->newLine();
        
        // === Metenox ===
        $this->comment('📍 Metenox Moon Drill:');
        
        $metenox = DB::table('corporation_structures')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->first();
        
        if ($metenox) {
            $this->line("  ✓ Structure created (ID: " . self::TEST_METENOX_ID . ")");
        } else {
            $this->error("  ✗ Structure not found!");
        }
        
        // Check fuel bay
        $fuelBlocks = DB::table('corporation_assets')
            ->where('location_id', self::TEST_METENOX_ID)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', 4312)
            ->value('quantity');
        
        $gas = DB::table('corporation_assets')
            ->where('location_id', self::TEST_METENOX_ID)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
            ->value('quantity');
        
        $this->line("  ✓ Fuel bay: {$fuelBlocks} blocks (15.5 days)");
        $this->line("  ✓ Fuel bay: {$gas} gas (12.3 days) [LIMITING]");
        
        // Check history
        $historyCount = DB::table('structure_fuel_history')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->count();
        
        $this->line("  ✓ Fuel history: {$historyCount} record(s)");
        
        $this->newLine();
        
        // === Astrahus ===
        $this->comment('🏰 Astrahus (Reserve Storage):');
        
        $astrahus = DB::table('corporation_structures')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->first();
        
        if ($astrahus) {
            $this->line("  ✓ Structure created (ID: " . self::TEST_ASTRAHUS_ID . ")");
        } else {
            $this->error("  ✗ Structure not found!");
        }
        
        // Check reserves
        $reserveBlocks = DB::table('corporation_assets')
            ->where('location_id', self::TEST_ASTRAHUS_ID)
            ->where('location_flag', 'CorpSAG3')
            ->where('type_id', 4312)
            ->value('quantity');
        
        $reserveGas = DB::table('corporation_assets')
            ->where('location_id', self::TEST_ASTRAHUS_ID)
            ->where('location_flag', 'CorpSAG4')
            ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
            ->value('quantity');
        
        $this->line("  ✓ Reserves: {$reserveBlocks} blocks in CorpSAG3");
        $this->line("  ✓ Reserves: {$reserveGas} gas in CorpSAG4 (30 days)");
        
        // Check Astrahus fuel bay
        $astFuel = DB::table('corporation_assets')
            ->where('location_id', self::TEST_ASTRAHUS_ID)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', 4312)
            ->value('quantity');
        
        $this->line("  ✓ Astrahus fuel bay: {$astFuel} blocks");
        
        // Check service
        $service = DB::table('corporation_structure_services')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->where('name', 'Clone Bay')
            ->first();
        
        if ($service) {
            $this->line("  ✓ Clone Bay service: {$service->state}");
        }
    }
    
    /**
     * Cleanup test data
     */
    private function cleanup()
    {
        $this->warn('Removing test structures...');
        $this->newLine();
        
        // === Cleanup Metenox ===
        $this->comment('📍 Cleaning up Metenox Moon Drill...');
        
        DB::table('corporation_structure_services')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted services');
        
        DB::table('structure_fuel_history')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel history');
        
        DB::table('structure_fuel_reserves')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel reserves');
        
        DB::table('corporation_assets')
            ->where('location_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted assets');
        
        DB::table('universe_structures')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted structure name');
        
        DB::table('corporation_structures')
            ->where('structure_id', self::TEST_METENOX_ID)
            ->delete();
        $this->line('  ✓ Deleted structure');
        
        $this->newLine();
        
        // === Cleanup Astrahus ===
        $this->comment('🏰 Cleaning up Astrahus...');
        
        DB::table('corporation_structure_services')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted services');
        
        DB::table('structure_fuel_history')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel history');
        
        DB::table('structure_fuel_reserves')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel reserves');
        
        DB::table('corporation_assets')
            ->where('location_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted assets');
        
        DB::table('universe_structures')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted structure name');
        
        DB::table('corporation_structures')
            ->where('structure_id', self::TEST_ASTRAHUS_ID)
            ->delete();
        $this->line('  ✓ Deleted structure');
        
        $this->newLine();
        $this->info('✓ All test data cleaned up successfully!');
        
        return 0;
    }
}
