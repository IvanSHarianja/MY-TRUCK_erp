<?php

namespace App\Services\Export;

/**
 * Enam file SOP Armada yang bisa diekspor. Nilai enum dipakai di URL
 * (/export/{tenant}/sop/{file}.xlsx) dan opsi --file di command.
 */
enum SopFile: string
{
    case Finance   = 'finance';
    case Aset      = 'aset';
    case Armada    = 'armada';
    case AlatBerat = 'alat-berat';
    case Material  = 'material';
    case Proyek    = 'proyek';

    public function label(): string
    {
        return match ($this) {
            self::Finance   => 'SOP Finance',
            self::Aset      => 'SOP Aset & Workshop',
            self::Armada    => 'SOP Operasional Armada',
            self::AlatBerat => 'SOP Operasional Alat Berat',
            self::Material  => 'SOP Material',
            self::Proyek    => 'SOP Proyek Borongan',
        };
    }

    /** Nama file template di resources/templates/sop (salinan dari docs/SOP Armada). */
    public function template(): string
    {
        return 'templates/sop/' . $this->label() . ' - Armada.xlsx';
    }

    /** @return class-string<SopWorkbookExporter> */
    public function exporterClass(): string
    {
        return match ($this) {
            self::Finance   => SopFinanceExporter::class,
            self::Aset      => SopAsetExporter::class,
            self::Armada    => SopArmadaExporter::class,
            self::AlatBerat => SopAlatBeratExporter::class,
            self::Material  => SopMaterialExporter::class,
            self::Proyek    => SopProyekExporter::class,
        };
    }

    public function exporter(): SopWorkbookExporter
    {
        return app($this->exporterClass());
    }

    /** Ringkasan isi untuk halaman Backup Excel. */
    public function description(): string
    {
        return match ($this) {
            self::Finance   => 'COA + saldo awal, klien, vendor, jurnal, tagihan, pembayaran, dan Master Unit untuk laba rugi per unit.',
            self::Aset      => 'Master unit & karyawan, pemeliharaan, penyusutan bulanan, dan ritase/jam kerja untuk pemakaian unit.',
            self::Armada    => 'Kontrak armada, ritase harian beserta biayanya, dan penagihan (invoice) armada.',
            self::AlatBerat => 'Kontrak rental alat berat, jam kerja harian beserta biayanya, dan penagihan (invoice) rental.',
            self::Material  => 'Master material (stok & harga pokok), pembelian, dan penjualan material.',
            self::Proyek    => 'Proyek borongan, progress fisik, DP dan termin.',
        };
    }

    /** @return array<string, string> value → label untuk dropdown */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $f) => [$f->value => $f->label()])->all();
    }
}
