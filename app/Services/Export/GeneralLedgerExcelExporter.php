<?php

namespace App\Services\Export;

use App\Models\Company;
use App\Services\Accounting\GeneralLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel Buku Besar:
 *   1. Ringkasan — saldo awal, mutasi debit/kredit, saldo akhir per akun
 *   2. Buku Besar — satu tabel datar (Kode Akun di tiap baris) supaya bisa
 *      langsung difilter / di-pivot di Excel. Tiap akun diawali baris Saldo awal.
 */
class GeneralLedgerExcelExporter
{
    /** Batas aman memori PhpSpreadsheet (±1 KB per sel). */
    public const MAX_LINES = 60000;

    private const MONEY = '#,##0;(#,##0);"-"';

    public function __construct(private GeneralLedgerService $ledger) {}

    public function build(Company $company, Carbon $dari, Carbon $sampai, ?int $accountId = null): Spreadsheet
    {
        $data = $this->ledger->build($company->id, $dari, $sampai, $accountId, self::MAX_LINES);
        if ($data['truncated']) {
            throw ValidationException::withMessages([
                'periode' => 'Terlalu banyak baris jurnal (' . number_format($data['line_count'], 0, ',', '.') . '). Persempit tanggal atau pilih satu akun.',
            ]);
        }

        $ss = new Spreadsheet();
        $ss->getProperties()->setCreator('MY-TRUCK')->setTitle('Buku Besar');
        $period = $dari->format('d/m/Y') . ' s/d ' . $sampai->format('d/m/Y');

        $this->summarySheet($ss->getActiveSheet(), $company, $period, $data);
        $this->detailSheet($ss->createSheet(), $company, $period, $dari, $data);
        $ss->setActiveSheetIndex(0);

        return $ss;
    }

    public function save(Spreadsheet $ss, string $path): void
    {
        (new Xlsx($ss))->save($path);
        $ss->disconnectWorksheets();
    }

    private function summarySheet(Worksheet $sh, Company $company, string $period, array $data): void
    {
        $sh->setTitle('Ringkasan');
        $this->title($sh, $company, "Buku Besar — Ringkasan per Akun — {$period}");

        $rows = array_map(fn ($a) => [
            $a['code'], $a['name'], $a['normal_balance'] === 'kredit' ? 'Kredit' : 'Debit',
            $a['opening'], $a['debit'], $a['kredit'], $a['closing'],
        ], $data['accounts']);

        $last = $this->table($sh, ['Kode Akun', 'Nama Akun', 'Saldo Normal', 'Saldo Awal', 'Debit', 'Kredit', 'Saldo Akhir'], $rows, [4, 5, 6, 7]);

        if ($rows) {
            $t = $last + 1;
            $sh->setCellValue("A{$t}", 'TOTAL MUTASI');
            $sh->setCellValue("E{$t}", "=SUM(E5:E{$last})");
            $sh->setCellValue("F{$t}", "=SUM(F5:F{$last})");
            $sh->getStyle("E{$t}:F{$t}")->getNumberFormat()->setFormatCode(self::MONEY);
            $sh->getStyle("A{$t}:G{$t}")->getFont()->setBold(true);
        }
    }

    private function detailSheet(Worksheet $sh, Company $company, string $period, Carbon $dari, array $data): void
    {
        $sh->setTitle('Buku Besar');
        $this->title($sh, $company, "Buku Besar — {$period}");

        $rows = [];
        foreach ($data['accounts'] as $a) {
            $rows[] = [$a['code'], $a['name'], $dari, null, null, 'Saldo awal', null, null, $a['opening']];
            foreach ($a['lines'] as $l) {
                $rows[] = [
                    $a['code'], $a['name'], Carbon::parse($l['date']), $l['entry_number'], $l['document_number'],
                    $l['description'], $l['debit'], $l['kredit'], $l['balance'],
                ];
            }
        }

        $this->table($sh, ['Kode Akun', 'Nama Akun', 'Tanggal', 'No. Jurnal', 'No. Bukti', 'Keterangan', 'Debit', 'Kredit', 'Saldo'], $rows, [7, 8, 9]);
        $sh->setAutoFilter('A4:I' . max(5, count($rows) + 4));
    }

    private function title(Worksheet $sh, Company $company, string $subtitle): void
    {
        $sh->setCellValueExplicit('A1', $company->name, DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A2', $subtitle, DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A3', 'Hanya jurnal Posted (void & pembalik tidak dihitung). Angka dalam kurung = saldo berlawanan dengan saldo normal.', DataType::TYPE_STRING);
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sh->getStyle('A2')->getFont()->setBold(true);
        $sh->getStyle('A3')->getFont()->setItalic(true)->setSize(9);
    }

    /**
     * Header baris 4, data mulai baris 5. Teks ditulis eksplisit sebagai teks
     * (keterangan "=..." tidak boleh jadi rumus).
     *
     * @param  list<int>  $moneyCols
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
                if ($v === null) {
                    continue;
                }
                $cell = $sh->getCell(Coordinate::stringFromColumnIndex($i + 1) . $r);
                if ($v instanceof Carbon) {
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
            $sh->setCellValue('A5', 'Tidak ada saldo maupun transaksi pada periode ini.');
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
