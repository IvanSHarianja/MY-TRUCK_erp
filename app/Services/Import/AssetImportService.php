<?php

namespace App\Services\Import;

use App\Enums\AccountRole;
use App\Enums\DepreciationMethod;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BusinessUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import Armada & Alat Berat dari daftar aset milik perusahaan.
 *
 * Kolom dikenali dari JUDUL (urutan bebas) dan satu sheet boleh berisi
 * beberapa tabel dengan judul kolom berbeda — mis. "DAFTAR KENDARAAN"
 * (NOPOL | KETERANGAN | TAHUN | HARGA BELI | NO URUT) lalu "DAFTAR ALAT BERAT"
 * (NAMA ALAT BERAT | TAHUN | KETERANGAN | HARGA).
 *
 * Data yang tidak lengkap TIDAK ditolak — keputusan Ivan 2026-11-17:
 *   - harga beli kosong  → Rp 0 + catatan "Perlu dilengkapi: harga beli"
 *   - hanya tahun        → tanggal beli 1 Juli tahun tsb (ditandai perkiraan)
 *   - umur ekonomis      → garis lurus 96 bulan (kelompok 2 pajak: truk & alat berat)
 *   - "sudah dijual"     → status Non-Aktif
 * Kode atau nopol yang sudah ada → DILEWATI, tidak ditimpa.
 *
 * Alur: preview() untuk layar pratinjau, commit() membaca ulang file yang
 * sama lalu menyimpan — isi pratinjau tidak pernah dikirim balik dari browser.
 */
class AssetImportService
{
    /** Awalan catatan aset yang datanya belum lengkap — dipakai filter di daftar aset. */
    public const INCOMPLETE_MARKER = 'Perlu dilengkapi:';

    public const DEFAULT_LIFE_MONTHS = 96;

    public const TYPES = [
        'dump_truck'            => 'Dump Truck',
        'excavator'             => 'Excavator',
        'bulldozer'             => 'Bulldozer',
        'wheel_loader'          => 'Wheel Loader',
        'kendaraan_operasional' => 'Kendaraan Operasional',
        'peralatan_kantor'      => 'Peralatan Kantor',
        'lainnya'               => 'Lainnya',
    ];

    private const CODE_PREFIX = [
        'dump_truck'            => 'DT',
        'excavator'             => 'EXC',
        'bulldozer'             => 'DZR',
        'wheel_loader'          => 'WL',
        'kendaraan_operasional' => 'KND',
        'peralatan_kantor'      => 'KTR',
        'lainnya'               => 'ALT',
    ];

    private const COLUMNS = [
        'code'  => ['kode', 'kodeaset', 'kodeunit', 'nourut', 'nourutkend', 'nourutkendaraan', 'nounit', 'nolambung', 'nolambungunit'],
        'plate' => ['nopol', 'nopolisi', 'nomorpolisi', 'platnomor', 'noplat', 'nomorkendaraan'],
        'year'  => ['tahun', 'thn', 'tahunbeli', 'tahunperolehan', 'tahunpembuatan', 'tahunpembelian'],
        'date'  => ['tanggalbeli', 'tglbeli', 'tanggalperolehan', 'tglperolehan', 'tanggalpembelian', 'tglpembelian'],
        'price' => ['harga', 'hargabeli', 'hargaperolehan', 'nilaiperolehan', 'hargapembelian', 'nilaibeli'],
        'type'  => ['jenis', 'jenisaset', 'jenisunit', 'tipe', 'tipeunit', 'kategori'],
        'life'  => ['umur', 'umurekonomis', 'masamanfaat', 'umurekonomistahun'],
        'desc'  => ['keterangan', 'ket', 'catatan', 'deskripsi'],
    ];

    private const NAME_ALIASES = ['unit', 'merk', 'merktipe', 'kendaraan', 'alatberat', 'namaunit'];

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   summary: array{total: int, import: int, skip: int, incomplete: int},
     *   account_error: ?string,
     * }
     */
    public function preview(int $companyId, string $path): array
    {
        $rows = $this->plan($companyId, $this->parse($path));

        $toImport = array_filter($rows, fn ($r) => $r['skip'] === null);

        return [
            'rows'    => $rows,
            'summary' => [
                'total'      => count($rows),
                'import'     => count($toImport),
                'skip'       => count($rows) - count($toImport),
                'incomplete' => count(array_filter($toImport, fn ($r) => $r['missing'] !== [])),
            ],
            'account_error' => $this->missingAccountMessage($companyId, $toImport),
        ];
    }

    /** @return array{imported: int, skipped: int, incomplete: int} */
    public function commit(int $companyId, string $path): array
    {
        $preview = $this->preview($companyId, $path);

        if ($preview['account_error']) {
            throw ValidationException::withMessages(['file' => $preview['account_error']]);
        }

        $imported = 0;
        DB::transaction(function () use ($companyId, $preview, &$imported) {
            foreach ($preview['rows'] as $r) {
                if ($r['skip'] !== null) {
                    continue;
                }

                Asset::withoutGlobalScopes()->create([
                    'company_id'               => $companyId,
                    'asset_code'               => $r['code'],
                    'name'                     => $r['name'],
                    'type'                     => $r['type'],
                    'plate_number'             => $r['plate'],
                    'purchase_date'            => $r['purchase_date'],
                    'purchase_price'           => $r['price'] ?? 0,
                    'salvage_value'            => 0,
                    'akumulasi_awal'           => $r['akumulasi_awal'],
                    'depreciation_method'      => DepreciationMethod::StraightLine->value,
                    'useful_life_months'       => $r['life_months'],
                    'account_id'               => $this->assetAccount($companyId, $r['type'])?->id,
                    'default_business_unit_id' => $this->businessUnitId($companyId, $r['bu']),
                    'status'                   => $r['status'],
                    'notes'                    => $r['notes'],
                ]);
                $imported++;
            }
        });

        return [
            'imported'   => $imported,
            'skipped'    => $preview['summary']['skip'],
            'incomplete' => $preview['summary']['incomplete'],
        ];
    }

    /**
     * Hapus isian yang sudah dilengkapi dari catatan "Perlu dilengkapi: …".
     * Dipanggil model Asset saat update.
     */
    public static function resolveIncompleteNote(?string $notes, bool $priceFilled, bool $dateChanged): ?string
    {
        if ($notes === null || ! str_contains($notes, self::INCOMPLETE_MARKER)) {
            return $notes;
        }

        $pattern = '/' . preg_quote(self::INCOMPLETE_MARKER, '/') . ' ([^.]*)\.\s?/u';

        return preg_replace_callback($pattern, function ($m) use ($priceFilled, $dateChanged) {
            $left = array_values(array_filter(
                array_map('trim', explode(',', $m[1])),
                fn ($item) => ! ($item === 'harga beli' && $priceFilled) && ! ($item === 'tanggal beli' && $dateChanged),
            ));

            return $left === [] ? '' : self::INCOMPLETE_MARKER . ' ' . implode(', ', $left) . '. ';
        }, $notes, 1);
    }

    // ------------------------------------------------------------------
    // Membaca file
    // ------------------------------------------------------------------

    /**
     * Baris mentah per aset (belum dicek ke database).
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $ss = $reader->load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca sebagai Excel/CSV.']);
        }

        $out = [];
        $foundHeader = false;

        foreach ($ss->getAllSheets() as $sheet) {
            $raw = $sheet->rangeToArray('A1:' . $sheet->getHighestDataColumn() . $sheet->getHighestDataRow(), null, true, false, false);
            $cols = null;
            $group = null;

            foreach ($raw as $i => $row) {
                if ($header = $this->headerColumns($row)) {
                    $cols  = $header;
                    $group = $this->groupFromTitle($this->titleAbove($raw, $i));
                    $foundHeader = true;
                    continue;
                }
                if ($cols === null || $this->filledCount($row) < 2) {
                    continue;   // sebelum tabel, baris kosong, atau judul tabel berikutnya
                }

                $get  = fn (?int $c) => $c === null ? null : $this->text($row[$c] ?? null);
                $name = $get($cols['name']);
                if ($name === null) {
                    continue;   // baris total ("1,240,000,000") / keterangan tanpa nama
                }

                $out[] = [
                    'source' => $sheet->getTitle() . ' baris ' . ($i + 1),
                    'group'  => $group,
                    'code'   => $get($cols['code'] ?? null),
                    'name'   => $name,
                    'plate'  => $get($cols['plate'] ?? null),
                    'year'   => $row[$cols['year'] ?? -1] ?? null,
                    'date'   => $row[$cols['date'] ?? -1] ?? null,
                    'price'  => $row[$cols['price'] ?? -1] ?? null,
                    'type'   => $get($cols['type'] ?? null),
                    'life'   => $row[$cols['life'] ?? -1] ?? null,
                    'desc'   => $get($cols['desc'] ?? null),
                ];
            }
        }

        if (! $foundHeader) {
            throw ValidationException::withMessages([
                'file' => 'Tabel aset tidak ditemukan. Pastikan ada baris judul kolom dengan NAMA (atau KETERANGAN) dan minimal salah satu dari NOPOL, TAHUN, HARGA, atau KODE.',
            ]);
        }
        if ($out === []) {
            throw ValidationException::withMessages(['file' => 'Tabel aset ditemukan, tetapi tidak berisi data.']);
        }

        return $out;
    }

    /**
     * Kolom tabel aset dari satu baris, atau null kalau baris ini bukan judul kolom.
     * Syarat: ada kolom nama (atau KETERANGAN sebagai nama) + minimal satu kolom aset.
     *
     * @return array<string, int>|null
     */
    private function headerColumns(array $row): ?array
    {
        $cols = [];
        foreach ($row as $idx => $v) {
            $norm = preg_replace('/[^a-z]/', '', mb_strtolower((string) $v));
            if ($norm === '') {
                continue;
            }
            if (! isset($cols['name']) && (str_starts_with($norm, 'nama') || in_array($norm, self::NAME_ALIASES, true))) {
                $cols['name'] = $idx;
                continue;
            }
            foreach (self::COLUMNS as $key => $aliases) {
                if (! isset($cols[$key]) && in_array($norm, $aliases, true)) {
                    $cols[$key] = $idx;
                    break;
                }
            }
        }

        // Daftar kendaraan sering tanpa kolom NAMA: KETERANGAN berisi "Hino Dutro 130 HT Dump".
        if (! isset($cols['name']) && isset($cols['desc'])) {
            $cols['name'] = $cols['desc'];
            unset($cols['desc']);
        }

        $assetCols = array_intersect_key($cols, array_flip(['code', 'plate', 'year', 'date', 'price']));

        return isset($cols['name']) && $assetCols !== [] ? $cols : null;
    }

    // ------------------------------------------------------------------
    // Menafsirkan baris → rencana simpan
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @return list<array<string, mixed>>
     */
    private function plan(int $companyId, array $parsed): array
    {
        $existing = Asset::withoutGlobalScopes()->where('company_id', $companyId)->get(['asset_code', 'plate_number', 'name', 'purchase_date']);
        $codes  = $existing->pluck('asset_code')->map(fn ($c) => $this->key($c))->flip()->all();
        $plates = $existing->pluck('plate_number')->filter()->map(fn ($p) => $this->key($p))->flip()->all();
        // Baris tanpa kode & nopol (umum di daftar alat berat) dikenali dari
        // nama + tanggal beli — tanpa ini import ulang menggandakan unitnya.
        $namesDates = $existing->map(fn ($a) => $this->key($a->name) . '|' . $a->purchase_date?->toDateString())->flip()->all();
        $names      = $existing->map(fn ($a) => $this->key($a->name))->flip()->all();   // baris tanpa tahun

        $today = Carbon::today();
        $rows = [];

        foreach ($parsed as $p) {
            $missing = [];
            $info    = [];
            $haystack = mb_strtolower($p['name'] . ' ' . $p['desc'] . ' ' . (is_string($p['price']) ? $p['price'] : ''));

            // Jenis
            $type = $this->typeFromColumn($p['type']);
            $typeGuessed = $type === null;
            $type ??= $this->guessType($p['name'] . ' ' . $p['desc'], $p['group']);

            // Status: "Sudah dijual" bisa ada di kolom harga / keterangan
            $sold   = (bool) preg_match('/\b(di ?jual|terjual|sudah jual)\b/u', $haystack);
            $status = $sold ? 'non_aktif' : 'aktif';

            // Harga
            $price = $this->money($p['price']);
            if ($price === null && ! $sold) {
                $missing[] = 'harga beli';
            }

            // Tanggal beli
            $date = $this->date($p['date']);
            if ($date === null) {
                $year = $this->year($p['year']);
                if ($year !== null) {
                    $date = Carbon::create($year, 7, 1);
                    $info[] = "Tanggal beli perkiraan (1 Juli {$year}, dari kolom tahun).";
                } else {
                    $date = $today->copy();
                    if (! $sold) {
                        $missing[] = 'tanggal beli';
                    }
                }
            }
            if ($date->gt($today)) {
                $date = $today->copy();
            }

            // Umur ekonomis (tahun; angka > 40 dianggap sudah dalam bulan)
            $life = is_numeric($p['life']) && (float) $p['life'] > 0 ? (float) $p['life'] : null;
            $lifeMonths = $life === null ? self::DEFAULT_LIFE_MONTHS : (int) round($life > 40 ? $life : $life * 12);

            $code  = $p['code'] !== null ? mb_substr($p['code'], 0, 20) : null;
            $plate = $p['plate'] !== null ? mb_substr($p['plate'], 0, 20) : null;

            $nameKey  = $this->key($p['name']);
            $nameDate = $nameKey . '|' . $date->toDateString();
            $undated  = in_array('tanggal beli', $missing, true) || ($sold && $info === [] && $this->date($p['date']) === null);
            // Tanpa kode & nopol: kenali dari nama + tanggal; tanpa tanggal (diisi
            // hari ini) cukup nama — tanggal hari ini berubah tiap kali import.
            $sameUnit = $code === null && $plate === null
                && ($undated ? isset($names[$nameKey]) : isset($namesDates[$nameDate]));

            $skip = match (true) {
                $code !== null && isset($codes[$this->key($code)])    => "Kode {$code} sudah ada",
                $plate !== null && isset($plates[$this->key($plate)]) => "Nopol {$plate} sudah ada",
                $sameUnit                                             => 'Unit bernama sama sudah ada',
                default                                               => null,
            };

            // Aset lama: penyusutan yang sudah terjadi sebelum memakai MY-TRUCK
            // (perkiraan garis lurus s/d akhir bulan lalu). Tanpa ini aset 2015
            // tidak pernah disusutkan tapi nilai bukunya tetap 100%.
            $akumulasiAwal = ($price !== null && ! in_array('tanggal beli', $missing, true))
                ? (new Asset([
                    'purchase_date' => $date, 'purchase_price' => $price, 'salvage_value' => 0,
                    'useful_life_months' => $lifeMonths,
                ]))->estimatedAkumulasiAwal($today)
                : 0;

            $notes = array_filter([
                $p['desc'] !== null ? rtrim($p['desc'], '. ') . '.' : null,
                $sold ? 'Sudah dijual.' : null,
                ...$info,
                $akumulasiAwal > 0 ? 'Akumulasi penyusutan awal Rp ' . number_format($akumulasiAwal, 0, ',', '.') . ' (perkiraan garis lurus).' : null,
                $missing !== [] ? self::INCOMPLETE_MARKER . ' ' . implode(', ', $missing) . '.' : null,
                'Diimpor dari Excel (' . $p['source'] . ').',
            ]);

            $rows[] = [
                'source'         => $p['source'],
                'code'           => $code,
                'code_generated' => false,
                'name'           => mb_substr($p['name'], 0, 255),
                'type'           => $type,
                'type_label'     => self::TYPES[$type],
                'type_guessed'   => $typeGuessed,
                'plate'          => $plate,
                'purchase_date'  => $date->toDateString(),
                'date_estimated' => $info !== [],
                'price'          => $price,
                'life_months'    => $lifeMonths,
                'akumulasi_awal' => $akumulasiAwal,
                'status'         => $status,
                'bu'             => $this->businessUnitCode($type, $p['group']),
                'missing'        => $missing,
                'notes'          => implode(' ', $notes),
                'skip'           => $skip,
            ];

            if ($skip === null) {
                if ($code === null && $plate === null) {
                    $namesDates[$nameDate] = true;
                    $names[$nameKey] = true;
                }
                if ($code !== null) {
                    $codes[$this->key($code)] = true;   // duplikat di dalam file yang sama
                }
                if ($plate !== null) {
                    $plates[$this->key($plate)] = true;
                }
            }
        }

        // Kode otomatis untuk baris tanpa kode — setelah semua kode dari file
        // tercatat, supaya tidak bertabrakan dengan kode baris berikutnya.
        foreach ($rows as &$r) {
            if ($r['code'] === null && $r['skip'] === null) {
                $prefix = self::CODE_PREFIX[$r['type']];
                $n = 1;
                while (isset($codes[$this->key($candidate = sprintf('%s-%03d', $prefix, $n))])) {
                    $n++;
                }
                $codes[$this->key($candidate)] = true;
                $r['code'] = $candidate;
                $r['code_generated'] = true;
            }
        }
        unset($r);

        return $rows;
    }

    private function typeFromColumn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = mb_strtolower($value);
        foreach (self::TYPES as $key => $label) {
            if ($v === $key || $v === mb_strtolower($label)) {
                return $key;
            }
        }

        return null;
    }

    /** Jenis aset dari nama/keterangan; tabel asal (kendaraan / alat berat) jadi penentu terakhir. */
    public function guessType(string $text, ?string $group): string
    {
        $t = mb_strtolower($text);

        return match (true) {
            (bool) preg_match('/dump|tipper/u', $t)                                     => 'dump_truck',
            (bool) preg_match('/excavator|excavat|\bexca\b|backhoe|\bpc ?\d{2,3}\b/u', $t) => 'excavator',
            (bool) preg_match('/dozer|\bd\d{2}\b/u', $t)                                 => 'bulldozer',
            (bool) preg_match('/wheel ?loader|\bwl\b/u', $t)                             => 'wheel_loader',
            (bool) preg_match('/pick ?up|avanza|innova|xenia|hilux|strada|motor|\bmobil\b/u', $t) => 'kendaraan_operasional',
            (bool) preg_match('/komputer|laptop|printer|meja|kursi|\bac\b/u', $t)        => 'peralatan_kantor',
            $group === 'kendaraan'                                                       => 'kendaraan_operasional',
            default                                                                      => 'lainnya',
        };
    }

    /** Lini bisnis default: truk → ARMD, alat berat → RENT (termasuk roller/vibro di tabel alat berat). */
    private function businessUnitCode(string $type, ?string $group): string
    {
        return match (true) {
            $type === 'dump_truck'                                             => 'ARMD',
            in_array($type, ['excavator', 'bulldozer', 'wheel_loader'], true)  => 'RENT',
            $group === 'alat_berat'                                            => 'RENT',
            default                                                            => 'UMUM',
        };
    }

    private function groupFromTitle(string $title): ?string
    {
        $t = mb_strtolower($title);

        return match (true) {
            (bool) preg_match('/alat berat|heavy|equipment/u', $t)        => 'alat_berat',
            (bool) preg_match('/kendaraan|armada|truk|truck|mobil/u', $t) => 'kendaraan',
            default                                                       => null,
        };
    }

    // ------------------------------------------------------------------
    // Akun & lini bisnis
    // ------------------------------------------------------------------

    private function assetAccount(int $companyId, string $type): ?Account
    {
        [$role, $code] = match ($type) {
            'kendaraan_operasional' => [AccountRole::FixedAssetKendaraan, '112120'],
            'peralatan_kantor'      => [AccountRole::FixedAssetKantor, '112110'],
            default                 => [AccountRole::FixedAssetArmada, '112100'],
        };

        // Kendaraan/kantor tanpa akun khusus → akun Armada & Alat Berat.
        return Account::findByRoleOrCode($role, $code, $companyId)
            ?? Account::findByRoleOrCode(AccountRole::FixedAssetArmada, '112100', $companyId);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function missingAccountMessage(int $companyId, array $rows): ?string
    {
        foreach ($rows as $r) {
            if ($this->assetAccount($companyId, $r['type']) === null) {
                return 'Akun Aset Tetap belum ada. Di Daftar Akun, beri Peran "' . AccountRole::FixedAssetArmada->label()
                    . '" pada akun aset tetap armada/alat berat, lalu ulangi import.';
            }
        }

        return null;
    }

    private function businessUnitId(int $companyId, string $code): ?int
    {
        return BusinessUnit::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->value('id');
    }

    // ------------------------------------------------------------------
    // Nilai sel
    // ------------------------------------------------------------------

    /** "100,000,000.00" / "Rp 100.000.000" / 100000000 → 100000000; teks lain → null. */
    public function money(mixed $v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? (int) round($v) : null;
        }
        $s = preg_replace('/[^0-9.,]/', '', (string) $v);
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimalSep = $lastDot > $lastComma ? '.' : ',';
        } else {
            $sep = $lastDot !== false ? '.' : ($lastComma !== false ? ',' : null);
            // Satu pemisah diikuti tepat 3 digit, atau muncul berkali-kali → pemisah ribuan.
            $decimalSep = $sep !== null && substr_count($s, $sep) === 1 && strlen($s) - strrpos($s, $sep) - 1 !== 3 ? $sep : null;
        }
        if ($decimalSep !== null) {
            $s = substr($s, 0, strrpos($s, $decimalSep));
        }
        $n = (int) preg_replace('/\D/', '', $s);

        return $n > 0 ? $n : null;
    }

    private function year(mixed $v): ?int
    {
        $max = (int) date('Y') + 1;
        if (is_numeric($v) && (int) $v >= 1950 && (int) $v <= $max) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/\b(19[5-9]\d|20\d{2})\b/', $v, $m) && (int) $m[1] <= $max) {
            return (int) $m[1];
        }

        return null;
    }

    private function date(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {     // nomor seri tanggal Excel
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->startOfDay();
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!' . $format, trim((string) $v));
                if ($d !== false && $d->format($format) === trim((string) $v)) {
                    return $d;
                }
            } catch (\Throwable) {
                // coba format berikutnya
            }
        }

        return null;
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            $v = (string) (int) $v;
        }
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $v === '' ? null : $v;
    }

    private function filledCount(array $row): int
    {
        return count(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== ''));
    }

    /** Judul tabel = baris terdekat di atas judul kolom yang isinya satu sel saja. */
    private function titleAbove(array $raw, int $headerIdx): string
    {
        for ($i = $headerIdx - 1; $i >= max(0, $headerIdx - 4); $i--) {
            $cells = array_values(array_filter($raw[$i], fn ($v) => $v !== null && trim((string) $v) !== ''));
            if (count($cells) === 1) {
                return (string) $cells[0];
            }
            if (count($cells) > 1) {
                break;
            }
        }

        return '';
    }

    /** Pembanding kode/nopol: "R 1975 FS" = "r1975fs". */
    private function key(string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($v));
    }
}
