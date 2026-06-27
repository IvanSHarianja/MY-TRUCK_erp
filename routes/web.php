<?php

use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\Route;

/**
 * Root URL → redirect ke /admin.
 *
 * Filament otomatis handle:
 *  - Belum login → tampil halaman login (/admin/login)
 *  - Sudah login → ke dashboard tenant (/admin/{tenant-slug})
 */
Route::get('/', fn () => redirect('/admin'));

/**
 * PDF Export Routes (auth required)
 * Format: /pdf/{tenant_slug}/{module}/{id?}
 */
Route::middleware(['web', 'auth'])->prefix('pdf')->name('pdf.')->group(function () {
    // Invoice
    Route::get('/invoice/{invoice}', [PdfController::class, 'invoice'])
        ->name('invoice');

    // Laporan Keuangan per tenant
    Route::prefix('{tenant:slug}')->group(function () {
        Route::get('/trial-balance', [PdfController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/income-statement', [PdfController::class, 'incomeStatement'])->name('income-statement');
        Route::get('/income-statement-matrix', [PdfController::class, 'incomeStatementMatrix'])->name('income-statement-matrix');
        Route::get('/balance-sheet', [PdfController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/equity-statement', [PdfController::class, 'equityStatement'])->name('equity-statement');
        Route::get('/cash-flow', [PdfController::class, 'cashFlow'])->name('cash-flow');
    });
});
