<?php

namespace App\Filament\Resources\Debts\Widgets;

use App\Filament\Resources\Debts\DebtResource;
use App\Models\DebtInstallment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Cicilan telat + jatuh tempo 30 hari ke depan, semua hutang. */
class UpcomingInstallments extends TableWidget
{
    protected static ?string $heading = 'Jatuh Tempo 30 Hari ke Depan';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => DebtInstallment::query()->with('debt')
                ->where('status', 'belum')
                ->whereDate('due_date', '<=', today()->addDays(30))
                ->orderBy('due_date'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Tidak ada cicilan jatuh tempo dalam 30 hari')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->recordUrl(fn (DebtInstallment $r) => DebtResource::getUrl('view', ['record' => $r->debt_id]))
            ->columns([
                TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y'),
                TextColumn::make('urgency')->label('')->badge()
                    ->state(fn (DebtInstallment $r) => $r->dueLabel())
                    ->color(fn (DebtInstallment $r) => match ($r->urgency()) {
                        'overdue' => 'danger', 'soon' => 'warning', default => 'gray',
                    }),
                TextColumn::make('debt.kreditur')->label('Kreditur')
                    ->description(fn (DebtInstallment $r) => "{$r->debt?->debt_number} · cicilan ke-{$r->ke}/{$r->debt?->tenor_bulan}"),
                TextColumn::make('total')->label('Tagihan')->alignEnd()->weight('bold')
                    ->state(fn (DebtInstallment $r) => $r->total())->money('IDR', 0),
            ]);
    }
}
