<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\DepreciationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Command depresiasi bulanan.
 *
 * Contoh pakai:
 *   php artisan depreciation:run                          # bulan lalu, semua company
 *   php artisan depreciation:run --month=2026-07          # bulan tertentu
 *   php artisan depreciation:run --company=maju-terus     # 1 company saja
 *   php artisan depreciation:run --preview                # dry run (tidak buat jurnal)
 *
 * Idempotent: aset yang sudah di-depresiasi untuk periode tsb akan di-skip.
 * Aman dijalankan berulang (cron atau manual).
 */
class RunDepreciationCommand extends Command
{
    protected $signature = 'depreciation:run
                            {--month= : Target bulan YYYY-MM (default: bulan lalu)}
                            {--company= : Slug company spesifik (default: semua)}
                            {--preview : Dry-run, hanya tampilkan preview tanpa post jurnal}';

    protected $description = 'Post jurnal penyusutan bulanan (garis lurus) untuk semua aset aktif';

    public function handle(DepreciationService $service): int
    {
        // Target bulan: default bulan lalu (kalau sekarang Aug, run untuk Jul)
        $monthOpt = $this->option('month');
        if ($monthOpt) {
            try {
                $target = Carbon::createFromFormat('Y-m', $monthOpt)->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("Format --month salah. Pakai YYYY-MM (contoh: 2026-07).");
                return self::FAILURE;
            }
        } else {
            $target = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        }

        $this->info("Target periode: {$target->format('F Y')} ({$target->format('Y-m')})");

        // Filter company
        $companies = Company::withoutGlobalScopes();
        if ($slug = $this->option('company')) {
            $companies->where('slug', $slug);
        } else {
            $companies->where('is_active', true);
        }
        $companies = $companies->get();

        if ($companies->isEmpty()) {
            $this->warn('Tidak ada company yang cocok filter.');
            return self::SUCCESS;
        }

        $isPreview = $this->option('preview');

        foreach ($companies as $company) {
            $this->newLine();
            $this->line("<fg=cyan>=== {$company->name} ({$company->slug}) ===</>");

            if ($isPreview) {
                $preview = $service->preview($company, $target->year, $target->month);
                $this->renderPreviewTable($preview);
                continue;
            }

            try {
                $result = $service->runForCompany($company, $target->year, $target->month);
            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
                continue;
            }

            $this->info("  Posted:  {$result['posted']} aset");
            $this->info("  Skipped: {$result['skipped']} aset");
            $this->info("  Total penyusutan: Rp " . number_format($result['total_amount'], 0, ',', '.'));

            if (! empty($result['errors'])) {
                $this->warn('  Errors (per aset, tidak fatal):');
                foreach ($result['errors'] as $err) {
                    $this->warn("    - {$err}");
                }
            }
        }

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $preview */
    private function renderPreviewTable(array $preview): void
    {
        $rows = [];
        foreach ($preview as $item) {
            $status = $item['eligible']
                ? '<fg=green>ELIGIBLE</>'
                : '<fg=yellow>SKIP: ' . $item['reason'] . '</>';
            $rows[] = [
                $item['asset_code'],
                substr($item['name'], 0, 40),
                'Rp ' . number_format($item['monthly'], 0, ',', '.'),
                $status,
            ];
        }

        $this->table(['Kode', 'Nama', 'Bulanan', 'Status'], $rows);
    }
}
