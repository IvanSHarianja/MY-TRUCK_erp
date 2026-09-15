<?php

namespace App\Http\Controllers;

use App\Support\UserGuide;
use Illuminate\Http\Response;

/**
 * Isi pop-up Panduan (HTML fragment) — diambil via fetch saat pop-up pertama
 * kali dibuka. Isinya statis & sama untuk semua PT, jadi cukup cek login.
 *
 * Sengaja abort 401 (bukan middleware `auth`): middleware auth me-redirect ke
 * route 'login' yang tidak ada di app ini (login ada di /admin/login).
 */
class PanduanController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->check(), 401);

        return response(UserGuide::renderedContent())
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, max-age=300');
    }
}
