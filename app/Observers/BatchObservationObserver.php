<?php

namespace App\Observers;

use App\Models\InventoryMovement;
use App\Services\BatchObservationService;
use Illuminate\Support\Facades\Schema;

class BatchObservationObserver
{
    /**
     * The inventory writer already owns the Flower lock and transaction.
     * An observation failure rolls back stock, movement and draft together.
     */
    public function created(InventoryMovement $movement): void
    {
        if (! Schema::connection($movement->getConnectionName())->hasColumn('batch_daily_observations', 'source')) {
            return;
        }

        app(BatchObservationService::class)->refreshDraft($movement);
    }
}
