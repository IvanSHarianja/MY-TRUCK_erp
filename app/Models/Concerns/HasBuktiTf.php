<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Trait untuk model yang punya kolom `bukti_tf_path`.
 * - Auto-hapus file di storage saat model deleted.
 * - Accessor `bukti_tf_url` generate public URL.
 *
 * WAJIB pakai disk 'public' — path relatif tersimpan di kolom,
 * URL di-render via asset('storage/...').
 */
trait HasBuktiTf
{
    protected static function bootHasBuktiTf(): void
    {
        static::deleting(function ($model) {
            if ($model->bukti_tf_path && Storage::disk('public')->exists($model->bukti_tf_path)) {
                Storage::disk('public')->delete($model->bukti_tf_path);
            }
        });
    }

    public function getBuktiTfUrlAttribute(): ?string
    {
        return $this->bukti_tf_path
            ? Storage::disk('public')->url($this->bukti_tf_path)
            : null;
    }
}
