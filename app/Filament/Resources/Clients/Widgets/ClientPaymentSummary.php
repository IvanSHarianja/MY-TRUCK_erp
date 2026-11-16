<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Models\Client;
use App\Services\Accounting\ReceivableReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/** Ringkasan tagihan & ketepatan bayar di atas halaman detail klien. */
class ClientPaymentSummary extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof Client) {
            return [];
        }

        $s   = app(ReceivableReportService::class)->clientSummary($this->record->company_id, $this->record->id);
        $fmt = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');

        return [
            Stat::make('Total Ditagih', $fmt($s['ditagih']))
                ->description('Invoice terbit (tanpa draft & void)'),

            Stat::make('Sudah Dibayar', $fmt($s['dibayar']))
                ->color('success'),

            Stat::make('Sisa Piutang', $fmt($s['sisa']))
                ->description($s['telat_terbuka'] > 0 ? "{$s['telat_terbuka']} invoice lewat jatuh tempo" : 'Tidak ada yang lewat jatuh tempo')
                ->color($s['telat_terbuka'] > 0 ? 'danger' : 'success'),

            Stat::make('Rata-rata Telat Bayar', $s['rata_telat'] === null ? '—' : "{$s['rata_telat']} hari")
                ->description($s['lunas_jumlah'] > 0
                    ? "{$s['lunas_telat']} dari {$s['lunas_jumlah']} invoice lunas dibayar telat"
                    : 'Belum ada invoice lunas')
                ->color(($s['rata_telat'] ?? 0) > 0 ? 'warning' : 'success'),
        ];
    }
}
