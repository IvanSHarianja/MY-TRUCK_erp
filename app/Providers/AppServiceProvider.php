<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\RentalContract;
use App\Observers\InvoiceObserver;
use App\Observers\RentalContractObserver;
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
    }
}
