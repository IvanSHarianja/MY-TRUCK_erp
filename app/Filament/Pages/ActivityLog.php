<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.activity-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $title = 'Riwayat Aktivitas';

    protected static ?string $navigationLabel = 'Riwayat Aktivitas';

    // Hidden dari sidebar — akses via user menu (Filament Settings panel)
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }
        $user = auth()->user();
        $pivot = $user?->companies()->where('companies.id', $tenant->getKey())->first()?->pivot;

        return $pivot && in_array($pivot->role, ['owner', 'admin'], true);
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(function () use ($tenant) {
                // Filter activity log: hanya record yang subject-nya milik tenant ini
                return Activity::query()
                    ->with(['causer', 'subject'])
                    ->where(function ($q) use ($tenant) {
                        // Subject types yang relevan + company_id check
                        $models = [
                            \App\Models\Invoice::class,
                            \App\Models\Payment::class,
                            \App\Models\ArmadaContract::class,
                            \App\Models\RentalContract::class,
                            \App\Models\Project::class,
                            \App\Models\MaterialSale::class,
                        ];

                        foreach ($models as $model) {
                            $table = (new $model)->getTable();
                            $q->orWhereExists(function ($sub) use ($model, $table, $tenant) {
                                $sub->select(\DB::raw(1))
                                    ->from($table)
                                    ->whereColumn("{$table}.id", 'activity_log.subject_id')
                                    ->where('activity_log.subject_type', $model)
                                    ->where("{$table}.company_id", $tenant->getKey());
                            });
                        }
                    })
                    ->latest();
            })
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('Pengguna')
                    ->placeholder('(sistem)')
                    ->badge(),

                TextColumn::make('log_name')
                    ->label('Modul')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'invoice'         => 'info',
                        'payment'         => 'success',
                        'armada_contract' => 'warning',
                        'rental_contract' => 'primary',
                        'project'         => 'danger',
                        'material_sale'   => 'gray',
                        default           => 'gray',
                    }),

                TextColumn::make('event')
                    ->label('Aksi')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                        default   => $state,
                    }),

                TextColumn::make('subject_id')
                    ->label('Record ID')
                    ->getStateUsing(fn ($record) => $record->subject_type
                        ? class_basename($record->subject_type) . ' #' . $record->subject_id
                        : '-')
                    ->size('sm'),

                TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description)
                    ->toggleable(),

                TextColumn::make('properties')
                    ->label('Perubahan')
                    ->getStateUsing(function ($record) {
                        $props = $record->properties->toArray();
                        if (! isset($props['attributes'])) {
                            return '-';
                        }
                        $changes = [];
                        $old = $props['old'] ?? [];
                        foreach ($props['attributes'] as $key => $newVal) {
                            $oldVal = $old[$key] ?? '∅';
                            $newDisplay = is_scalar($newVal) ? (string) $newVal : json_encode($newVal);
                            $oldDisplay = is_scalar($oldVal) ? (string) $oldVal : json_encode($oldVal);
                            $changes[] = "{$key}: {$oldDisplay} → {$newDisplay}";
                        }
                        return implode(' | ', $changes);
                    })
                    ->limit(60)
                    ->tooltip(function ($record) {
                        $props = $record->properties->toArray();
                        if (! isset($props['attributes'])) return null;
                        $changes = [];
                        $old = $props['old'] ?? [];
                        foreach ($props['attributes'] as $key => $newVal) {
                            $oldVal = $old[$key] ?? '∅';
                            $newDisplay = is_scalar($newVal) ? (string) $newVal : json_encode($newVal);
                            $oldDisplay = is_scalar($oldVal) ? (string) $oldVal : json_encode($oldVal);
                            $changes[] = "{$key}: {$oldDisplay} → {$newDisplay}";
                        }
                        return implode("\n", $changes);
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Modul')
                    ->options([
                        'invoice'         => 'Invoice',
                        'payment'         => 'Payment',
                        'armada_contract' => 'Kontrak Armada',
                        'rental_contract' => 'Kontrak Rental',
                        'project'         => 'Proyek',
                        'material_sale'   => 'Penjualan Material',
                    ]),

                SelectFilter::make('event')
                    ->label('Aksi')
                    ->options([
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                    ]),

                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('start')->label('Dari')->native(false),
                        DatePicker::make('end')->label('Sampai')->native(false),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['start'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['end'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->paginated([25, 50, 100]);
    }
}
