<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Export\SopExportException;
use App\Services\Export\SopFile;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Backup Excel format SOP Armada dari terminal — untuk dijadwalkan
 * (scheduler) atau dijalankan manual tanpa batas waktu request web.
 *
 * Contoh:
 *   php artisan sop:export pt-maju --file=all --dari=2026-01-01 --sampai=2026-10-31
 *   php artisan sop:export pt-maju --file=armada
 */
class ExportSopCommand extends Command
{
    protected $signature = 'sop:export
        {company : Slug atau ID PT}
        {--file=all : finance | aset | armada | alat-berat | material | proyek | all}
        {--dari= : Tanggal awal (Y-m-d), default 1 Januari tahun berjalan}
        {--sampai= : Tanggal akhir (Y-m-d), default hari ini}
        {--dir= : Folder tujuan, default storage/app/private/backup-sop}';

    protected $description = 'Ekspor data PT ke file Excel format SOP Armada';

    public function handle(): int
    {
        $key     = (string) $this->argument('company');
        $company = Company::where('slug', $key)->orWhere('id', ctype_digit($key) ? (int) $key : 0)->first();

        if (! $company) {
            $this->error("PT '{$key}' tidak ditemukan.");
            return self::FAILURE;
        }

        $option = (string) $this->option('file');
        $files  = $option === 'all' ? SopFile::cases() : array_filter([SopFile::tryFrom($option)]);
        if ($files === []) {
            $this->error("File '{$option}' tidak dikenal. Pilihan: " . implode(', ', array_keys(SopFile::options())) . ', all');
            return self::FAILURE;
        }

        $dari   = Carbon::parse($this->option('dari') ?: now()->startOfYear());
        $sampai = Carbon::parse($this->option('sampai') ?: now());
        $dir    = rtrim($this->option('dir') ?: storage_path('app/private/backup-sop'), '/\\');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        @ini_set('memory_limit', '1024M');
        $failed = 0;

        foreach ($files as $file) {
            $output = sprintf('%s/%s - %s - %s sd %s.xlsx', $dir, $file->label(), Str::slug($company->name),
                $dari->format('Ymd'), $sampai->format('Ymd'));

            $this->info("{$file->label()}: {$company->name} ({$dari->toDateString()} s.d. {$sampai->toDateString()})…");
            $start = microtime(true);

            try {
                $result = $file->exporter()->export($company, $dari, $sampai, $output, 'artisan');
            } catch (SopExportException $e) {
                $this->error("  Gagal: {$e->getMessage()}");
                $failed++;
                continue;
            }

            $this->line('  ' . collect($result['counts'])->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | '));
            foreach ($result['warnings'] as $w) {
                $this->warn("  {$w}");
            }
            $this->info(sprintf('  Selesai %.0f detik → %s', microtime(true) - $start, $output));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
