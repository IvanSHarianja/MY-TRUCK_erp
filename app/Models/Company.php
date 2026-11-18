<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model implements HasCurrentTenantLabel
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_name',
        'fiscal_year',
        'fiscal_start',
        'fiscal_end',
        'address',
        'phone',
        'email',
        'logo',
        'signature_path',
        'stamp_path',
        'is_active',
        'harga_solar_default',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_start'        => 'date',
            'fiscal_end'          => 'date',
            'is_active'           => 'boolean',
            // Integer cast — cegah bug rupiah 100× lipat pada form ->rupiah().
            'harga_solar_default' => 'integer',
        ];
    }

    /**
     * Gambar tanda tangan / cap sebagai data URI untuk PDF (DomPDF tidak bisa
     * membaca disk privat lewat URL). Null bila tidak ada / file hilang.
     */
    /** File tanda tangan / cap lama dihapus setelah diganti atau dikosongkan. */
    protected static function booted(): void
    {
        static::updated(function (Company $company) {
            foreach (['signature_path', 'stamp_path'] as $field) {
                $old = $company->getOriginal($field);
                if ($company->wasChanged($field) && is_string($old) && str_starts_with($old, 'company-signatures/')) {
                    \Illuminate\Support\Facades\DB::afterCommit(
                        fn () => \Illuminate\Support\Facades\Storage::disk('local')->delete($old)
                    );
                }
            }
        });
    }

    public function imageDataUri(string $field): ?string
    {
        $path = $this->{$field};
        if (! $path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = \Illuminate\Support\Facades\Storage::disk('local')->mimeType($path) ?: 'image/png';
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($path));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function getCurrentTenantLabel(): string
    {
        return $this->name;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
