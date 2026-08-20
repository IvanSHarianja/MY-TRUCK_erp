<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generate file template Excel untuk import COA saat register PT baru.
 *
 * Format:
 * - Sheet "COA": 4 kolom (Kode, Nama Akun, Kategori, Kode Parent) + 5 baris contoh
 *   yang wajib dihapus user sebelum import.
 * - Sheet "Petunjuk": instruksi mengisi.
 * - Data validation dropdown pada kolom Kategori.
 *
 * Dipakai oleh controller download — di-stream langsung, tidak simpan di disk.
 */
class CoaTemplateExporter
{
    public function stream(string $filename = 'template-coa-my-truck.xlsx'): StreamedResponse
    {
        $spreadsheet = $this->build();

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(
            function () use ($writer) {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'max-age=0',
                'Pragma'              => 'public',
            ],
        );
    }

    private function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildSheetCoa($spreadsheet);
        $this->buildSheetPetunjuk($spreadsheet);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSheetCoa(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('COA');

        // Header
        $sheet->setCellValue('A1', 'Kode');
        $sheet->setCellValue('B1', 'Nama Akun');
        $sheet->setCellValue('C1', 'Kategori');
        $sheet->setCellValue('D1', 'Kode Parent');

        // Contoh baris (user WAJIB hapus sebelum import)
        $examples = [
            ['111000', 'Kas dan Bank',           'aset',       ''],
            ['111100', 'Kas Besar',              'aset',       '111000'],
            ['111110', 'Kas Kecil Lapangan',     'aset',       '111000'],
            ['441100', 'Pendapatan Sewa Alat',   'pendapatan', ''],
            ['551100', 'Beban BBM Solar',        'beban',      ''],
        ];

        foreach ($examples as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue("A{$r}", $row[0]);
            $sheet->setCellValue("B{$r}", $row[1]);
            $sheet->setCellValue("C{$r}", $row[2]);
            $sheet->setCellValue("D{$r}", $row[3]);
        }

        // Styling header — background gray, bold, center
        $headerStyle = $sheet->getStyle('A1:D1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1D5DB');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Border baris contoh
        $sheet->getStyle('A2:D6')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Data validation dropdown untuk kolom Kategori (C2:C1000)
        $validation = $sheet->getDataValidation('C2:C1000');
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(false);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('Kategori tidak valid');
        $validation->setError('Pilih salah satu: aset, kewajiban, ekuitas, pendapatan, beban.');
        $validation->setPromptTitle('Pilih Kategori');
        $validation->setPrompt('aset / kewajiban / ekuitas / pendapatan / beban');
        $validation->setFormula1('"aset,kewajiban,ekuitas,pendapatan,beban"');

        // Auto-width kolom
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Freeze header row
        $sheet->freezePane('A2');
    }

    private function buildSheetPetunjuk(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $instructions = [
            'PETUNJUK PENGISIAN TEMPLATE COA',
            '',
            '1. Buka sheet "COA" dan HAPUS 5 baris contoh sebelum mulai mengisi data Anda.',
            '',
            '2. Kolom wajib (tidak boleh kosong):',
            '   - Kode        : kode akun (huruf, angka, atau tanda "-"). Contoh: 111100, 441-A.',
            '   - Nama Akun   : nama akun (maksimal 255 karakter).',
            '   - Kategori    : pilih dari dropdown — aset / kewajiban / ekuitas / pendapatan / beban.',
            '',
            '3. Kolom opsional:',
            '   - Kode Parent : kalau akun ini adalah sub-akun, isi kode induknya (harus ada di file yang sama).',
            '                    Contoh: akun "Kas BCA" (111100) parent-nya "Kas dan Bank" (111000).',
            '',
            '4. Aturan penting:',
            '   - Kode WAJIB unik dalam file.',
            '   - Kategori anak HARUS sama dengan kategori induk. Aset tidak boleh punya parent pendapatan.',
            '   - Referensi siklik dilarang (A→B→A).',
            '   - Kalau ada 1 baris invalid, SELURUH import dibatalkan — Anda upload ulang setelah perbaiki.',
            '',
            '5. Kolom lain (Sub-Kategori, Cash Flow, Role, Normal Balance) akan diisi otomatis oleh sistem',
            '   berdasarkan Kategori. Setelah import, Anda bisa fine-tune di menu Master Data → Daftar Akun.',
            '',
            '6. Setelah COA di-import, Anda tetap bisa tambah akun manual atau edit dari halaman COA.',
        ];

        foreach ($instructions as $i => $line) {
            $sheet->setCellValue('A' . ($i + 1), $line);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(100);
    }
}
