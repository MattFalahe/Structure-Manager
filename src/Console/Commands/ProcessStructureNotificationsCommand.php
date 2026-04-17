<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\ProcessStructureNotifications;
use StructureManager\Integrations\ManagerCoreIntegration;

class ProcessStructureNotificationsCommand extends Command
{
    protected $signature = 'structure-manager:process-notifications';

    protected $description = 'Fallback: read SeAT\'s character_notifications table and dispatch Structure Manager webhooks (no-op when Manager Core is installed).';

    public function handle(): int
    {
        if (ManagerCoreIntegration::isAvailable()) {
            $this->info('Manager Core is installed and handling ESI notifications. This fallback is a no-op.');
            return 0;
        }

        $this->info('Manager Core not installed; processing SeAT native notifications...');
        dispatch(new ProcessStructureNotifications());
        $this->info('Fallback job dispatched.');

        return 0;
    }
}
