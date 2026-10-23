<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryMethodController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\TransactionTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DocumentClassificationController;
use App\Http\Controllers\Settings\PerformanceStandardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
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
        Route::post('/documents/{document}/route', [DocumentController::class, 'routeDocument'])->name('documents.route');

        // --- Executive approvals (FGD Phase 2): ADM single approval, OCM certificate approval ---
        Route::get('/adm-approvals', [App\Http\Controllers\AdmApprovalController::class, 'index'])->name('adm-approvals.index');
        Route::post('/adm-approvals/{document}/receive', [App\Http\Controllers\AdmApprovalController::class, 'receive'])->name('adm-approvals.receive');
        Route::post('/adm-approvals/{document}/approve', [App\Http\Controllers\AdmApprovalController::class, 'approve'])->name('adm-approvals.approve');
        Route::get('/ocm-approvals', [App\Http\Controllers\OcmApprovalController::class, 'index'])->name('ocm-approvals.index');
        Route::post('/ocm-approvals/{document}/approve', [App\Http\Controllers\OcmApprovalController::class, 'approve'])->name('ocm-approvals.approve');
        Route::post('/approvals/{document}/stamp-placement', [App\Http\Controllers\OcmApprovalController::class, 'findStampPlacement'])->name('approvals.stamp-placement');
        
        Route::get('/transactions/inbox', [TransactionController::class, 'index'])->name('transactions.inbox');
        Route::post('/transactions/{document}/process', [TransactionController::class, 'process'])->name('transactions.process');
        Route::post('/transactions/{document}/receive-copy', [TransactionController::class, 'receiveCopy'])->name('transactions.receive-copy');
        Route::post('/documents/{document}/primary-office', [TransactionController::class, 'changePrimary'])->name('documents.primary-office');

    });

    // 2. READ-ONLY ROUTES (Must go LAST)
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/signed-pdf', [App\Http\Controllers\OcmApprovalController::class, 'previewSigned'])->name('documents.signed-pdf');

    // 3. DASHBOARD
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('dashboard/report', App\Http\Controllers\DashboardReportController::class)->name('dashboard.report');
});

// User management: System Administrator only (FGD access matrix, slide 9).
Route::middleware(['auth', 'role:system_administrator'])->group(function () {
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
});

// System settings & registries: System Administrator and Records Section (slide 9).
Route::middleware(['auth', 'role:system_administrator,records_manager'])->group(function () {
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('document-classifications', DocumentClassificationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('document-types', DocumentTypeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('delivery-methods', DeliveryMethodController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('people', PersonController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('performance-standards', PerformanceStandardController::class)->only(['index', 'store']);
    Route::resource('transaction-types', TransactionTypeController::class)->only(['index', 'store', 'update', 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
