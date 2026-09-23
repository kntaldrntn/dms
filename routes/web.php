<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryMethodController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\TransactionTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TransactionController;
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
    
    // 1. PROCESSING & API ROUTES (Must go FIRST so wildcards don't catch them)
    Route::middleware(['working_hours'])->group(function () {
        
        // Smart Barcode Search API
        Route::get('documents/search-barcodes', [DocumentController::class, 'searchBarcodes'])->name('documents.searchBarcodes');
        
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('documents/analyze', [DocumentController::class, 'analyze'])->name('documents.analyze');
        Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        
        // --- NEW: Workflow Endpoints ---
        Route::post('/documents/{document}/receive', [DocumentController::class, 'receiveDocument'])->name('documents.receive');
        Route::post('/documents/{document}/route', [DocumentController::class, 'routeDocument'])->name('documents.route');
        Route::post('/documents/{document}/complete', [DocumentController::class, 'completeDocument'])->name('documents.complete');

        // --- NEW: Referral Slip Generation ---
        Route::get('/referral-slips', [App\Http\Controllers\ReferralSlipController::class, 'index'])->name('referral-slips.index');
        Route::post('/referral-slips/{document}/generate', [App\Http\Controllers\ReferralSlipController::class, 'generate'])->name('referral-slips.generate');
        
        Route::get('/transactions/inbox', [TransactionController::class, 'index'])->name('transactions.inbox');
        Route::post('/transactions/{document}/process', [TransactionController::class, 'process'])->name('transactions.process');

    });

    // 2. READ-ONLY ROUTES (Must go LAST)
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    // 3. DASHBOARD
    Route::get('dashboard', DashboardController::class)->name('dashboard');
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