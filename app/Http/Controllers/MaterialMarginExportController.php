<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Company;
use App\Models\Material;
use App\Services\Accounting\MaterialMarginService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Excel Laporan Margin Penjualan Material. Izin = Lihat Laporan + Export. */
class MaterialMarginExportController extends Controller
{
    public function __invoke(Request $request, Company $tenant, MaterialMarginService $service)
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessTenant($tenant), 403, 'Anda tidak memiliki akses ke PT ini.');
        abort_unless($user->canIn($tenant, Permission::ReportsView) && $user->canIn($tenant, Permission::ReportsExportPdf), 403);

        $data = $request->validate([
            'dari'        => ['required', 'date'],
            'sampai'      => ['required', 'date', 'after_or_equal:dari'],
            'client_id'   => ['nullable', 'integer'],
            'material_id' => ['nullable', 'integer'],
        ]);

        // IDOR: filter harus milik PT ini.
        $clientId = ! empty($data['client_id'])
            ? (Client::withoutGlobalScopes()->where('company_id', $tenant->id)->whereKey($data['client_id'])->value('id') ?? abort(404))
            : null;
        $materialId = ! empty($data['material_id'])
            ? (Material::withoutGlobalScopes()->where('company_id', $tenant->id)->whereKey($data['material_id'])->value('id') ?? abort(404))
            : null;

        $dari   = Carbon::parse($data['dari'])->startOfDay();
        $sampai = Carbon::parse($data['sampai'])->startOfDay();
        $report = $service->report($tenant->id, $dari, $sampai, $clientId, $materialId);

        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet()->setTitle('Margin Penjualan');
        $sh->setCellValueExplicit('A1', $tenant->name, DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A2', 'Margin Penjualan Material — ' . $dari->format('d/m/Y') . ' s/d ' . $sampai->format('d/m/Y'), DataType::TYPE_STRING);
        $sh->getStyle('A1:A2')->getFont()->setBold(true);

        $headers = ['Tanggal', 'No. Penjualan', 'Klien', 'Material', 'Dijual per', 'Jumlah Rit', 'Volume', 'Truk',
            'Penjualan', 'HPP', 'Ongkos Supir', 'Uang Makan', 'BBM', 'BM', 'Portal', 'Lain-lain', 'Total Biaya Kirim', 'Margin', 'Margin %'];
        $sh->fromArray($headers, null, 'A4');
        $sh->getStyle('A4:S4')->getFont()->setBold(true);
        $sh->freezePane('A5');

        $r = 5;
        foreach ($report['rows'] as $row) {
            $s = $row['sale'];
            $b = $s->deliveryCostBreakdown();
            $values = [
                ExcelDate::PHPToExcel($s->sale_date), $s->sale_number, $s->client?->name, $s->material?->name,
                $s->satuan_jual === 'rit' ? 'Rit' : ($s->material?->satuan ?? ''), (float) $s->jumlah_rit, (float) $s->volume,
                $s->asset?->asset_code, $row['pendapatan'], $row['hpp'], $b['ongkos_supir'], $b['uang_makan_supir'],
                $b['biaya_bbm'], $b['bm'], $b['biaya_portal'], $b['biaya_lain'], $row['kirim'], $row['margin'], $row['pct'] / 100,
            ];
            foreach ($values as $i => $v) {
                $cell = $sh->getCell([$i + 1, $r]);
                is_string($v) || $v === null
                    ? $cell->setValueExplicit((string) $v, DataType::TYPE_STRING)   // teks eksplisit: cegah "=…" jadi rumus
                    : $cell->setValueExplicit($v, DataType::TYPE_NUMERIC);
            }
            $r++;
        }
        $last = max(5, $r - 1);
        $sh->getStyle("A5:A{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sh->getStyle("I5:R{$last}")->getNumberFormat()->setFormatCode('#,##0;(#,##0);"-"');
        $sh->getStyle("S5:S{$last}")->getNumberFormat()->setFormatCode('0.0%');
        foreach (range('A', 'S') as $col) {
            $sh->getColumnDimension($col)->setAutoSize(true);
        }

        $path = storage_path('app/private/margin-' . Str::uuid() . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        (new Xlsx($ss))->save($path);
        $ss->disconnectWorksheets();

        return response()->download($path, sprintf('Margin Penjualan Material - %s - %s sd %s.xlsx',
            Str::slug($tenant->name), $dari->format('Ymd'), $sampai->format('Ymd')))->deleteFileAfterSend();
    }
}
