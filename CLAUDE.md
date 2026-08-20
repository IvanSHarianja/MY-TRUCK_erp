# MY-TRUCK — Project Context untuk Claude

> **Baca file ini pertama kali sebelum menjawab pertanyaan/menulis kode di project ini.** Berisi konteks bisnis, keputusan arsitektur, konvensi wajib, dan gotchas yang tidak terlihat dari sekedar baca code.

## Ringkasan 30 Detik

- **Apa**: SaaS akuntansi multi-tenant untuk perusahaan **alat berat & dump truck** di Indonesia
- **Bisnis**: 4 lini — Rental Alat Berat (RENT), Jasa Angkutan Armada (ARMD), Penjualan Material (MATL), Borongan Pengurugan (BONG)
- **Stack**: Laravel 13.8 + Filament 5.6 + PHP 8.3 + MySQL 8. Test PHPUnit 12.5 dengan SQLite in-memory.
- **User**: bahasa Indonesia. Panel admin di `/admin`, tenant via slug URL.
- **Status**: production-ready untuk MVP tapi ada beberapa gap operasional (lihat "Gap Belum Selesai" di bawah).

## Bootstrap untuk Chat Baru

```bash
# 1. Verifikasi state
cd /d/laragon/www/my-truck
php artisan test --testsuite=Feature   # target 263+ pass, ~60 detik
git log --oneline -15                  # lihat 15 commit terakhir untuk context

# 2. Cek struktur (kalau perlu)
ls app/Services/Accounting/            # 20 service akuntansi
ls app/Filament/Resources/             # 15+ resource
ls app/Policies/                       # 17 policy
```

## Business Domain

### 4 Lini Bisnis + 1 Umum

| Kode | Lini | Karakteristik | Journal Auto-post |
|---|---|---|---|
| RENT | Rental Alat Berat | Sewa per jam kerja (all-in / semi / alat_saja) | `BBK-RL-*` cost + `DEPUSE-*` (kalau per_hour) |
| ARMD | Jasa Angkutan Dump Truck | Tarif per rit (ritase) | `BBK-RT-*` cost + `DEPUSE-*` (kalau per_rit) |
| MATL | Penjualan Material | Sale material (m³) dengan HPP | `MS-*` + `HPP-*` |
| BONG | Borongan Pengurugan | Kontrak proyek dengan DP + termin progress | `DP-*` DP + Invoice termin |
| UMUM | Kategori lain-lain | Tidak spesifik lini | default fallback |

### 3-Way Match (Kontrak → Log → Invoice)

- **Kontrak** (RentalContract/ArmadaContract/Project) — kesepakatan tarif + scope
- **Log operasional** (RentalLog/RitLog) — pemakaian aktual harian
- **Invoice** — penagihan atas log yang belum ditagih (via aksi "Tagih" di kontrak)

Owner harus review konsistensi 3 dokumen ini. Sistem enforce via `billed_jam`/`billed_rit` counter di contract yang auto-increment saat tagih.

## Arsitektur Kunci

### Multi-Tenant via Filament Native

- Model **Company** = tenant
- Setiap user via pivot `company_user` — punya `role` + `is_active`
- `Filament::getTenant()` return Company aktif berdasarkan URL slug
- Trait `BelongsToCompany` auto-scope query berdasarkan tenant aktif
- Untuk CLI/queue/PDF (di luar Filament panel) — WAJIB pakai `withoutGlobalScopes()`

### Layer Pattern

```
Filament UI → Resource → Service → Model
              (Schema/Table)  (business logic)  (Eloquent)
```

**Tanpa Domain layer / Repository** — layered cukup tipis. Kalau ke depan tim tumbuh, pertimbangkan `App\Actions\` untuk operasi kompleks.

### Auto-Journal Pattern

**JANGAN pernah** `JournalEntry::create()` langsung. Selalu via:

```php
$journalService->createEntryWithLines(
    company: $company,
    date: $date,
    entryDataFactory: fn (string $entryNumber) => [...],
    linesFactory: fn (JournalEntry $entry) => [...],
);
```

Race-safe dengan retry pada `UniqueConstraintViolationException` (entry_number bentrok saat concurrent).

### Document Number Prefix → Event Map

| Prefix | Event | Cascade Void Behavior |
|---|---|---|
| `INV{YYMM}-{NNNN}` | Invoice issued | Cascade: rollback source (rental/armada/project) |
| `PAY{YYMM}-{NNNN}` | Payment received | Reverse via `PaymentService::reverse` |
| `BBK-RL-{log_id}` | RentalLog biaya operasional | Nullify log.journal_entry_id |
| `BBK-RT-{log_id}` | RitLog biaya operasional | Nullify log.journal_entry_id |
| `BBK-MT-{log_id}` | MaintenanceLog | Nullify log.journal_entry_id |
| `DEP-{asset_id}-{YYYYMM}` | Depresiasi bulanan (cron) | Idempotent check |
| `DEPUSE-{asset_id}-{log_id}` | Depresiasi usage-based (observer) | Cascade dari log void |
| `PB{YYMM}-{NNNN}` | Material Purchase (BIZ-01) | Cascade: rollback stock + recompute MAC |
| `HPP-{sale_number}` | HPP saat sale | No cascade needed |
| `DP-{project_number}` | Project DP | Decrement project.dp_diterima |
| `MS-{sale_id}` | Material sale revenue | Cascade lewat invoice |
| `REV-*` | Jurnal pembalik (auto saat void) | — |

Kalau tambah pattern baru, update `JournalEntryObserver::updated()` untuk handle cascade void.

## Role & Permission System

- **4 Role**: Owner (immutable) / Admin / Accountant / Viewer — di pivot `company_user.role`
- **17 Permission**: `App\Enums\Permission` — grouped: System, Master, Operasional, Financial, Reporting
- **Static default matrix**: `App\Support\RoleMatrix::permissions(Role $role): array`
- **Per-tenant override**: table `company_role_permissions` — Owner bisa edit via halaman **Role Akses**
- **Resolver**: `App\Support\RoleAccessManager` — memoized per request, DB query fallback ke static default
- **User helpers**: `$user->canIn($tenant, $permission)` / `$user->canCurrent($permission)` / `$user->roleIn($tenant)`
- **Policies**: 17 file di `app/Policies/` (auto-integrated Filament)
- **UI**: `Role Akses` page (menu paling bawah sidebar, owner-only) — checkbox toggle langsung save

**Aturan pokok:**
- Owner selalu semua permission — tidak bisa dikutik (safety)
- Owner tidak bisa disable dirinya sendiri
- Admin tidak bisa manage user Owner (guard 3 lapis di UserManagement)
- Register PT Baru hidden untuk non-owner (kecuali user 0-tenant untuk first-time)

## Convention Wajib

### 1. Rupiah = integer cast (JANGAN decimal:2)

Bug rupiah 100× lipat: `decimal:2` return string "500000.00" yang bikin Filament mask `->rupiah()` salah interpret titik sebagai thousand separator → tampil 100× lipat.

**Fix pattern:**
```php
protected $casts = [
    'purchase_price' => 'integer',   // ← untuk field Rupiah pakai ->rupiah()
    'volume'         => 'decimal:2', // ← untuk field non-Rp (m³, liter, jam) tetap decimal
];
```

Field yang WAJIB integer: `harga_per_satuan`, `harga_pokok`, `tarif_per_jam`, `tarif_per_rit`, `gaji_*`, `uang_makan_*`, `premi_*`, `purchase_price`, `salvage_value`, `nilai_kontrak`, `cost`, `total`, `amount`.

Field yang HARUS tetap decimal: `bbm_liter_*`, `jam_kerja`, `hm_*`, `progress_pct`, `useful_life_hours/rits/days`, `current_mac` (MAC), `volume`.

### 2. Verifikasi Runtime, bukan cuma `php -l`

Setelah Write file baru:
```bash
php artisan tinker --execute="echo class_exists('App\\Namespace\\ClassName') ? 'OK' : 'FAIL';"
```

Untuk service dengan method baru, tambah method call verify.

### 3. Filament v5.6 Namespace Quirks

Beda dari v3/v4 (banyak AI training data masih v3/v4):

| Komponen | ❌ Salah (v3/v4) | ✅ Benar (v5.6) |
|---|---|---|
| Section | `Filament\Forms\Components\Section` | **`Filament\Schemas\Components\Section`** |
| Action page/table | `Filament\{Pages,Tables}\Actions\Action` | **`Filament\Actions\Action`** |
| Table row actions | `->actions([])` | **`->recordActions([])`** |
| Action form schema | `->form([])` | **`->schema([])`** |
| Callback param | `$r` | **`$record`** (reflection bind by name) |

### 4. Multi-tenant Query Patterns

```php
// Di dalam panel Filament — auto-scope aktif via BelongsToCompany
$invoices = Invoice::all();  // ← otomatis filter by tenant aktif

// Di CLI / queue / PDF controller — WAJIB explicit
$invoices = Invoice::withoutGlobalScopes()->where('company_id', $companyId)->get();
```

### 5. Auto-Journal Idempotency

Pakai deterministic document_number untuk idempotent operasi berulang:

```php
// Contoh DEPUSE:
$documentNumber = "DEPUSE-{$asset->id}-{$log->id}";
$existing = JournalEntry::where('document_number', $documentNumber)
    ->whereIn('status', ['draft', 'posted'])
    ->first();
if ($existing) return $existing;  // idempotent
```

## Testing

- **Test DB**: SQLite in-memory (defined di `phpunit.xml`)
- **Base class**: `Tests\TestCase` punya helper: `createTenant()`, `createTenantUser()`, `actingAsTenant()`, `makeJournalEntry()`, `postableAccount()`, `businessUnit()`, `createClient()`, `makeDraftInvoice()`
- **Run**: `php artisan test --testsuite=Feature` (~60 detik untuk 263+ test)
- **Convention**: setiap fitur baru minimal 2-3 feature test. Untuk service accounting minimum test: happy path + edge case (void, race, negative)

## Skema Data Kunci

### 3 Tabel Fondasi
- `companies` — tenant
- `company_user` (pivot) — user × company + role + is_active
- `company_role_permissions` — override permission per tenant

### Chart of Accounts
- `accounts` — hierarchical via `parent_code`. Kolom: `code`, `name`, `category`, `sub_category`, `role`, `normal_balance`, `cash_flow_category`, `parent_code`, `is_active`
- Enum `AccountRole` (50+ role) — mapping role fungsional → kode standar. Fallback via `Account::findByRoleOrCode($role, $code, $companyId)`

### Ledger Double-Entry
- `journal_entries` — header + status (draft/posted/void) + doc_number + reversed_by_id
- `journal_entry_lines` — line dengan `debit`, `kredit`, `asset_id`, `sort_order`
- Setiap posted journal harus balance (Debit=Kredit) via `JournalService::validateBalance()` dengan epsilon `< 0.005`

### Inventory & Aset
- `materials` — master + `current_stock`, `current_mac` (Moving Average Cost)
- `material_purchases` + `material_stock_movements` (BIZ-01)
- `assets` — dengan `depreciation_method` enum (StraightLine / PerHour / PerRit / PerDay), `useful_life_*`, `monthly_target_hours` (dashboard target)
- `asset_maintenance_logs` — riwayat maintenance

### Contract & Operasional
- `rental_contracts` + `rental_logs` (jam_kerja + hm_awal/hm_akhir)
- `armada_contracts` + `rit_logs` (rit_count)
- `projects` + `project_termins` + `project_progress_updates`
- `material_sales`

## Sprint History (Ringkas)

- **Sprint 0** — Baseline test setup (79 test)
- **Sprint 1** — 8 bugfix production (108 test)
- **Sprint 2** — 10 race condition + state machine (152 test)
- **Sprint 2.5** — COA role-based refactor (117 test)
- **Sprint 11** — Domain-specific accounting: BIZ-01 s/d BIZ-05
  - BIZ-01: Material Purchase + Stock + MAC (perpetual inventory)
  - BIZ-02: Usage-based depreciation (per jam/rit/hari)
  - BIZ-03: Auto-post DEPUSE saat log usage
  - BIZ-04: Laporan Penyusutan per Aset
  - BIZ-05: Laporan Biaya per Unit
- **Post-Sprint 11**:
  - Bug rupiah 100× fix (8 model)
  - Role System dinamis + 17 Policy + halaman Role Akses interactive
  - Dashboard Operasional dengan 4 widget
  - HM overlap validation di RentalLog
  - Password strength di Add User
  - Cash dropdown hanya sub-akun (hierarki paksa)
  - Berbagai UX fix

**Total sekarang**: 263 test, 1089 assertion, zero regression.

## Gap Belum Selesai (untuk kesadaran)

### 🔴 Critical (blocker untuk production)
- `STRICT_TENANT_SCOPE` env dormant — failsafe tenant leak belum aktif
- Kalau `STRICT_TENANT_SCOPE=true` di aktivasi, PDF export akan error karena `Filament::getTenant()` null di luar panel — perlu explicit `withoutGlobalScopes()` di PDF path
- `Mail::to()->send()` di dalam DB transaction — perlu ganti ke `queue()` atau `afterCommit()`

### 🟠 High
- `APP_DEBUG=true` di `.env` produksi — perlu `.env.production` template
- `FILESYSTEM_DISK=local` untuk bukti_tf — perlu backup ke S3-compatible
- `LOG_STACK=single` — perlu daily rotation

### 🟡 Medium
- `CompanyDelete` + `PeriodClose` permission ada di matrix tapi UI belum dibangun
- HPP silent skip kalau akun 551300/111260 tidak ada — cuma log warning
- Multi-warehouse tidak di-scope (kalau perlu tenant besar)
- `RoleAccessManager` lazy query per permission — kalau prod besar, ganti eager-load

## Common Pitfalls & Debug Tips

### "Column not found" saat run
Kemungkinan migration belum apply ke MySQL dev DB (SQLite test terpisah):
```bash
php artisan migrate:status | tail -5   # cek pending
php artisan migrate --force            # apply
```

### "Route not found"
Cek `getPages()` di Resource yang di-referensi — mungkin route (view/edit) tidak di-daftarkan. Filament v5.6 tidak auto-generate view page.

### Placeholder di Filament form muncul raw (bukan formatted HTML)
Nama Placeholder bentrok dengan state field (e.g., `total_amount_display` juga di-`$set()` di afterStateUpdated). Rename field, jangan set state ke nama Placeholder.

### Widget dashboard render 2x
Jangan panggil `<x-filament-widgets::widgets>` di custom view kalau `getHeaderWidgets()` di-override di Page — Filament auto-render.

### Filament v5.6 form field `->rupiah()` tapi angka 100× lipat
Cast model `decimal:2` bentrok dengan mask. Ganti ke `integer`.

### `gt:field_lain` rule di Filament form tidak jalan
Live validation cuma slice field yang berubah. Ganti ke closure rule:
```php
->rule(static function (Get $get): \Closure {
    return static function (string $attr, $value, \Closure $fail) use ($get): void {
        if ((float) $value <= (float) $get('field_lain')) $fail('...');
    };
})
```

### Select searchable + `getSearchResultsUsing()` throw LogicException
Wajib tambah `->getOptionLabelUsing()` untuk validate submitted value.

### Password autofill di modal Add User
Tambah `->extraInputAttributes(['autocomplete' => 'new-password', 'data-lpignore' => 'true'])`.

## Referensi File Kunci

- **Middleware tenant guard**: `app/Http/Middleware/EnsureTenantAccess.php`, `EnsurePdfTenantAccess.php`
- **Register panel + tenant menu**: `app/Providers/Filament/AdminPanelProvider.php`
- **User model + role helpers**: `app/Models/User.php`
- **Static role matrix**: `app/Support/RoleMatrix.php`
- **Dynamic role resolver**: `app/Support/RoleAccessManager.php`
- **BelongsToCompany scope**: `app/Models/Concerns/BelongsToCompany.php`
- **Company template seeder (COA default)**: `app/Services/CompanyTemplateService.php`
- **Master accounting service**: `app/Services/Accounting/JournalService.php`
- **Test base helpers**: `tests/TestCase.php`
- **Route web**: `routes/web.php` — 15 route PDF export + root redirect
- **Route console (scheduler)**: `routes/console.php` — cron depreciation bulanan

## Kalau Baca CLAUDE.md Selesai

Sekarang siap kerja. Untuk task spesifik:
1. Identifikasi service/model yang terkait
2. Baca file tersebut fully (bukan grep parsial)
3. Ikuti convention di atas (rupiah cast, filament namespace, auto-journal pattern)
4. Sebelum submit, jalankan `php artisan test --testsuite=Feature` — pastikan 263+ tetap pass
5. Kalau ada file baru, tinker `class_exists()` verify runtime
