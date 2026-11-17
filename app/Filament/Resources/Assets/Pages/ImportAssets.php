<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Concerns\PreviewsExcelImport;
use App\Filament\Resources\Assets\AssetResource;
use App\Services\Import\AssetImportService;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Import Armada & Alat Berat dengan PRATINJAU: unggah → tabel "apa yang akan
 * disimpan" (ditebak / kurang / dilewati) → Konfirmasi. Lihat PreviewsExcelImport.
 */
class ImportAssets extends Page implements HasForms
{
    use InteractsWithForms;
    use PreviewsExcelImport {
        PreviewsExcelImport::form insteadof InteractsWithForms;
    }

    protected static string $resource = AssetResource::class;

    protected string $view = 'filament.resources.assets.import-assets';

    protected static ?string $title = 'Import Armada & Alat Berat';

    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        return AssetResource::canCreate();
    }

    protected function importDirectory(): string
    {
        return 'asset-imports';
    }

    protected function uploadHelperText(): string
    {
        return 'Kolom dikenali dari judulnya: Kode / No Urut, Nama (atau Keterangan), Nopol, Tahun / Tanggal Beli, Harga Beli, Jenis, Umur. Satu sheet boleh berisi beberapa tabel (mis. kendaraan lalu alat berat).';
    }

    protected function buildPreview(string $absolutePath): array
    {
        return app(AssetImportService::class)->preview(Filament::getTenant()->getKey(), $absolutePath);
    }

    /** Langkah 2: simpan ke database. */
    public function import(): void
    {
        $path = $this->storedPath();
        if ($path === null) {
            Notification::make()->warning()->title('Unggah file dulu')->send();

            return;
        }

        try {
            $r = app(AssetImportService::class)->commit(Filament::getTenant()->getKey(), $path);
        } catch (ValidationException $e) {
            $this->notifyErrors('Import gagal', $e);

            return;
        }

        $this->discardFile();

        Notification::make()->success()->title("{$r['imported']} aset diimpor")
            ->body(implode(' ', array_filter([
                $r['skipped'] ? "{$r['skipped']} baris dilewati karena sudah ada." : null,
                $r['incomplete'] ? "{$r['incomplete']} aset bertanda Perlu Dilengkapi — lengkapi harga/tanggal belinya." : null,
            ])))
            ->persistent()->send();

        $this->redirect(AssetResource::getUrl('index') . ($r['incomplete']
            ? '?' . http_build_query(['filters' => ['perlu_dilengkapi' => ['isActive' => true]]])
            : ''));
    }

    protected function getViewData(): array
    {
        return ['preview' => $this->safePreview()];
    }
}
