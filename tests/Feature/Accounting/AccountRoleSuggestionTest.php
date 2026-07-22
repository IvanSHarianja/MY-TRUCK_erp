<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountRole;
use Tests\TestCase;

/**
 * Opsi C — Role Filter by Category + Auto-Suggest by Name Keyword.
 *
 * Menguji 3 method di AccountRole:
 *  - categoryOf() — role tsb termasuk category apa
 *  - applicableRolesForCategory() — return list role untuk category
 *  - suggestFromName() — keyword matching nama akun → role
 */
class AccountRoleSuggestionTest extends TestCase
{
    // ============================================================
    // categoryOf()
    // ============================================================

    public function test_semua_role_return_category_yang_valid(): void
    {
        $validCategories = ['aset', 'kewajiban', 'ekuitas', 'pendapatan', 'beban', 'penutup'];

        foreach (AccountRole::cases() as $role) {
            $this->assertContains(
                $role->categoryOf(),
                $validCategories,
                "Role {$role->value} return category invalid: {$role->categoryOf()}"
            );
        }
    }

    public function test_role_cash_category_aset(): void
    {
        $this->assertSame('aset', AccountRole::Cash->categoryOf());
        $this->assertSame('aset', AccountRole::CashPetty->categoryOf());
        $this->assertSame('aset', AccountRole::FixedAssetArmada->categoryOf());
    }

    public function test_role_equity_category_ekuitas(): void
    {
        $this->assertSame('ekuitas', AccountRole::EquityModal->categoryOf());
        $this->assertSame('ekuitas', AccountRole::EquityPrive->categoryOf());
    }

    public function test_role_revenue_category_pendapatan(): void
    {
        $this->assertSame('pendapatan', AccountRole::RevenueRent->categoryOf());
        $this->assertSame('pendapatan', AccountRole::RevenueMatl->categoryOf());
    }

    public function test_role_cogs_category_beban(): void
    {
        $this->assertSame('beban', AccountRole::CogsBbm->categoryOf());
        $this->assertSame('beban', AccountRole::OpexPenyusutan->categoryOf());
    }

    // ============================================================
    // applicableRolesForCategory()
    // ============================================================

    public function test_applicable_aset_hanya_role_aset(): void
    {
        $roles = AccountRole::applicableRolesForCategory('aset');

        $this->assertArrayHasKey('cash', $roles);
        $this->assertArrayHasKey('fixed_asset_armada', $roles);
        $this->assertArrayNotHasKey('equity_modal', $roles);
        $this->assertArrayNotHasKey('revenue_rent', $roles);
    }

    public function test_applicable_ekuitas_hanya_4_role(): void
    {
        $roles = AccountRole::applicableRolesForCategory('ekuitas');

        $this->assertCount(4, $roles);
        $this->assertArrayHasKey('equity_modal', $roles);
        $this->assertArrayHasKey('equity_prive', $roles);
        $this->assertArrayHasKey('equity_laba_ditahan', $roles);
        $this->assertArrayHasKey('equity_laba_berjalan', $roles);
    }

    public function test_applicable_pendapatan(): void
    {
        $roles = AccountRole::applicableRolesForCategory('pendapatan');

        $this->assertArrayHasKey('revenue_rent', $roles);
        $this->assertArrayHasKey('revenue_armd', $roles);
        $this->assertArrayHasKey('revenue_matl', $roles);
        $this->assertArrayHasKey('revenue_bong', $roles);
        $this->assertArrayHasKey('revenue_lain', $roles);
    }

    public function test_applicable_kategori_null_return_kosong(): void
    {
        $this->assertEmpty(AccountRole::applicableRolesForCategory(null));
    }

    // ============================================================
    // suggestFromName() — keyword matching
    // ============================================================

    public function test_suggest_cash_dari_nama_bank(): void
    {
        $this->assertSame(AccountRole::Cash, AccountRole::suggestFromName('Bank Mandiri'));
        $this->assertSame(AccountRole::Cash, AccountRole::suggestFromName('Bank BCA Cabang Utama'));
        $this->assertSame(AccountRole::Cash, AccountRole::suggestFromName('Kas dan Bank'));
    }

    public function test_suggest_cash_petty_dari_kas_kecil(): void
    {
        $this->assertSame(AccountRole::CashPetty, AccountRole::suggestFromName('Kas Kecil Lapangan'));
        $this->assertSame(AccountRole::CashPetty, AccountRole::suggestFromName('Petty Cash Cabang Solo'));
    }

    public function test_suggest_equity_modal_dari_setoran_modal(): void
    {
        $this->assertSame(AccountRole::EquityModal, AccountRole::suggestFromName('Modal Pemilik'));
        $this->assertSame(AccountRole::EquityModal, AccountRole::suggestFromName('Setoran Modal Awal'));
        $this->assertSame(AccountRole::EquityModal, AccountRole::suggestFromName('Modal Disetor'));
    }

    public function test_suggest_equity_prive(): void
    {
        $this->assertSame(AccountRole::EquityPrive, AccountRole::suggestFromName('Prive'));
        $this->assertSame(AccountRole::EquityPrive, AccountRole::suggestFromName('Pengambilan Pribadi'));
    }

    public function test_suggest_receivable_dari_piutang(): void
    {
        $this->assertSame(AccountRole::ReceivableUsaha, AccountRole::suggestFromName('Piutang Usaha'));
        $this->assertSame(AccountRole::ReceivableRetensi, AccountRole::suggestFromName('Piutang Retensi'));
    }

    public function test_suggest_fixed_asset_armada(): void
    {
        $this->assertSame(AccountRole::FixedAssetArmada, AccountRole::suggestFromName('Aset Tetap Armada'));
        $this->assertSame(AccountRole::FixedAssetArmada, AccountRole::suggestFromName('Dump Truck Hino 500'));
        $this->assertSame(AccountRole::FixedAssetArmada, AccountRole::suggestFromName('Excavator Komatsu'));
    }

    public function test_suggest_akumulasi_penyusutan(): void
    {
        $this->assertSame(AccountRole::AkumulasiArmada, AccountRole::suggestFromName('Akumulasi Penyusutan Armada'));
        $this->assertSame(AccountRole::AkumulasiKendaraan, AccountRole::suggestFromName('Akumulasi Penyusutan Kendaraan Operasional'));
    }

    public function test_suggest_revenue_per_lini(): void
    {
        $this->assertSame(AccountRole::RevenueRent, AccountRole::suggestFromName('Pendapatan Sewa Alat Berat'));
        $this->assertSame(AccountRole::RevenueArmd, AccountRole::suggestFromName('Pendapatan Ritase Dump Truck'));
        $this->assertSame(AccountRole::RevenueMatl, AccountRole::suggestFromName('Pendapatan Penjualan Material'));
        $this->assertSame(AccountRole::RevenueBong, AccountRole::suggestFromName('Pendapatan Borongan Pengurugan'));
    }

    public function test_suggest_cogs_bbm_dari_beban_solar(): void
    {
        $this->assertSame(AccountRole::CogsBbm, AccountRole::suggestFromName('Beban BBM Solar'));
        $this->assertSame(AccountRole::CogsBbm, AccountRole::suggestFromName('Beban Solar Lapangan'));
    }

    public function test_suggest_opex_penyusutan(): void
    {
        $this->assertSame(AccountRole::OpexPenyusutan, AccountRole::suggestFromName('Beban Penyusutan'));
    }

    public function test_suggest_utang_payable(): void
    {
        $this->assertSame(AccountRole::PayableVendor, AccountRole::suggestFromName('Utang Usaha Vendor'));
        $this->assertSame(AccountRole::PayableGaji, AccountRole::suggestFromName('Utang Gaji Karyawan'));
        $this->assertSame(AccountRole::PayableLeasing, AccountRole::suggestFromName('Utang Leasing'));
        $this->assertSame(AccountRole::PayableBank, AccountRole::suggestFromName('Utang Bank'));
    }

    public function test_suggest_case_insensitive(): void
    {
        $this->assertSame(AccountRole::Cash, AccountRole::suggestFromName('BANK MANDIRI'));
        $this->assertSame(AccountRole::Cash, AccountRole::suggestFromName('bank mandiri'));
        $this->assertSame(AccountRole::EquityModal, AccountRole::suggestFromName('SETORAN MODAL'));
    }

    public function test_suggest_null_kalau_nama_tidak_match_keyword(): void
    {
        $this->assertNull(AccountRole::suggestFromName('XYZ Sesuatu Random 12345'));
        $this->assertNull(AccountRole::suggestFromName(''));
        $this->assertNull(AccountRole::suggestFromName(null));
    }

    public function test_suggest_priority_longest_match_wins(): void
    {
        // 'kas kecil' HARUS match CashPetty dulu, bukan 'kas' → Cash
        $this->assertSame(AccountRole::CashPetty, AccountRole::suggestFromName('Kas Kecil Cabang Solo'));

        // 'akumulasi armada' → AkumulasiArmada, bukan generic 'akumulasi'
        $this->assertSame(AccountRole::AkumulasiArmada, AccountRole::suggestFromName('Akumulasi Armada BCA'));

        // 'utang leasing' → PayableLeasing (panjang), bukan generic
        $this->assertSame(AccountRole::PayableLeasing, AccountRole::suggestFromName('Utang Leasing Kendaraan'));
    }
}
