
<?php

use Illuminate\Support\Facades\Route;

// Home Page
Route::get('/', function () {
    return view('welcome');
});

// Supplier Dashboard
Route::get('/supplier/dashboard', function () {
    return view('supplier.dashboard');
});
