<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Enums\AccountRole;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    /**
     * Pre-fill form jika ada query string ?parent_code=XXX
     * (dari Action "+ Sub-Akun" di list).
     */
    protected function fillForm(): void
    {
        $parentCode = request()->query('parent_code');
        $tenant     = Filament::getTenant();

        if ($parentCode && $tenant) {
            $parent = Account::withoutGlobalScopes()
                ->where('company_id', $tenant->getKey())
                ->where('code', $parentCode)
                ->first();

            if ($parent) {
                // Inherit role dari parent — sub-akun biasanya berbagi role
                // dengan parent (mis. "Kas & Bank" role='cash', child "Bank Mandiri"
                // & "Bank BCA" juga role='cash').
                //
                // Fallback: kalau parent tidak punya role (mis. akun HEADER lama
                // yang belum di-set role), coba suggest dari nama parent.
                // Header "Kas & Bank" → suggest AccountRole::Cash.
                $parentRole = $parent->role instanceof AccountRole
                    ? $parent->role->value
                    : $parent->role;

                if (! $parentRole) {
                    $suggested = AccountRole::suggestFromName($parent->name);
                    $parentRole = $suggested?->value;
                }

                $this->form->fill([
                    'parent_code'        => $parent->code,
                    'code'               => Account::suggestChildCode($parent->code, $tenant->getKey()),
                    'category'           => $parent->category,
                    'sub_category'       => $parent->sub_category,
                    'normal_balance'     => $parent->normal_balance,
                    'cash_flow_category' => $parent->cash_flow_category,
                    'role'               => $parentRole,
                    'tax_type'           => $parent->tax_type ?? 'non_pajak',
                    'is_active'          => true,
                ]);
                return;
            }
        }

        parent::fillForm();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
