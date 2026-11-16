<?php

namespace App\Services\Export;

use App\Models\Company;
use App\Services\Accounting\ReceivableReportService;
use App\Support\PaymentTiming;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export Excel Laporan Tagihan & Piutang — isi sama dengan halaman laporan:
 *   1. Umur Piutang per Klien (posisi per SAMPAI)
 *   2. Invoice Belum Lunas
 *   3. Riwayat Pembayaran (DARI–SAMPAI)
 *
 * Dibangun dari nol (bukan template SOP): ringan, ±1 detik.
 */
class ReceivableExcelExporter
{
    private const MONEY = '#,##0;-#,##0;"-"';

    public function __construct(private ReceivableReportService $report) {}

    public function build(Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?int $clientId = null): Spreadsheet
    {
        $ss = new Spreadsheet();
        $ss->getProperties()->setCreator('MY-TRUCK')->setTitle('Laporan Tagihan & Piutang');

        $this->agingSheet($ss->getActiveSheet(), $company, $sampai, $clientId);
        $this->outstandingSheet($ss->createSheet(), $company, $sampai, $clientId);
        $this->paymentsSheet($ss->createSheet(), $company, $dari, $sampai, $clientId);
        $ss->setActiveSheetIndex(0);

        return $ss;
    }

    public function save(Spreadsheet $ss, string $path): void
    {
        (new Xlsx($ss))->save($path);
        $ss->disconnectWorksheets();
    }

    private function agingSheet(Worksheet $sh, Company $company, CarbonInterface $sampai, ?int $clientId): void
    {
        $sh->setTitle('Umur Piutang');
        $this->title($sh, $company, 'Umur Piutang per Klien — posisi ' . $sampai->format('d/m/Y'));

        $headers = ['Kode Klien', 'Nama Klien', ...array_values(PaymentTiming::BUCKETS), 'Total Sisa', 'Jumlah Invoice Belum Lunas', 'Telat Terlama (hari)', 'Rata-rata Telat Bayar (hari)', 'Invoice Lunas Telat'];
        $rows = $this->report->agingByClient($company->id, $sampai, $clientId)
            ->filter(fn ($r) => $r['total_sisa'] > 0 || $r['lunas_jumlah'] > 0)
            ->map(fn ($r) => [
                $r['client_code'], $r['client_name'],
                ...array_map(fn ($b) => $r["bucket_{$b}"], array_keys(PaymentTiming::BUCKETS)),
                $r['total_sisa'], $r['jumlah_invoice'], $r['telat_maks'], $r['rata_telat'],
                $r['lunas_jumlah'] > 0 ? "{$r['lunas_telat']} dari {$r['lunas_jumlah']}" : null,
            ])->values()->all();

        $last = $this->table($sh, $headers, $rows, moneyCols: range(3, 3 + count(PaymentTiming::BUCKETS)));

        // Baris total pakai rumus SUM supaya ikut berubah bila file diedit.
        if ($rows) {
            $totalRow = $last + 1;
            $sh->setCellValue("A{$totalRow}", 'TOTAL');
            foreach (range(3, 3 + count(PaymentTiming::BUCKETS)) as $col) {
                $c = Coordinate::stringFromColumnIndex($col);
                $sh->setCellValue("{$c}{$totalRow}", "=SUM({$c}5:{$c}{$last})");
                $sh->getStyle("{$c}{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY);
            }
            $sh->getStyle("A{$totalRow}:" . Coordinate::stringFromColumnIndex(count($headers)) . $totalRow)->getFont()->setBold(true);
        }
    }

    private function outstandingSheet(Worksheet $sh, Company $company, CarbonInterface $sampai, ?int $clientId): void
    {
        $sh->setTitle('Invoice Belum Lunas');
        $this->title($sh, $company, 'Invoice Belum Lunas — posisi ' . $sampai->format('d/m/Y'));

        $headers = ['No. Invoice', 'Kode Klien', 'Nama Klien', 'Lini', 'Tanggal Invoice', 'Jatuh Tempo', 'Nilai', 'Dibayar', 'Sisa', 'Telat (hari)', 'Kelompok', 'Uraian'];
        $rows = $this->report->outstandingInvoices($company->id, $sampai, $clientId)
            ->map(fn ($i) => [
                $i->invoice_number, $i->client_code, $i->client_name, $i->lini,
                Carbon::parse($i->invoice_date), $i->due, (float) $i->amount, $i->paid, $i->sisa,
                $i->days_late, PaymentTiming::BUCKETS[$i->bucket], $i->description,
            ])->all();

        $this->table($sh, $headers, $rows, moneyCols: [7, 8, 9]);
    }

    private function paymentsSheet(Worksheet $sh, Company $company, CarbonInterface $dari, CarbonInterface $sampai, ?int $clientId): void
    {
        $sh->setTitle('Riwayat Pembayaran');
        $this->title($sh, $company, 'Riwayat Pembayaran ' . $dari->format('d/m/Y') . ' – ' . $sampai->format('d/m/Y'));

        $headers = ['Tanggal Bayar', 'No. Pembayaran', 'No. Invoice', 'Kode Klien', 'Nama Klien', 'Jatuh Tempo', 'Nominal', 'Telat (hari)', 'Ketepatan', 'Diterima ke', 'No. Bukti', 'Status'];
        $rows = $this->report->payments($company->id, $dari, $sampai, $clientId)
            ->map(fn ($p) => [
                Carbon::parse($p->payment_date), $p->payment_number, $p->invoice_number, $p->client_code, $p->client_name,
                Carbon::parse($p->due_date ?? $p->invoice_date), (float) $p->amount, $p->days_late,
                PaymentTiming::paymentLabel($p->days_late), $p->cash_account, $p->reference_number,
                $p->batal ? 'Batal' : 'Sah',
            ])->all();

        $this->table($sh, $headers, $rows, moneyCols: [7]);
    }

    private function title(Worksheet $sh, Company $company, string $subtitle): void
    {
        $sh->setCellValue('A1', $company->name);
        $sh->setCellValue('A2', $subtitle);
        $sh->setCellValue('A3', 'Dicetak ' . now()->format('d/m/Y H:i') . ' dari MY-TRUCK');
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sh->getStyle('A2')->getFont()->setBold(true);
        $sh->getStyle('A3')->getFont()->setItalic(true)->setSize(9);
    }

    /**
     * Header di baris 4, data mulai baris 5. String ditulis eksplisit sebagai
     * teks (nama klien "=..." tidak boleh jadi rumus).
     *
     * @param  list<int>  $moneyCols  indeks kolom (1-based) berformat rupiah
     * @return int baris data terakhir
     */
    private function table(Worksheet $sh, array $headers, array $rows, array $moneyCols): int
    {
        foreach ($headers as $i => $h) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '4', $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sh->getStyle("A4:{$lastCol}4")->getFont()->setBold(true);
        $sh->getStyle("A4:{$lastCol}4")->getFill()->setFillType('solid')->getStartColor()->setRGB('E5E7EB');
        $sh->freezePane('A5');

        $r = 5;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $v) {
                $cell = $sh->getCell(Coordinate::stringFromColumnIndex($i + 1) . $r);
                if ($v === null) {
                    continue;
                } elseif ($v instanceof CarbonInterface) {
                    $cell->setValueExplicit(ExcelDate::PHPToExcel($v), DataType::TYPE_NUMERIC);
                    $cell->getStyle()->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_int($v) || is_float($v)) {
                    $cell->setValueExplicit($v, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit((string) $v, DataType::TYPE_STRING);
                }
            }
            $r++;
        }

        if ($rows === []) {
            $sh->setCellValue('A5', 'Tidak ada data.');
        }

        foreach ($moneyCols as $col) {
            $c = Coordinate::stringFromColumnIndex($col);
            $sh->getStyle("{$c}5:{$c}" . max(5, $r - 1))->getNumberFormat()->setFormatCode(self::MONEY);
        }
        foreach (range(1, count($headers)) as $col) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return max(5, $r - 1);
    }
}
