Untuk pekerjaan borongan pengurugan dengan nilai kontrak tetap, dibayar lewat **uang muka (DP)** dan **termin** sesuai progress.

### 1. Daftarkan proyek

**Proyek Borongan** → **Daftarkan Proyek Baru**. Isi pelanggan, nama proyek, jenis pekerjaan, dan **Nilai Kontrak**.

### 2. Terima DP (bila ada)

Aksi **Terima DP** → pilih tanggal, rekening penerima (**Diterima ke Akun**), dan nominal. DP dicatat sebagai *uang muka proyek* (kewajiban), belum menjadi pendapatan.

### 3. Update progress

Aksi **Update Progress** → isi **Progress Fisik (%)** dan catatan. Riwayat progress tersimpan per tanggal.

### 4. Tagih termin

Aksi **Tagih Termin** → isi **Persen Termin (%)** dari nilai kontrak. Invoice terbit di **Tagihan & Piutang**.

- Total DP + semua termin **tidak boleh melebihi** nilai kontrak. Contoh: kontrak Rp 100 jt dengan DP Rp 20 jt → termin maksimal total 80%.

### 5. Proyek selesai

- Status otomatis menjadi **selesai** ketika progress fisik **dan** persentase tertagih sama-sama mencapai 100%.
- Proyek yang menerima DP tidak akan mencapai tertagih 100% (karena sebagian nilai sudah diterima sebagai DP) — tutup manual dengan aksi **Tutup Proyek** setelah semua termin ditagih.
