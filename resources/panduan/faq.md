### Rekening bank tampil minus (dalam kurung) di Neraca

Biasanya saldo awal rekening tersebut belum dicatat. Buat jurnal dengan Jenis Bukti **Saldo Awal** (lihat *Langkah Awal Menyiapkan PT*). Kemungkinan lain: pembayaran dicatat dari rekening yang salah.

### Arus Kas kosong setelah memindahkan dana antar rekening

Normal. Pindah dana antar rekening sendiri tidak mengubah total kas, sehingga bukan arus kas. Mutasinya terlihat di **Posisi Kas per Rekening** di bawah laporan Arus Kas.

### Neraca "TIDAK BALANCE"

1. Buka **Neraca Saldo** — pastikan total debit = total kredit.
2. Periksa di **Daftar Akun** apakah ada akun dengan kategori/sub-kategori yang tidak sesuai (mis. akun bank berkategori beban).
3. Bila masih tidak balance, hubungi admin sistem dengan menyertakan periode laporan.

### Tidak bisa void invoice

Invoice yang sudah punya pembayaran tidak bisa di-void. Pembatalan pembayaran belum tersedia di aplikasi — hubungi admin sistem.

### Log jam kerja / ritase tidak bisa diedit

Log sudah ditagih. Void invoice-nya terlebih dahulu (hanya bisa bila belum ada pembayaran); log akan kembali bisa diedit dan ditagih ulang.

### Jurnal tidak bisa di-post

- Total debit ≠ kredit.
- Ada baris yang memakai akun **HEADER** — pilih sub-akunnya.
- Periode tanggal jurnal sudah ditutup buku — hubungi admin sistem.

### No. Bukti ditolak saat membuat jurnal

Format tersebut dipakai jurnal otomatis sistem. Gunakan format lain, mis. *KOR-001*.

### Tombol Export PDF atau menu laporan tidak muncul

Role Anda tidak punya izin *Lihat Laporan Keuangan* atau *Export PDF Laporan*. Minta Owner mengaturnya di **Role Akses**.

### Metode penyusutan aset tidak bisa diubah

Aset sudah punya jurnal penyusutan. Void jurnal penyusutan terkait dulu, atau buat aset baru bila memang terjadi perubahan kebijakan.
