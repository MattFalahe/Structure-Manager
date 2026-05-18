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
        // Gate on the detection-mode-aware check, not just "is MC installed?".
        // The relevant question is whether the SM-native sweep should be the
        // ACTIVE path right now, which depends on both MC presence AND the
        // operator's chosen mode:
        //
        //   mode=auto    + MC absent   → sweep runs (the natural fallback)
        //   mode=auto    + MC present  → no-op (MC's fast-poll + sweep do the work)
        //   mode=seat_native           → sweep runs (operator opted out of MC even with MC installed)
        //   mode=off                   → no-op (operator disabled detection entirely)
        //
        // Previously this gated on isAvailable() (class_exists check) which
        // meant seat_native mode with MC installed produced a dead zone:
        // MC's poll/sweep exited because SM never registered handlers, AND
        // SM's command exited saying "MC is handling it". No webhook fired
        // until the next worker restart re-evaluated boot-time state. The
        // job's handle() itself already has the right gate; the bug was
        // here at the dispatcher.
        if (!ManagerCoreIntegration::isNativeSweepEnabled()) {
            $mode = ManagerCoreIntegration::detectionMode();
            $mcOn = ManagerCoreIntegration::isAvailable();
            $this->info(sprintf(
                'Native sweep is disabled (mode=%s, MC available=%s). This command is a no-op.',
                $mode,
                $mcOn ? 'yes' : 'no'
            ));
            return 0;
        }

        $this->info('Native sweep active; dispatching ProcessStructureNotifications job to the queue...');
        dispatch(new ProcessStructureNotifications());
        $this->info('Job dispatched.');

        return 0;
    }
}
