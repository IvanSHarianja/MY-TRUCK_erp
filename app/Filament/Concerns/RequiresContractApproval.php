<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\ContractApprovals;
use App\Services\ContractChangeService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Halaman Edit Kontrak (Armada & Rental): simpan = AJUKAN perubahan.
 *
 * - Tombol Simpan bawaan diganti aksi "Ajukan Perubahan" dengan alasan wajib.
 * - save() bawaan (Enter / Ctrl+S) dialihkan ke aksi yang sama — jalur
 *   update langsung tidak boleh tersisa.
 * - Pemberi persetujuan (default Owner) melihat "Simpan Perubahan": langsung
 *   berlaku, tetap dengan alasan & tercatat.
 *
 * @mixin \Filament\Resources\Pages\EditRecord
 */
trait RequiresContractApproval
{
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->mountAction('submitChange');
    }

    protected function submitChangeAction(): Action
    {
        $service  = app(ContractChangeService::class);
        $approver = fn (): bool => $service->canApprove(auth()->user(), Filament::getTenant());

        return Action::make('submitChange')
            ->label(fn () => $approver() ? 'Simpan Perubahan' : 'Ajukan Perubahan')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (): bool => $this->record->isAktif() && ! $service->pendingFor($this->record))
            ->modalHeading(fn () => $approver() ? 'Simpan perubahan kontrak' : 'Ajukan perubahan kontrak')
            ->modalDescription(fn () => $approver()
                ? 'Perubahan langsung berlaku dan dicatat beserta alasannya.'
                : 'Perubahan baru berlaku setelah disetujui Owner. Sampai saat itu kontrak (dan log baru) tetap memakai nilai lama.')
            ->modalSubmitActionLabel(fn () => $approver() ? 'Simpan' : 'Kirim Pengajuan')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan perubahan')
                    ->placeholder('contoh: Harga solar naik per 1 Nov sesuai surat klien No. 12/XI')
                    ->required()
                    ->minLength(5)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action) use ($service) {
                // Validasi form utama. Kalau gagal di dalam aksi, error-nya menempel
                // ke pop-up (tak terlihat) dan tombol seolah "ngeload" terus —
                // jadi tutup pop-up, tandai kolomnya di form, beri tahu pengguna.
                try {
                    $state = $this->form->getState();
                } catch (ValidationException $e) {
                    $this->setErrorBag($e->validator->errors());
                    Notification::make()->danger()->title('Lengkapi isian kontrak dulu')
                        ->body(collect($e->errors())->flatten()->unique()->implode(' '))->send();
                    $action->cancel();
                }

                try {
                    $request = $service->submit($this->record, $state, $data['reason'], auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Perubahan tidak disimpan')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $request->status === 'approved'
                    ? Notification::make()->success()->title('Perubahan kontrak disimpan')->send()
                    : Notification::make()->success()->title('Pengajuan dikirim')
                        ->body('Menunggu persetujuan Owner. Kontrak tetap memakai nilai lama sampai disetujui.')->send();

                // Muat ulang: form kembali menampilkan nilai kontrak yang BERLAKU.
                $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
            });
    }

    public function getSubheading(): ?string
    {
        $pending = app(ContractChangeService::class)->pendingFor($this->record);
        if (! $pending) {
            return null;
        }

        return sprintf(
            'Ada pengajuan perubahan dari %s (%s) yang menunggu persetujuan — kontrak masih memakai nilai lama. Lihat di menu %s.',
            $pending->requester?->name ?? 'pengguna',
            $pending->created_at->format('d/m/Y H:i'),
            ContractApprovals::getNavigationLabel(),
        );
    }
}
