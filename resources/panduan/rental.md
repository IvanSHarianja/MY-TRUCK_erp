Untuk penyewaan alat berat (excavator, bulldozer, wheel loader) yang ditagih **per jam kerja**.

### 1. Buat kontrak

**Rental Alat Berat** → **Buat Kontrak Rental**. Pilih unit, pelanggan, lokasi, dan **Tarif/Jam**. Tentukan tipe rental:

| Tipe | Biaya operasional yang ditanggung PT |
|---|---|
| **All In** | BBM + operator |
| **Semi** | Operator saja (BBM dari klien) |
| **Alat Saja (Dry Rental)** | Tidak ada — klien menanggung semua |

Untuk All In/Semi, isi standar biaya (liter BBM per jam, gaji & uang makan operator per hari, premi per jam). Standar ini hanya **perkiraan / pembanding** di laporan **Biaya Standar vs Aktual** — tidak dijurnal.

### 2. Input jam kerja harian

Buka kontrak → bagian log → **Input Jam Kerja**. Isi tanggal, operator, **HM Awal (Pagi)**, dan **HM Akhir (Sore)** — jam kerja terhitung otomatis.

- Log **tidak menjurnal biaya**. **Perkiraan Biaya** di form menampilkan biaya standar kontrak; aktifkan **Biaya hari ini berbeda dari standar kontrak** bila ingin perkiraannya memakai angka hari itu.
- Biaya yang benar-benar dibayar (solar, gaji/uang makan, premi operator) dicatat keuangan di **Transaksi & Beban** dengan **Lini Bisnis** *RENT* dan **Unit** alat beratnya — angka inilah yang masuk Laba Rugi. Lihat bagian *Biaya: standar vs aktual* di [Kontrak Armada](#armada).
- Jurnal biaya lama **BBK-RL-…** (dari log sebelum aturan ini) tetap tercatat; void di Jurnal Umum bila biaya aktualnya juga sudah dicatat.
- Rentang HM tidak boleh tumpang tindih dengan log lain untuk unit yang sama.
- Bila unit memakai penyusutan **Per Jam Kerja**, penyusutannya ikut dijurnal otomatis.

### 3. Tagih

Di daftar kontrak, kolom **Belum Ditagih** dan **Nilai Siap Tagih** menunjukkan jam yang belum diinvoice. Klik **Tagih** → pilih tanggal invoice. Invoice atas semua jam yang belum ditagih langsung terbit di **Tagihan & Piutang**.

> Log yang sudah ditagih tidak bisa diubah atau dihapus lagi. Pastikan data jam kerja benar sebelum menagih.

### 4. Tutup kontrak

Setelah pekerjaan selesai dan semua jam sudah ditagih, klik **Tutup Kontrak**. Status unit kembali tersedia.
