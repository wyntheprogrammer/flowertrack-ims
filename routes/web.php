<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Supplier\FlowerController;
use App\Http\Controllers\Supplier\InventoryController;
use App\Http\Controllers\Supplier\BatchController;
use App\Http\Controllers\Supplier\ClientController;
use App\Http\Controllers\Supplier\OrderController;


// Public home
Route::view('/', 'welcome');

// Login
Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('guest')
    ->name('login.store');

// Signed-in accounts
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::middleware('account.active')->group(function () {
        // These routes must remain accessible while a password change is required.
        Route::get('/change-password', [
            AuthController::class,
            'showChangePassword',
        ])->name('password.change');

        Route::put('/change-password', [
            AuthController::class,
            'updatePassword',
        ])
            ->middleware('throttle:5,1')
            ->name('password.update');

        // Supplier/Admin operational screens
        Route::prefix('supplier')
            ->name('supplier.')
            ->middleware([
                'password.changed',
                'role:admin,supplier',
            ])
            ->group(function () {
                Route::view('/dashboard', 'supplier.dashboard')
                    ->name('dashboard');



                Route::get('/flowers', [FlowerController::class, 'index'])
                    ->name('flowers');

                Route::get('/flowers/data', [FlowerController::class, 'data'])
                    ->name('flowers.data');

                Route::post('/flowers', [FlowerController::class, 'store'])
                    ->name('flowers.store');

                Route::post('/flowers/{flower}/update', [FlowerController::class, 'update'])
                    ->whereNumber('flower')
                    ->name('flowers.update');

                Route::post('/flowers/{flower}/deactivate', [FlowerController::class, 'deactivate'])
                    ->whereNumber('flower')
                    ->name('flowers.deactivate');



                Route::get('/inventory', [InventoryController::class, 'index'])
                    ->name('inventory');

                Route::get('/inventory/data', [InventoryController::class, 'data'])
                    ->name('inventory.data');

                Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn'])
                    ->name('inventory.stock-in');

                Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut'])
                    ->name('inventory.stock-out');

                Route::post('/inventory/batches/{batch}/receipt', [InventoryController::class, 'correctReceipt'])
                    ->whereNumber('batch')
                    ->name('inventory.correct-receipt');

                Route::post('/inventory/flowers/{flower}/units', [InventoryController::class, 'updateUnits'])
                    ->whereNumber('flower')
                    ->name('inventory.units');



                Route::get('/batches', [BatchController::class, 'index'])
                    ->name('batches');

                Route::get('/batches/data', [BatchController::class, 'data'])
                    ->name('batches.data');

                Route::post('/batches/{batch}/update', [BatchController::class, 'update'])
                    ->whereNumber('batch')
                    ->name('batches.update');



                Route::view('/spoilage-prediction', 'supplier.spoilage-prediction')
                    ->name('spoilage-prediction');



                Route::get('/clients', [ClientController::class, 'index'])
                    ->name('clients');

                Route::get('/clients/data', [ClientController::class, 'data'])
                    ->name('clients.data');

                Route::get('/clients/{client}', [ClientController::class, 'show'])
                    ->whereNumber('client')
                    ->name('clients.show');

                Route::post('/clients', [ClientController::class, 'store'])
                    ->name('clients.store');

                Route::post('/clients/{client}/update', [ClientController::class, 'update'])
                    ->whereNumber('client')
                    ->name('clients.update');

                Route::post('/clients/{client}/status', [ClientController::class, 'status'])
                    ->whereNumber('client')
                    ->name('clients.status');



                Route::get('/orders', [OrderController::class, 'index'])
                    ->name('orders');

                Route::get('/orders/data', [OrderController::class, 'data'])
                    ->name('orders.data');

                Route::get('/orders/{order}', [OrderController::class, 'show'])
                    ->whereNumber('order')
                    ->name('orders.show');

                Route::post('/orders', [OrderController::class, 'store'])
                    ->name('orders.store');

                Route::post('/orders/{order}/update', [OrderController::class, 'update'])
                    ->whereNumber('order')
                    ->name('orders.update');

                Route::post('/orders/{order}/action', [OrderController::class, 'action'])
                    ->whereNumber('order')
                    ->name('orders.action');


                    

                Route::view('/deliveries', 'supplier.deliveries')
                    ->name('deliveries');

                Route::view('/sales-payments', 'supplier.sales-payments')
                    ->name('sales-payments');

                // Admin-only screens
                Route::middleware('role:admin')->group(function () {
                    Route::view('/reports', 'supplier.reports')
                        ->name('reports');

                    Route::view('/users', 'supplier.users')
                        ->name('users');

                    Route::view('/audit-trail', 'supplier.audit-trail')
                        ->name('audit-trail');
                });
            });
    });
});