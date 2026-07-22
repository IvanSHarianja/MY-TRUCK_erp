<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Invoice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BUG-01 & BUG-02 — Guard cross-tenant leak untuk semua route PDF export.
 *
 * MASALAH (sebelum fix):
 *   Route /pdf/invoice/{invoice} dan /pdf/{tenant:slug}/{report} hanya
 *   dilindungi middleware `auth` — cukup login sebagai user manapun untuk
 *   akses PDF milik tenant lain sekedar dengan menebak angka/slug di URL.
 *   Kebocoran data invoice & laporan keuangan antar customer.
 *
 * SOLUSI:
 *   Middleware ini cek dua kemungkinan route param:
 *     1. `tenant` (Company via slug) — untuk route /pdf/{tenant:slug}/*.
 *        Tolak kalau user tidak punya pivot company_user dengan tenant tsb.
 *     2. `invoice` (Invoice via id) — untuk route /pdf/invoice/{invoice}.
 *        Ambil invoice->company, lalu cek akses seperti kasus #1.
 *
 *   Semua abort 403 (bukan 404) supaya user paham ini masalah otorisasi,
 *   bukan resource tidak ada. 404 justru bisa membocorkan keberadaan
 *   resource (via response time atau caching hint).
 *
 * User::canAccessTenant() sudah cek pivot is_active=true, jadi user
 * dengan pivot non-aktif juga otomatis ditolak.
 */
class EnsurePdfTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            // Defensive — 'auth' middleware harusnya sudah handle, tapi double-check.
            abort(403);
        }

        // Case 1: route punya param {tenant:slug} → Company binding
        $tenant = $request->route('tenant');
        if ($tenant instanceof Company) {
            abort_unless($user->canAccessTenant($tenant), 403,
                "Anda tidak memiliki akses ke tenant [{$tenant->slug}]."
            );
            return $next($request);
        }

        // Case 2: route punya param {invoice} → cek via invoice->company
        $invoice = $request->route('invoice');
        if ($invoice instanceof Invoice) {
            $invoiceCompany = Company::find($invoice->company_id);
            abort_unless(
                $invoiceCompany && $user->canAccessTenant($invoiceCompany),
                403,
                "Anda tidak memiliki akses ke invoice ini."
            );
            return $next($request);
        }

        // Route PDF tidak punya param tenant maupun invoice — konfigurasi salah.
        // Fail-close (403) supaya bug wiring tidak jadi security hole.
        abort(403, 'Route PDF tidak dikenali.');
    }
}
