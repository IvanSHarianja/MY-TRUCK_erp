Mengelola barang bergudang: material yang **dijual ke klien** (tanah urug, sirtu, batu) dan **sparepart yang dipakai sendiri** saat servis unit. Stok dan harga pokok dihitung otomatis untuk keduanya.

### Material

Master barang: nama, **jenis**, satuan (mis. m³), dan harga jual. Kolom **Stok** dan **MAC (Harga Pokok)** terisi otomatis dari pembelian — tidak diinput manual.

Ada dua **jenis**:

| Jenis | Dipakai untuk | Keluar lewat |
|---|---|---|
| **Dijual ke Klien** | Barang dagangan (tanah urug, sirtu, batu) | Penjualan Material |
| **Sparepart (Dipakai Sendiri)** | Suku cadang unit (filter, oli, ban) | Form Pemeliharaan di menu Armada & Alat Berat |

Sparepart tidak muncul di form Penjualan Material, dan material dagangan tidak muncul di form Pemeliharaan.

> **MAC (Moving Average Cost)** = harga pokok rata-rata. Setiap pembelian baru menghitung ulang rata-rata dari stok lama + pembelian baru.

### Pembelian Material

Menu **Pembelian Material** → **Input Pembelian**.

- **Tunai** — pilih rekening kas/bank yang dipakai membayar.
- **Kredit** — wajib pilih vendor dan **Jatuh Tempo Pembayaran**; tercatat sebagai utang vendor dan otomatis masuk menu **Hutang & Cicilan** (dibayar dari sana).

Setiap pembelian otomatis: menambah stok, menghitung ulang MAC, dan membuat jurnal (**Persediaan Material** bertambah, Kas berkurang / Utang bertambah). Akun yang dibutuhkan ada di [Paket Akun per Fitur](#coa-paket).

### Penjualan Material

Menu **Penjualan Material** → **Buat Penjualan Baru**. Pilih **Metode Pembayaran**:

- **Tunai (langsung kas)** — uang langsung masuk ke rekening yang dipilih.
- **Invoice (piutang)** — dibuatkan tagihan; pembayaran dicatat nanti di **Tagihan & Piutang**.

Stok berkurang otomatis dan **HPP** dijurnal memakai MAC saat penjualan.

**Dijual per**:

- **Satuan material** (M³ / ton) — total = volume × harga.
- **Rit** — total = jumlah rit × **harga per rit**. Tetap isi **Volume Keluar dari Stok** (mis. 10 rit × 6 M³ = 60 M³), karena stok & HPP selalu dihitung dalam satuan material.

**Pengiriman (Armada)** — biaya mengantar material, dicatat per penjualan dan dibayar tunai dari kas yang dipilih:

| Isian | Dihitung | Masuk ke akun |
|---|---|---|
| Ongkos supir | per rit × jumlah rit | Premi & Uang Jalan |
| BM (bongkar muat) | per rit × jumlah rit | Subkontraktor / BM |
| Uang makan supir, Portal | nominal | Premi & Uang Jalan |
| BBM | nominal | Beban BBM |
| Lain-lain | nominal + keterangan | Beban Lain |

Pilih **Truk** supaya biayanya masuk **Laba Rugi per Unit**. Bagian bawah form menampilkan **Perkiraan Margin** sebelum disimpan.

**Membatalkan penjualan:**

- **Tunai** — tombol **Batalkan** di daftar penjualan (isi alasan). Jurnal penjualan, HPP, dan biaya kirim dibalik, stok dikembalikan, dan penjualan ditandai **DIBATALKAN** (tetap tersimpan untuk jejak audit). Memakai izin *Void Journal*.
- Stok yang kembali dinilai sebesar biaya saat barang keluar, lalu **MAC dihitung ulang** — sehingga stok × MAC tetap sama dengan saldo Persediaan meski harga beli sudah berubah.
- **Invoice** — void invoice-nya di **Tagihan & Piutang**; efeknya sama.
- Jurnal HPP atau biaya kirim **tidak bisa di-void sendirian** dari Jurnal Umum — batalkan penjualannya. Void jurnal penjualan tunai dari Jurnal Umum otomatis membatalkan penjualannya.

**Membatalkan pembelian:** void jurnal pembeliannya (*PB…*) di Jurnal Umum — stok & MAC dikembalikan. Untuk pembelian **kredit**, hutang vendornya ikut terhapus dari menu Hutang & Cicilan. Bila hutang itu sudah ada pembayarannya, void ditolak: batalkan pembayarannya dulu di menu Hutang.

### Laporan Margin Penjualan

Daftar Penjualan Material → **Laporan Margin**: per penjualan berisi penjualan − HPP − biaya kirim = **margin (Rp & %)**, dengan filter tanggal, klien, dan material, plus **Export Excel** berisi rincian setiap biaya. Penjualan yang invoice-nya di-void tidak dihitung — void juga membatalkan jurnal HPP & biaya kirimnya.

### Sparepart: dari gudang ke unit

1. **Beli** lewat **Pembelian Material** seperti biasa — stok dan MAC terisi otomatis.
2. **Pakai** saat servis: buka **Armada & Alat Berat** → pilih unit → tab Pemeliharaan →
   **Catat Pemeliharaan** → isi bagian **Sparepart dari Stok**.
3. Stok otomatis berkurang, dan nilainya (qty × MAC) masuk sebagai biaya servis unit itu.

Di form servis ada dua kolom biaya yang berbeda:

- **Biaya Jasa / Dibayar Tunai** — upah montir, jasa bengkel, dan sparepart yang dibeli
  langsung di bengkel (tidak lewat gudang).
- **Sparepart dari Stok** — diambil dari gudang, nilainya dihitung sendiri oleh sistem.

> Jangan menghitung sparepart dua kali: kalau sudah diisi di bagian Sparepart dari Stok,
> jangan ditambahkan lagi ke Biaya Jasa.

Kalau stok sparepart tidak cukup, pencatatan servis **ditolak** — input pembeliannya dulu.
Menghapus atau mengubah catatan servis otomatis mengembalikan stok.

### Perhatian

- Lakukan input pembelian **sebelum** penjualan, agar HPP memakai harga pokok yang benar.
- Membatalkan pembelian mengurangi stok. Lakukan segera setelah salah input, sebelum ada penjualan material tersebut.
