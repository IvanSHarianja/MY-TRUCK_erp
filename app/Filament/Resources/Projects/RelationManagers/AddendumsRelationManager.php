<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectAddendum;
use App\Services\Accounting\ProjectAddendumService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Pekerjaan tambah (addendum) proyek borongan — menambah piutang klien
 * setelah disetujui Owner. Lihat ProjectAddendumService.
 */
class AddendumsRelationManager extends RelationManager
{
    protected static string $relationship = 'addendums';

    protected static ?string $title = 'Pekerjaan Tambah';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('tanggal')->label('Tanggal')->required()->default(now())->native(false),
                TextInput::make('nilai')->label('Nilai Pekerjaan Tambah')->required()->rupiah(),
                Textarea::make('uraian')->label('Uraian Pekerjaan')->required()->rows(2)->maxLength(255)->columnSpanFull()
                    ->placeholder('contoh: Tambahan timbunan 500 m³ area parkir sesuai permintaan klien'),
            ]);
    }

    public function table(Table $table): Table
    {
        $service = fn () => app(ProjectAddendumService::class);
        $canApprove = fn (): bool => ($t = Filament::getTenant()) && app(ProjectAddendumService::class)->canApprove(auth()->user(), $t);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['invoice', 'requester', 'reviewer']))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada pekerjaan tambah')
            ->emptyStateDescription('Pekerjaan di luar kontrak awal yang ditagihkan ke klien. Wajib disetujui Owner sebelum invoice terbit.')
            ->columns([
                TextColumn::make('addendum_number')->label('No.')->weight('bold'),
                TextColumn::make('tanggal')->label('Tanggal')->date('d M Y'),
                TextColumn::make('uraian')->label('Uraian')->wrap()->limit(80),
                TextColumn::make('nilai')->label('Nilai')->money('IDR', 0)->alignEnd()->weight('bold'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => ProjectAddendum::STATUS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning', 'approved' => 'success', 'rejected', 'void' => 'danger', default => 'gray',
                    })
                    ->description(fn (ProjectAddendum $r) => $r->review_note),
                TextColumn::make('invoice.invoice_number')->label('Invoice')->placeholder('—'),
                TextColumn::make('requester.name')->label('Diajukan')->toggleable(),
                TextColumn::make('reviewer.name')->label('Diproses')->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ajukan Pekerjaan Tambah')
                    ->modalHeading('Ajukan Pekerjaan Tambah')
                    ->visible(fn (): bool => $this->getOwnerRecord()->isBerjalan())
                    ->successNotificationTitle(fn (ProjectAddendum $record) => $record->status === 'approved'
                        ? 'Pekerjaan tambah disetujui & invoice terbit'
                        : 'Pengajuan terkirim — menunggu persetujuan Owner')
                    ->using(fn (array $data): Model => $this->run(fn () => $service()->submit($this->getOwnerRecord(), auth()->user(), $data))),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->authorize($canApprove)
                    ->visible(fn (ProjectAddendum $r) => $r->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(fn (ProjectAddendum $r) => "Setujui {$r->addendum_number}?")
                    ->modalDescription(fn (ProjectAddendum $r) => 'Invoice Rp ' . number_format($r->nilai, 0, ',', '.')
                        . ' langsung terbit sebagai piutang klien.')
                    ->action(function (ProjectAddendum $r) use ($service) {
                        $this->notifyOnFail(fn () => $service()->approve($r, auth()->user()));
                        Notification::make()->success()->title('Disetujui — invoice terbit')->send();
                    }),

                Action::make('reject')
                    ->label('Tolak')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->authorize($canApprove)
                    ->visible(fn (ProjectAddendum $r) => $r->isPending())
                    ->schema([Textarea::make('review_note')->label('Alasan')->required()->rows(2)])
                    ->action(function (ProjectAddendum $r, array $data) use ($service) {
                        $this->notifyOnFail(fn () => $service()->reject($r, auth()->user(), $data['review_note']));
                        Notification::make()->success()->title('Pengajuan ditolak')->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (ProjectAddendum $r) => $r->isPending() && $r->requested_by === auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (ProjectAddendum $r) => $this->notifyOnFail(fn () => $service()->cancel($r, auth()->user()))),
            ]);
    }

    private function run(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Gagal menyimpan')
                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

            throw (new \Filament\Support\Exceptions\Halt())->rollBackDatabaseTransaction();
        }
    }

    private function notifyOnFail(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Gagal')
                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();

            throw new \Filament\Support\Exceptions\Halt();
        }
    }
}
