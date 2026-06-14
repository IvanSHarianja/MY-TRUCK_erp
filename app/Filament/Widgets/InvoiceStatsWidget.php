<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Payment;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class InvoiceStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Ringkasan Invoice & Piutang';

    protected int|string|array $columnSpan = 'full';

    /** Auto-refresh tiap 5 detik */
    protected ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        $companyId = $tenant->getKey();
        $today     = Carbon::today();

        // Range filter: pakai endDate untuk "tertagih bulan ini"
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfMonth());
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now());

        // Total piutang berjalan (outstanding)
        $outstanding = Invoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['terbit', 'sebagian'])
            ->selectRaw('SUM(amount - paid_amount) as total, COUNT(*) as cnt')
            ->first();

        $totalPiutang = (float) ($outstanding->total ?? 0);
        $jumlahOutstanding = (int) ($outstanding->cnt ?? 0);

        // Overdue >30 hari
        $overdue = Invoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['terbit', 'sebagian'])
            ->whereRaw('DATEDIFF(?, invoice_date) > 30', [$today->toDateString()])
            ->selectRaw('SUM(amount - paid_amount) as total, COUNT(*) as cnt')
            ->first();

        $totalOverdue = (float) ($overdue->total ?? 0);
        $jumlahOverdue = (int) ($overdue->cnt ?? 0);

        // Tertagih dalam range filter (payments)
        $tertagih = Payment::query()
            ->where('company_id', $companyId)
            ->whereBetween('payment_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        $fmt = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        $periodLabel = $startDate->translatedFormat('d M') . ' - ' . $endDate->translatedFormat('d M Y');

        return [
            Stat::make('Total Piutang Berjalan', $fmt($totalPiutang))
                ->description("{$jumlahOutstanding} invoice outstanding")
                ->descriptionIcon('heroicon-m-document-text')
                ->color($totalPiutang > 0 ? 'warning' : 'success'),

            Stat::make('Overdue (>30 hari)', $fmt($totalOverdue))
                ->description("{$jumlahOverdue} invoice perlu ditagih segera")
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($jumlahOverdue > 0 ? 'danger' : 'success'),

            Stat::make('Tertagih', $fmt($tertagih))
                ->description($periodLabel)
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Jumlah Invoice', (string) $jumlahOutstanding)
                ->description('Belum lunas / sebagian')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
        ];
    }
}
