<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Company;
use App\Services\Export\GeneralLedgerExcelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unduh Excel Buku Besar. Izin = Lihat Laporan + Export (sama dengan PDF-nya):
 * Excel berisi SELURUH baris jurnal — mencabut izin export harus ikut menutupnya.
 */
class GeneralLedgerExportController extends Controller
{
    public function __invoke(Request $request, Company $tenant, GeneralLedgerExcelExporter $exporter)
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessTenant($tenant), 403, 'Anda tidak memiliki akses ke PT ini.');
        abort_unless($user->canIn($tenant, Permission::ReportsView), 403, 'Anda tidak memiliki izin melihat laporan.');
        abort_unless($user->canIn($tenant, Permission::ReportsExportPdf), 403, 'Anda tidak memiliki izin export laporan.');

        [$dari, $sampai, $accountId] = PdfController::ledgerFilters($request, $tenant);

        set_time_limit(180);

        try {
            $ss = $exporter->build($tenant, $dari, $sampai, $accountId);
        } catch (ValidationException $e) {
            abort(422, collect($e->errors())->flatten()->first());
        }

        $path = storage_path('app/private/buku-besar-' . Str::uuid() . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $exporter->save($ss, $path);

        $filename = sprintf('Buku Besar - %s - %s sd %s.xlsx', Str::slug($tenant->name), $dari->format('Ymd'), $sampai->format('Ymd'));

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
