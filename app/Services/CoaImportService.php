<?php

namespace App\Services;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Company;
use App\Services\Import\LabeledCoaParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import Chart of Accounts (COA) dari file Excel saat register PT baru.
 *
 * Kontrak template Excel (sheet pertama):
 *   Kolom A: Kode           (wajib, alfanumerik + dash, unik dalam file)
 *   Kolom B: Nama Akun      (wajib, max 255)
 *   Kolom C: Kategori       (wajib, salah satu dari 5 enum bahasa Indonesia)
 *   Kolom D: Kode Parent    (opsional, harus mereferensi Kode lain di file)
 *
 * Strategi validasi: KUMPULKAN SEMUA ERROR dulu, baru abort.
 * User yang isi 200 baris tidak perlu upload 20x supaya tahu semua salahnya.
 *
 * Strategi seed: atomic dalam DB::transaction — kalau ada 1 kegagalan mid-insert
 * (mis. constraint violation yang lolos validasi), rollback total. COA konsisten
 * atau tidak ada sama sekali.
 */
class CoaImportService
{
    /** Kategori valid — cocok dengan `accounts.category` enum di DB & COA seeder. */
    private const VALID_CATEGORIES = ['aset', 'kewajiban', 'ekuitas', 'pendapatan', 'beban'];

    /** Regex kode: huruf, angka, dash. Tidak boleh spasi / underscore / karakter aneh. */
    private const CODE_REGEX = '/^[A-Za-z0-9\-]+$/';

    /** Ikut batas model — jangan di-hardcode ulang di sini. */
    private const CODE_MAX_LENGTH = Account::CODE_MAX_LENGTH;

    /**
     * Catatan penanda Peran hasil tebakan nama. Daftar Akun mencarinya lewat
     * awalan (GUESSED_ROLE_PREFIX) — user menghapus catatan setelah memeriksa.
     */
    public const GUESSED_ROLE_NOTE   = 'Peran diisi otomatis dari nama akun saat import — mohon periksa.';
    public const GUESSED_ROLE_PREFIX = 'Peran diisi otomatis';

    /**
     * Buang penanda "Peran hasil tebakan" dari catatan (catatan lain tetap).
     * Dipanggil saat Peran diubah / dikonfirmasi user — dulu penanda hanya
     * hilang bila teks catatannya dihapus manual, jadi peringatan di Daftar
     * Akun tidak pernah berkurang walau Peran sudah diperiksa.
     */
    public static function stripGuessedNote(?string $notes): ?string
    {
        if ($notes === null || ! str_contains($notes, self::GUESSED_ROLE_PREFIX)) {
            return $notes;
        }
        $clean = trim(preg_replace('/' . preg_quote(self::GUESSED_ROLE_PREFIX, '/') . '[^.]*\.?\s*/u', '', $notes));

        return $clean === '' ? null : $clean;
    }

    /**
     * Ringkasan import terakhir: format, jumlah akun, slot dilewati, peran terisi.
     * Dipakai notifikasi setelah daftar PT.
     */
    public array $report = [];

    public function __construct(private ?LabeledCoaParser $labeledParser = null)
    {
        $this->labeledParser ??= new LabeledCoaParser();
    }

    public function seedFromExcel(Company $company, string $absoluteFilePath): int
    {
        $rows = $this->parseAndValidate($absoluteFilePath);

        // Auto-mapping code standar → role. Untuk kode custom, role di-NULL
        // (user bisa set nanti di menu Master Data → Daftar Akun).
        $roleMapping = AccountRole::standardCodeMapping();

        return DB::transaction(function () use ($company, $rows, $roleMapping) {
            $inserted = 0;

            foreach ($rows as $row) {
                // Format berlabel membawa saldo normal, sub-kategori, arus kas & peran
                // sendiri; format standar tidak → default per kategori / kode standar.
                $normalBalance = $row['normal_balance'] ?? (in_array($row['category'], ['aset', 'beban'], true)
                    ? 'debit'
                    : 'kredit');

                // withoutGlobalScopes: saat register PT baru Filament::getTenant()
                // masih null. Dengan STRICT_TENANT_SCOPE aktif, BelongsToCompany
                // akan throw. Kita explicit pass company_id, aman lewatkan scope.
                Account::withoutGlobalScopes()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $row['code']],
                    [
                        'company_id'     => $company->id,
                        'code'           => $row['code'],
                        'name'           => $row['name'],
                        'category'       => $row['category'],
                        'parent_code'        => $row['parent_code'],
                        'normal_balance'     => $normalBalance,
                        'sub_category'       => $row['sub_category'] ?? null,
                        'cash_flow_category' => $row['cash_flow_category'] ?? null,
                        'role'               => $row['role'] ?? $roleMapping[$row['code']] ?? null,
                        // Peran hasil tebakan nama → tandai supaya ditinjau (lihat LabeledCoaParser).
                        'notes'              => ! empty($row['role_guessed'])
                            ? self::GUESSED_ROLE_NOTE
                            : null,
                        'is_active'          => true,
                    ],
                );
                $inserted++;
            }

            return $inserted;
        });
    }

    /**
     * Parse & validate file Excel. Kumpulkan SEMUA error dulu, baru throw.
     *
     * @return array<int, array{code:string, name:string, category:string, parent_code:?string}>
     * @throws ValidationException Kalau ada 1 atau lebih error validasi.
     */
    public function parseAndValidate(string $absoluteFilePath): array
    {
        if (! is_readable($absoluteFilePath)) {
            throw ValidationException::withMessages([
                'coa_excel_path' => 'File Excel tidak bisa dibaca. Coba upload ulang.',
            ]);
        }

        try {
            $spreadsheet = IOFactory::load($absoluteFilePath);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'coa_excel_path' => 'File yang di-upload bukan Excel valid (.xlsx). Detail: ' . $e->getMessage(),
            ]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, false);

        $header = array_map(
            fn ($c) => strtolower(trim((string) $c)),
            array_slice($raw[0] ?? [], 0, 4),
        );

        $expected = ['kode', 'nama akun', 'kategori', 'kode parent'];
        if ($header !== $expected) {
            // Bukan template MY-TRUCK → coba format COA berlabel milik klien
            // (judul kolom KODE / NAMA AKUN / TYPE di baris & sheet mana pun).
            if ($found = $this->labeledParser->detect($spreadsheet)) {
                return $this->finishLabeled($spreadsheet, $found);
            }

            throw ValidationException::withMessages([
                'coa_excel_path' => 'Format Excel tidak dikenali. Gunakan template MY-TRUCK (kolom: '
                    . implode(' | ', array_map('ucwords', $expected))
                    . ') atau file COA dengan judul kolom KODE, NAMA AKUN, dan TYPE (Header/Child).',
            ]);
        }

        if (count($raw) < 2) {
            throw ValidationException::withMessages([
                'coa_excel_path' => 'File Excel kosong. Minimal harus ada 1 baris header + 1 baris data.',
            ]);
        }

        $errors = [];
        $rows = [];
        $codesInFile = [];

        foreach ($raw as $i => $rowRaw) {
            if ($i === 0) continue;

            $excelRowNum = $i + 1;

            $isEmptyRow = collect($rowRaw)->every(fn ($v) => $v === null || trim((string) $v) === '');
            if ($isEmptyRow) continue;

            $code = trim((string) ($rowRaw[0] ?? ''));
            $name = trim((string) ($rowRaw[1] ?? ''));
            $category = strtolower(trim((string) ($rowRaw[2] ?? '')));
            $parentCode = trim((string) ($rowRaw[3] ?? ''));
            $parentCode = $parentCode === '' ? null : $parentCode;

            if ($code === '') {
                $errors[] = "Baris {$excelRowNum}: Kolom 'Kode' kosong.";
                continue;
            }

            if (mb_strlen($code) > self::CODE_MAX_LENGTH) {
                $errors[] = "Baris {$excelRowNum}: Kode '{$code}' terlalu panjang (maks "
                    . self::CODE_MAX_LENGTH . ' karakter).';
            } elseif (! preg_match(self::CODE_REGEX, $code)) {
                $errors[] = "Baris {$excelRowNum}: Kode '{$code}' invalid. Hanya boleh huruf, angka, dan tanda '-'.";
            }

            if (isset($codesInFile[$code])) {
                $errors[] = "Baris {$excelRowNum}: Kode '{$code}' duplikat (sudah muncul di baris {$codesInFile[$code]}).";
            } else {
                $codesInFile[$code] = $excelRowNum;
            }

            if ($name === '') {
                $errors[] = "Baris {$excelRowNum}: Kolom 'Nama Akun' kosong.";
            } elseif (mb_strlen($name) > 255) {
                $errors[] = "Baris {$excelRowNum}: Nama akun terlalu panjang (max 255 karakter).";
            }

            if (! in_array($category, self::VALID_CATEGORIES, true)) {
                $errors[] = "Baris {$excelRowNum}: Kategori '{$category}' invalid. Pilih salah satu: "
                    . implode(', ', self::VALID_CATEGORIES) . '.';
            }

            if ($parentCode !== null && mb_strlen($parentCode) > self::CODE_MAX_LENGTH) {
                $errors[] = "Baris {$excelRowNum}: Kode Parent '{$parentCode}' terlalu panjang (maks "
                    . self::CODE_MAX_LENGTH . ' karakter).';
            } elseif ($parentCode !== null && ! preg_match(self::CODE_REGEX, $parentCode)) {
                $errors[] = "Baris {$excelRowNum}: Kode Parent '{$parentCode}' invalid. Hanya boleh huruf, angka, dan tanda '-'.";
            }

            if ($parentCode !== null && $parentCode === $code) {
                $errors[] = "Baris {$excelRowNum}: Akun '{$code}' tidak boleh menjadi parent dari dirinya sendiri.";
            }

            $rows[] = [
                'code'        => $code,
                'name'        => $name,
                'category'    => $category,
                'parent_code' => $parentCode,
                '_row_num'    => $excelRowNum,
            ];
        }

        // "File kosong" hanya berlaku kalau memang tidak ada baris data sama sekali
        // (bukan karena semua baris invalid — untuk itu detail error yang jauh lebih
        // berguna dilampirkan di bawah).
        if (empty($rows) && empty($errors)) {
            throw ValidationException::withMessages([
                'coa_excel_path' => 'File Excel tidak berisi data akun. Isi minimal 1 baris di bawah header.',
            ]);
        }

        foreach ($rows as $row) {
            if ($row['parent_code'] !== null && ! isset($codesInFile[$row['parent_code']])) {
                $errors[] = "Baris {$row['_row_num']}: Kode Parent '{$row['parent_code']}' "
                    . 'tidak ditemukan di kolom Kode. Parent harus ada di file yang sama.';
            }
        }

        $codeToParent = [];
        foreach ($rows as $row) {
            $codeToParent[$row['code']] = $row['parent_code'];
        }
        foreach ($rows as $row) {
            if ($this->hasCycle($row['code'], $codeToParent)) {
                $errors[] = "Baris {$row['_row_num']}: Akun '{$row['code']}' membentuk referensi siklik "
                    . '(A → B → A). Periksa kolom Kode Parent.';
            }
        }

        $codeToCategory = [];
        foreach ($rows as $row) {
            $codeToCategory[$row['code']] = $row['category'];
        }
        foreach ($rows as $row) {
            if ($row['parent_code'] !== null && isset($codeToCategory[$row['parent_code']])) {
                $parentCat = $codeToCategory[$row['parent_code']];
                if ($parentCat !== $row['category']) {
                    $errors[] = "Baris {$row['_row_num']}: Kategori '{$row['category']}' tidak konsisten "
                        . "dengan parent '{$row['parent_code']}' (kategori '{$parentCat}'). "
                        . 'Akun anak harus sekategori dengan induknya.';
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages([
                'coa_excel_path' => implode("\n", array_slice($errors, 0, 30))
                    . (count($errors) > 30 ? "\n\n... dan " . (count($errors) - 30) . ' error lain (ditampilkan 30 pertama).' : ''),
            ]);
        }

        $this->report = ['format' => 'template MY-TRUCK', 'imported' => count($rows), 'skipped_slots' => 0, 'roles' => null];

        return array_map(function ($r) {
            unset($r['_row_num']);
            return $r;
        }, $rows);
    }

    /** Validasi lanjutan & kumpulan error untuk format berlabel. */
    private function finishLabeled(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $found): array
    {
        ['rows' => $rows, 'errors' => $errors] = $this->labeledParser->parse($spreadsheet, $found);

        if (empty($rows) && empty($errors)) {
            $errors[] = 'Tabel COA ditemukan, tetapi tidak ada akun ber-TYPE (Header/Child).';
        }

        $byCode = array_column($rows, null, 'code');
        foreach ($rows as $r) {
            if ($r['parent_code'] !== null && ($byCode[$r['parent_code']]['category'] ?? null) !== $r['category']) {
                $errors[] = "Akun '{$r['code']} {$r['name']}' berkategori '{$r['category']}' tetapi induknya '{$r['parent_code']}' "
                    . "berkategori '" . ($byCode[$r['parent_code']]['category'] ?? '?') . "'. Samakan kategorinya di file.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages([
                'coa_excel_path' => implode("\n", array_slice($errors, 0, 30))
                    . (count($errors) > 30 ? "\n\n... dan " . (count($errors) - 30) . ' error lain (ditampilkan 30 pertama).' : ''),
            ]);
        }

        $this->report = $this->labeledParser->report;

        return $rows;
    }

    /**
     * BFS deteksi siklus di graph parent — mulai dari $startCode, telusuri ke atas.
     * Kalau bertemu $startCode lagi → siklus.
     *
     * @param array<string, ?string> $codeToParent
     */
    private function hasCycle(string $startCode, array $codeToParent): bool
    {
        $visited = [];
        $current = $codeToParent[$startCode] ?? null;

        while ($current !== null) {
            if ($current === $startCode) {
                return true;
            }
            if (isset($visited[$current])) {
                return false;
            }
            $visited[$current] = true;
            $current = $codeToParent[$current] ?? null;
        }

        return false;
    }
}
