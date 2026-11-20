Untuk jasa armada: **ongkos gendong** (jasa angkut muatan) yang ditagih **per rit**, **per M³**, atau **per ton**, **rental dump truck harian**, serta **mobilisasi alat berat** dengan truk self loader.

### 1. Buat kontrak

**Kontrak Armada** → **Buat Kontrak Baru**. Isi pelanggan, rute/uraian pekerjaan, **Satuan Tarif**, dan **Tarif**:

| Satuan Tarif | Dasar tagihan | Contoh |
|---|---|---|
| **Per Rit** | Jumlah rit × tarif | Rp 250.000 / rit |
| **Per M³** | Volume dari surat jalan × tarif | Rp 45.000 / M³ |
| **Per Ton** | Tonase dari tiket timbang × tarif | Rp 30.000 / ton |
| **Per Hari** | Hari kerja (1 atau ½ hari) × tarif | Rp 1.500.000 / hari |

> Satuan tarif **terkunci** setelah ada log harian. Bila satu pekerjaan memakai satuan lain, buat kontrak baru.

Tipe kontrak menentukan biaya operasional yang ditanggung PT:

| Tipe | Biaya operasional yang ditanggung PT |
|---|---|
| **All In** | BBM + supir |
| **Semi** | Supir saja (BBM dari klien) |
| **Alat Saja** | Tidak ada — klien menanggung semua |

Isi standar biaya (liter BBM per rit, gaji & uang makan supir per hari, uang jalan, premi per rit) sebagai **perkiraan / pembanding**. Standar ini dihitung dari **rit dan hari**, apa pun satuan tarifnya, dan **tidak dijurnal** — lihat bagian *Biaya: standar vs aktual* di bawah.

### 2. Input log harian

Buka kontrak → tab **Log Harian** → **Input Ritase**. Pilih tanggal, **Dump Truck**, **Driver**, lalu:

- **Per Rit**: isi **Jumlah Rit**.
- **Per M³**: isi **Jumlah Rit** dan **Volume (M³)** hari itu — volume inilah yang ditagih.
- **Per Ton**: isi **Jumlah Rit** dan **Tonase** dari tiket timbang — tonase inilah yang ditagih.
- **Per Hari**: pilih **1 hari penuh** atau **½ hari**. Jumlah rit boleh **0** bila unit standby (tetap dibayar per hari).

Log harian **tidak menjurnal biaya**. **Perkiraan Biaya** di form hanya menampilkan biaya standar kontrak; aktifkan **Biaya hari ini berbeda dari standar kontrak** bila ingin perkiraan memakai angka hari itu.

### Biaya: standar vs aktual

Laba Rugi memakai **biaya yang benar-benar dikeluarkan perusahaan**, bukan tarif biaya di kontrak.

1. Saat membayar solar, gaji/uang makan, atau uang jalan & premi supir, catat di **Transaksi & Beban** sebesar yang dibayar, dari rekening yang dipakai.
2. Pilih **Lini Bisnis** *ARMD* dan **Unit** dump truck-nya, supaya biaya masuk ke **Laba Rugi per Unit**. Pembayaran untuk beberapa unit sekaligus: catat per unit.
3. Pantau di laporan **Biaya Standar vs Aktual**: per unit per bulan, standar kontrak dibanding biaya aktual. Selisih merah berarti biaya lebih besar dari standar.

> Jurnal biaya lama bernomor **BBK-RT-…** (dibuat otomatis dari log sebelum aturan ini) tetap tercatat. Bila biaya aktual bulan yang sama juga sudah dicatat, void jurnal BBK-RT tersebut di **Jurnal Umum** supaya tidak terhitung dua kali. Penyusutan per rit unit tidak ikut batal.

### 3. Mobilisasi alat berat

Buka kontrak → tab **Mobilisasi Alat Berat** → **Catat Mobilisasi**. Isi tanggal, jenis (**Mobilisasi** / **Demobilisasi**), alat yang diangkut (mis. *Excavator PC 75*), **truk self loader** pengangkutnya, dan nominalnya.

- Biaya mobilisasi **ikut ditagih** bersama log harian dalam satu invoice.
- Isi truk pengangkut supaya pendapatannya masuk **Laba Rugi per Unit** truk tersebut.

### 4. Tagih

Kolom **Belum Ditagih** menunjukkan yang belum diinvoice, mis. *"120 M³ + 1 mobilisasi alat"*. Klik **Tagih** → pilih tanggal invoice. Satu invoice terbit di **Tagihan & Piutang** dengan rincian di keterangannya, contoh:

*Angkutan 120 M³ @ Rp 45.000 — Kuari → Proyek; Mobilisasi alat: Mobilisasi Excavator PC 75 (05/03) Rp 2.500.000*

> Log harian dan mobilisasi yang sudah ditagih tidak bisa diubah atau dihapus. Untuk memperbaiki, **void** invoice-nya — semuanya kembali berstatus belum ditagih dan bisa ditagih ulang.

### 5. Tutup kontrak

Klik **Tutup Kontrak** setelah pekerjaan selesai dan seluruh log harian serta mobilisasi sudah ditagih.
