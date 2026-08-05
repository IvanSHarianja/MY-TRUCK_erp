<?php

namespace App\Providers;

use App\Models\AssetMaintenanceLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Observers\AssetMaintenanceLogObserver;
use App\Observers\InvoiceObserver;
use App\Observers\JournalEntryObserver;
use App\Observers\RentalContractObserver;
use App\Observers\RentalLogObserver;
use App\Observers\RitLogObserver;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
        RentalContract::observe(RentalContractObserver::class);

        // Auto-post beban operasional harian ke jurnal (Tahap 3).
        // Observer resolusi biaya via OperationalCostService (contract standard +
        // override log kalau override_biaya=true).
        RentalLog::observe(RentalLogObserver::class);
        RitLog::observe(RitLogObserver::class);

        // Maintenance: observer hanya handle update & delete karena create dilakukan
        // eksplisit via MaintenanceService::log() (menghindari double-post).
        AssetMaintenanceLog::observe(AssetMaintenanceLogObserver::class);

        // Cascade rollback saat JournalEntry di-void — sinkronkan counter di
        // source (project.dp_diterima, log.journal_entry_id) yang tidak
        // otomatis di-handle oleh JournalService::void().
        JournalEntry::observe(JournalEntryObserver::class);

        // ->rupiah() macro — format Indonesia (100.000.000). Display live
        // saat user ketik, strip '.' sebelum submit → DB simpan angka murni.
        // Model yang dipakai di field ini WAJIB cast kolom ke 'integer'
        // (bukan decimal:2), karena string "500000.00" bikin mask $money
        // salah interpret titik sebagai thousand separator (tampil 100× lipat).
        TextInput::macro('rupiah', function () {
            /** @var TextInput $this */
            return $this
                ->prefix('Rp')
                ->rules(['numeric', 'min:0'])
                ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                ->stripCharacters(['.']);
        });

        // ->buktiTf() macro — FileUpload untuk bukti transfer (opsional).
        // Auto-resize max 1920px di client (Filament imageEditor), max 5MB.
        // Directory: bukti-tf/{company_id}/{YYYY}/{MM}/.
        FileUpload::macro('buktiTf', function () {
            /** @var FileUpload $this */
            return $this
                ->label('Bukti Transfer (opsional)')
                ->image()
                ->imageEditor()
                ->imageResizeMode('contain')
                ->imageResizeTargetWidth(1920)
                ->maxSize(5120)
                ->disk('public')
                ->directory(fn () => sprintf(
                    'bukti-tf/%s/%s',
                    optional(Filament::getTenant())?->id ?? 'shared',
                    now()->format('Y/m'),
                ))
                ->helperText('Foto struk/screenshot transfer. max 5MB.');
        });

        // Action::liatBukti() — row action untuk buka bukti transfer di tab baru.
        // Auto-hidden kalau record belum ada bukti-nya. Pasang di table/RelationManager
        // yang record-nya pakai trait HasBuktiTf (Payment, RentalLog, RitLog, dll).
        Action::macro('liatBukti', function () {
            /** @var Action $this */
            return $this
                ->label('Lihat Bukti')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('info')
                ->visible(fn ($record): bool => (bool) ($record?->bukti_tf_path))
                ->url(fn ($record): ?string => $record?->bukti_tf_url, shouldOpenInNewTab: true);
        });
    }
}
