<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Strict Tenant Scope
    |--------------------------------------------------------------------------
    | BUG-32: kalau true, model bertrait BelongsToCompany akan THROW saat
    | di-query tanpa Filament tenant (di luar CLI). Bagus untuk catch tenant
    | leak silent di production. Default false biar seeder/artisan lama tidak
    | break. Aktifkan via .env: STRICT_TENANT_SCOPE=true
    */
    'strict_scope' => env('STRICT_TENANT_SCOPE', false),
];
