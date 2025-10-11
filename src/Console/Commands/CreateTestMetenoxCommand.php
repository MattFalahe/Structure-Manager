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
                            {--cleanup : Remove test Metenox instead of creating}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a fake Metenox Moon Drill for testing dual fuel tracking';
    
    /**
     * Test structure ID - high number to avoid conflicts
     */
    const TEST_STRUCTURE_ID = 9999999999;
    
    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;
    
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
        
        $this->info('Creating test Metenox Moon Drill...');
        
        // Get a valid corporation and system from existing structures
        $existingStructure = DB::table('corporation_structures')->first();
        
        if (!$existingStructure) {
            $this->error('No existing structures found! You need at least one real structure first.');
            return 1;
        }
        
        $corpId = $existingStructure->corporation_id;
        $systemId = $existingStructure->system_id;
        
        $this->line("Using corporation ID: {$corpId}");
        $this->line("Using system ID: {$systemId}");
        
        // Step 1: Create structure
        $this->createStructure($corpId, $systemId);
        
        // Step 2: Create structure name
        $this->createStructureName($systemId);
        
        // Step 3: Add fuel bay contents
        $this->createFuelBayContents($corpId);
        
        // Step 4: Add reserves
        $this->createReserves($corpId);
        
        // Step 5: Create fuel history
        $this->createFuelHistory($corpId);
        
        // Step 6: Create service
        $this->createService();
        
        // Verify
        $this->newLine();
        $this->info('✓ Test Metenox structure created successfully!');
        $this->newLine();
        $this->verifyCreation();
        
        $this->newLine();
        $this->info('View your test Metenox at:');
        $this->line('  ' . url('structure-manager/structure/' . self::TEST_STRUCTURE_ID));
        $this->newLine();
        $this->comment('When done testing, cleanup with:');
        $this->line('  php artisan structure-manager:create-test-metenox --cleanup');
        
        return 0;
    }
    
    /**
     * Create the structure record
     */
    private function createStructure($corpId, $systemId)
    {
        $this->line('Creating structure record...');
        
        DB::table('corporation_structures')->updateOrInsert(
            ['structure_id' => self::TEST_STRUCTURE_ID],
            [
                'corporation_id' => $corpId,
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
     * Create the structure name
     */
    private function createStructureName($systemId)
    {
        $this->line('Creating structure name...');
        
        DB::table('universe_structures')->updateOrInsert(
            ['structure_id' => self::TEST_STRUCTURE_ID],
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
     * Create fuel bay contents
     */
    private function createFuelBayContents($corpId)
    {
        $this->line('Adding fuel blocks to fuel bay (1860 blocks = 15.5 days)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_STRUCTURE_ID + 1,
            ],
            [
                'location_id' => self::TEST_STRUCTURE_ID,
                'location_type' => 'structure',
                'type_id' => 4312, // Oxygen Fuel Block
                'quantity' => 1860, // 15.5 days worth
                'location_flag' => 'StructureFuel',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        $this->line('Adding magmatic gas to fuel bay (59040 units = 12.3 days) [LIMITING]...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_STRUCTURE_ID + 2,
            ],
            [
                'location_id' => self::TEST_STRUCTURE_ID,
                'location_type' => 'structure',
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
     * Create reserves
     */
    private function createReserves($corpId)
    {
        $this->line('Adding fuel block reserves to CorpSAG3 (5000 blocks)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_STRUCTURE_ID + 3,
            ],
            [
                'location_id' => self::TEST_STRUCTURE_ID,
                'location_type' => 'structure',
                'type_id' => 4312,
                'quantity' => 5000,
                'location_flag' => 'CorpSAG3',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        $this->line('Adding magmatic gas reserves to CorpSAG4 (144000 units = 30 days)...');
        
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id' => self::TEST_STRUCTURE_ID + 4,
            ],
            [
                'location_id' => self::TEST_STRUCTURE_ID,
                'location_type' => 'structure',
                'type_id' => self::MAGMATIC_GAS_TYPE_ID,
                'quantity' => 144000, // 30 days worth
                'location_flag' => 'CorpSAG4',
                'is_singleton' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
    
    /**
     * Create fuel history
     */
    private function createFuelHistory($corpId)
    {
        $this->line('Creating initial fuel history snapshot...');
        
        DB::table('structure_fuel_history')->insert([
            'structure_id' => self::TEST_STRUCTURE_ID,
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
     * Create service
     */
    private function createService()
    {
        $this->line('Creating Automatic Moon Drilling service...');
        
        DB::table('corporation_structure_services')->updateOrInsert(
            [
                'structure_id' => self::TEST_STRUCTURE_ID,
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
     * Verify creation
     */
    private function verifyCreation()
    {
        $this->info('Verification:');
        
        // Check structure
        $structure = DB::table('corporation_structures')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->first();
        
        if ($structure) {
            $this->line("  ✓ Structure created (ID: " . self::TEST_STRUCTURE_ID . ")");
        } else {
            $this->error("  ✗ Structure not found!");
        }
        
        // Check assets
        $fuelBlocks = DB::table('corporation_assets')
            ->where('location_id', self::TEST_STRUCTURE_ID)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', 4312)
            ->value('quantity');
        
        $gas = DB::table('corporation_assets')
            ->where('location_id', self::TEST_STRUCTURE_ID)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
            ->value('quantity');
        
        $this->line("  ✓ Fuel bay: {$fuelBlocks} blocks (15.5 days)");
        $this->line("  ✓ Fuel bay: {$gas} gas (12.3 days) [LIMITING]");
        
        // Check reserves
        $reserveBlocks = DB::table('corporation_assets')
            ->where('location_id', self::TEST_STRUCTURE_ID)
            ->where('location_flag', 'CorpSAG3')
            ->sum('quantity');
        
        $reserveGas = DB::table('corporation_assets')
            ->where('location_id', self::TEST_STRUCTURE_ID)
            ->where('location_flag', 'CorpSAG4')
            ->sum('quantity');
        
        $this->line("  ✓ Reserves: {$reserveBlocks} blocks in CorpSAG3");
        $this->line("  ✓ Reserves: {$reserveGas} gas in CorpSAG4");
        
        // Check history
        $historyCount = DB::table('structure_fuel_history')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->count();
        
        $this->line("  ✓ Fuel history: {$historyCount} record(s)");
    }
    
    /**
     * Cleanup test data
     */
    private function cleanup()
    {
        $this->warn('Removing test Metenox structure...');
        
        DB::table('corporation_structure_services')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted services');
        
        DB::table('structure_fuel_history')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel history');
        
        DB::table('structure_fuel_reserves')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted fuel reserves');
        
        DB::table('corporation_assets')
            ->where('location_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted assets');
        
        DB::table('universe_structures')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted structure name');
        
        DB::table('corporation_structures')
            ->where('structure_id', self::TEST_STRUCTURE_ID)
            ->delete();
        $this->line('  ✓ Deleted structure');
        
        $this->newLine();
        $this->info('✓ Test data cleaned up successfully!');
        
        return 0;
    }
}
