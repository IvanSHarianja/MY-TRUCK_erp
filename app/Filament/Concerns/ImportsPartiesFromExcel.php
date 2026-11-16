<?php

namespace App\Filament\Concerns;

use App\Enums\Permission;
use App\Services\Import\PartyImportService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tombol "Import Excel" di halaman daftar Klien / Vendor.
 * Data yang sudah ada (kode atau nama sama) dilewati — aman diulang.
 *
 * @mixin \Filament\Resources\Pages\ListRecords
 */
trait ImportsPartiesFromExcel
{
    /** 'client' | 'vendor' */
    abstract protected function partyKind(): string;

    protected function importExcelAction(): Action
    {
        $label = $this->partyKind() === 'client' ? 'klien' : 'vendor';

        return Action::make('importExcel')
            ->label('Import Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->canCurrent(Permission::MasterDataManage) ?? false)
            ->modalHeading("Import {$label} dari Excel")
            ->modalDescription($this->partyKind() === 'client'
                ? 'Kolom dikenali dari judulnya: Kode, Nama (atau Keterangan), Alamat, No Telp, Email, Kontak, NPWP. Bila ada kolom JENIS, hanya baris PIUTANG yang diimpor. Kode/nama yang sudah ada dilewati.'
                : 'Kolom dikenali dari judulnya: Kode, Nama (atau Keterangan), Alamat, No Telp, Email, Kontak, NPWP, Keterangan. Bila ada kolom JENIS, hanya baris HUTANG yang diimpor. Jenis vendor ditebak dari keterangan (sparepart, bengkel, BBM, …). Kode/nama yang sudah ada dilewati.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel (.xlsx / .xls / .csv)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                    ])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('party-imports')
                    ->visibility('private')
                    ->required(),
            ])
            ->action(function (array $data) use ($label) {
                $path = Storage::disk('local')->path($data['file']);

                try {
                    $r = app(PartyImportService::class)->import($this->partyKind(), Filament::getTenant()->getKey(), $path);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Import gagal')
                        ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }

                $lines = array_filter([
                    "{$r['imported']} {$label} baru diimpor dari sheet '{$r['sheet']}'.",
                    $r['skipped_existing'] ? "{$r['skipped_existing']} dilewati karena kode/nama sudah ada." : null,
                    $r['skipped_other_type'] ? "{$r['skipped_other_type']} baris berjenis lain dilewati." : null,
                    $r['skipped_invalid'] ? "{$r['skipped_invalid']} baris dilewati karena kode lebih dari 20 karakter." : null,
                ]);

                Notification::make()->success()->title('Import selesai')->body(implode(' ', $lines))->persistent()->send();
            });
    }
}
