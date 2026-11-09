<?php

namespace Tests\Feature\Export;

use App\Enums\DepreciationMethod;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Material;
use App\Models\Project;
use App\Models\RentalContract;
use App\Models\RentalLog;
use App\Models\RitLog;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\ArmadaContractService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\MaterialPurchaseService;
use App\Services\Accounting\MaterialSaleService;
use App\Services\Accounting\ProjectService;
use App\Services\Accounting\RentalContractService;
use App\Services\Export\SopAlatBeratExporter;
use App\Services\Export\SopArmadaExporter;
use App\Services\Export\SopAsetExporter;
use App\Services\Export\SopFile;
use App\Services\Export\SopMaterialExporter;
use App\Services\Export\SopProyekExporter;
use App\Services\Export\SopWorkbookExporter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Backup Excel Fase 2 — 5 file SOP operasional. Periode uji: Maret 2026.
 * Angka harapan dihitung manual dari skenario, bukan dari keluaran kode.
 */
class SopOperationalExporterTest extends TestCase
{
    private Company $company;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createTenant(['name' => 'PT Uji Operasional', 'harga_solar_default' => 7_000]);
        $this->user    = $this->createTenantUser($this->company);
        $this->actingAsTenant($this->user, $this->company);
        $this->client  = $this->createClient($this->company, ['code' => 'KL-01', 'name' => 'CV Klien']);
    }

    private function payload(SopWorkbookExporter $exporter): array
    {
        return $exporter->buildPayload($this->company, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 31));
    }

    private function asset(array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'company_id'          => $this->company->id,
            'asset_code'          => 'DT-01',
            'name'                => 'Dump Truck 01',
            'type'                => 'dump_truck',
            'plate_number'        => 'B 1234 XY',
            'purchase_date'       => '2025-01-10',
            'purchase_price'      => 360_000_000,
            'salvage_value'       => 0,
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_months'  => 60,
            'status'              => 'aktif',
        ], $attrs));
    }

    private function employee(string $code, string $position): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'employee_id' => $code, 'name' => "Karyawan {$code}",
            'position' => $position, 'is_active' => true,
        ]);
    }

    // ── Armada ──────────────────────────────────────────────────────────

    public function test_armada_kontrak_ritase_biaya_dan_tagihan(): void
    {
        $truck  = $this->asset();
        $driver = $this->employee('DRV-01', 'driver');
        $contract = ArmadaContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'KA26-0001', 'client_id' => $this->client->id,
            'tipe_kontrak' => 'all_in', 'route_description' => 'Kuari → Proyek A',
            'tarif_per_rit' => 300_000, 'bbm_liter_per_rit' => 10, 'harga_bbm_per_liter' => 7_000,
            'gaji_supir_per_hari' => 150_000, 'uang_makan_per_hari' => 50_000,
            'uang_jalan_per_rit' => 40_000, 'premi_per_rit' => 10_000,
            'billed_rit' => 0, 'status' => 'aktif', 'started_at' => '2026-01-01', 'created_by' => $this->user->id,
        ]);
        $log = fn (string $date, int $rit, array $extra = []) => RitLog::create(array_merge([
            'company_id' => $this->company->id, 'armada_contract_id' => $contract->id, 'asset_id' => $truck->id,
            'driver_id' => $driver->id, 'log_date' => $date, 'rit_count' => $rit, 'created_by' => $this->user->id,
        ], $extra));

        $log('2026-02-20', 2);                                                      // sebelum periode
        $log('2026-03-05', 3);
        $log('2026-03-10', 2, ['override_biaya' => true, 'solar_liter' => 25, 'uang_jalan_supir' => 100_000]);
        $invoice = app(ArmadaContractService::class)->tagih($contract->fresh(), Carbon::create(2026, 3, 15));
        $log('2026-03-20', 1);                                                      // belum ditagih

        $p = $this->payload(app(SopArmadaExporter::class));

        $k = $p['cells']['Kontrak Armada'];
        $this->assertSame('KA26-0001', $k['C12']);
        $this->assertSame([1, 1, 2026], [$k['E12'], $k['F12'], $k['G12']]);
        $this->assertSame('KL-01', $k['H12']);
        $this->assertSame('All In', $k['K12']);
        $this->assertSame('Aktif', $k['T12']);
        $this->assertSame('Disetujui', $k['U12']);
        $this->assertSame(8.0, $k['V12'], 'Total rit s.d. SAMPAI: 2 + 3 + 2 + 1');
        $this->assertSame(7.0, $k['W12'], 'Rit ditagih: semua log sebelum invoice 15 Mar');

        $r = $p['cells']['Ritase'];
        $this->assertSame(3, $p['counts']['ritase']);
        // 5 Mar, 3 rit, tanpa override
        $this->assertSame(['KA26-0001', 'DT-01', 'DRV-01', 3.0, 'Tidak'], [$r['H12'], $r['L12'], $r['N12'], $r['P12'], $r['Q12']]);
        $this->assertSame(210_000.0, $r['V12'], 'BBM 3 rit × 10 L × 7.000');
        $this->assertSame(150_000.0, $r['W12']);
        $this->assertSame(50_000.0, $r['X12']);
        $this->assertSame(120_000.0, $r['Y12'], 'Uang jalan 3 × 40.000');
        $this->assertSame(30_000.0, $r['Z12'], 'Premi 3 × 10.000');
        $this->assertSame('Sudah Ditagih', $r['AC12']);
        // 10 Mar, override: solar 25 L, uang jalan 100rb, premi kosong → standar
        $this->assertSame('Ya', $r['Q13']);
        $this->assertSame(25.0, $r['R13']);
        $this->assertSame(175_000.0, $r['V13'], 'BBM 25 L × 7.000');
        $this->assertSame(100_000.0, $r['Y13']);
        $this->assertSame(20_000.0, $r['Z13']);
        // 20 Mar
        $this->assertSame('Belum Ditagih', $r['AC14']);

        $t = $p['cells']['Tagih Armada'];
        $this->assertSame($invoice->invoice_number, $t['C12']);
        $this->assertSame('KA26-0001', $t['H12']);
        $this->assertSame(7.0, $t['S12']);
        $this->assertSame(2_100_000.0, $t['U12']);
        $this->assertSame('Diajukan', $t['V12']);
        $this->assertSame([10, 3, 2026], [$t['O12'], $t['P12'], $t['Q12']], 'Periode s.d. = log terakhir yang ditagih');

        // Jembatan IMPORTRANGE diganti salinan statis.
        $this->assertStringContainsString('Salinan statis', $p['cells']['Master Unit']['A1']);
        $this->assertSame('DT-01', $p['cells']['Master Unit']['C12']);
        $this->assertSame('B 1234 XY', $p['cells']['Master Unit']['F12'], 'Ritase!M (No Polisi) membaca kolom F');
        $this->assertSame('DRV-01', $p['cells']['Master Karyawan']['C12']);
        $this->assertSame('Karyawan DRV-01', $p['cells']['Master Karyawan']['D12']);
        $this->assertSame('KL-01', $p['cells']['Master Klien']['C12']);
    }

    // ── Alat Berat ──────────────────────────────────────────────────────

    public function test_alat_berat_jam_kerja_dan_biaya_rental(): void
    {
        $exca     = $this->asset(['asset_code' => 'EX-01', 'name' => 'Excavator 01', 'type' => 'excavator']);
        $operator = $this->employee('OPR-01', 'operator');
        $contract = RentalContract::create([
            'company_id' => $this->company->id, 'contract_number' => 'RN26-0001', 'client_id' => $this->client->id,
            'asset_id' => $exca->id, 'tipe_rental' => 'semi', 'tarif_per_jam' => 400_000,
            'gaji_operator_per_hari' => 200_000, 'uang_makan_per_hari' => 60_000, 'premi_per_jam' => 5_000,
            'lokasi_kerja' => 'Proyek B', 'billed_jam' => 0, 'status' => 'aktif',
            'started_at' => '2026-03-01', 'created_by' => $this->user->id,
        ]);
        RentalLog::create([
            'company_id' => $this->company->id, 'rental_contract_id' => $contract->id, 'asset_id' => $exca->id,
            'operator_id' => $operator->id, 'log_date' => '2026-03-03', 'hm_awal' => 100, 'hm_akhir' => 108,
            'jam_kerja' => 7.5, 'created_by' => $this->user->id,
        ]);
        $invoice = app(RentalContractService::class)->tagih($contract->fresh(), Carbon::create(2026, 3, 31));

        $p = $this->payload(app(SopAlatBeratExporter::class));

        $k = $p['cells']['Kontrak Rental'];
        $this->assertSame(['RN26-0001', 'KL-01', 'EX-01', 'Semi', 'Proyek B'], [$k['C12'], $k['H12'], $k['J12'], $k['M12'], $k['L12']]);
        $this->assertSame([7.5, 7.5], [$k['W12'], $k['X12']]);

        $j = $p['cells']['Jam Kerja'];
        $this->assertSame(7.5, $j['R12'], 'Jam kerja MY-TRUCK menimpa rumus HM akhir − HM awal (8)');
        $this->assertSame('OPR-01', $j['N12']);
        $this->assertSame(0.0, $j['W12'], 'Semi: BBM tidak termasuk');
        $this->assertSame(200_000.0, $j['X12']);
        $this->assertSame(60_000.0, $j['Y12']);
        $this->assertSame(37_500.0, $j['Z12'], 'Premi 7,5 jam × 5.000');
        $this->assertSame('Sudah Ditagih', $j['AC12']);

        $t = $p['cells']['Tagih Rental'];
        $this->assertSame($invoice->invoice_number, $t['C12']);
        $this->assertSame(3_000_000.0, $t['U12'], '7,5 jam × 400.000');
    }

    // ── Aset & Workshop ─────────────────────────────────────────────────

    public function test_aset_penyusutan_dari_buku_besar_dan_pemeliharaan(): void
    {
        $truck  = $this->asset();
        $beban  = $this->postableAccount($this->company, '552100');
        $akum   = $this->postableAccount($this->company, '112105');
        $dep = function (string $date, int $amount) use ($truck, $beban, $akum) {
            $entry = $this->makeJournalEntry($this->company, [
                ['account_id' => $beban->id, 'asset_id' => $truck->id, 'debit' => $amount, 'kredit' => 0],
                ['account_id' => $akum->id, 'asset_id' => $truck->id, 'debit' => 0, 'kredit' => $amount],
            ], ['document_type' => 'penyusutan'], Carbon::parse($date));

            return app(JournalService::class)->post($entry);
        };
        $dep('2026-01-31', 6_000_000);
        $dep('2026-02-28', 6_000_000);
        $dep('2026-03-31', 6_000_000);
        $void = $dep('2026-03-31', 999_000);                       // salah input, di-void
        app(JournalService::class)->void($void, 'dobel');

        $vendor = Vendor::create(['company_id' => $this->company->id, 'code' => 'VD-01', 'name' => 'Bengkel Jaya', 'is_active' => true]);
        AssetMaintenanceLog::create([
            'company_id' => $this->company->id, 'asset_id' => $truck->id, 'maintenance_date' => '2026-03-12',
            'type' => 'service_berat', 'description' => 'Overhaul mesin', 'vendor_id' => $vendor->id,
            'cost' => 4_500_000, 'next_service_date' => '2026-06-12', 'created_by' => $this->user->id,
        ]);

        $p = $this->payload(app(SopAsetExporter::class));

        $this->assertSame(18_000_000.0, $p['cells']['Master Unit']['AD12'], 'Akumulasi s.d. 31 Mar, jurnal void tidak dihitung');
        $this->assertSame('Dump Truck', $p['cells']['Master Unit']['E12']);
        $this->assertSame('Garis Lurus (Bulanan)', $p['cells']['Master Unit']['N12']);
        $this->assertSame([10, 1, 2025], [$p['cells']['Master Unit']['I12'], $p['cells']['Master Unit']['J12'], $p['cells']['Master Unit']['K12']]);

        $d = $p['cells']['Penyusutan'];
        $this->assertSame(1, $p['counts']['penyusutan'], 'Hanya bulan Maret');
        $this->assertSame([3, 2026, 'DT-01'], [$d['E12'], $d['F12'], $d['G12']]);
        $this->assertSame(6_000_000.0, $d['P12']);
        $this->assertSame(12_000_000.0, $d['O12'], 'Akumulasi sebelumnya = Jan + Feb');

        $m = $p['cells']['Pemeliharaan'];
        $this->assertSame([12, 3, 2026], [$m['E12'], $m['F12'], $m['G12']]);
        $this->assertSame('DT-01', $m['H12']);
        $this->assertSame('Service Berat / Overhaul', $m['K12']);
        $this->assertSame('Bengkel Jaya', $m['M12']);
        $this->assertSame(4_500_000.0, $m['N12']);
        $this->assertSame([12, 6, 2026], [$m['S12'], $m['T12'], $m['U12']]);
        $this->assertSame('Disetujui', $m['W12']);
    }

    // ── Material ────────────────────────────────────────────────────────

    public function test_material_stok_dan_mac_dari_riwayat_pergerakan(): void
    {
        $material = Material::withoutGlobalScopes()->where('company_id', $this->company->id)->where('jenis', 'jual')->first();
        $purchases = app(MaterialPurchaseService::class);
        $purchases->record(['material_id' => $material->id, 'purchase_date' => '2026-02-10', 'qty' => 10, 'unit_price' => 100_000, 'payment_method' => 'tunai']);
        $march = $purchases->record(['material_id' => $material->id, 'purchase_date' => '2026-03-05', 'qty' => 10, 'unit_price' => 200_000, 'payment_method' => 'tunai']);
        $sale = app(MaterialSaleService::class)->create([
            'material_id' => $material->id, 'client_id' => $this->client->id, 'volume' => 4, 'harga_satuan' => 250_000,
            'sale_date' => '2026-03-10', 'metode' => 'tunai',
            'cash_account_id' => $this->postableAccount($this->company, '111100')->id,
        ]);

        $p = $this->payload(app(SopMaterialExporter::class));

        $row = array_search((string) $material->code, $p['cells']['Master Material'], true);
        $n   = substr($row, 1);
        $mm  = $p['cells']['Master Material'];
        $this->assertSame([10.0, 100_000.0], [$mm["H{$n}"], $mm["I{$n}"]], 'Posisi awal 1 Maret');
        $this->assertSame([16.0, 150_000.0], [$mm["L{$n}"], $mm["M{$n}"]], 'Akhir: 10 + 10 − 4, MAC (10×100rb + 10×200rb)/20');

        $b = $p['cells']['Pembelian Material'];
        $this->assertSame(1, $p['counts']['pembelian']);
        $this->assertSame($march->purchase_number, $b['C12']);
        $this->assertSame([10.0, 100_000.0, 150_000.0], [$b['T12'], $b['U12'], $b['V12']]);

        $s = $p['cells']['Penjualan Material'];
        $this->assertSame($sale->sale_number, $s['C12']);
        $this->assertSame('KL-01', $s['H12']);
        $this->assertSame(1_000_000.0, $s['Q12']);
        $this->assertSame(150_000.0, $s['T12'], 'HPP/satuan = MAC saat jual');
        $this->assertSame(20.0, $s['W12'], 'Stok tersedia sebelum jual');
    }

    // ── Proyek ──────────────────────────────────────────────────────────

    public function test_proyek_dp_progress_dan_termin(): void
    {
        $project = Project::create([
            'company_id' => $this->company->id, 'project_number' => 'PR26-0001', 'name' => 'Urug Lahan A',
            'client_id' => $this->client->id, 'jenis_pekerjaan' => 'Cut & Fill', 'nilai_kontrak' => 100_000_000,
            'progress_pct' => 0, 'tertagih_pct' => 0, 'dp_diterima' => 0, 'status' => 'berjalan',
            'started_at' => '2026-02-01', 'target_end_date' => '2026-06-30', 'created_by' => $this->user->id,
        ]);
        $svc = app(ProjectService::class);
        $kas = $this->postableAccount($this->company, '111100');
        $svc->terimaDP($project, $kas, 5_000_000, Carbon::create(2026, 2, 1));
        $svc->terimaDP($project->fresh(), $kas, 2_000_000, Carbon::create(2026, 3, 3));
        $svc->updateProgress($project->fresh(), 40, 'Galian selesai', Carbon::create(2026, 3, 4));
        $invoice = $svc->tagihTermin($project->fresh(), 30, Carbon::create(2026, 3, 6));

        $p = $this->payload(app(SopProyekExporter::class));

        $pr = $p['cells']['Proyek'];
        $this->assertSame(['PR26-0001', 'KL-01', 'Urug Lahan A', 'Cut & Fill', 'Berjalan'], [$pr['C12'], $pr['H12'], $pr['J12'], $pr['K12'], $pr['Q12']]);
        $this->assertSame(7_000_000.0, $pr['S12'], 'DP s.d. SAMPAI: Feb + Mar');
        $this->assertSame(40.0, $pr['T12']);
        $this->assertSame(30.0, $pr['U12']);
        $this->assertSame(30_000_000.0, $pr['V12']);
        $this->assertSame([30, 6, 2026], [$pr['N12'], $pr['O12'], $pr['P12']]);

        $this->assertSame([40.0, 'PR26-0001'], [$p['cells']['Progress']['J12'], $p['cells']['Progress']['H12']]);

        $dt = $p['cells']['DP & Termin'];
        $this->assertSame(2, $p['counts']['dp & termin'], 'DP Februari di luar periode');
        $this->assertSame(['DP', 2_000_000.0, $kas->name, 'Diajukan'], [$dt['M12'], $dt['O12'], $dt['Q12'], $dt['R12']]);
        $this->assertSame(['Termin', 30.0, 30_000_000.0, $invoice->invoice_number], [$dt['M13'], $dt['N13'], $dt['P13'], $dt['C13']]);
    }

    // ── Umum ────────────────────────────────────────────────────────────

    public function test_setiap_file_punya_template_dan_exporter(): void
    {
        foreach (SopFile::cases() as $file) {
            $this->assertFileExists(resource_path($file->template()), $file->label());
            $this->assertSame($file, $file->exporter()->file());
        }
    }

    public function test_route_file_tidak_dikenal_404(): void
    {
        $this->actingAs($this->user)
            ->get("/export/{$this->company->slug}/sop/tidak-ada.xlsx?dari=2026-03-01&sampai=2026-03-31")
            ->assertNotFound();
    }
}
