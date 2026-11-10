<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SigningCertificateController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/certificate', [SigningCertificateController::class, 'edit'])->name('certificate.edit');
    Route::post('settings/certificate', [SigningCertificateController::class, 'update'])->name('certificate.update');
    Route::delete('settings/certificate', [SigningCertificateController::class, 'destroy'])->name('certificate.destroy');
    // Signers only (SignatureImageRequest / SIGNER_ROLES check): the image on the left of their PNPKI stamp.
    Route::get('settings/signature-image', [SigningCertificateController::class, 'showSignatureImage'])->name('signature-image.show');
    Route::post('settings/signature-image', [SigningCertificateController::class, 'updateSignatureImage'])->name('signature-image.update');
    Route::delete('settings/signature-image', [SigningCertificateController::class, 'destroySignatureImage'])->name('signature-image.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});
