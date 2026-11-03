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

    // Upcoming modules
    $upcomingModules = [
        'spoilage-prediction' => 'Spoilage Prediction',
        'clients' => 'Client Management',
        'orders' => 'Order Management',
        'deliveries' => 'Delivery Management',
        'sales-payments' => 'Sales & Payments',
        'reports' => 'Reports',
        'users' => 'User Management',
        'audit-trail' => 'Audit Trail',
    ];

    foreach ($upcomingModules as $path => $title) {
        Route::view('/' . $path, 'supplier.module-placeholder', [
            'moduleTitle' => $title,
        ])->name($path);
    }

});