<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Filament\Concerns\PreviewsExcelImport;
use App\Services\Import\PartyImportService;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Import Klien / Vendor dengan pratinjau. Subclass cukup menentukan
 * $resource dan kind() ('client' | 'vendor').
 */
abstract class ImportPartiesPage extends Page implements HasForms
{
    use InteractsWithForms;
    use PreviewsExcelImport {
        PreviewsExcelImport::form insteadof InteractsWithForms;
    }

    protected string $view = 'filament.import-parties';

    public ?array $data = [];

    /** @return 'client'|'vendor' */
    abstract protected static function kind(): string;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->canCurrent(Permission::MasterDataManage) ?? false;
    }

    public function getTitle(): string
    {
        return static::kind() === 'client' ? 'Import Klien' : 'Import Vendor';
    }

    protected function label(): string
    {
        return static::kind() === 'client' ? 'klien' : 'vendor';
    }

    protected function importDirectory(): string
    {
        return 'party-imports';
    }

    protected function uploadHelperText(): string
    {
        return static::kind() === 'client'
            ? 'Kolom dikenali dari judulnya: Kode, Nama (atau Keterangan), Alamat, No Telp, Email, Kontak, NPWP. Bila ada kolom JENIS, hanya baris PIUTANG yang diambil. Bila satu sheet berisi beberapa tabel, tabel berjudul konsumen/pelanggan yang diambil.'
            : 'Kolom dikenali dari judulnya: Kode, Nama (atau Keterangan), Alamat, No Telp, Email, Kontak, NPWP, Keterangan. Bila ada kolom JENIS, hanya baris HUTANG yang diambil. Jenis vendor ditebak dari keterangan (sparepart, bengkel, BBM, kuari).';
    }

    protected function buildPreview(string $absolutePath): array
    {
        return app(PartyImportService::class)->preview(static::kind(), Filament::getTenant()->getKey(), $absolutePath);
    }

    public function import(): void
    {
        $path = $this->storedPath();
        if ($path === null) {
            Notification::make()->warning()->title('Unggah file dulu')->send();

            return;
        }

        try {
            $r = app(PartyImportService::class)->import(static::kind(), Filament::getTenant()->getKey(), $path);
        } catch (ValidationException $e) {
            $this->notifyErrors('Import gagal', $e);

            return;
        }

        $this->discardFile();

        Notification::make()->success()->title("{$r['imported']} {$this->label()} diimpor")
            ->body($r['skipped_existing'] ? "{$r['skipped_existing']} baris dilewati karena kode/nama sudah ada." : null)
            ->send();

        $this->redirect(static::getResource()::getUrl('index'));
    }

    protected function getViewData(): array
    {
        return [
            'preview'     => $this->safePreview(),
            'label'       => $this->label(),
            'isVendor'    => static::kind() === 'vendor',
            'vendorTypes' => PartyImportService::VENDOR_TYPES,
        ];
    }
}
