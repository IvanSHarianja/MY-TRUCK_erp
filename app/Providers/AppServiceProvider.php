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
    }
}
