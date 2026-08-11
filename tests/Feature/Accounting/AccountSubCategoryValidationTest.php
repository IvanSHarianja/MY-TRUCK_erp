<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Test validasi sub_category vs category — cegah silent failure di Neraca/L-R.
 *
 * KENAPA test ini penting:
 * Sebelum fix, user bisa set sub_category='beban_hpp' pada category='aset'.
 * Tidak ada error, tapi akun HILANG dari BalanceSheet & IncomeStatement karena
 * filter di service pakai strict `sub_category === '...'`. Neraca tidak balance,
 * user bingung selama berjam-jam. Fix ini hard-throw dengan pesan jelas.
 */
class AccountSubCategoryValidationTest extends TestCase
{
    public function test_valid_kombinasi_category_dan_sub_category_bisa_di_save(): void
    {
        $company = $this->createTenant();

        $account = Account::create([
            'company_id'     => $company->id,
            'code'           => 'TEST-01',
            'name'           => 'Test Aset Lancar',
            'category'       => 'aset',
            'sub_category'   => 'aset_lancar',
            'normal_balance' => 'debit',
        ]);

        $this->assertNotNull($account->id);
        $this->assertSame('aset_lancar', $account->sub_category);
    }

    public function test_invalid_kombinasi_throw_validation_exception(): void
    {
        $company = $this->createTenant();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Sub-Kategori 'beban_hpp' tidak valid untuk Kategori 'aset'");

        Account::create([
            'company_id'     => $company->id,
            'code'           => 'TEST-02',
            'name'           => 'Test Mismatch',
            'category'       => 'aset',
            'sub_category'   => 'beban_hpp',  // ← mismatch! aset harusnya aset_lancar/aset_tetap
            'normal_balance' => 'debit',
        ]);
    }

    public function test_typo_sub_category_juga_ditolak(): void
    {
        $company = $this->createTenant();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Sub-Kategori 'aset_lancarr' tidak valid");  // typo kelebihan 'r'

        Account::create([
            'company_id'     => $company->id,
            'code'           => 'TEST-03',
            'name'           => 'Test Typo',
            'category'       => 'aset',
            'sub_category'   => 'aset_lancarr',  // typo!
            'normal_balance' => 'debit',
        ]);
    }

    public function test_sub_category_kosong_masih_bisa_via_auto_fill(): void
    {
        $company = $this->createTenant();

        // Sub_category kosong → auto-fill di booted::saving handle
        $account = Account::create([
            'company_id'     => $company->id,
            'code'           => 'TEST-04',
            'name'           => 'Test Auto Fill',
            'category'       => 'kewajiban',
            'sub_category'   => null,  // biarkan auto-fill isi
            'normal_balance' => 'kredit',
        ]);

        // Verify auto-fill jalan
        $this->assertSame('kewajiban_lancar', $account->sub_category);
    }

    public function test_update_juga_di_validasi(): void
    {
        $company = $this->createTenant();

        $account = Account::create([
            'company_id'     => $company->id,
            'code'           => 'TEST-05',
            'name'           => 'Test Update',
            'category'       => 'beban',
            'sub_category'   => 'beban_hpp',
            'normal_balance' => 'debit',
        ]);

        // Coba update sub_category ke value invalid
        $this->expectException(ValidationException::class);
        $account->update(['sub_category' => 'aset_lancar']);
    }

    public function test_helper_valid_sub_categories_return_correct_map(): void
    {
        // Direct test helper — dipakai oleh form dropdown + audit command
        $this->assertSame(
            ['aset_lancar' => 'Aset Lancar', 'aset_tetap' => 'Aset Tetap'],
            Account::validSubCategoriesForCategory('aset'),
        );

        $this->assertSame(
            ['penutup' => 'Penutup'],
            Account::validSubCategoriesForCategory('penutup'),
        );

        // Kategori tidak dikenal → return array kosong (bukan throw)
        $this->assertSame([], Account::validSubCategoriesForCategory('unknown'));

        // NULL → return semua opsi valid (dipakai form kalau category belum dipilih)
        $all = Account::validSubCategoriesForCategory(null);
        $this->assertArrayHasKey('aset_lancar', $all);
        $this->assertArrayHasKey('beban_hpp', $all);
        $this->assertArrayHasKey('ekuitas', $all);
    }

    public function test_seed_coa_default_tidak_ada_mismatch(): void
    {
        // Regression test: pastikan CompanyTemplateService::seedDefaults()
        // tidak produce akun dengan mismatch. Kalau seed rusak, test lain
        // yang pakai createTenant() akan langsung fail — safety net.
        $company = $this->createTenant();

        $mismatchCount = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('sub_category')
            ->whereNotNull('category')
            ->get()
            ->filter(function ($acc) {
                $valid = array_keys(Account::validSubCategoriesForCategory($acc->category));
                return ! in_array($acc->sub_category, $valid, true);
            })
            ->count();

        $this->assertSame(0, $mismatchCount,
            'Seed COA default TIDAK BOLEH produce akun dengan sub_category mismatch. '
            . 'Cek CompanyTemplateService::accounts() kalau test ini fail.');
    }
}
