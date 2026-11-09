<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\Export\SopFile;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Halaman unduh backup Excel format SOP Armada (6 file, satu per unduhan).
 * Pembuatan file dilakukan SopExportController; halaman ini hanya memilih
 * file & periode lalu mengarahkan browser ke URL unduhan.
 */
class BackupExcel extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.backup-excel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'Backup Excel (SOP)';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Backup Excel — Format SOP Armada';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canCurrent(Permission::DataExport) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'file'   => SopFile::Finance->value,
            'dari'   => now()->startOfYear()->toDateString(),
            'sampai' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('file')
                    ->label('File SOP')
                    ->options(SopFile::options())
                    ->helperText(fn ($state) => SopFile::tryFrom((string) $state)?->description())
                    ->live()
                    ->selectablePlaceholder(false)
                    ->required(),

                DatePicker::make('dari')
                    ->label('Dari tanggal')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),

                DatePicker::make('sampai')
                    ->label('Sampai tanggal')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterOrEqual('dari')
                    ->required(),
            ]);
    }

    public function download(): mixed
    {
        $state = $this->form->getState();

        return redirect()->to(route('export.sop', [
            'tenant' => Filament::getTenant()->slug,
            'file'   => $state['file'],
            'dari'   => Carbon::parse($state['dari'])->toDateString(),
            'sampai' => Carbon::parse($state['sampai'])->toDateString(),
        ]));
    }
}
