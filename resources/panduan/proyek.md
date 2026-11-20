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
- Termin yang membuat tertagih 100% memotong **seluruh sisa DP**, sehingga Uang Muka Proyek tuntas — **paling banyak sebesar nilai termin itu**. Bila DP lebih besar dari sisa nilai kontrak, kelebihannya tetap di Uang Muka Proyek (kewajiban: dikembalikan / diperhitungkan dengan klien), bukan jadi pendapatan.
- Termin yang **lunas penuh dari DP** tidak punya invoice; di tab **Riwayat Tagihan Termin** tertulis *Lunas dari DP*. Untuk membatalkannya klik **Batalkan** pada termin itu — jurnal pemotongan DP dibalik, persentase tertagih & sisa DP kembali.
- Void invoice termin mengembalikan potongan DP-nya. DP yang sudah dipotong tidak bisa di-void sebelum invoice terminnya di-void.

### 5. Proyek selesai

- Status otomatis menjadi **selesai** ketika progress fisik **dan** persentase tertagih sama-sama mencapai 100%.
- Proyek dengan DP juga mencapai tertagih 100% — DP sudah dipotongkan di termin-terminnya.

### 6. Pemakaian alat berat & truk sendiri

Buka proyek → tab **Pemakaian Unit Harian** → **Catat Pemakaian**, setiap hari unit bekerja di proyek: unit, operator/supir, **jam kerja** (alat berat) atau **jumlah rit** (truk), solar (liter), gaji, uang makan, premi/uang jalan, lain-lain, dan kas pembayar.

- Biaya langsung dijurnal ke lini **Borongan** dan ditandai unitnya (ikut **Laba Rugi per Unit**).
- Unit dengan penyusutan **per jam / per rit** ikut disusutkan sesuai pemakaiannya di proyek (masuk Laporan Margin Proyek).
- Unit **garis lurus** dan **per hari** disusutkan lewat penyusutan bulanan (per hari = semua hari kalender), jadi tidak disusutkan lagi per catatan dan belum dialokasikan ke margin proyek.
- Gaji operator yang sudah dibayar lewat penggajian bulanan **jangan diisi lagi** di catatan harian — biayanya akan terhitung dua kali. Isi gaji di sini hanya untuk upah harian.
- Mengubah atau menghapus catatan membatalkan jurnal lamanya lalu memposting ulang.
- Di **Arus Kas (Format LAK)** biayanya masuk seksi *Pengeluaran Borongan*: BBM, Operator & Supir, serta Premi & Uang Makan.
- **Backup Excel (SOP) Proyek** memuat sheet tambahan **"Pemakaian Unit"** berisi semua catatan dalam periode.

### 7. Pekerjaan tambah (addendum)

Pekerjaan di luar kontrak awal yang diminta klien (mis. tambahan timbunan, gorong-gorong) **wajib disetujui Owner** sebelum ditagih.

1. Buka proyek → tab **Pekerjaan Tambah** → **Ajukan Pekerjaan Tambah**: tanggal, nilai, uraian.
2. Pengajuan berstatus **Menunggu Persetujuan**. Owner membuka tab yang sama → **Setujui** atau **Tolak** (wajib alasan). Pengaju bisa **Batalkan** selama belum diproses.
3. Setelah disetujui, **invoice sebesar nilai pekerjaan tambah langsung terbit** (lini Borongan) **bertanggal hari persetujuan**, dan menjadi piutang klien di **Tagihan & Piutang**. Proyek yang sudah **Batal** tidak bisa disetujui pekerjaan tambahnya — tolak pengajuannya.

- Bila Owner sendiri yang mengajukan, pengajuan langsung disetujui.
- Pekerjaan tambah **tidak mengubah persentase termin**: termin tetap dihitung dari nilai kontrak awal.
- Void invoice pekerjaan tambah → statusnya menjadi *Invoice Di-void* dan nilainya tidak lagi dihitung.
- Siapa yang boleh menyetujui diatur lewat izin **Setujui Perubahan Kontrak** (default Owner).

### 8. Laporan Margin Proyek

Daftar proyek → **Laporan Margin Proyek**: per proyek berisi pendapatan termin + pekerjaan tambah yang disetujui − biaya unit − penyusutan = **margin (Rp & %)**, dengan rincian per unit (hari, jam, rit, biaya).

> Biaya lain yang dijurnal manual (subkontraktor, pembelian material) belum terhubung ke proyek tertentu — hanya biaya dari Pemakaian Unit Harian yang dihitung.
