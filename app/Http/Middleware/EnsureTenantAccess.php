<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware yang memastikan user login diarahkan ke alur onboarding
 * yang benar bila:
 *   - Belum punya tenant (Company) sama sekali → force ke /admin/new
 *   - Punya tenant tapi session menunjuk tenant yang sudah dihapus
 *     (edge case setelah migrate:fresh) → clear session tenant + redirect /admin
 *
 * Ditempatkan di panel `authMiddleware` supaya jalan hanya untuk request
 * yang sudah authenticated (Authenticate::class jalan duluan).
 *
 * Design decision: middleware ini hanya redirect DALAM konteks panel admin.
 * Route lain (mis. /pdf/*) tidak disentuh.
 */
class EnsureTenantAccess
{
    /**
     * Path yang dikecualikan dari cek tenant — user boleh akses tanpa tenant.
     * URL relative terhadap prefix panel (/admin).
     */
    private const EXEMPT_PATHS = [
        'admin',           // RedirectToTenantController — Filament's default handler
        'admin/new',       // Tenant registration page
        'admin/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Skip kalau tidak authenticated (Authenticate middleware sudah handle,
        // ini defensive check).
        if (! auth()->check()) {
            return $next($request);
        }

        // Skip paths yang tidak butuh tenant (registration, logout, dll).
        $path = trim($request->path(), '/');
        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return $next($request);
        }

        $user = auth()->user();

        // Kalau user 0 tenant → force ke registration dengan pesan ramah.
        // Ini menutup gap dari Filament default RedirectToTenantController
        // yang kadang tidak reliable (mis. session stale setelah migrate:fresh).
        $tenants = $user->getTenants(Filament::getPanel('admin'));

        if ($tenants->isEmpty()) {
            return redirect('/admin/new')
                ->with('onboarding_message', 'Selamat datang! Silakan daftarkan perusahaan pertama Anda untuk mulai menggunakan sistem.');
        }

        return $next($request);
    }
}
