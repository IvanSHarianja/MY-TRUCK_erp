<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant permission override — row = kebijakan berbeda dari
 * App\Support\RoleMatrix default. Owner tidak boleh punya row di sini
 * (di-enforce di RoleAccessManager).
 */
class CompanyRolePermission extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'role',
        'permission',
        'is_granted',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'role'       => Role::class,
            'permission' => Permission::class,
            'is_granted' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
