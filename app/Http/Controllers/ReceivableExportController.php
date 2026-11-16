<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Company;
use App\Services\Export\ReceivableExcelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Unduh Excel Laporan Tagihan & Piutang. Izin = Lihat Laporan Keuangan. */
class ReceivableExportController extends Controller
{
    public function __invoke(Request $request, Company $tenant, ReceivableExcelExporter $exporter)
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessTenant($tenant), 403, 'Anda tidak memiliki akses ke PT ini.');
        abort_unless($user->canIn($tenant, Permission::ReportsView), 403, 'Anda tidak memiliki izin melihat laporan.');

        $data = $request->validate([
            'dari'      => ['required', 'date'],
            'sampai'    => ['required', 'date', 'after_or_equal:dari'],
            'client_id' => ['nullable', 'integer'],
        ]);

        // IDOR: klien harus milik PT ini, bukan sekadar id yang ada.
        $clientId = null;
        if (! empty($data['client_id'])) {
            $client = Client::withoutGlobalScopes()->where('company_id', $tenant->id)->find($data['client_id']);
            abort_unless($client, 404);
            $clientId = $client->id;
        }

        $dari   = Carbon::parse($data['dari']);
        $sampai = Carbon::parse($data['sampai']);

        $path = storage_path('app/private/piutang-' . Str::uuid() . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $exporter->save($exporter->build($tenant, $dari, $sampai, $clientId), $path);

        $filename = sprintf('Laporan Piutang - %s - per %s.xlsx', Str::slug($tenant->name), $sampai->format('Ymd'));

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
