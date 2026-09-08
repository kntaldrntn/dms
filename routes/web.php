<?php

use App\Http\Controllers\DeliveryMethodController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\TransactionTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DocumentClassificationController;
use App\Http\Controllers\Settings\PerformanceStandardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


// CORE DOCUMENT ROUTES
Route::middleware(['auth', 'verified'])->group(function () {
    
    // 1. PROCESSING ROUTES (Must go FIRST so /create is caught before /{document})
    Route::middleware(['working_hours'])->group(function () {
        Route::get('documents/create', [DocumentController::class, 'create'])->name('documents.create');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('documents/analyze', [DocumentController::class, 'analyze'])->name('documents.analyze');
        Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        Route::post('/documents/{document}/route', [DocumentController::class, 'routeDocument'])->name('documents.route');
    });

    // 2. READ-ONLY ROUTES (Must go LAST)
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
});


// ADMIN ONLY REGISTRIES
Route::middleware(['role:system_administrator'])->group(function () {
    Route::resource('departments', DepartmentController::class);
    Route::resource('users', UserController::class);
    Route::resource('document-classifications', DocumentClassificationController::class);
    Route::resource('document-types', DocumentTypeController::class);
    Route::resource('delivery-methods', DeliveryMethodController::class);
    Route::resource('performance-standards', PerformanceStandardController::class);
    Route::resource('transaction-types', TransactionTypeController::class);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';