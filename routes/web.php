<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Supplier\AuditTrailController;
use App\Http\Controllers\Supplier\BatchController;
use App\Http\Controllers\Supplier\ClientController;
use App\Http\Controllers\Supplier\DeliveryController;
use App\Http\Controllers\Supplier\FlowerController;
use App\Http\Controllers\Supplier\InventoryController;
use App\Http\Controllers\Supplier\OrderController;
use App\Http\Controllers\Supplier\ReportController;
use App\Http\Controllers\Supplier\SalesPaymentController;
use App\Http\Controllers\Supplier\UserController;
use App\Http\Middleware\EnsureAccountSessionIsCurrent;
use Illuminate\Support\Facades\Route;

// ========================================
// PUBLIC PAGES AND LOGIN
// ========================================

Route::view('/', 'welcome');

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('guest')
    ->name('login.store');

// ========================================
// AUTHENTICATED ACCOUNTS
// ========================================

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::middleware([
        'account.active',
        EnsureAccountSessionIsCurrent::class,
    ])->group(function () {

        // Accessible while a password change is required.
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

        // ========================================
        // DELIVERY PERSONNEL
        // ========================================

        Route::prefix('delivery')
            ->name('delivery.')
            ->middleware(['password.changed', 'role:delivery'])
            ->group(function () {
                Route::get('/deliveries', [DeliveryController::class, 'index'])
                    ->name('deliveries');

                Route::get('/deliveries/data', [DeliveryController::class, 'data'])
                    ->name('deliveries.data');

                Route::get('/deliveries/{delivery}/proof', [DeliveryController::class, 'proof'])
                    ->whereNumber('delivery')
                    ->name('deliveries.proof');

                Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])
                    ->whereNumber('delivery')
                    ->name('deliveries.show');

                Route::post('/deliveries/{delivery}/action', [DeliveryController::class, 'action'])
                    ->whereNumber('delivery')
                    ->name('deliveries.action');
            });

        // ========================================
        // SUPPLIER AND ADMIN
        // ========================================

        Route::prefix('supplier')
            ->name('supplier.')
            ->middleware([
                'password.changed',
                'role:admin,supplier',
            ])
            ->group(function () {
                Route::view('/dashboard', 'supplier.dashboard')
                    ->name('dashboard');

                // Flower Management
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

                // Inventory Management
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

                // Batch Management
                Route::get('/batches', [BatchController::class, 'index'])
                    ->name('batches');

                Route::get('/batches/data', [BatchController::class, 'data'])
                    ->name('batches.data');

                Route::post('/batches/{batch}/update', [BatchController::class, 'update'])
                    ->whereNumber('batch')
                    ->name('batches.update');

                // Spoilage Prediction
                Route::view('/spoilage-prediction', 'supplier.spoilage-prediction')
                    ->name('spoilage-prediction');

                // Client Management
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

                // Order Management
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

                // Delivery Management
                Route::get('/deliveries', [DeliveryController::class, 'index'])
                    ->name('deliveries');

                Route::get('/deliveries/data', [DeliveryController::class, 'data'])
                    ->name('deliveries.data');

                Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])
                    ->whereNumber('delivery')
                    ->name('deliveries.show');

                Route::post('/deliveries/{delivery}/action', [DeliveryController::class, 'action'])
                    ->whereNumber('delivery')
                    ->name('deliveries.action');

                // Sales and Payments
                Route::get('/sales-payments', [SalesPaymentController::class, 'index'])
                    ->name('sales-payments');

                Route::get('/sales-payments/data', [SalesPaymentController::class, 'data'])
                    ->name('sales-payments.data');

                Route::post('/sales-payments/prices/{flower}', [SalesPaymentController::class, 'price'])
                    ->whereNumber('flower')
                    ->name('sales-payments.price');

                Route::post('/sales-payments/payments', [SalesPaymentController::class, 'recordPayment'])
                    ->name('sales-payments.record');

                Route::post('/sales-payments/payments/{payment}/confirm', [SalesPaymentController::class, 'confirm'])
                    ->whereNumber('payment')
                    ->name('sales-payments.confirm');

                Route::post('/sales-payments/payments/{payment}/refund', [SalesPaymentController::class, 'refund'])
                    ->whereNumber('payment')
                    ->name('sales-payments.refund');

                // ========================================
                // ADMIN ONLY
                // ========================================

                Route::middleware('role:admin')->group(function () {
                    // Reports
                    Route::get('/reports', [ReportController::class, 'index'])
                        ->name('reports');

                    Route::get('/reports/data', [ReportController::class, 'data'])
                        ->name('reports.data');

                    // User Management
                    Route::get('/users', [UserController::class, 'index'])
                        ->name('users');

                    Route::get('/users/data', [UserController::class, 'data'])
                        ->name('users.data');

                    Route::get('/users/{user}', [UserController::class, 'show'])
                        ->whereNumber('user')
                        ->name('users.show');

                    Route::post('/users', [UserController::class, 'store'])
                        ->name('users.store');

                    Route::post('/users/{user}/update', [UserController::class, 'update'])
                        ->whereNumber('user')
                        ->name('users.update');

                    Route::post('/users/{user}/status', [UserController::class, 'status'])
                        ->whereNumber('user')
                        ->name('users.status');

                    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
                        ->whereNumber('user')
                        ->name('users.reset-password');

                    // Audit Trail
                    Route::get('/audit-trail', [AuditTrailController::class, 'index'])
                        ->name('audit-trail');

                    Route::get('/audit-trail/data', [AuditTrailController::class, 'data'])
                        ->name('audit-trail.data');
                });
            });
    });
});