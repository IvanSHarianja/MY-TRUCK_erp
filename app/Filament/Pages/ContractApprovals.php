<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\ArmadaContract;
use App\Models\ContractChangeRequest;
use App\Services\ContractChangeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Daftar pengajuan perubahan kontrak. Pemberi persetujuan (default Owner)
 * menyetujui/menolak; pengaju bisa memantau & membatalkan pengajuannya.
 */
class ContractApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.contract-approvals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Persetujuan Kontrak';

    protected static ?string $title = 'Persetujuan Perubahan Kontrak';

    protected static string|\UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->canCurrent(Permission::ContractApprove) || $user->canCurrent(Permission::OperationalManage));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ContractChangeRequest::query()->where('status', ContractChangeRequest::PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        $service = app(ContractChangeService::class);
        $canApprove = fn (): bool => $service->canApprove(auth()->user(), Filament::getTenant());

        return $table
            ->query(ContractChangeRequest::query()->with(['requester', 'reviewer']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pengajuan perubahan kontrak')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->description(fn (ContractChangeRequest $r) => $r->requester?->name)
                    ->sortable(),

                TextColumn::make('kontrak')
                    ->label('Kontrak')
                    ->getStateUsing(fn (ContractChangeRequest $r) => $service->contractNumber($r))
                    ->description(fn (ContractChangeRequest $r) => $r->contract_type === ArmadaContract::class ? 'Armada' : 'Rental')
                    ->weight('bold'),

                TextColumn::make('perubahan')
                    ->label('Perubahan (lama → baru)')
                    ->getStateUsing(fn (ContractChangeRequest $r) => new HtmlString(
                        collect($service->describe($r))->map(fn ($l) => e($l))->implode('<br>')
                    ))
                    ->html()
                    ->wrap(),

                TextColumn::make('reason')
                    ->label('Alasan')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, ContractChangeRequest $r) => $r->statusLabel())
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    })
                    ->description(fn (ContractChangeRequest $r) => $r->reviewer
                        ? trim($r->reviewer->name . ($r->review_note ? ": {$r->review_note}" : ''))
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Menunggu',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'cancelled' => 'Dibatalkan',
                    ])
                    ->default('pending'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (ContractChangeRequest $r) => $r->isPending() && $canApprove())
                    ->requiresConfirmation()
                    ->modalHeading('Setujui perubahan kontrak?')
                    ->modalDescription('Perubahan langsung berlaku untuk log/transaksi BARU. Log yang sudah ada tidak dihitung ulang.')
                    ->schema([Textarea::make('note')->label('Catatan (opsional)')->rows(2)])
                    ->action(fn (ContractChangeRequest $r, array $data) => $this->run(
                        fn () => $service->approve($r, auth()->user(), $data['note'] ?? null),
                        'Perubahan disetujui & diterapkan ke kontrak',
                    )),

                Action::make('reject')
                    ->label('Tolak')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (ContractChangeRequest $r) => $r->isPending() && $canApprove())
                    ->schema([Textarea::make('note')->label('Alasan penolakan')->required()->rows(2)])
                    ->action(fn (ContractChangeRequest $r, array $data) => $this->run(
                        fn () => $service->reject($r, auth()->user(), $data['note']),
                        'Pengajuan ditolak',
                    )),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->color('gray')
                    ->visible(fn (ContractChangeRequest $r) => $r->isPending() && $r->requested_by === auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (ContractChangeRequest $r) => $this->run(
                        fn () => $service->cancel($r, auth()->user()),
                        'Pengajuan dibatalkan',
                    )),
            ]);
    }

    private function run(callable $fn, string $success): void
    {
        try {
            $fn();
            Notification::make()->success()->title($success)->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Gagal')
                ->body(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }
}
