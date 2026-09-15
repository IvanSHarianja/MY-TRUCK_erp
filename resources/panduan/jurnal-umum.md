Tempat semua jurnal — baik yang dibuat otomatis oleh sistem maupun yang diinput manual. Gunakan jurnal manual untuk transaksi yang tidak punya menu khusus: saldo awal, pindah dana antar rekening, koreksi, penyesuaian.

### Membuat jurnal manual

1. Isi **Tanggal**, **No. Bukti** (opsional), **Jenis Bukti**, lini bisnis, dan keterangan.
2. Tambahkan baris: pilih **sub-akun** lalu isi debit **atau** kredit (tidak boleh keduanya dalam satu baris).
3. Total debit harus sama dengan total kredit — indikator balance tampil di bawah baris.
4. Simpan → jurnal berstatus **draft** (belum mempengaruhi laporan).
5. Klik **Post** (atau **Post Jurnal** di halaman edit) → jurnal masuk ke laporan.

### Contoh: pindah dana antar rekening

Pindah Rp 3.000.000 dari BCA ke Mandiri:

| Akun | Debit | Kredit |
|---|---|---|
| Mandiri - 1234 (tujuan) | 3.000.000 | |
| BCA - 5678 (asal) | | 3.000.000 |

Total kas tidak berubah, jadi transaksi ini **tidak muncul sebagai arus kas** — mutasinya terlihat di **Posisi Kas per Rekening** pada laporan Arus Kas.

### Aturan No. Bukti

Format nomor milik sistem tidak bisa dipakai di jurnal manual, mis. *INV2609-…*, *PAY2609-…*, *DEPUSE-…*, *BBK-RL-…*, *DP-PR26-…*. Gunakan format sendiri seperti *KOR-001* dan sebutkan dokumen rujukan di keterangan.

### Draft, Post, Void

- **Draft** — masih bisa diedit atau dihapus.
- **Posted** — tidak bisa diedit. Untuk membatalkan gunakan **Void** (isi alasan): sistem membuat jurnal pembalik dan jurnal asli tidak lagi dihitung di laporan.
- Jurnal otomatis (invoice, biaya log, penyusutan) sebaiknya dibatalkan dari menu asalnya, bukan dari sini.
