<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->schema([
            DatePicker::make('startDate')
                ->label('Dari Tanggal')
                ->default(now()->startOfYear()->toDateString())
                ->native(false),
            DatePicker::make('endDate')
                ->label('Sampai Tanggal')
                ->default(now()->toDateString())
                ->native(false),
            Actions::make([
                Action::make('resetFilters')
                    ->label('')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->size('lg')
                    ->action(function () {
                        $this->filters = [
                            'startDate' => now()->startOfYear()->toDateString(),
                            'endDate'   => now()->toDateString(),
                        ];

                        $this->getFiltersForm()->fill($this->filters);

                        session()->forget($this->getFiltersSessionKey());
                    }),
            ])->verticallyAlignEnd(),
        ]);
    }
}
