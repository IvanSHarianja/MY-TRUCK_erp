<?php

namespace Tests;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\CompanyTemplateService;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Bikin Company baru + seed COA/BU/Material template.
     * Return Company yang siap dipakai sebagai tenant di test.
     */
    protected function createTenant(array $attributes = []): Company
    {
        $company = Company::factory()->create($attributes);
        app(CompanyTemplateService::class)->seedDefaults($company);

        return $company->fresh();
    }

    /**
     * Bikin user + attach ke company via pivot company_user.
     * Role default: 'owner', is_active: true.
     */
    protected function createTenantUser(Company $company, array $attributes = [], string $role = 'owner'): User
    {
        $user = User::factory()->create($attributes);
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);

        return $user;
    }

    /**
     * Login sebagai user + set Filament tenant sehingga BelongsToCompany
     * global scope aktif memfilter query ke company tsb.
     */
    protected function actingAsTenant(User $user, Company $company): static
    {
        $this->actingAs($user);

        // Filament v5.6: setTenant(?Model $tenant, bool $isQuiet = false)
        // Defensif: kalau panel belum boot di beberapa test env, jangan gagalkan test.
        try {
            Filament::setTenant($company, isQuiet: true);
        } catch (\Throwable) {
            // Silent — Service-layer test tidak butuh Filament panel boot.
        }

        return $this;
    }

    /**
     * Cari akun POSTABLE by code untuk company tsb. Throw kalau tidak ada,
     * supaya kesalahan seed / kode salah langsung terlihat di test.
     */
    protected function postableAccount(Company $company, string $code): Account
    {
        $account = Account::findPostableByCode($code, $company->id);

        if (! $account) {
            throw new \RuntimeException(
                "Postable account [{$code}] tidak ditemukan untuk company #{$company->id}. "
                . "Cek CompanyTemplateService::accounts()."
            );
        }

        return $account;
    }

    /**
     * Cari BusinessUnit by code (RENT/ARMD/MATL/BONG/UMUM) untuk company tsb.
     */
    protected function businessUnit(Company $company, string $code): BusinessUnit
    {
        $bu = BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->first();

        if (! $bu) {
            throw new \RuntimeException(
                "BusinessUnit [{$code}] tidak ditemukan untuk company #{$company->id}. "
                . "Cek CompanyTemplateService::businessUnits()."
            );
        }

        return $bu;
    }

    /**
     * Helper: bikin JournalEntry dengan lines via JournalService::createEntryWithLines.
     *
     * $lines format: [['account_id' => X, 'debit' => Y, 'kredit' => Z], ...]
     * Auto isi created_by = auth()->id() atau fallback ke user pertama company.
     * Status default: 'draft'. Untuk posted, panggil ->post() setelah return.
     */
    protected function makeJournalEntry(
        Company $company,
        array $lines,
        array $overrides = [],
        ?CarbonInterface $date = null,
    ): JournalEntry {
        $date ??= Carbon::today();

        $createdBy = auth()->id() ?? $this->createTenantUser($company)->id;

        return app(JournalService::class)->createEntryWithLines(
            $company,
            $date,
            entryDataFactory: fn (string $entryNumber) => array_merge([
                'company_id'    => $company->id,
                'entry_number'  => $entryNumber,
                'entry_date'    => $date,
                'document_type' => 'manual',
                'description'   => 'Test entry',
                'period_year'   => $date->year,
                'period_month'  => $date->month,
                'status'        => 'draft',
                'created_by'    => $createdBy,
                'total_amount'  => array_sum(array_column($lines, 'debit')),
            ], $overrides),
            linesFactory: fn () => $lines,
        );
    }

    /**
     * Bikin Client sederhana untuk test invoice/payment.
     */
    protected function createClient(Company $company, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'company_id'     => $company->id,
            'code'           => 'CLT-' . strtoupper(Str::random(4)),
            'name'           => 'Test Client ' . Str::random(3),
            'contact_person' => 'Contact ' . Str::random(3),
            'email'          => 'client-' . Str::random(6) . '@test.local',
            'is_active'      => true,
        ], $attributes));
    }

    /**
     * Bikin Invoice status 'draft' siap di-issue.
     * Auto-fill client, business_unit, tanggal, amount default.
     */
    protected function makeDraftInvoice(Company $company, array $attributes = []): Invoice
    {
        $client  = $attributes['client_id'] ?? $this->createClient($company)->id;
        $buCode  = $attributes['business_unit_code'] ?? 'RENT';
        $bu      = $this->businessUnit($company, $buCode);
        $creator = auth()->id() ?? $this->createTenantUser($company)->id;

        unset($attributes['business_unit_code']);

        return Invoice::create(array_merge([
            'company_id'       => $company->id,
            'invoice_number'   => 'DRAFT-' . Str::upper(Str::random(8)),
            'invoice_date'     => Carbon::today()->toDateString(),
            'due_date'         => Carbon::today()->addDays(30)->toDateString(),
            'client_id'        => $client,
            'business_unit_id' => $bu->id,
            'amount'           => 1_000_000,
            'paid_amount'      => 0,
            'status'           => 'draft',
            'created_by'       => $creator,
            'description'      => 'Test invoice',
        ], $attributes));
    }

    protected function tearDown(): void
    {
        // Reset Filament tenant supaya tidak bocor ke test berikutnya.
        try {
            Filament::setTenant(null, isQuiet: true);
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }
}
