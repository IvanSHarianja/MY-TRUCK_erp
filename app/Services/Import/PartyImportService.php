<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Import Klien / Vendor dari Excel milik klien — kolom dikenali dari JUDUL,
 * jadi format berbeda-beda tetap bisa dipakai:
 *
 *   - "Kode Bantu" Armada : KODE | KETERANGAN (=nama) | JENIS (PIUTANG/HUTANG)
 *   - Daftar supplier     : NO | NAMA | ALAMAT | NO.TELP | KETERANGAN
 *
 * Aturan:
 *   - Ada kolom JENIS → Klien hanya baris PIUTANG, Vendor hanya HUTANG;
 *     baris lain (karyawan, biaya dibayar dimuka, …) dilewati.
 *   - Tidak ada kolom KODE → kode dibuat otomatis (KL-001 / VD-001, lanjut
 *     dari yang sudah ada).
 *   - Kode atau nama yang sudah ada di PT → DILEWATI, tidak ditimpa.
 *     Import aman diulang.
 */
class PartyImportService
{
    private const COLUMNS = [
        'code'    => ['kode', 'kodebantu', 'kodeklien', 'kodevendor', 'kodesupplier', 'kodecustomer', 'kodepelanggan'],
        'name'    => ['nama', 'namaklien', 'namavendor', 'namasupplier', 'namacustomer', 'namapelanggan', 'namakonsumen', 'namaperusahaan'],
        'desc'    => ['keterangan', 'ket', 'deskripsi', 'catatan'],
        'jenis'   => ['jenis', 'tipe', 'type'],
        'address' => ['alamat'],
        'phone'   => ['notelp', 'telp', 'telepon', 'notelepon', 'nohp', 'hp', 'nowa', 'wa', 'kontaktelepon'],
        'email'   => ['email', 'surel'],
        'contact' => ['kontak', 'kontakperson', 'contactperson', 'pic', 'cp'],
        'npwp'    => ['npwp'],
    ];

    public const VENDOR_TYPES = [
        'bbm'       => 'BBM',
        'sparepart' => 'Sparepart',
        'kuari'     => 'Kuari / Material',
        'leasing'   => 'Leasing',
        'jasa'      => 'Jasa / Bengkel',
        'lainnya'   => 'Lainnya',
    ];

    /** Kata badan usaha yang diabaikan saat mencari nama MIRIP ("PT Maju" ≈ "CV Maju"). */
    private const ENTITY_WORDS = '/\b(pt|cv|ud|pd|tb|toko|firma|fa|koperasi|kop|tbk|persero)\b/u';

    /**
     * Rencana import untuk layar pratinjau — tidak menyimpan apa pun.
     *
     * Per baris: kode final (termasuk kode otomatis), alasan dilewati (sudah
     * ada), dan peringatan nama MIRIP dengan data lain — mis. "PT. Maju Jaya"
     * vs "CV Maju Jaya". Nama yang hanya beda tanda baca/spasi/huruf besar
     * dianggap SAMA dan dilewati.
     *
     * @param  'client'|'vendor'  $kind
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   summary: array{total: int, import: int, skip: int, similar: int, other_type: int, invalid: int},
     *   sheet: string,
     * }
     */
    public function preview(string $kind, int $companyId, string $path): array
    {
        $parsed = $this->parse($kind, $path, $sheet, $skippedType, $skippedInvalid);

        $model    = $kind === 'client' ? Client::class : Vendor::class;
        $prefix   = $kind === 'client' ? 'KL-' : 'VD-';
        $existing = $model::withoutGlobalScopes()->where('company_id', $companyId)->get(['code', 'name']);
        $codes    = $existing->pluck('code')->map(fn ($c) => mb_strtolower($c))->flip()->all();
        $names    = $existing->mapWithKeys(fn ($e) => [$this->nameKey($e->name) => $e->name])->all();
        $similar  = $existing->mapWithKeys(fn ($e) => [$this->similarKey($e->name) => $e->name])->all();
        $nextNo   = $this->nextNumber($existing->pluck('code'), $prefix);

        $rows = [];
        foreach ($parsed as $r) {
            $nameKey = $this->nameKey($r['name']);
            $skip = match (true) {
                $r['code'] !== null && isset($codes[mb_strtolower($r['code'])]) => "Kode {$r['code']} sudah ada",
                isset($names[$nameKey])                                           => "Nama sudah ada ({$names[$nameKey]})",
                default                                                           => null,
            };

            $simKey  = $this->similarKey($r['name']);
            $warning = $skip === null && $simKey !== '' && isset($similar[$simKey])
                ? "Mirip dengan \"{$similar[$simKey]}\" — pastikan bukan pihak yang sama"
                : null;

            $code = $r['code'];
            $generated = false;
            if ($skip === null) {
                while ($code === null || isset($codes[mb_strtolower($code)])) {
                    $code = $prefix . str_pad((string) $nextNo++, 3, '0', STR_PAD_LEFT);
                    $generated = true;
                }
                $codes[mb_strtolower($code)] = true;
                $names[$nameKey] = $r['name'];
                $similar[$simKey] ??= $r['name'];
            }

            $rows[] = $r + [
                'final_code'     => $code,
                'code_generated' => $generated,
                'vendor_type'    => $kind === 'vendor' ? $this->vendorType($r['desc'] . ' ' . $r['name']) : null,
                'skip'           => $skip,
                'warning'        => $warning,
            ];
        }

        $toImport = array_filter($rows, fn ($r) => $r['skip'] === null);

        return [
            'rows'    => $rows,
            'summary' => [
                'total'      => count($rows),
                'import'     => count($toImport),
                'skip'       => count($rows) - count($toImport),
                'similar'    => count(array_filter($toImport, fn ($r) => $r['warning'] !== null)),
                'other_type' => $skippedType,
                'invalid'    => $skippedInvalid,
            ],
            'sheet' => $sheet,
        ];
    }

    /**
     * Simpan hasil pratinjau. File dibaca ulang — isi pratinjau di browser
     * tidak pernah dipakai sebagai sumber data.
     *
     * @param  'client'|'vendor'  $kind
     * @return array{imported: int, skipped_existing: int, skipped_other_type: int, skipped_invalid: int, sheet: string}
     */
    public function import(string $kind, int $companyId, string $path): array
    {
        $preview = $this->preview($kind, $companyId, $path);
        $model   = $kind === 'client' ? Client::class : Vendor::class;

        $imported = 0;
        DB::transaction(function () use ($preview, $model, $companyId, $kind, &$imported) {
            foreach ($preview['rows'] as $r) {
                if ($r['skip'] !== null) {
                    continue;
                }

                $data = [
                    'company_id'     => $companyId,
                    'code'           => $r['final_code'],
                    'name'           => $r['name'],
                    'contact_person' => $r['contact'],
                    'phone'          => $r['phone'],
                    'email'          => $r['email'],
                    'address'        => $r['address'],
                    'npwp'           => $r['npwp'],
                    'is_active'      => true,
                ];
                if ($kind === 'vendor') {
                    $data['type'] = $r['vendor_type'];
                }
                $model::withoutGlobalScopes()->create($data);
                $imported++;
            }
        });

        return [
            'imported'           => $imported,
            'skipped_existing'   => $preview['summary']['skip'],
            'skipped_other_type' => $preview['summary']['other_type'],
            'skipped_invalid'    => $preview['summary']['invalid'],
            'sheet'              => $preview['sheet'],
        ];
    }

    /**
     * @return list<array{code: ?string, name: string, desc: string, address: ?string, phone: ?string, email: ?string, contact: ?string, npwp: ?string}>
     */
    public function parse(string $kind, string $path, ?string &$sheetName = null, ?int &$skippedType = 0, ?int &$skippedInvalid = 0): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $ss = $reader->load($path);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca sebagai Excel/CSV.']);
        }

        $found = $this->detect($ss)
            ?? throw ValidationException::withMessages([
                'file' => 'Tabel tidak ditemukan. Pastikan ada baris judul dengan kolom NAMA (atau KODE + KETERANGAN), mis. "Kode | Nama | Alamat | No Telp".',
            ]);

        [$sheet, $headerRow, $cols] = $found;
        $sheetName = $sheet->getTitle();
        $nameCol   = $cols['name'] ?? $cols['desc'];      // format Kode Bantu: KETERANGAN = nama
        $descCol   = isset($cols['name']) ? ($cols['desc'] ?? null) : null;
        // Kata UTUH: "piutang" mengandung "utang" — substring akan memasukkan
        // klien (PIUTANG) ke daftar vendor.
        $wanted    = $kind === 'client'
            ? '/\b(piutang|customer|klien|pelanggan|konsumen)\b/u'
            : '/\b(hutang|utang|supplier|suplier|vendor|pemasok)\b/u';

        $lastCol = $sheet->getHighestDataColumn();
        $raw = $sheet->rangeToArray('A1:' . $lastCol . $sheet->getHighestDataRow(), null, true, false, false);
        $get = fn (array $row, ?int $col) => $col === null ? null : $this->text($row[$col] ?? null);

        // Satu sheet bisa berisi beberapa tabel ("DAFTAR SUPLIER" lalu "DAFTAR
        // KONSUMEN", masing-masing dengan baris judul kolom sendiri). Pecah per
        // baris judul kolom; tiap tabel diklasifikasi dari judul di atasnya.
        $headerSig = $this->signature($raw[$headerRow - 1]);
        $other     = $kind === 'client'
            ? '/\b(hutang|utang|supplier|suplier|vendor|pemasok)\b/u'
            : '/\b(piutang|customer|klien|pelanggan|konsumen)\b/u';
        $skipBlock = false;

        $rows = [];
        $skippedType = 0;
        $skippedInvalid = 0;
        foreach ($raw as $i => $row) {
            if ($i + 1 < $headerRow) {
                continue;
            }
            if ($this->signature($row) === $headerSig) {
                $title     = mb_strtolower($this->titleAbove($raw, $i));
                $skipBlock = $title !== '' && preg_match($other, $title) && ! preg_match($wanted, $title);
                continue;
            }

            $name = $get($row, $nameCol);
            if ($name === null) {
                continue;               // baris kosong / judul bagian tanpa nama
            }
            if ($skipBlock) {
                $skippedType++;         // tabel untuk jenis lain (mis. KONSUMEN saat import vendor)
                continue;
            }
            if (isset($cols['jenis'])) {
                $jenis = mb_strtolower((string) $get($row, $cols['jenis']));
                if ($jenis === '' && $get($row, $cols['code'] ?? null) === null) {
                    continue;           // judul bagian ("PIUTANG USAHA MATERIAL"), bukan data
                }
                if (! preg_match($wanted, $jenis)) {
                    $skippedType++;     // HUTANG saat import klien, karyawan, biaya dibayar dimuka, …
                    continue;
                }
            }

            $code = $get($row, $cols['code'] ?? null);
            if ($code !== null && mb_strlen($code) > 20) {
                $skippedInvalid++;
                continue;
            }

            $rows[] = [
                'code'    => $code,
                'name'    => mb_substr($name, 0, 255),
                'desc'    => (string) $get($row, $descCol),
                'address' => ($a = $get($row, $cols['address'] ?? null)) !== null ? mb_substr($a, 0, 255) : null,
                'phone'   => ($p = $get($row, $cols['phone'] ?? null)) !== null ? mb_substr($p, 0, 30) : null,
                'email'   => $get($row, $cols['email'] ?? null),
                'contact' => $get($row, $cols['contact'] ?? null),
                'npwp'    => ($n = $get($row, $cols['npwp'] ?? null)) !== null ? mb_substr($n, 0, 30) : null,
            ];
        }

        if ($rows === [] && $skippedType === 0) {
            throw ValidationException::withMessages(['file' => "Tabel di sheet '{$sheetName}' tidak berisi data."]);
        }

        return $rows;
    }

    /** @return array{0: \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet, 1: int, 2: array<string,int>}|null */
    private function detect(Spreadsheet $ss): ?array
    {
        foreach ($ss->getAllSheets() as $sheet) {
            $maxRow = min(15, $sheet->getHighestDataRow());
            for ($r = 1; $r <= $maxRow; $r++) {
                $cells = $sheet->rangeToArray("A{$r}:" . $sheet->getHighestDataColumn() . $r, null, true, false, false)[0];
                $norms = array_map(fn ($v) => preg_replace('/[^a-z]/', '', mb_strtolower((string) $v)), $cells);

                if (in_array('namaakun', $norms, true)) {
                    continue 2;         // ini tabel COA, bukan daftar klien/vendor
                }

                $cols = [];
                foreach ($norms as $idx => $norm) {
                    foreach (self::COLUMNS as $key => $aliases) {
                        if ($norm !== '' && ! isset($cols[$key]) && in_array($norm, $aliases, true)) {
                            $cols[$key] = $idx;
                            break;
                        }
                    }
                }

                // Tabel klien/vendor: ada NAMA, atau KODE + KETERANGAN (format Kode Bantu).
                if (isset($cols['name']) || (isset($cols['code']) && isset($cols['desc']))) {
                    return [$sheet, $r, $cols];
                }
            }
        }

        return null;
    }

    /** Tanda tangan baris judul kolom (untuk mengenali tabel kedua di sheet yang sama). */
    private function signature(array $row): string
    {
        return implode('|', array_map(fn ($v) => preg_replace('/[^a-z]/', '', mb_strtolower((string) $v)), $row));
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
                break;      // sudah masuk baris data tabel sebelumnya
            }
        }

        return '';
    }

    /** Nilai sel → teks rapi; nomor telepon 6281... dari Excel bisa float. */
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

    /** Nama yang dianggap SAMA: beda huruf besar, spasi, atau tanda baca ("PT. Maju" = "pt maju"). */
    private function nameKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($name));
    }

    /** Nama yang MIRIP: sama setelah kata badan usaha dibuang ("PT Maju Jaya" ≈ "CV Maju Jaya"). */
    private function similarKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', preg_replace(self::ENTITY_WORDS, ' ', mb_strtolower($name)));
    }

    private function nextNumber(\Illuminate\Support\Collection $codes, string $prefix): int
    {
        $max = $codes
            ->filter(fn ($c) => str_starts_with($c, $prefix) && ctype_digit(substr($c, strlen($prefix))))
            ->map(fn ($c) => (int) substr($c, strlen($prefix)))
            ->max();

        return ($max ?? 0) + 1;
    }

    /** Jenis vendor dari keterangan/nama (Ban, Sparepart → sparepart; Bengkel/Las/Bubut → jasa; …). */
    private function vendorType(string $text): string
    {
        $t = mb_strtolower($text);

        return match (true) {
            (bool) preg_match('/solar|bbm|spbu|pertamina/', $t)              => 'bbm',
            (bool) preg_match('/sparepart|spare part|suku cadang|\bban\b|oli/', $t) => 'sparepart',
            (bool) preg_match('/kuari|quarry|pasir|batu|material|tanah/', $t) => 'kuari',
            (bool) preg_match('/leasing|finance|pembiayaan/', $t)           => 'leasing',
            (bool) preg_match('/bengkel|las|bubut|jasa|service|servis|kontraktor/', $t) => 'jasa',
            default                                                          => 'lainnya',
        };
    }
}
