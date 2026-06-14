<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Account;
use App\Models\Project;
use App\Services\Accounting\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('project_number', 'desc')
            ->columns([
                TextColumn::make('project_number')
                    ->label('No. Proyek')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nama Proyek')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->name),

                TextColumn::make('client.name')
                    ->label('Pelanggan')
                    ->limit(20)
                    ->searchable(),

                TextColumn::make('jenis_pekerjaan')
                    ->label('Jenis')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                TextColumn::make('nilai_kontrak')
                    ->label('Nilai Kontrak')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('progress_pct')
                    ->label('Progress Fisik')
                    ->getStateUsing(fn ($record) => number_format($record->progress_pct, 1) . '%')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        (float) $record->progress_pct >= 100 => 'success',
                        (float) $record->progress_pct >= 50  => 'info',
                        (float) $record->progress_pct >= 25  => 'warning',
                        default                              => 'gray',
                    }),

                TextColumn::make('tertagih_pct')
                    ->label('Tertagih %')
                    ->getStateUsing(fn ($record) => number_format($record->tertagih_pct, 1) . '%')
                    ->alignEnd(),

                TextColumn::make('tertagih_nilai')
                    ->label('Sudah Ditagih')
                    ->money('IDR')
                    ->getStateUsing(fn ($record) => $record->tertagih_nilai),

                TextColumn::make('sisa_nilai')
                    ->label('Sisa Nilai')
                    ->money('IDR')
                    ->getStateUsing(fn ($record) => $record->sisa_nilai)
                    ->weight('bold'),

                TextColumn::make('dp_diterima')
                    ->label('DP Diterima')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'berjalan' => 'success',
                        'selesai'  => 'gray',
                        'batal'    => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'berjalan' => 'Berjalan',
                        'selesai'  => 'Selesai',
                        'batal'    => 'Batal',
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    // Terima DP
                    Action::make('terima_dp')
                        ->label('Terima DP')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('success')
                        ->visible(fn (Project $p): bool => $p->isBerjalan())
                        ->schema([
                            DatePicker::make('dp_date')
                                ->label('Tanggal Terima')
                                ->required()
                                ->default(now())
                                ->native(false),

                            Select::make('cash_account_id')
                                ->label('Diterima ke Akun')
                                ->required()
                                ->options(function (Project $record) {
                                    return Account::query()
                                        ->where('company_id', $record->company_id)
                                        ->where('is_active', true)
                                        ->where('code', 'like', '111%')
                                        ->orderBy('code')->get()
                                        ->mapWithKeys(fn ($a) => [$a->id => "[{$a->code}] {$a->name}"])
                                        ->toArray();
                                })
                                ->searchable(),

                            TextInput::make('amount')
                                ->label('Nominal DP (Rp)')
                                ->required()
                                ->numeric()
                                ->prefix('Rp')
                                ->minValue(1)
                                ->helperText(fn (Project $r) => 'Nilai kontrak: Rp ' . number_format($r->nilai_kontrak, 0, ',', '.')),

                            Textarea::make('notes')
                                ->label('Catatan')
                                ->rows(2),
                        ])
                        ->modalHeading('Terima Uang Muka Proyek')
                        ->modalDescription('Jurnal otomatis: Dr Kas/Bank, Cr Uang Muka Proyek (221170)')
                        ->action(function (array $data, Project $record) {
                            try {
                                $account = Account::findOrFail($data['cash_account_id']);
                                app(ProjectService::class)->terimaDP(
                                    project: $record,
                                    cashAccount: $account,
                                    amount: (float) $data['amount'],
                                    date: \Carbon\Carbon::parse($data['dp_date']),
                                    notes: $data['notes'] ?? null,
                                );
                                Notification::make()
                                    ->title('DP diterima — jurnal otomatis di-post')
                                    ->success()->send();
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                Notification::make()
                                    ->title('Gagal terima DP')
                                    ->body(collect($e->errors())->flatten()->implode(' '))
                                    ->danger()->send();
                            }
                        }),

                    // Update Progress
                    Action::make('update_progress')
                        ->label('Update Progress')
                        ->icon(Heroicon::OutlinedChartBar)
                        ->color('info')
                        ->visible(fn (Project $p): bool => $p->isBerjalan())
                        ->schema([
                            DatePicker::make('update_date')
                                ->label('Tanggal Update')
                                ->required()
                                ->default(now())
                                ->native(false),

                            TextInput::make('progress_pct')
                                ->label('Progress Fisik (%)')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->step(0.1)
                                ->suffix('%')
                                ->helperText(fn (Project $r) => 'Progress sekarang: ' . $r->progress_pct . '% (tidak boleh mundur)'),

                            Textarea::make('notes')
                                ->label('Catatan Update')
                                ->rows(2)
                                ->placeholder('contoh: Galian selesai, mulai timbunan'),
                        ])
                        ->action(function (array $data, Project $record) {
                            try {
                                app(ProjectService::class)->updateProgress(
                                    project: $record,
                                    progressPct: (float) $data['progress_pct'],
                                    notes: $data['notes'] ?? null,
                                    date: \Carbon\Carbon::parse($data['update_date']),
                                );
                                Notification::make()
                                    ->title('Progress diperbarui ke ' . $data['progress_pct'] . '%')
                                    ->success()->send();
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                Notification::make()
                                    ->title('Gagal update progress')
                                    ->body(collect($e->errors())->flatten()->implode(' '))
                                    ->danger()->send();
                            }
                        }),

                    // Tagih Termin
                    Action::make('tagih_termin')
                        ->label('Tagih Termin')
                        ->icon(Heroicon::Banknotes)
                        ->color('warning')
                        ->visible(fn (Project $p): bool => $p->isBerjalan() && (float) $p->progress_pct > (float) $p->tertagih_pct)
                        ->schema([
                            DatePicker::make('invoice_date')
                                ->label('Tanggal Invoice')
                                ->required()
                                ->default(now())
                                ->native(false),

                            TextInput::make('termin_pct')
                                ->label('Persen Termin (%)')
                                ->required()
                                ->numeric()
                                ->minValue(0.01)
                                ->step(0.01)
                                ->suffix('%')
                                ->helperText(fn (Project $r) => sprintf(
                                    'Progress: %s%% · Tertagih: %s%% · Bisa ditagih maks: %s%%',
                                    $r->progress_pct, $r->tertagih_pct, $r->sisa_tagih_pct,
                                )),

                            Textarea::make('description')
                                ->label('Keterangan Termin')
                                ->rows(2)
                                ->placeholder('contoh: Termin galian & land clearing'),
                        ])
                        ->modalHeading('Tagih Termin Proyek')
                        ->action(function (array $data, Project $record) {
                            try {
                                $invoice = app(ProjectService::class)->tagihTermin(
                                    project: $record,
                                    terminPct: (float) $data['termin_pct'],
                                    invoiceDate: \Carbon\Carbon::parse($data['invoice_date']),
                                    description: $data['description'] ?? null,
                                );
                                Notification::make()
                                    ->title('Termin ' . $data['termin_pct'] . '% berhasil ditagih')
                                    ->body($invoice->invoice_number . ' — Rp ' . number_format($invoice->amount, 0, ',', '.'))
                                    ->success()->send();
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                Notification::make()
                                    ->title('Gagal tagih termin')
                                    ->body(collect($e->errors())->flatten()->implode(' '))
                                    ->danger()->send();
                            }
                        }),

                    // Tutup Proyek
                    Action::make('selesai')
                        ->label('Tutup Proyek')
                        ->icon(Heroicon::CheckCircle)
                        ->color('gray')
                        ->visible(fn (Project $p): bool => $p->isBerjalan())
                        ->requiresConfirmation()
                        ->modalDescription('Pastikan semua progress sudah 100% dan semua termin sudah ditagih.')
                        ->action(function (Project $record) {
                            try {
                                app(ProjectService::class)->selesai($record);
                                Notification::make()->title('Proyek ditutup')->success()->send();
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                Notification::make()
                                    ->title('Gagal tutup')
                                    ->body(collect($e->errors())->flatten()->implode(' '))
                                    ->danger()->send();
                            }
                        }),

                    EditAction::make(),
                ])
                ->label('Aksi')
                ->icon(Heroicon::EllipsisVertical)
                ->button(),
            ]);
    }
}
