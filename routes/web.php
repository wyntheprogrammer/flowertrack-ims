<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

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