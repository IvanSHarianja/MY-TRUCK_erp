<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\ArmadaContract;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractChangeRequest;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perubahan kontrak Armada & Rental wajib lewat persetujuan atasan.
 *
 * Alur:
 *   - Pengguna tanpa izin ContractApprove → submit() membuat pengajuan
 *     PENDING; kontrak TIDAK berubah (log baru tetap pakai tarif lama).
 *   - Pengguna dengan izin (default Owner) → perubahan langsung diterapkan,
 *     tetap tercatat sebagai pengajuan "approved" berisi alasannya (audit).
 *   - approve() menolak pengajuan basi: bila nilai kontrak sekarang sudah
 *     berbeda dari "nilai lama" saat diajukan, perubahan tidak ditimpakan.
 *
 * Catatan: perubahan tarif/biaya standar TIDAK berlaku mundur ke log yang
 * sudah ada — hanya log baru/yang diedit setelahnya.
 */
class ContractChangeService
{
    /** Field yang boleh diubah lewat form + label tampilan + jenis nilai. */
    public const FIELDS = [
        ArmadaContract::class => [
            'client_id'           => ['Pelanggan', 'client'],
            'started_at'          => ['Tanggal Mulai', 'date'],
            'status'              => ['Status', 'text'],
            'route_description'   => ['Rute / Uraian', 'text'],
            'tarif_per_rit'       => ['Tarif per Rit', 'money'],
            'tipe_kontrak'        => ['Tipe Kontrak', 'text'],
            'bbm_liter_per_rit'   => ['BBM per Rit (liter)', 'number'],
            'harga_bbm_per_liter' => ['Harga BBM per Liter', 'money'],
            'gaji_supir_per_hari' => ['Gaji Supir per Hari', 'money'],
            'uang_makan_per_hari' => ['Uang Makan per Hari', 'money'],
            'uang_jalan_per_rit'  => ['Uang Jalan per Rit', 'money'],
            'premi_per_rit'       => ['Premi per Rit', 'money'],
            'notes'               => ['Catatan', 'text'],
        ],
        RentalContract::class => [
            'client_id'              => ['Pelanggan', 'client'],
            'asset_id'               => ['Unit', 'asset'],
            'started_at'             => ['Tanggal Mulai', 'date'],
            'status'                 => ['Status', 'text'],
            'lokasi_kerja'           => ['Lokasi Kerja', 'text'],
            'tarif_per_jam'          => ['Tarif per Jam', 'money'],
            'tipe_rental'            => ['Tipe Rental', 'text'],
            'bbm_liter_per_jam'      => ['BBM per Jam (liter)', 'number'],
            'harga_bbm_per_liter'    => ['Harga BBM per Liter', 'money'],
            'gaji_operator_per_hari' => ['Gaji Operator per Hari', 'money'],
            'uang_makan_per_hari'    => ['Uang Makan per Hari', 'money'],
            'premi_per_jam'          => ['Premi per Jam', 'money'],
            'notes'                  => ['Catatan', 'text'],
        ],
    ];

    private const LABELS = [
        'aktif' => 'Aktif', 'selesai' => 'Selesai', 'batal' => 'Batal',
        'all_in' => 'All In', 'semi' => 'Semi', 'alat_saja' => 'Alat Saja',
    ];

    public function canApprove(?User $user, Company $company): bool
    {
        return $user !== null && $user->canIn($company, Permission::ContractApprove);
    }

    public function pendingFor(Model $contract): ?ContractChangeRequest
    {
        return ContractChangeRequest::withoutGlobalScopes()
            ->where('contract_type', $contract->getMorphClass())
            ->where('contract_id', $contract->getKey())
            ->where('status', ContractChangeRequest::PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Selisih data form vs kontrak sekarang, hanya field yang diizinkan.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(Model $contract, array $data): array
    {
        $changes = [];
        foreach ($this->fieldsFor($contract) as $field => [, $kind]) {
            if (! array_key_exists($field, $data)) {
                continue;   // field tersembunyi di form (mis. section BBM) → tidak diubah
            }
            $old = $this->normalize($contract->getAttribute($field), $kind);
            $new = $this->normalize($data[$field], $kind);
            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Ajukan perubahan. Approver → langsung diterapkan & tercatat approved.
     */
    public function submit(Model $contract, array $data, string $reason, User $user): ContractChangeRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Alasan perubahan wajib diisi (minimal 5 karakter).']);
        }

        $changes = $this->diff($contract, $data);
        if ($changes === []) {
            throw ValidationException::withMessages(['reason' => 'Tidak ada perubahan pada kontrak.']);
        }

        if ($this->pendingFor($contract)) {
            throw ValidationException::withMessages([
                'reason' => 'Kontrak ini masih punya pengajuan perubahan yang menunggu persetujuan. Tunggu keputusan atau batalkan pengajuan itu dulu.',
            ]);
        }

        $company = Company::findOrFail($contract->company_id);

        return DB::transaction(function () use ($contract, $changes, $reason, $user, $company) {
            $request = ContractChangeRequest::create([
                'company_id'    => $contract->company_id,
                'contract_type' => $contract->getMorphClass(),
                'contract_id'   => $contract->getKey(),
                'changes'       => $changes,
                'reason'        => $reason,
                'status'        => ContractChangeRequest::PENDING,
                'requested_by'  => $user->id,
            ]);

            $this->log($contract, $user, 'Perubahan kontrak diajukan', $request);

            if ($this->canApprove($user, $company)) {
                $this->approve($request, $user, 'Disimpan langsung oleh pemberi persetujuan.');
            }

            return $request->refresh();
        });
    }

    public function approve(ContractChangeRequest $request, User $user, ?string $note = null): void
    {
        $company = Company::findOrFail($request->company_id);
        if (! $this->canApprove($user, $company)) {
            throw ValidationException::withMessages(['request' => 'Anda tidak berwenang menyetujui perubahan kontrak.']);
        }

        DB::transaction(function () use ($request, $user, $note) {
            $locked = ContractChangeRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => "Pengajuan ini sudah {$locked->statusLabel()}."]);
            }

            $class    = $locked->contract_type;
            $contract = $class::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->contract_id);
            $fields   = $this->fieldsFor($contract);

            // Pengajuan basi: kontrak berubah setelah diajukan → jangan timpa.
            // Kedua sisi dinormalisasi: cast JSON mengembalikan 250000.0 sebagai int 250000.
            $stale = collect($locked->changes)
                ->filter(function ($c, $field) use ($contract, $fields) {
                    $kind = $fields[$field][1] ?? 'text';

                    return $this->normalize($contract->getAttribute($field), $kind) !== $this->normalize($c['old'], $kind);
                })
                ->keys();
            if ($stale->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'request' => 'Kontrak sudah berubah sejak pengajuan ini dibuat (' . $stale->map(fn ($f) => $fields[$f][0] ?? $f)->implode(', ')
                        . '). Tolak pengajuan ini lalu minta diajukan ulang.',
                ]);
            }

            foreach ($locked->changes as $field => $c) {
                $contract->{$field} = $c['new'];
            }
            $contract->save();   // include_* diturunkan dari tipe oleh hook saving model

            $locked->update([
                'status'      => ContractChangeRequest::APPROVED,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->log($contract, $user, 'Perubahan kontrak disetujui', $locked);
        });
    }

    public function reject(ContractChangeRequest $request, User $user, string $note): void
    {
        if (! $this->canApprove($user, Company::findOrFail($request->company_id))) {
            throw ValidationException::withMessages(['request' => 'Anda tidak berwenang menolak perubahan kontrak.']);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi.']);
        }

        $this->close($request, $user, ContractChangeRequest::REJECTED, $note, 'Perubahan kontrak ditolak');
    }

    /** Pengaju membatalkan pengajuannya sendiri. */
    public function cancel(ContractChangeRequest $request, User $user): void
    {
        if ($request->requested_by !== $user->id) {
            throw ValidationException::withMessages(['request' => 'Hanya pengaju yang bisa membatalkan pengajuan ini.']);
        }

        $this->close($request, $user, ContractChangeRequest::CANCELLED, null, 'Pengajuan perubahan kontrak dibatalkan');
    }

    /**
     * Baris "Label: lama → baru" untuk ditampilkan ke approver.
     *
     * @return list<string>
     */
    public function describe(ContractChangeRequest $request): array
    {
        $fields = self::FIELDS[$request->contract_type] ?? [];

        return collect($request->changes)->map(function ($c, $field) use ($fields) {
            [$label, $kind] = $fields[$field] ?? [$field, 'text'];

            return "{$label}: {$this->display($c['old'], $kind)} → {$this->display($c['new'], $kind)}";
        })->values()->all();
    }

    public function contractNumber(ContractChangeRequest $request): string
    {
        $class = $request->contract_type;

        return (string) ($class::withoutGlobalScopes()->whereKey($request->contract_id)->value('contract_number') ?? "#{$request->contract_id}");
    }

    // ────────────────────────────────────────────────────────────────────

    private function close(ContractChangeRequest $request, User $user, string $status, ?string $note, string $logText): void
    {
        DB::transaction(function () use ($request, $user, $status, $note, $logText) {
            $locked = ContractChangeRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => "Pengajuan ini sudah {$locked->statusLabel()}."]);
            }
            $locked->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $note]);

            $class = $locked->contract_type;
            if ($contract = $class::withoutGlobalScopes()->find($locked->contract_id)) {
                $this->log($contract, $user, $logText, $locked);
            }
        });
    }

    private function fieldsFor(Model $contract): array
    {
        return self::FIELDS[$contract->getMorphClass()]
            ?? throw new \InvalidArgumentException('Model ' . $contract::class . ' bukan kontrak yang didukung.');
    }

    /** Samakan bentuk nilai form vs database supaya "250000" == 250000.00. */
    private function normalize(mixed $value, string $kind): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($kind) {
            'money', 'number' => round((float) str_replace(',', '.', (string) $value), 2),
            'client', 'asset' => (int) $value,
            'date'            => Carbon::parse($value)->toDateString(),
            default           => trim((string) $value),
        };
    }

    private function display(mixed $value, string $kind): string
    {
        if ($value === null) {
            return '(kosong)';
        }

        return match ($kind) {
            'money'  => 'Rp ' . number_format((float) $value, 0, ',', '.'),
            'number' => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ','),
            'date'   => Carbon::parse($value)->format('d/m/Y'),
            'client' => (string) (Client::withoutGlobalScopes()->whereKey($value)->value('name') ?? "#{$value}"),
            'asset'  => (string) (Asset::withoutGlobalScopes()->whereKey($value)->value('asset_code') ?? "#{$value}"),
            default  => self::LABELS[$value] ?? mb_strimwidth((string) $value, 0, 80, '…'),
        };
    }

    private function log(Model $contract, User $user, string $text, ContractChangeRequest $request): void
    {
        activity()
            ->causedBy($user)
            ->performedOn($contract)
            ->withProperties([
                'pengajuan' => $request->id,
                'perubahan' => $this->describe($request),
                'alasan'    => $request->reason,
                'catatan'   => $request->review_note,
            ])
            ->log($text);
    }
}
