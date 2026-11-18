Tutup buku memindahkan laba/rugi satu tahun ke **Laba Ditahan** dan mengunci semua periodenya. Lakukan setiap awal tahun untuk tahun sebelumnya. Menu ini hanya untuk peran dengan izin **Close / Open Period** (default: Owner).

### Kapan dilakukan

Setelah **semua** transaksi dan penyesuaian tahun itu selesai:

- penyusutan Desember sudah dijalankan,
- invoice & pembayaran Desember sudah dicatat,
- koreksi dari auditor/akuntan sudah dijurnal.

### Cara

1. Buka **Laporan Keuangan → Tutup Buku**.
2. Pada tahun yang berstatus *Belum ditutup*, klik **Tutup Buku {tahun}** lalu konfirmasi.

Sistem lalu:

- membuat **jurnal penutup** tanggal 31 Desember (No. Bukti `TUTUP-{tahun}`): semua akun pendapatan & beban dinolkan, selisihnya masuk Laba Ditahan;
- mengunci 12 periode tahun itu — jurnal bertanggal tahun itu tidak bisa diinput lagi.

Tutup buku harus **berurutan**: 2026 dulu, baru 2027.

### Pengaruh ke laporan

| Laporan | Setelah tutup buku |
|---|---|
| **Laba Rugi** tahun itu | Tetap menampilkan laba tahun itu (jurnal penutup diabaikan) |
| **Neraca** | Laba tahun itu pindah ke baris Laba Ditahan; total tetap sama |
| **Neraca Saldo** | Akun pendapatan & beban kembali nol, Laba Ditahan bertambah |

> Selama tahun lalu **belum** ditutup, Neraca tetap seimbang dengan baris *"Laba/Rugi Tahun Lalu — belum ditutup buku"* dan peringatan tahun mana yang perlu ditutup.

### Buka kembali

Bila ada koreksi setelah tutup buku, klik **Buka Kembali** pada tahun terakhir yang ditutup dan isi alasannya. Jurnal penutup di-void dan periode dibuka. Setelah koreksi selesai, tutup buku lagi. Setiap tutup & buka buku tercatat di **Riwayat Aktivitas**.
