<?php

namespace App\Providers;

use App\Listeners\AuditAuthentication;
use App\Listeners\CatchUpBatchObservations;
use App\Models\InventoryMovement;
use App\Observers\AuditObserver;
use App\Observers\BatchObservationObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        InventoryMovement::observe(BatchObservationObserver::class);
        // Record changes to operational models.
        foreach (array_keys(AuditObserver::MODULES) as $model) {
            $class = 'App\\Models\\'.$model;

            $class::observe(AuditObserver::class);
        }

        // Record login, failed login, and logout.
        Event::subscribe(AuditAuthentication::class);
        Event::subscribe(CatchUpBatchObservations::class);
    }
}
