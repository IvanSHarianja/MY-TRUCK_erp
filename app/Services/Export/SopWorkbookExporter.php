<?php

namespace App\Services\Export;

use App\Models\Company;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Basis export data MY-TRUCK ke file SOP Armada (format spreadsheet milik
 * divisi). Satu subclass per file SOP — lihat SopFile.
 *
 * Prinsip bersama (keputusan Ivan, 2026-11-09):
 *   - Angka & nomor dokumen = MY-TRUCK. Rumus SOP yang akan menghasilkan
 *     angka/ID berbeda DITIMPA nilai.
 *   - Kolom Input/Pilih diisi; rumus lain dibiarkan hidup.
 *   - Data per periode DARI–SAMPAI; data sebelum DARI diringkas
 *     (saldo awal, akumulasi, stok awal) supaya kapasitas template cukup.
 *
 * Alur: buildPayload() (murni data, cepat, dites tanpa template) →
 * write() ke Spreadsheet → export() memuat template & menyimpan file.
 *
 * Bentuk payload:
 *   cells    : [sheet => [coord => nilai]]  nilai: string|int|float|DateTimeInterface|null
 *   clear    : [sheet => [range, ...]]      dikosongkan sebelum cells ditulis
 *   counts   : [label => jumlah]            ringkasan untuk UI/command
 *   warnings : [pesan, ...]
 */
abstract class SopWorkbookExporter
{
    /** Baris data pertama di semua sheet transaksi/master SOP. */
    public const FIRST_ROW = 12;

    abstract public function file(): SopFile;

    /**
     * @return array{cells: array<string, array<string, mixed>>, clear: array<string, list<string>>, counts: array<string, int>, warnings: list<string>}
     */
    abstract public function buildPayload(
        Company $company,
        CarbonInterface $dari,
        CarbonInterface $sampai,
        ?string $exportedBy = null,
    ): array;

    /**
     * Bangun & simpan file. Berat (memuat template 0,6–6 MB): caller
     * menaikkan batas waktu & memori.
     *
     * @return array{counts: array<string,int>, warnings: list<string>}
     */
    public function export(
        Company $company,
        CarbonInterface $dari,
        CarbonInterface $sampai,
        string $outputPath,
        ?string $exportedBy = null,
    ): array {
        // Payload dulu — gagal cepat bila melebihi kapasitas, sebelum memuat template.
        $payload = $this->buildPayload($company, $dari, $sampai, $exportedBy);

        $template = resource_path($this->file()->template());
        if (! is_file($template)) {
            throw new SopExportException("Template {$this->file()->label()} tidak ditemukan: {$template}");
        }

        $spreadsheet = IOFactory::createReader('Xlsx')->setIncludeCharts(true)->load($template);
        $this->write($spreadsheet, $payload);

        // Tanpa pre-calculate: rumus template (termasuk IMPORTRANGE) tidak bisa
        // dihitung PhpSpreadsheet. Writer menandai fullCalcOnLoad=1 → Excel/
        // Google Sheets menghitung ulang saat file dibuka.
        $writer = new XlsxWriter($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();

        return ['counts' => $payload['counts'], 'warnings' => $payload['warnings']];
    }

    /**
     * Terapkan payload ke workbook.
     *
     * String SELALU ditulis eksplisit sebagai teks: nama/uraian yang diawali
     * "=" tidak boleh berubah jadi rumus (formula injection).
     */
    public function write(Spreadsheet $spreadsheet, array $payload): void
    {
        foreach ($payload['clear'] as $sheetName => $ranges) {
            $sheet = $this->sheet($spreadsheet, $sheetName);
            foreach ($ranges as $range) {
                foreach (Coordinate::extractAllCellReferencesInRange($range) as $coord) {
                    $sheet->getCell($coord)->setValueExplicit(null, DataType::TYPE_NULL);
                }
            }
        }

        foreach ($payload['cells'] as $sheetName => $cells) {
            $sheet = $this->sheet($spreadsheet, $sheetName);

            foreach ($cells as $coord => $value) {
                $cell = $sheet->getCell($coord);

                if ($value === null) {
                    $cell->setValueExplicit(null, DataType::TYPE_NULL);
                } elseif ($value instanceof DateTimeInterface) {
                    $cell->setValueExplicit(ExcelDate::PHPToExcel($value), DataType::TYPE_NUMERIC);
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_int($value) || is_float($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
                }
            }
        }
    }

    // ── Helper untuk subclass ───────────────────────────────────────────

    /**
     * Normalisasi periode. Batas atas EKSKLUSIF ($untilStr = hari setelah
     * SAMPAI): kolom date bisa tersimpan dengan jam ("2026-03-31 00:00:00"
     * di SQLite), sehingga "<= '2026-03-31'" sebagai string membuang
     * transaksi tanggal SAMPAI.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string, 3: string}  [dari, sampai, dariStr, untilStr]
     */
    protected function period(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $dari   = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->startOfDay();

        if ($dari->gt($sampai)) {
            throw new SopExportException('Tanggal DARI tidak boleh setelah tanggal SAMPAI.');
        }

        return [$dari, $sampai, $dari->toDateString(), $sampai->copy()->addDay()->toDateString()];
    }

    /** Tolak bila jumlah baris melebihi rentang yang dijangkau rumus template. */
    protected function assertCapacity(string $what, int $count, int $lastRow, int $firstRow = self::FIRST_ROW): void
    {
        $max = $lastRow - $firstRow + 1;
        if ($count > $max) {
            throw new SopExportException(
                "{$what}: {$count} baris, melebihi kapasitas template SOP ({$max} baris). "
                . 'Pilih periode yang lebih pendek.'
            );
        }
    }

    /** Kode angka murni ditulis sebagai angka, seperti template. */
    protected function codeValue(?string $code): int|string|null
    {
        return SopLookups::code($code);
    }

    /**
     * Template SOP memecah tanggal jadi 3 kolom input (Hari | Bulan | Tahun);
     * kolom tanggal sebelahnya rumus DATE(). $dayCol = kolom Hari.
     *
     * @return array<string, int|null>
     */
    protected function dateParts(string $dayCol, int $row, mixed $date): array
    {
        $i = Coordinate::columnIndexFromString($dayCol);
        [$d, $m, $y] = [$dayCol, Coordinate::stringFromColumnIndex($i + 1), Coordinate::stringFromColumnIndex($i + 2)];

        if ($date === null || $date === '') {
            return ["{$d}{$row}" => null, "{$m}{$row}" => null, "{$y}{$row}" => null];
        }

        $c = Carbon::parse($date);

        return ["{$d}{$row}" => $c->day, "{$m}{$row}" => $c->month, "{$y}{$row}" => $c->year];
    }

    /** Sheet Parameter (layout sama di keenam file) + catatan export di B20:C23. */
    protected function parameterCells(Company $company, Carbon $dari, Carbon $sampai, ?string $exportedBy, string $catatan): array
    {
        $cells = [
            'C5'  => $company->name,
            'B20' => 'INFO EXPORT MY-TRUCK',
            'B21' => 'Periode data',
            'C21' => $dari->format('d/m/Y') . ' s.d. ' . $sampai->format('d/m/Y'),
            'B22' => 'Diekspor',
            'C22' => now()->format('d/m/Y H:i') . ($exportedBy ? " oleh {$exportedBy}" : ''),
            'B23' => 'Catatan',
            'C23' => $catatan . ' Nomor dokumen & kode = nomor MY-TRUCK.',
        ];
        if ((float) $company->harga_solar_default > 0) {
            $cells['C6'] = (float) $company->harga_solar_default;
        }

        return $cells;
    }

    /** Baris jurnal yang dihitung laporan MY-TRUCK: posted, bukan pembalik. */
    protected function postedLines(int $cid): Builder
    {
        return DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $cid)
            ->where('je.status', 'posted')
            ->where('je.document_type', '!=', 'pembalik');
    }

    protected function yesNo(mixed $value): string
    {
        return $value ? 'Ya' : 'Tidak';
    }

    protected function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    protected function sheet(Spreadsheet $spreadsheet, string $name): Worksheet
    {
        return $spreadsheet->getSheetByName($name)
            ?? throw new SopExportException("Sheet '{$name}' tidak ada di template {$this->file()->label()}.");
    }
}
