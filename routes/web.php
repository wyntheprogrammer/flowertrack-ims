
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

// Supplier Flower Management
Route::get('/supplier/flowers', function () {
    return view('supplier.flowers');
});