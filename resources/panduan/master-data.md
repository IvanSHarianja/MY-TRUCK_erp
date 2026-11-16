Data referensi yang dipakai berulang di transaksi. Semua ada di grup menu **Master Data**.

### Klien

Pelanggan yang ditagih (penyewa alat, pengguna jasa angkutan, pembeli material, pemberi proyek). Isi email klien bila ingin invoice dikirim otomatis lewat email. Kolom **Piutang Berjalan** menunjukkan tagihan yang belum dibayar.

### Vendor

Pemasok (material, sparepart, bengkel, subkontraktor). Wajib diisi untuk pembelian **kredit** agar utang tercatat atas nama vendor.

### Import Klien & Vendor dari Excel

Tombol **Import Excel** di halaman Klien dan Vendor memasukkan banyak data sekaligus dari file milik perusahaan (.xlsx / .xls / .csv).

- Kolom dikenali dari **judulnya**, jadi urutan kolom bebas: Kode, Nama (atau Keterangan), Alamat, No Telp, Email, Kontak, NPWP.
- Bila ada kolom **JENIS** (seperti sheet *Kode Bantu*), import Klien hanya mengambil baris **PIUTANG**, dan import Vendor hanya **HUTANG**. Baris lain (karyawan, biaya dibayar dimuka) dilewati.
- Bila satu sheet berisi beberapa tabel (mis. *Daftar Suplier* lalu *Daftar Konsumen*), tiap tabel dibaca terpisah sesuai judulnya.
- Tanpa kolom Kode, kode dibuat otomatis (KL-001 / VD-001). Jenis vendor ditebak dari keterangannya (sparepart, bengkel, BBM, kuari).
- Kode atau nama yang **sudah ada dilewati, tidak ditimpa**, jadi import aman diulang.

### Karyawan

Operator alat berat dan supir dump truck. Dipilih saat input jam kerja (rental) atau ritase (armada).

### Lini Bisnis

RENT, ARMD, MATL, BONG, dan UMUM. Setiap jurnal ditandai lini bisnisnya sehingga laporan **Laba Rugi per Lini** bisa memisahkan kinerja tiap lini. Biasanya tidak perlu diubah setelah setup awal.

> Nonaktifkan data yang sudah tidak dipakai (toggle **Aktif**) daripada menghapus — transaksi lama tetap merujuk ke data tersebut.
