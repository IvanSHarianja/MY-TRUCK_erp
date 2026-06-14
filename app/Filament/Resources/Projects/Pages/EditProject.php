<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->formId('form')
                ->visible(fn (): bool => $this->record->isBerjalan()),

            $this->getCancelFormAction(),

            DeleteAction::make()
                ->visible(fn (): bool =>
                    (float) $this->record->tertagih_pct === 0.0
                    && (float) $this->record->dp_diterima === 0.0
                    && (float) $this->record->progress_pct === 0.0
                ),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
