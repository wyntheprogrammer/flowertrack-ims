<?php

use Illuminate\Support\Facades\Route;

// Home Page
Route::view('/', 'welcome');

// Supplier / Admin UI
Route::prefix('supplier')->name('supplier.')->group(function () {

    // Completed UI modules
    Route::view('/dashboard', 'supplier.dashboard')
        ->name('dashboard');

    Route::view('/flowers', 'supplier.flowers')
        ->name('flowers');

    Route::view('/inventory', 'supplier.inventory')
        ->name('inventory');

    Route::view('/batches', 'supplier.batches')
        ->name('batches');

    Route::view('/spoilage-prediction', 'supplier.spoilage-prediction')
        ->name('spoilage-prediction');

    Route::view('/clients', 'supplier.clients')
        ->name('clients');

    Route::view('/orders', 'supplier.orders')
        ->name('orders');

    Route::view('/deliveries', 'supplier.deliveries')
        ->name('deliveries');

    Route::view('/sales-payments', 'supplier.sales-payments')
        ->name('sales-payments');

    Route::view('/reports', 'supplier.reports')
        ->name('reports');

    // Upcoming modules
    $upcomingModules = [
        'users' => 'User Management',
        'audit-trail' => 'Audit Trail',
    ];

    foreach ($upcomingModules as $path => $title) {
        Route::view('/' . $path, 'supplier.module-placeholder', [
            'moduleTitle' => $title,
        ])->name($path);
    }

});