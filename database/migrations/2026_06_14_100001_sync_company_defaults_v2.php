<?php

use App\Models\Company;
use App\Services\CompanyTemplateService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sinkronkan template default (COA + Business Units + Materials)
     * ke SEMUA company yang sudah ada di database.
     *
     * - Akun baru (221170 Uang Muka Proyek, 551600 Beban Subkontraktor) akan ditambahkan.
     * - Default materials akan diisi untuk company yang belum punya.
     * - updateOrCreate aman — tidak duplikat data existing.
     */
    public function up(): void
    {
        $service = app(CompanyTemplateService::class);

        Company::query()->each(function (Company $company) use ($service) {
            $service->seedDefaults($company);
        });
    }

    public function down(): void
    {
        // Tidak perlu rollback — data master tidak boleh di-truncate otomatis
    }
};
