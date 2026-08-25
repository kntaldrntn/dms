<?php

use App\Http\Controllers\DeliveryMethodController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\TransactionTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DocumentClassificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['role:system_administrator'])->group(function () {
        Route::resource('departments', DepartmentController::class);
        Route::resource('users', UserController::class);
        Route::resource('document-classifications', DocumentClassificationController::class);
        Route::resource('document-types', DocumentTypeController::class);
        Route::resource('delivery-methods', DeliveryMethodController::class);
        Route::resource('transaction-types', TransactionTypeController::class);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
