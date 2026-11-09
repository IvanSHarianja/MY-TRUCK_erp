Menu **Laporan Keuangan → Backup Excel (SOP)** membuat salinan data MY-TRUCK dalam format **file SOP Armada** (6 file, satu per unduhan). File bisa dibuka di Excel atau diunggah ke Google Sheets, dan rumus serta laporan di dalamnya langsung terisi.

**Cara memakai**

1. Pilih **File SOP** yang ingin diunduh.
2. Pilih **Dari tanggal** dan **Sampai tanggal**. Disarankan per tahun buku, misalnya 1 Januari s.d. hari ini.
3. Klik **Unduh Backup Excel**, lalu tunggu sampai unduhan muncul (20 detik – 1,5 menit; file Finance paling lama).
4. Ulangi untuk file lain, lalu simpan semuanya di tempat aman di luar komputer server.

**Isi tiap file**

- **SOP Finance**: COA dengan saldo awal, klien, vendor, jurnal, tagihan, pembayaran, dan Master Unit untuk laba rugi per unit.
- **SOP Aset & Workshop**: master unit (dengan akumulasi penyusutan), master karyawan, pemeliharaan, dan penyusutan bulanan dari jurnal.
- **SOP Operasional Armada**: kontrak armada, ritase harian beserta biayanya, dan penagihan armada.
- **SOP Operasional Alat Berat**: kontrak rental, jam kerja harian beserta biayanya, dan penagihan rental.
- **SOP Material**: master material (stok & harga pokok), pembelian, dan penjualan material.
- **SOP Proyek Borongan**: proyek, progress fisik, DP, dan termin.

**Perlu diketahui**

- Angka dan nomor dokumen sama dengan MY-TRUCK (mis. INV2603-0001, KA26-0001), bukan nomor otomatis SOP. Biaya ritase/jam kerja = perhitungan MY-TRUCK.
- Data sebelum tanggal DARI diringkas: saldo awal akun, akumulasi penyusutan, stok awal material, serta total rit/jam dan DP per kontrak/proyek.
- Jurnal void dan jurnal pembalik tidak disertakan, sama seperti laporan MY-TRUCK.
- Sheet yang di template menarik data dari file lain (Master Unit, Master Karyawan, Master Klien, Ritase, dll.) diisi **salinan statis**, jadi tiap file bisa dibuka sendiri tanpa internet.
- Kolom *Approval* di SOP diisi "Disetujui", karena data yang sudah tersimpan di MY-TRUCK dianggap sah.
- Bila jumlah data melebihi kapasitas template (mis. 3.000 ritase atau 10.000 baris jurnal), pilih periode yang lebih pendek.
- Hanya **Owner** dan **Admin** yang bisa mengunduh, dan setiap unduhan tercatat di Riwayat Aktivitas.
- File ini adalah salinan data, **bukan pengganti backup database**.
