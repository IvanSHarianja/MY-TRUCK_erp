Untuk pekerjaan borongan pengurugan dengan nilai kontrak tetap, dibayar lewat **uang muka (DP)** dan **termin** sesuai progress.

### 1. Daftarkan proyek

**Proyek Borongan** → **Daftarkan Proyek Baru**. Isi pelanggan, nama proyek, jenis pekerjaan, dan **Nilai Kontrak**.

### 2. Terima DP (bila ada)

Aksi **Terima DP** → pilih tanggal, rekening penerima (**Diterima ke Akun**), dan nominal. DP dicatat sebagai *uang muka proyek* (kewajiban), belum menjadi pendapatan.

### 3. Update progress

Aksi **Update Progress** → isi **Progress Fisik (%)** dan catatan. Riwayat progress tersimpan per tanggal.

### 4. Tagih termin

Aksi **Tagih Termin** → isi **Persen Termin (%)** dari nilai kontrak. Invoice terbit di **Tagihan & Piutang**.

- **Uang muka (DP) dipotong proporsional** di setiap termin. Contoh: kontrak Rp 100 jt, DP Rp 30 jt, termin 40% → invoice = Rp 40 jt − potongan DP Rp 12 jt = **Rp 28 jt**. Form menampilkan pratinjau potongannya.
- Termin yang membuat tertagih 100% memotong **seluruh sisa DP**, sehingga Uang Muka Proyek tuntas.
- Void invoice termin mengembalikan potongan DP-nya. DP yang sudah dipotong tidak bisa di-void sebelum invoice terminnya di-void.

### 5. Proyek selesai

- Status otomatis menjadi **selesai** ketika progress fisik **dan** persentase tertagih sama-sama mencapai 100%.
- Proyek dengan DP juga mencapai tertagih 100% — DP sudah dipotongkan di termin-terminnya.

### 6. Pemakaian alat berat & truk sendiri

Buka proyek → tab **Pemakaian Unit Harian** → **Catat Pemakaian**, setiap hari unit bekerja di proyek: unit, operator/supir, **jam kerja** (alat berat) atau **jumlah rit** (truk), solar (liter), gaji, uang makan, premi/uang jalan, lain-lain, dan kas pembayar.

- Biaya langsung dijurnal ke lini **Borongan** dan ditandai unitnya (ikut **Laba Rugi per Unit**).
- Unit dengan penyusutan **per jam / per rit / per hari** ikut disusutkan sesuai pemakaiannya di proyek.
- Mengubah atau menghapus catatan membatalkan jurnal lamanya lalu memposting ulang.

### 7. Laporan Margin Proyek

Daftar proyek → **Laporan Margin Proyek**: per proyek berisi pendapatan termin − biaya unit − penyusutan = **margin (Rp & %)**, dengan rincian per unit (hari, jam, rit, biaya).

> Biaya lain yang dijurnal manual (subkontraktor, pembelian material) belum terhubung ke proyek tertentu — hanya biaya dari Pemakaian Unit Harian yang dihitung.
