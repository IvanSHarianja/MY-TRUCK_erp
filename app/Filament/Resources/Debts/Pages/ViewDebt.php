<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use App\Services\Accounting\DebtService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewDebt extends ViewRecord
{
    protected static string $resource = DebtResource::class;

    public function getSubheading(): ?string
    {
        /** @var Debt $d */
        $d = $this->getRecord();
        $sisa = app(DebtService::class)->outstanding($d);

        return Debt::JENIS[$d->jenis] . ' · Sisa pokok Rp ' . number_format($sisa, 0, ',', '.')
            . ' dari Rp ' . number_format($d->pokok, 0, ',', '.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Hapus')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->authorize(fn () => auth()->user()->can('delete', $this->getRecord()))
                ->visible(fn () => ! $this->getRecord()->source_type)
                ->requiresConfirmation()
                ->modalDescription('Hanya bisa bila belum ada cicilan dibayar. Jurnal pencairan (bila ada) di-void.')
                ->action(function () {
                    try {
                        app(DebtService::class)->delete($this->getRecord());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Gagal menghapus')
                            ->body(collect($e->errors())->flatten()->implode(' '))->send();

                        return;
                    }
                    Notification::make()->success()->title('Hutang dihapus')->send();
                    $this->redirect(DebtResource::getUrl('index'));
                }),
        ];
    }
}
