<?php

namespace App\Services\Import;

use App\Enums\AccountRole;
use App\Models\Account;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Parser COA "berlabel" — format Excel akuntansi yang lazim dipakai klien
 * (contoh: file COA PT Armada), dikenali dari JUDUL kolom, bukan posisi:
 *
 *   KODE | NAMA AKUN | TYPE (Header/Child) | Kategori | Sub-Kategori |
 *   Saldo normal | Arus kas | Peran
 *
 * Judul boleh di baris mana pun (file klien biasanya punya judul laporan di
 * atasnya) dan di sheet mana pun ("COA" diutamakan). Hanya KODE, NAMA AKUN,
 * TYPE yang wajib ada sebagai kolom.
 *
 * Aturan:
 *   - Baris tanpa TYPE = slot cadangan ("112004 Bank", "Nama Kas") → dilewati.
 *   - Kode induk tidak ada di file → ditebak dari hierarki kode: induk = baris
 *     Header terdekat yang kodenya (tanpa nol di belakang) menjadi awalan kode
 *     ini. 111001 → 111000 (KAS) → 110000 (ASET LANCAR).
 *   - Kategori kosong → dari Sub-Kategori ("Akumulasi depresiasi" → aset),
 *     lalu dari induk, lalu dari anak-anaknya, lalu dari digit pertama kode.
 *   - Peran: cocok persis dengan label peran MY-TRUCK, kalau tidak → tebak dari
 *     nama akun (AccountRole::suggestFromName) asal sekategori. Hanya untuk akun
 *     detail (yang tidak punya anak) — peran di akun header tidak dipakai.
 */
class LabeledCoaParser
{
    private const CODE_REGEX = '/^[A-Za-z0-9\-]+$/';

    /** Kolom yang dikenali: kunci internal → judul (sudah dinormalisasi). */
    private const COLUMNS = [
        'code'     => ['kode', 'kodeakun', 'noakun', 'nomorakun'],
        'name'     => ['namaakun', 'nama'],
        'type'     => ['type', 'tipe', 'jenis'],
        'category' => ['kategori'],
        'sub'      => ['subkategori'],
        'normal'   => ['saldonormal', 'posisi', 'sn'],
        'cashflow' => ['aruskas'],
        'role'     => ['peran', 'role'],
    ];

    /** Laporan proses import terakhir (untuk notifikasi ke pengguna). */
    public array $report = [];

    /**
     * Cari tabel COA berlabel di workbook. Null = bukan format ini.
     *
     * @return array{sheet: string, header_row: int, cols: array<string,int>}|null
     */
    public function detect(Spreadsheet $ss): ?array
    {
        $sheets = $ss->getAllSheets();
        usort($sheets, fn ($a, $b) => (strcasecmp($b->getTitle(), 'COA') === 0) <=> (strcasecmp($a->getTitle(), 'COA') === 0));

        foreach ($sheets as $sheet) {
            $maxRow = min(15, $sheet->getHighestDataRow());
            for ($r = 1; $r <= $maxRow; $r++) {
                $cols = $this->matchHeader($sheet->rangeToArray("A{$r}:" . $sheet->getHighestDataColumn() . $r, null, true, false, false)[0]);
                if (isset($cols['code'], $cols['name'], $cols['type'])) {
                    return ['sheet' => $sheet->getTitle(), 'header_row' => $r, 'cols' => $cols];
                }
            }
        }

        return null;
    }

    /**
     * @return array{rows: list<array>, errors: list<string>}
     */
    public function parse(Spreadsheet $ss, array $found): array
    {
        $sheet  = $ss->getSheetByName($found['sheet']);
        $cols   = $found['cols'];
        $raw    = $sheet->rangeToArray('A' . ($found['header_row'] + 1) . ':' . $sheet->getHighestDataColumn() . $sheet->getHighestDataRow(), null, true, false, false);
        $cell   = fn (array $row, string $key) => isset($cols[$key]) ? trim((string) ($row[$cols[$key]] ?? '')) : '';

        $errors = [];
        $rows   = [];
        $seen   = [];
        $skipped = 0;

        foreach ($raw as $i => $row) {
            $rowNum = $found['header_row'] + 1 + $i;
            $code   = $this->codeString($row[$cols['code']] ?? null);
            if ($code === '') {
                continue;
            }

            $type = mb_strtolower($cell($row, 'type'));
            if ($type === '') {
                $skipped++;          // slot cadangan tanpa TYPE
                continue;
            }

            $name = $cell($row, 'name');
            if ($name === '') {
                $errors[] = "Baris {$rowNum}: Akun '{$code}' tidak punya Nama Akun.";
                continue;
            }
            if (mb_strlen($code) > Account::CODE_MAX_LENGTH || ! preg_match(self::CODE_REGEX, $code)) {
                $errors[] = "Baris {$rowNum}: Kode '{$code}' tidak valid (huruf/angka/'-', maks " . Account::CODE_MAX_LENGTH . ' karakter).';
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Baris {$rowNum}: Kode '{$code}' duplikat (sudah ada di baris {$seen[$code]}).";
                continue;
            }
            $seen[$code] = $rowNum;

            $sub = $cell($row, 'sub');
            $rows[] = [
                'code'      => $code,
                'name'      => mb_substr($name, 0, 255),
                'is_header' => str_starts_with($type, 'head') || $type === 'induk',
                'category'  => $this->mapCategory($cell($row, 'category')) ?? $this->categoryFromSub($sub),
                'sub_raw'   => mb_strtolower($sub),
                'normal'    => $this->mapNormal($cell($row, 'normal')),
                'cashflow'  => $this->mapCashflow($cell($row, 'cashflow')),
                'peran'     => $cell($row, 'role'),
                '_row'      => $rowNum,
            ];
        }

        $this->assignParents($rows);
        $this->inferCategories($rows, $errors);

        $parents = array_flip(array_filter(array_column($rows, 'parent_code')));
        $roles   = 0;
        $guessed = 0;
        $out     = [];

        foreach ($rows as $r) {
            if ($r['category'] === null) {
                continue;   // error sudah dicatat
            }
            $sub    = $this->mapSub($r['category'], $r['sub_raw'], $r['name']);
            $normal = $r['normal'] ?? $this->defaultNormal($r['category'], $r['sub_raw'] . ' ' . mb_strtolower($r['name']));

            $postable = ! isset($parents[$r['code']]);
            [$role, $isGuess] = $postable ? $this->resolveRole($r['peran'], $r['name'], $r['category'], $sub, $normal) : [null, false];
            $roles   += $role ? 1 : 0;
            $guessed += $isGuess ? 1 : 0;

            $out[] = [
                'code'               => $r['code'],
                'name'               => $r['name'],
                'category'           => $r['category'],
                'parent_code'        => $r['parent_code'],
                'sub_category'       => $sub,
                'normal_balance'     => $normal,
                'cash_flow_category' => $r['cashflow'],
                'role'               => $role?->value,
                'role_guessed'       => $isGuess,
            ];
        }

        $this->report = [
            'format'        => "berlabel (sheet '{$found['sheet']}')",
            'imported'      => count($out),
            'skipped_slots' => $skipped,
            'roles'         => $roles,
            'roles_guessed' => $guessed,
        ];

        return ['rows' => $out, 'errors' => $errors];
    }

    // ────────────────────────────────────────────────────────────────────

    /** @return array<string, int> kunci → indeks kolom */
    private function matchHeader(array $cells): array
    {
        $found = [];
        foreach ($cells as $idx => $value) {
            $norm = $this->normHeader((string) $value);
            if ($norm === '') {
                continue;
            }
            foreach (self::COLUMNS as $key => $aliases) {
                if (! isset($found[$key]) && in_array($norm, $aliases, true)) {
                    $found[$key] = $idx;
                    break;
                }
            }
        }

        return $found;
    }

    private function normHeader(string $s): string
    {
        $s = preg_replace('/[^a-z]/', '', mb_strtolower($s));

        return str_replace('katagori', 'kategori', $s);   // ejaan di file klien
    }

    /** Kode angka dari Excel bisa datang sebagai float 111001.0. */
    private function codeString(mixed $v): string
    {
        if (is_float($v) && floor($v) === $v) {
            return (string) (int) $v;
        }

        return trim((string) $v);
    }

    private function mapCategory(string $s): ?string
    {
        $s = mb_strtolower(trim($s));

        return match (true) {
            $s === ''                                                                  => null,
            str_starts_with($s, 'aset') || str_starts_with($s, 'aktiva') || str_starts_with($s, 'harta') => 'aset',
            str_starts_with($s, 'hutang') || str_starts_with($s, 'utang') || str_starts_with($s, 'kewajiban') => 'kewajiban',
            str_starts_with($s, 'modal') || str_starts_with($s, 'ekuitas')             => 'ekuitas',
            str_starts_with($s, 'pendapatan') || str_starts_with($s, 'penjualan')      => 'pendapatan',
            str_starts_with($s, 'biaya') || str_starts_with($s, 'beban')               => 'beban',
            default                                                                    => null,
        };
    }

    private function categoryFromSub(string $sub): ?string
    {
        $sub = mb_strtolower($sub);

        return match (true) {
            str_contains($sub, 'akumulasi') || str_contains($sub, 'aktiva') || str_contains($sub, 'aset') => 'aset',
            default => $this->mapCategory($sub),
        };
    }

    private function mapSub(string $category, string $sub, string $name): string
    {
        $text = $sub . ' ' . mb_strtolower($name);

        return match ($category) {
            // MY-TRUCK hanya membedakan lancar vs tetap; "Aktiva lainnya" (tidak lancar) → tetap.
            'aset'       => preg_match('/tetap|akumulasi|lainnya/', $sub) ? 'aset_tetap' : 'aset_lancar',
            'kewajiban'  => str_contains($sub, 'panjang') ? 'kewajiban_panjang' : 'kewajiban_lancar',
            'ekuitas'    => 'ekuitas',
            'pendapatan' => preg_match('/lain|bunga|non usaha|non-usaha/', $text) ? 'pendapatan_lain' : 'pendapatan_usaha',
            default      => preg_match('/pokok|hpp/', $sub) ? 'beban_hpp' : 'beban_operasional',
        };
    }

    private function mapNormal(string $s): ?string
    {
        $s = mb_strtolower($s);

        return match (true) {
            str_starts_with($s, 'deb') || $s === 'db' || $s === 'd' => 'debit',
            str_starts_with($s, 'kre') || str_starts_with($s, 'cre') || $s === 'kr' || $s === 'k' => 'kredit',
            default => null,
        };
    }

    private function defaultNormal(string $category, string $text): string
    {
        if ($category === 'aset' && str_contains($text, 'akumulasi')) {
            return 'kredit';   // kontra-aset
        }

        return in_array($category, ['aset', 'beban'], true) ? 'debit' : 'kredit';
    }

    private function mapCashflow(string $s): ?string
    {
        $s = mb_strtolower($s);

        return match (true) {
            str_contains($s, 'non')        => 'non_kas',
            str_contains($s, 'operasi')    => 'operasi',
            str_contains($s, 'investasi')  => 'investasi',
            str_contains($s, 'pendanaan')  => 'pendanaan',
            default                        => null,
        };
    }

    /** Induk = Header sebelumnya yang awalan kodenya paling panjang & cocok. */
    private function assignParents(array &$rows): void
    {
        $headers = [];   // code → awalan
        foreach ($rows as &$r) {
            $own  = rtrim($r['code'], '0') ?: $r['code'];
            $best = null;
            foreach ($headers as $hCode => $prefix) {
                if ($hCode !== $r['code'] && strlen($prefix) < strlen($own) && str_starts_with($r['code'], $prefix)
                    && ($best === null || strlen($prefix) > strlen($headers[$best]))) {
                    $best = $hCode;
                }
            }
            $r['parent_code'] = $best;

            if ($r['is_header']) {
                $headers[$r['code']] = $own;
            }
        }
    }

    /** Kategori kosong: induk → anak-anak → digit pertama kode. */
    private function inferCategories(array &$rows, array &$errors): void
    {
        $byCode = [];
        foreach ($rows as $i => $r) {
            $byCode[$r['code']] = $i;
        }

        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($rows as $i => $r) {
                if ($r['category'] !== null) {
                    continue;
                }
                // 1. dari induk
                $p = $r['parent_code'];
                while ($p !== null && isset($byCode[$p])) {
                    if ($rows[$byCode[$p]]['category'] !== null) {
                        $rows[$i]['category'] = $rows[$byCode[$p]]['category'];
                        continue 2;
                    }
                    $p = $rows[$byCode[$p]]['parent_code'];
                }
                // 2. dari anak-anak (header puncak tanpa kategori, mis. "110000 ASET LANCAR")
                $children = array_filter($rows, fn ($c) => $c['parent_code'] === $r['code'] && $c['category'] !== null);
                if ($children) {
                    $counts = array_count_values(array_column($children, 'category'));
                    arsort($counts);
                    $rows[$i]['category'] = array_key_first($counts);
                }
            }
        }

        foreach ($rows as $i => $r) {
            if ($r['category'] === null) {
                $rows[$i]['category'] = match ($r['code'][0] ?? '') {
                    '1' => 'aset', '2' => 'kewajiban', '3' => 'ekuitas', '4' => 'pendapatan', '5', '6', '7', '8', '9' => 'beban',
                    default => null,
                };
            }
            if ($rows[$i]['category'] === null) {
                $errors[] = "Baris {$r['_row']}: Kategori akun '{$r['code']} {$r['name']}' tidak bisa ditentukan. Isi kolom Kategori.";
            }
        }
    }

    /**
     * @return array{0: ?AccountRole, 1: bool}  [peran, hasil tebakan?]
     */
    private function resolveRole(string $peran, string $name, string $category, string $sub, string $normal): array
    {
        // 1. Kolom Peran persis = label peran MY-TRUCK → keputusan pemilik data.
        $key = mb_strtolower(trim($peran));
        if ($key !== '') {
            foreach (AccountRole::cases() as $role) {
                if (mb_strtolower($role->label()) === $key && $role->categoryOf() === $category) {
                    return [$role, false];
                }
            }
        }

        // 2. Peran di file terlalu umum ("Biaya", "Persediaan") → tebak dari nama,
        //    TAPI hanya bila sub-kategori & saldo normal cocok dengan peran itu.
        //    Peran salah lebih berbahaya daripada kosong: jurnal otomatis masuk ke
        //    akun keliru tanpa ketahuan. Contoh yang ditolak: "Persediaan Spare
        //    Part Alat Berat" (aset lancar) ≠ Aset Tetap Armada; "Akum. Penyusutan
        //    Peralatan Kantor" (kredit) ≠ Aset Tetap Peralatan (debit).
        $s = AccountRole::suggestFromName($name);
        if (! $s || $s->categoryOf() !== $category || $s->defaultSubCategory() !== $sub || $this->expectedNormal($s) !== $normal) {
            return [null, false];
        }

        return [$s, true];
    }

    private function expectedNormal(AccountRole $role): string
    {
        return match (true) {
            str_starts_with($role->value, 'akumulasi_')                 => 'kredit',
            $role === AccountRole::EquityPrive                          => 'debit',
            in_array($role->categoryOf(), ['aset', 'beban'], true)      => 'debit',
            default                                                     => 'kredit',
        };
    }
}
