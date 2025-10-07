<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Seat\Web\Models\Acl\Permission;
use Seat\Web\Models\Acl\Role;

class SetupPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:setup 
                            {--reset : Reset all Structure Manager permissions}
                            {--grant-admin : Grant all permissions to the Admin role}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup Structure Manager permissions in database';

    /**
     * The permissions to create
     *
     * @var array
     */
    protected $permissions = [
        'structure-manager.view' => 'View Structure Manager',
        'structure-manager.admin' => 'Administer Structure Manager',
        'structure-manager.export' => 'Export Reports',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Setting up Structure Manager permissions...');
        
        if ($this->option('reset')) {
            $this->resetPermissions();
        }
        
        $created = 0;
        $existing = 0;
        
        foreach ($this->permissions as $name => $description) {
            $permission = Permission::firstOrCreate(
                ['title' => $name],
                ['description' => $description]
            );
            
            if ($permission->wasRecentlyCreated) {
                $created++;
                $this->info("✓ Created permission: {$name}");
            } else {
                $existing++;
                $this->comment("• Permission already exists: {$name}");
            }
        }
        
        $this->newLine();
        $this->info("Summary: {$created} created, {$existing} already existed");
        
        if ($this->option('grant-admin')) {
            $this->grantAdminPermissions();
        }
        
        $this->newLine();
        $this->info('Setup complete!');
        
        return 0;
    }

    protected function resetPermissions()
    {
        $this->warn('Resetting all Structure Manager permissions...');
        $count = Permission::whereIn('title', array_keys($this->permissions))->delete();
        $this->info("Removed {$count} existing permissions.");
        $this->newLine();
    }

    protected function grantAdminPermissions()
    {
        $this->info('Granting permissions to Admin role...');
        
        $adminRole = Role::where('title', 'admin')
            ->orWhere('title', 'Administrator')
            ->orWhere('id', 1)
            ->first();
        
        if (!$adminRole) {
            $this->error('Could not find Admin role!');
            return;
        }
        
        $permissionIds = Permission::whereIn('title', array_keys($this->permissions))
            ->pluck('id')
            ->toArray();
        
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        
        $this->info("✓ Granted all Structure Manager permissions to {$adminRole->title} role");
    }
}
