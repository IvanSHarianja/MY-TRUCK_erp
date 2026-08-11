<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Command audit sub_category vs category — deteksi data lama yang inkonsisten.
 *
 * Bug historis: sebelum validasi save-time diaktifkan, user bisa isi sub_category
 * bebas via TextInput. Kalau tidak match category, akun akan HILANG dari
 * Neraca / L-R diam-diam (silent failure di filter service).
 *
 * Command ini scan seluruh tenant, laporkan akun yang mismatch, dan
 * (opsional --fix) auto-perbaiki dengan default per category.
 *
 * Contoh pakai:
 *   php artisan accounts:audit-sub-category                    # scan semua tenant, report only
 *   php artisan accounts:audit-sub-category --company=abc      # scan 1 tenant saja
 *   php artisan accounts:audit-sub-category --fix              # scan + auto-fix ke default per category
 *
 * SAFETY:
 *   - Tanpa --fix: read-only, hanya print laporan
 *   - Dengan --fix: confirm interaktif dulu sebelum update
 *   - Auto-fix pakai default per category (mis. aset → aset_lancar) —
 *     BUKAN role-based, karena role bisa NULL untuk data legacy.
 */
class AuditAccountSubCategoryCommand extends Command
{
    protected $signature = 'accounts:audit-sub-category
                            {--company= : Slug company spesifik (default: semua)}
                            {--fix : Auto-perbaiki mismatch ke default per category (butuh konfirmasi)}';

    protected $description = 'Audit sub_category akun COA — deteksi mismatch dengan category yang bisa bikin akun hilang dari Neraca/L-R.';

    public function handle(): int
    {
        $companySlug = $this->option('company');
        $doFix       = (bool) $this->option('fix');

        $companies = Company::withoutGlobalScopes()
            ->when($companySlug, fn ($q) => $q->where('slug', $companySlug))
            ->get();

        if ($companies->isEmpty()) {
            $this->error('Tidak ada company yang cocok.');
            return self::FAILURE;
        }

        $totalMismatch = 0;
        $totalFixed    = 0;

        foreach ($companies as $company) {
            $mismatched = $this->findMismatched($company);

            if ($mismatched->isEmpty()) {
                $this->line("✓ [{$company->slug}] Bersih — tidak ada mismatch.");
                continue;
            }

            $this->warn("⚠ [{$company->slug}] Ditemukan {$mismatched->count()} akun mismatch:");
            $this->table(
                ['Kode', 'Nama', 'Kategori', 'Sub-Kategori (SALAH)', 'Sub-Kategori Seharusnya'],
                $mismatched->map(fn ($acc) => [
                    $acc->code,
                    $acc->name,
                    $acc->category,
                    $acc->sub_category,
                    $this->suggestFix($acc->category),
                ])->all(),
            );

            $totalMismatch += $mismatched->count();

            if ($doFix) {
                if (! $this->confirm("Perbaiki {$mismatched->count()} akun ini ke default per category?")) {
                    $this->line("→ Skip [{$company->slug}].");
                    continue;
                }

                $fixed = $this->fixMismatched($mismatched);
                $totalFixed += $fixed;
                $this->info("→ [{$company->slug}] {$fixed} akun diperbaiki.");
            }
        }

        $this->newLine();
        $this->line("═══════════════════════════════════════");
        $this->line("Total tenant di-scan       : " . $companies->count());
        $this->line("Total akun mismatch        : {$totalMismatch}");

        if ($doFix) {
            $this->line("Total akun diperbaiki      : {$totalFixed}");
        } else {
            if ($totalMismatch > 0) {
                $this->warn("→ Jalankan lagi dengan --fix untuk auto-perbaiki.");
            }
        }

        return $totalMismatch > 0 && ! $doFix ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Cari semua akun dengan sub_category tidak valid untuk category-nya.
     */
    private function findMismatched(Company $company): \Illuminate\Support\Collection
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('category')
            ->whereNotNull('sub_category')
            ->get()
            ->filter(function ($acc) {
                $valid = array_keys(Account::validSubCategoriesForCategory($acc->category));
                return ! in_array($acc->sub_category, $valid, true);
            })
            ->values();
    }

    /**
     * Auto-fix pakai default per category (bukan role-based — role bisa NULL).
     * Return jumlah akun yang berhasil di-update.
     *
     * PENTING: Bypass event booted::saving supaya update tidak throw validasi lagi
     * (karena kita SEDANG memperbaiki mismatch — validasi baru berlaku setelahnya).
     */
    private function fixMismatched(\Illuminate\Support\Collection $mismatched): int
    {
        $fixed = 0;
        foreach ($mismatched as $acc) {
            $suggested = $this->suggestFix($acc->category);
            if (! $suggested) continue;

            // Update langsung ke DB via query builder — bypass event validasi.
            // Aman karena kita sedang bikin data valid lagi.
            \DB::table('accounts')
                ->where('id', $acc->id)
                ->update(['sub_category' => $suggested, 'updated_at' => now()]);

            $fixed++;
        }
        return $fixed;
    }

    /**
     * Default per category — konservatif, aman untuk mayoritas kasus UMKM.
     */
    private function suggestFix(string $category): ?string
    {
        return match ($category) {
            'aset'       => 'aset_lancar',
            'kewajiban'  => 'kewajiban_lancar',
            'ekuitas'    => 'ekuitas',
            'pendapatan' => 'pendapatan_usaha',
            'beban'      => 'beban_operasional',
            'penutup'    => 'penutup',
            default      => null,
        };
    }
}
