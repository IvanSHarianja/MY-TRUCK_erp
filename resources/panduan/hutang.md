Menu **Hutang & Cicilan** mencatat pinjaman bank, leasing unit, dan hutang vendor beserta **jadwal jatuh temponya**, lalu mencatat pembayarannya sekaligus jurnalnya.

### Mencatat hutang

**Catat Hutang** → isi:

| Isian | Keterangan |
|---|---|
| Jenis & Kreditur | Pinjaman Bank / Leasing / Hutang Vendor / Lainnya, dan nama kreditur |
| Akun Hutang | Akun kewajiban, mis. Utang Bank, Utang Leasing |
| Pokok, Bunga, Tenor | Bunga **flat % per tahun**; tenor dalam bulan (hutang sekali bayar: 1) |
| Tanggal Mulai & Jatuh Tempo Pertama | Cicilan berikutnya jatuh tempo di tanggal yang sama tiap bulan |
| Dana Diterima di | **Isi** bila dana pinjaman baru masuk rekening → jurnal Kas / Hutang. **Kosongkan** untuk hutang lama yang sudah tercatat di saldo awal |

Jadwal cicilan langsung terbentuk: pokok dibagi rata (sisa pembulatan di cicilan terakhir), bunga sama tiap bulan.

### Memantau jatuh tempo

- Angka di menu = jumlah cicilan **telat** (merah) atau jatuh tempo **≤ 7 hari** (kuning).
- Bagian atas daftar menampilkan **Jatuh Tempo 30 Hari ke Depan** untuk semua hutang.
- Kolom **Jatuh Tempo Berikutnya** menunjukkan tanggal, sisa hari / hari telat, dan nominalnya. Filter **Telat / jatuh tempo ≤ 7 hari** memilah yang perlu segera dibayar.

### Membayar cicilan

Buka hutang (**Jadwal & Bayar**) → pada cicilan klik **Bayar** → tanggal bayar, rekening, bunga (bisa disesuaikan dengan tagihan bank), dan denda bila ada.

Jurnal otomatis: **Utang** (pokok) + **Beban Bunga** (bunga + denda) / **Kas**. Setelah semua cicilan dibayar, status hutang menjadi **Lunas**.

- **Batal Bayar** membatalkan jurnal pembayaran dan mengembalikan cicilan ke *belum dibayar*.
- Jurnal pencairan & pembayaran cicilan (No. Bukti *HT…*) **tidak bisa di-void dari Jurnal Umum** — gunakan Batal Bayar / Hapus di menu ini supaya status cicilan ikut berubah.
- Hutang hanya bisa **dihapus** selama belum ada cicilan dibayar.

### Hutang vendor dari pembelian kredit

Pembelian material dengan metode **Kredit** otomatis masuk ke menu ini sebagai hutang vendor sekali bayar, dengan jatuh tempo dari form pembelian. Utangnya sudah dijurnal saat pembelian, jadi tidak tercatat dua kali. Bila pembeliannya dibatalkan (void jurnal *PB…*), hutangnya ikut terhapus.

### Satu pintu pembayaran

Selama masih ada hutang **berjalan** di menu ini untuk sebuah akun utang (mis. Utang Vendor), transaksi **Bayar Utang** di menu Transaksi untuk akun itu **ditolak** — bayar dari sini supaya cicilannya tercatat lunas dan tidak terbayar dua kali. Hutang vendor lama yang belum ada di menu ini: catat dulu lewat **Catat Hutang** dengan **Dana Diterima di** dikosongkan.

> Membutuhkan akun **Beban Bunga** (peran *opex_bunga* / kode 552700) bila ada bunga atau denda.
