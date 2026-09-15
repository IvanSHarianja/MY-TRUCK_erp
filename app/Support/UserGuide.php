<?php

namespace App\Support;

use App\Filament\Pages;
use App\Filament\Pages\Reports;
use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Resources;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Indeks Panduan Pengguna — satu sumber kebenaran untuk:
 *   - daftar isi pop-up Panduan (grup + judul)
 *   - isi tiap bagian (Markdown di resources/panduan/{key}.md)
 *   - tombol "?" per halaman: class Page/Resource → bagian panduan
 *
 * Tampilan: pop-up slide-over (view filament.components.panduan-modal) yang
 * dipasang di semua halaman TANPA isi; isi diambil lewat route panduan.konten
 * saat pertama dibuka. Render Markdown ±300 ms / 48 KB — terlalu mahal untuk
 * disisipkan di setiap halaman.
 *
 * Kenapa Markdown statis (bukan tabel DB): isi panduan mengikuti perilaku kode,
 * jadi ikut di-review & di-versioning bersama kode. Mengubah teks = edit file .md.
 *
 * Tambah modul baru → daftarkan class-nya di 'pages' salah satu bagian.
 * UserGuideTest gagal kalau ada Resource/Page di panel yang belum terpetakan.
 */
final class UserGuide
{
    /**
     * @var array<int, array{key: string, group: string, title: string, pages: array<int, class-string>}>
     */
    public const SECTIONS = [
        ['key' => 'memulai', 'group' => 'Memulai', 'title' => 'Mengenal MY-TRUCK', 'pages' => []],
        ['key' => 'pengaturan-awal', 'group' => 'Memulai', 'title' => 'Langkah Awal Menyiapkan PT', 'pages' => [EditCompanyProfile::class]],
        ['key' => 'dashboard', 'group' => 'Memulai', 'title' => 'Dashboard', 'pages' => [Pages\Dashboard::class, Pages\OperationalDashboard::class]],
        ['key' => 'pengguna-akses', 'group' => 'Memulai', 'title' => 'Pengguna & Hak Akses', 'pages' => [Pages\UserManagement::class, Pages\RoleMatrix::class, Pages\ActivityLog::class]],

        ['key' => 'daftar-akun', 'group' => 'Master Data', 'title' => 'Daftar Akun (COA)', 'pages' => [Resources\Accounts\AccountResource::class, Resources\AccountMappings\AccountMappingResource::class]],
        ['key' => 'coa-paket', 'group' => 'Master Data', 'title' => 'Paket Akun per Fitur', 'pages' => []],
        ['key' => 'master-data', 'group' => 'Master Data', 'title' => 'Klien, Vendor, Karyawan & Lini Bisnis', 'pages' => [
            Resources\Clients\ClientResource::class,
            Resources\Vendors\VendorResource::class,
            Resources\Employees\EmployeeResource::class,
            Resources\BusinessUnits\BusinessUnitResource::class,
        ]],
        ['key' => 'aset', 'group' => 'Master Data', 'title' => 'Armada & Alat Berat', 'pages' => [Resources\Assets\AssetResource::class]],
        ['key' => 'material', 'group' => 'Master Data', 'title' => 'Material: Stok, Pembelian & Penjualan', 'pages' => [
            Resources\Materials\MaterialResource::class,
            Resources\MaterialPurchases\MaterialPurchaseResource::class,
            Resources\MaterialSales\MaterialSaleResource::class,
        ]],

        ['key' => 'rental', 'group' => 'Operasional', 'title' => 'Rental Alat Berat', 'pages' => [Resources\RentalContracts\RentalContractResource::class]],
        ['key' => 'armada', 'group' => 'Operasional', 'title' => 'Jasa Angkutan Armada', 'pages' => [Resources\ArmadaContracts\ArmadaContractResource::class]],
        ['key' => 'proyek', 'group' => 'Operasional', 'title' => 'Proyek Borongan', 'pages' => [Resources\Projects\ProjectResource::class]],
        ['key' => 'transaksi-beban', 'group' => 'Operasional', 'title' => 'Transaksi & Beban', 'pages' => [Pages\QuickTransaction::class]],

        ['key' => 'tagihan', 'group' => 'Keuangan', 'title' => 'Tagihan & Piutang', 'pages' => [Resources\Invoices\InvoiceResource::class]],
        ['key' => 'jurnal-umum', 'group' => 'Keuangan', 'title' => 'Jurnal Umum', 'pages' => [Resources\JournalEntries\JournalEntryResource::class]],
        ['key' => 'laporan-keuangan', 'group' => 'Keuangan', 'title' => 'Laporan Keuangan', 'pages' => [
            Reports\TrialBalance::class,
            Reports\BalanceSheet::class,
            Reports\IncomeStatementUnified::class,
            Reports\IncomeStatement::class,
            Reports\IncomeStatementMatrix::class,
            Reports\IncomeStatementByAsset::class,
            Reports\EquityStatement::class,
        ]],
        ['key' => 'arus-kas', 'group' => 'Keuangan', 'title' => 'Arus Kas', 'pages' => [Reports\CashFlow::class, Reports\CashFlowLak::class]],
        ['key' => 'laporan-aset', 'group' => 'Keuangan', 'title' => 'Penyusutan & Biaya per Unit', 'pages' => [Reports\AssetDepreciation::class, Reports\AssetCostPerUnit::class]],

        ['key' => 'faq', 'group' => 'Bantuan', 'title' => 'Pertanyaan Umum & Kendala', 'pages' => []],
    ];

    /**
     * @return array<string, array<int, array{key: string, title: string}>> group → bagian
     */
    public static function tableOfContents(): array
    {
        $toc = [];
        foreach (self::SECTIONS as $section) {
            $toc[$section['group']][] = ['key' => $section['key'], 'title' => $section['title']];
        }

        return $toc;
    }

    /**
     * Bagian panduan untuk halaman aktif. $scopes = render hook scopes Filament:
     * [class Page] untuk halaman biasa, [class Page, class Resource] untuk halaman resource.
     */
    public static function sectionFor(array $scopes): ?array
    {
        foreach (self::SECTIONS as $section) {
            if (array_intersect($scopes, $section['pages'])) {
                return $section;
            }
        }

        return null;
    }

    public static function html(string $key): HtmlString
    {
        $path = resource_path("panduan/{$key}.md");
        if (! is_file($path)) {
            return new HtmlString('');
        }

        // Isi ditulis developer, tapi tetap render aman: HTML mentah di-strip,
        // link javascript: dsb ditolak.
        return new HtmlString(Str::markdown(file_get_contents($path), [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    /**
     * HTML lengkap isi pop-up (daftar isi + semua bagian), di-cache.
     *
     * Kunci cache memuat waktu ubah semua file .md + view-nya, jadi edit teks
     * panduan langsung terlihat tanpa perlu clear cache manual.
     */
    public static function renderedContent(): string
    {
        $files = [
            ...glob(resource_path('panduan/*.md')),
            resource_path('views/filament/components/panduan-konten.blade.php'),
        ];
        $version = md5(serialize(self::SECTIONS) . implode('|', array_map(
            fn (string $file) => $file . ':' . filemtime($file),
            $files,
        )));

        return Cache::remember("panduan-html:{$version}", now()->addDay(), fn (): string => view(
            'filament.components.panduan-konten',
            [
                'toc'      => self::tableOfContents(),
                'sections' => array_map(
                    fn (array $section) => $section + ['html' => self::html($section['key'])],
                    self::SECTIONS,
                ),
            ],
        )->render());
    }
}
