<?php

namespace App\Services\Export;

use App\Enums\DepreciationMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data master yang dipakai lintas file SOP: Master Unit, Master Karyawan,
 * Master Klien, Master Vendor.
 *
 * Tiap master tampil di DUA peran:
 *   - Sheet utama (mis. Master Unit di file Aset) → hanya kolom Input/Pilih
 *     + rumus yang ditimpa angka MY-TRUCK.
 *   - Sheet "jembatan" di file lain → di template berupa
 *     IMPORTRANGE(file lain, "'Master Unit'!A1:AH211") yang hanya hidup di
 *     Google Sheets. Untuk backup offline, IMPORTRANGE diganti SALINAN
 *     STATIS di posisi sel yang sama (layout identik), termasuk nilai kolom
 *     rumus yang dibaca lookup (nama, no polisi, metode, tarif, ...).
 */
class SopMasterData
{
    public const UNIT_LAST_ROW     = 211;
    public const KARYAWAN_LAST_ROW = 311;
    public const KLIEN_LAST_ROW    = 511;
    public const VENDOR_LAST_ROW   = 511;

    private const MIRROR_NOTE = 'Salinan statis dari MY-TRUCK (pengganti IMPORTRANGE) — jangan diedit; perbarui dengan export ulang.';

    /** Kode akun akumulasi bawaan SOP per jenis unit (sama dengan rumus Master Unit!S). */
    private const HEAVY_TYPES = ['dump_truck', 'excavator', 'bulldozer', 'wheel_loader'];

    /** @var array<string, Collection> memo per company+periode */
    private array $memo = [];

    // ── Unit ────────────────────────────────────────────────────────────

    /**
     * Semua aset PT + akumulasi penyusutan dari buku besar s.d. $untilStr
     * (eksklusif). Akumulasi = baris jurnal bertag asset_id di akun
     * akumulasi (aset tetap bersaldo normal kredit), posted & bukan pembalik
     * — konsisten dengan Neraca.
     */
    public function units(int $cid, string $untilStr): Collection
    {
        return $this->memo["units:{$cid}:{$untilStr}"] ??= (function () use ($cid, $untilStr) {
            $akumulasi = DB::table('journal_entry_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('je.company_id', $cid)
                ->where('je.status', 'posted')
                ->where('je.document_type', '!=', 'pembalik')
                ->where('je.entry_date', '<', $untilStr)
                ->where('a.category', 'aset')
                ->where('a.sub_category', 'aset_tetap')
                ->where('a.normal_balance', 'kredit')
                ->whereNotNull('jl.asset_id')
                ->groupBy('jl.asset_id')
                ->selectRaw('jl.asset_id, SUM(jl.kredit) - SUM(jl.debit) as total')
                ->pluck('total', 'jl.asset_id');

            return DB::table('assets as s')
                ->leftJoin('business_units as bu', 'bu.id', '=', 's.default_business_unit_id')
                ->leftJoin('accounts as acc', 'acc.id', '=', 's.account_id')
                ->where('s.company_id', $cid)
                ->orderBy('s.asset_code')
                ->select('s.*', 'bu.code as bu_code', 'acc.code as account_code')
                ->get()
                ->map(function ($a) use ($akumulasi) {
                    $method = DepreciationMethod::tryFrom((string) $a->depreciation_method) ?? DepreciationMethod::StraightLine;
                    $umur   = match ($method) {
                        DepreciationMethod::StraightLine => $a->useful_life_months,
                        DepreciationMethod::PerHour      => $a->useful_life_hours,
                        DepreciationMethod::PerRit       => $a->useful_life_rits,
                        DepreciationMethod::PerDay       => $a->useful_life_days,
                    };
                    $umur = $umur !== null ? (float) $umur : null;

                    $a->jenis       = $this->assetType($a->type);
                    $a->metode      = $method->label();
                    $a->satuan      = match ($method) {
                        DepreciationMethod::StraightLine => 'bulan',
                        DepreciationMethod::PerHour      => 'jam',
                        DepreciationMethod::PerRit       => 'rit',
                        DepreciationMethod::PerDay       => 'hari',
                    };
                    $a->umur        = $umur;
                    $a->tarif       = $umur > 0 ? round(((float) $a->purchase_price - (float) $a->salvage_value) / $umur, 0) : null;
                    $a->akun_akum   = in_array($a->type, self::HEAVY_TYPES, true) ? 112105 : ($a->type === 'kendaraan_operasional' ? 112125 : 112115);
                    $a->akumulasi   = round((float) ($akumulasi[$a->id] ?? 0), 2);
                    $a->status_sop  = match ($a->status) {
                        'maintenance' => 'Maintenance',
                        'non_aktif'   => 'Non-Aktif',
                        default       => 'Aktif',
                    };
                    $a->lini        = in_array($a->bu_code, SopLookups::LINI, true) ? $a->bu_code : null;

                    return $a;
                });
        })();
    }

    /**
     * Sheet "Master Unit". $mirror=false → sheet utama (file Aset);
     * true → salinan statis pengganti IMPORTRANGE.
     *
     * @return array<string, mixed>
     */
    public function unitCells(int $cid, string $untilStr, bool $mirror): array
    {
        $units = $this->units($cid, $untilStr);
        $this->guard('Master Unit', $units->count(), self::UNIT_LAST_ROW);
        $cells = $mirror ? $this->mirrorHeader([
            'C' => 'Kode Unit', 'D' => 'Nama Unit', 'E' => 'Jenis Unit', 'F' => 'No Polisi / No Seri',
            'G' => 'Lini Utama', 'H' => 'Tanggal Perolehan', 'L' => 'Harga Perolehan (Rp)', 'M' => 'Nilai Sisa (Rp)',
            'N' => 'Metode Penyusutan', 'O' => 'Umur Ekonomis', 'P' => 'Satuan Umur', 'Q' => 'Penyusutan per Satuan (Rp)',
            'R' => 'Akun Aset', 'S' => 'Akun Akumulasi', 'T' => 'Target Jam / Bulan', 'AC' => 'Status Unit',
            'AD' => 'Akumulasi Penyusutan (Rp)', 'AE' => 'Nilai Buku (Rp)',
        ]) : [];

        $row = SopWorkbookExporter::FIRST_ROW;
        foreach ($units as $u) {
            $cells["C{$row}"]  = (string) $u->asset_code;      // timpa rumus UNT-NNN
            $cells["D{$row}"]  = $u->name;
            $cells["E{$row}"]  = $u->jenis;
            $cells["F{$row}"]  = $u->plate_number;
            $cells["G{$row}"]  = $u->lini;
            $cells["L{$row}"]  = round((float) $u->purchase_price, 2);
            $cells["M{$row}"]  = round((float) $u->salvage_value, 2);
            $cells["N{$row}"]  = $u->metode;
            $cells["O{$row}"]  = $u->umur;
            $cells["T{$row}"]  = $u->monthly_target_hours !== null ? (float) $u->monthly_target_hours : null;
            $cells["AC{$row}"] = $u->status_sop;
            // Akumulasi dari buku besar: rumus SOP hanya menjumlah sheet
            // Penyusutan di file, padahal penyusutan sebelum periode tidak diekspor.
            $cells["AD{$row}"] = $u->akumulasi;
            if ($u->account_code) {
                $cells["R{$row}"] = SopLookups::code($u->account_code);   // akun aset MY-TRUCK, bukan tebakan per jenis
            }

            if ($mirror) {
                $cells["H{$row}"]  = $u->purchase_date ? Carbon::parse($u->purchase_date) : null;
                $cells["P{$row}"]  = $u->satuan;
                $cells["Q{$row}"]  = $u->tarif;
                $cells["S{$row}"]  = $u->akun_akum;
                $cells["AE{$row}"] = round((float) $u->purchase_price - $u->akumulasi, 2);
            } else {
                $date = $u->purchase_date ? Carbon::parse($u->purchase_date) : null;
                $cells["I{$row}"] = $date?->day;
                $cells["J{$row}"] = $date?->month;
                $cells["K{$row}"] = $date?->year;
                $cells["AH{$row}"] = $u->notes;
            }
            $row++;
        }

        return $cells;
    }

    // ── Karyawan ────────────────────────────────────────────────────────

    public function employees(int $cid): Collection
    {
        return $this->memo["employees:{$cid}"] ??= DB::table('employees as e')
            ->leftJoin('assets as s', 's.id', '=', 'e.assigned_asset_id')
            ->where('e.company_id', $cid)
            ->orderBy('e.employee_id')
            ->select('e.*', 's.asset_code', 's.name as asset_name')
            ->get();
    }

    /** @return array<string, mixed> */
    public function employeeCells(int $cid, bool $mirror): array
    {
        $cells = $mirror ? $this->mirrorHeader([
            'C' => 'Kode Karyawan', 'D' => 'Nama Karyawan', 'E' => 'Posisi', 'F' => 'No HP',
            'G' => 'Unit Ditugaskan', 'H' => 'Nama Unit', 'I' => 'Tanggal Masuk', 'S' => 'Aktif',
        ]) : [];

        $this->guard('Master Karyawan', $this->employees($cid)->count(), self::KARYAWAN_LAST_ROW);

        $row = SopWorkbookExporter::FIRST_ROW;
        foreach ($this->employees($cid) as $e) {
            $cells["C{$row}"] = (string) $e->employee_id;   // timpa rumus KRY-NNN
            $cells["D{$row}"] = $e->name;
            $cells["E{$row}"] = SopLookups::POSISI[$e->position] ?? 'Lainnya';
            $cells["F{$row}"] = $e->phone;
            $cells["G{$row}"] = $e->asset_code;
            $cells["S{$row}"] = $e->is_active ? 'Ya' : 'Tidak';

            $date = $e->join_date ? Carbon::parse($e->join_date) : null;
            if ($mirror) {
                $cells["H{$row}"] = $e->asset_name;
                $cells["I{$row}"] = $date;
            } else {
                $cells["J{$row}"] = $date?->day;
                $cells["K{$row}"] = $date?->month;
                $cells["L{$row}"] = $date?->year;
            }
            $row++;
        }

        return $cells;
    }

    // ── Klien & Vendor (sheet utamanya di file Finance) ─────────────────

    public function clients(int $cid): Collection
    {
        return $this->memo["clients:{$cid}"] ??= DB::table('clients')->where('company_id', $cid)->orderBy('code')->get();
    }

    public function vendors(int $cid): Collection
    {
        return $this->memo["vendors:{$cid}"] ??= DB::table('vendors')->where('company_id', $cid)->orderBy('code')->get();
    }

    /** Salinan statis Master Klien untuk file operasional. @return array<string, mixed> */
    public function clientMirrorCells(int $cid): array
    {
        $cells = $this->mirrorHeader(['C' => 'Kode Klien', 'D' => 'Nama Klien', 'E' => 'Kontak Person',
            'F' => 'Telepon', 'G' => 'Email', 'H' => 'Alamat', 'I' => 'NPWP', 'N' => 'Aktif']);

        $this->guard('Master Klien', $this->clients($cid)->count(), self::KLIEN_LAST_ROW);

        $row = SopWorkbookExporter::FIRST_ROW;
        foreach ($this->clients($cid) as $c) {
            $cells += [
                "C{$row}" => (string) $c->code, "D{$row}" => $c->name, "E{$row}" => $c->contact_person,
                "F{$row}" => $c->phone, "G{$row}" => $c->email, "H{$row}" => $c->address,
                "I{$row}" => $c->npwp, "N{$row}" => $c->is_active ? 'Ya' : 'Tidak',
            ];
            $row++;
        }

        return $cells;
    }

    /** Salinan statis Master Vendor untuk file operasional. @return array<string, mixed> */
    public function vendorMirrorCells(int $cid): array
    {
        $cells = $this->mirrorHeader(['C' => 'Kode Vendor', 'D' => 'Nama Vendor', 'E' => 'Jenis Vendor',
            'F' => 'Kontak Person', 'G' => 'Telepon', 'H' => 'Alamat', 'I' => 'NPWP', 'N' => 'Aktif']);

        $this->guard('Master Vendor', $this->vendors($cid)->count(), self::VENDOR_LAST_ROW);

        $row = SopWorkbookExporter::FIRST_ROW;
        foreach ($this->vendors($cid) as $v) {
            $cells += [
                "C{$row}" => (string) $v->code, "D{$row}" => $v->name,
                "E{$row}" => SopLookups::JENIS_VENDOR[$v->type] ?? 'Lainnya',
                "F{$row}" => $v->contact_person, "G{$row}" => $v->phone, "H{$row}" => $v->address,
                "I{$row}" => $v->npwp, "N{$row}" => $v->is_active ? 'Ya' : 'Tidak',
            ];
            $row++;
        }

        return $cells;
    }

    /**
     * A1 di sheet jembatan berisi IMPORTRANGE — ditimpa catatan, lalu baris
     * judul kolom (baris 8, sama dengan sheet sumber) supaya salinan terbaca.
     *
     * @param  array<string, string>  $labels  kolom → judul
     * @return array<string, mixed>
     */
    public function mirrorHeader(array $labels): array
    {
        $cells = ['A1' => self::MIRROR_NOTE];
        foreach ($labels as $col => $label) {
            $cells["{$col}8"] = $label;
        }

        return $cells;
    }

    private function guard(string $what, int $count, int $lastRow): void
    {
        $max = $lastRow - SopWorkbookExporter::FIRST_ROW + 1;
        if ($count > $max) {
            throw new SopExportException("{$what}: {$count} baris, melebihi kapasitas template SOP ({$max} baris).");
        }
    }

    public function assetType(?string $type): string
    {
        return SopLookups::JENIS_UNIT[$type] ?? 'Lainnya';
    }
}
