<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\Accounting\YearEndClosingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Tutup Buku tahunan: pindahkan laba/rugi tahun ke Laba Ditahan + kunci
 * 12 periodenya. Owner (izin Close / Open Period). Lihat YearEndClosingService.
 */
class YearEndClosing extends Page
{
    protected string $view = 'filament.pages.year-end-closing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Tutup Buku';

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan Keuangan';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Tutup Buku Tahunan';

    public static function canAccess(): bool
    {
        return auth()->user()?->canCurrent(Permission::PeriodClose) ?? false;
    }

    public function closeYearAction(): Action
    {
        return Action::make('closeYear')
            ->label(fn (array $arguments) => 'Tutup Buku ' . ($arguments['year'] ?? ''))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->authorize(fn (): bool => static::canAccess())
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Tutup buku tahun ' . $arguments['year'] . '?')
            ->modalDescription(fn (array $arguments) => sprintf(
                'Laba/rugi %1$s dipindah ke Laba Ditahan lewat jurnal penutup tanggal 31 Desember %1$s, lalu semua periode %1$s dikunci. '
                . 'Setelah itu jurnal bertanggal %1$s tidak bisa diinput sampai buku dibuka kembali.',
                $arguments['year'],
            ))
            ->modalSubmitActionLabel('Ya, tutup buku')
            ->action(function (array $arguments) {
                try {
                    $journal = app(YearEndClosingService::class)->close(Filament::getTenant(), (int) $arguments['year']);
                    Notification::make()->success()
                        ->title("Buku {$arguments['year']} ditutup")
                        ->body($journal ? "Jurnal penutup {$journal->entry_number} diposting." : 'Tidak ada saldo laba/rugi — periode dikunci.')
                        ->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Gagal tutup buku')
                        ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                }
            });
    }

    public function reopenYearAction(): Action
    {
        return Action::make('reopenYear')
            ->label('Buka Kembali')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading(fn (array $arguments) => 'Buka kembali buku ' . $arguments['year'] . '?')
            ->modalDescription('Jurnal penutup di-void dan periode dibuka lagi supaya koreksi bisa diinput. Tutup buku lagi setelah koreksi selesai.')
            ->schema([
                Textarea::make('reason')->label('Alasan')->required()->minLength(5)->rows(2)
                    ->placeholder('contoh: koreksi penyusutan Desember dari auditor'),
            ])
            ->modalSubmitActionLabel('Buka kembali')
            ->action(function (array $arguments, array $data) {
                try {
                    app(YearEndClosingService::class)->reopen(Filament::getTenant(), (int) $arguments['year'], $data['reason']);
                    Notification::make()->success()->title("Buku {$arguments['year']} dibuka kembali")->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Gagal membuka buku')
                        ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                }
            });
    }

    protected function getViewData(): array
    {
        return [
            'years' => array_reverse(app(YearEndClosingService::class)->years(Filament::getTenant()->getKey())),
        ];
    }
}
