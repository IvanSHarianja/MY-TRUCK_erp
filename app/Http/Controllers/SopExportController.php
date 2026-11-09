<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Company;
use App\Services\Export\SopExportException;
use App\Services\Export\SopFile;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Unduh backup Excel format SOP Armada (satu file SOP per unduhan).
 *
 * Berisi data operasional & keuangan PT → wajib anggota aktif tenant DAN
 * izin DataExport (Owner/Admin secara default). Setiap unduhan dicatat di
 * Riwayat Aktivitas.
 */
class SopExportController extends Controller
{
    public function __invoke(Request $request, Company $tenant, string $file)
    {
        $user = $request->user();

        abort_unless($user && $user->canAccessTenant($tenant), 403, 'Anda tidak memiliki akses ke PT ini.');
        abort_unless($user->canIn($tenant, Permission::DataExport), 403, 'Anda tidak memiliki izin unduh backup Excel.');

        $sopFile = SopFile::tryFrom($file) ?? abort(404);

        $data = $request->validate([
            'dari'   => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
        ]);

        $dari   = Carbon::parse($data['dari']);
        $sampai = Carbon::parse($data['sampai']);

        // Memuat template SOP (0,6–6 MB) butuh hingga ±1,5 menit & ±550 MB memori.
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $path = storage_path('app/private/sop-export-' . Str::uuid() . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        try {
            $result = $sopFile->exporter()->export($tenant, $dari, $sampai, $path, $user->name);
        } catch (SopExportException $e) {
            @unlink($path);

            Notification::make()
                ->danger()
                ->title("Backup Excel {$sopFile->label()} gagal dibuat")
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return back();
        }

        activity()
            ->causedBy($user)
            ->performedOn($tenant)
            ->withProperties([
                'file'   => $sopFile->value,
                'dari'   => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
                'jumlah' => $result['counts'],
            ])
            ->log("Unduh backup Excel ({$sopFile->label()})");

        $filename = sprintf(
            '%s - %s - %s sd %s.xlsx',
            $sopFile->label(),
            Str::slug($tenant->name),
            $dari->format('Ymd'),
            $sampai->format('Ymd'),
        );

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
