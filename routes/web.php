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
 * PDF Export Routes (auth + tenant access required).
 *
 * BUG-01 & BUG-02 fix: middleware `pdf.tenant` (EnsurePdfTenantAccess) mencegah
 * user Company A akses invoice/laporan keuangan Company B via URL guessing.
 * Sebelumnya cuma middleware `auth` — cukup login jadi user manapun.
 *
 * Format: /pdf/invoice/{invoice} atau /pdf/{tenant_slug}/{module}
 */
Route::middleware(['web', 'auth', 'pdf.tenant'])->prefix('pdf')->name('pdf.')->group(function () {
    // Invoice (guard via invoice->company)
    Route::get('/invoice/{invoice}', [PdfController::class, 'invoice'])
        ->name('invoice');

    // Laporan Keuangan per tenant (guard via {tenant:slug})
    Route::prefix('{tenant:slug}')->group(function () {
        Route::get('/trial-balance', [PdfController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/income-statement', [PdfController::class, 'incomeStatement'])->name('income-statement');
        Route::get('/income-statement-matrix', [PdfController::class, 'incomeStatementMatrix'])->name('income-statement-matrix');
        Route::get('/income-statement-by-asset', [PdfController::class, 'incomeStatementByAsset'])->name('income-statement-by-asset');
        Route::get('/balance-sheet', [PdfController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/equity-statement', [PdfController::class, 'equityStatement'])->name('equity-statement');
        Route::get('/cash-flow', [PdfController::class, 'cashFlow'])->name('cash-flow');
    });
});
