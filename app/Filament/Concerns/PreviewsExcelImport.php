<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Pola import dengan PRATINJAU (Aset, Klien, Vendor):
 *   1. unggah → file disimpan sementara di disk privat `local`
 *   2. pratinjau dibaca dari file itu
 *   3. Konfirmasi membaca ULANG file yang sama lalu menyimpan
 *
 * Isi pratinjau tidak pernah dikirim balik dari browser, dan path file hanya
 * diterima bila berada di folder import halaman ini (cegah path traversal).
 *
 * Halaman pemakai wajib: use InteractsWithForms, properti `public ?array $data`,
 * view yang memanggil readFile / import / cancel.
 *
 * @mixin \Filament\Resources\Pages\Page
 */
trait PreviewsExcelImport
{
    /** Path relatif file yang sedang dipratinjau (disk local). */
    public ?string $storedFile = null;

    /** Folder di disk local, mis. "asset-imports". */
    abstract protected function importDirectory(): string;

    abstract protected function uploadHelperText(): string;

    /** @return array<string, mixed> hasil pratinjau dari service */
    abstract protected function buildPreview(string $absolutePath): array;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label('File Excel (.xlsx / .xls / .csv)')
                    ->helperText($this->uploadHelperText())
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                    ])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory($this->importDirectory())
                    ->visibility('private')
                    ->required(),
            ]);
    }

    /** Langkah 1: simpan file, tampilkan pratinjau. */
    public function readFile(): void
    {
        $state = $this->form->getState();
        $this->discardFile();
        $this->storedFile = $state['file'];
        $this->form->fill();

        // File tak terbaca → notifikasi & kembali ke langkah unggah.
        try {
            $this->preview();
        } catch (ValidationException $e) {
            $this->discardFile();
            $this->notifyErrors('File tidak bisa diimpor', $e);
        }
    }

    public function cancel(): void
    {
        $this->discardFile();
    }

    /** @return array<string, mixed>|null */
    public function preview(): ?array
    {
        $path = $this->storedPath();

        return $path === null ? null : $this->buildPreview($path);
    }

    /** Pratinjau untuk view; file rusak/hilang → kembali ke langkah unggah. */
    protected function safePreview(): ?array
    {
        try {
            return $this->preview();
        } catch (ValidationException) {
            return null;
        }
    }

    /** Path absolut file pratinjau — hanya file di folder import halaman ini. */
    protected function storedPath(): ?string
    {
        $file = $this->storedFile;
        $dir  = preg_quote($this->importDirectory(), '#');

        if ($file === null
            || ! preg_match('#^' . $dir . '/[A-Za-z0-9_-]+\.(xlsx|xls|csv)$#', $file)
            || ! Storage::disk('local')->exists($file)) {
            return null;
        }

        return Storage::disk('local')->path($file);
    }

    protected function discardFile(): void
    {
        if ($this->storedFile !== null && str_starts_with($this->storedFile, $this->importDirectory() . '/')) {
            Storage::disk('local')->delete($this->storedFile);
        }
        $this->storedFile = null;
    }

    protected function notifyErrors(string $title, ValidationException $e): void
    {
        Notification::make()->danger()->title($title)
            ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
    }
}
