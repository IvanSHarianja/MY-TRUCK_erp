Lakukan langkah ini sekali, saat PT baru didaftarkan. Urutannya penting karena transaksi membutuhkan akun dan master data.

### 1. Daftarkan PT

Isi nama perusahaan, tahun buku, dan periode buku. Pada **Cara Setup**, pilih salah satu:

- **Standar (Rekomendasi)** — otomatis membuat daftar akun (COA), 5 lini bisnis, dan material default. Cocok untuk sebagian besar perusahaan alat berat/dump truck.
- **Import COA dari Excel** — untuk perusahaan yang sudah punya COA sendiri (mis. dari MYOB/Accurate/Zahir). Klik **Download Template Excel**, isi, lalu unggah. Setelah import, akun berkode sendiri **belum punya Peran** — lengkapi sesuai [Paket Akun per Fitur](#coa-paket).
- **Kosongan** — Anda membuat COA manual. Buat akun sesuai [Paket Akun per Fitur](#coa-paket) sebelum bertransaksi; tanpa itu sebagian fitur otomatis gagal atau jurnalnya tidak lengkap.

### 2. Lengkapi profil PT

Buka menu PT (pojok kiri atas) → profil. Isi **Harga Solar Default (Rp/liter)** — dipakai menghitung biaya BBM otomatis bila kontrak tidak mengisi harga sendiri.

### 3. Buat sub-akun kas & bank

Di **Daftar Akun (COA)**, pada akun **111100 Kas dan Bank** klik **+ Sub-Akun** untuk tiap rekening (mis. "Mandiri - 1234", "BCA - 5678"). Transaksi kas selalu memakai sub-akun, bukan akun induknya.

### 4. Catat saldo awal

Buat jurnal di **Jurnal Umum** dengan **Jenis Bukti: Saldo Awal**, contoh untuk rekening Mandiri Rp 50.000.000:

| Akun | Debit | Kredit |
|---|---|---|
| Mandiri - 1234 | 50.000.000 | |
| Modal Pemilik | | 50.000.000 |

> **Penting:** gunakan Jenis Bukti **Saldo Awal**, bukan Manual. Laporan Arus Kas membaca saldo awal hanya dari jenis ini. Tanpa saldo awal, rekening akan tampil **minus** begitu ada pembayaran keluar.

### 5. Isi master data

Klien, Vendor, Karyawan (operator/supir), serta **Armada & Alat Berat**. Setelah itu kontrak dan transaksi siap dibuat.
