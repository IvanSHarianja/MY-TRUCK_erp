Untuk lini **Penjualan Material** (tanah urug, sirtu, batu, dll). Stok dan harga pokok dihitung otomatis.

### Material

Master barang: nama, satuan (mis. m³), dan harga jual. Kolom **Stok** dan **MAC (Harga Pokok)** terisi otomatis dari pembelian — tidak diinput manual.

> **MAC (Moving Average Cost)** = harga pokok rata-rata. Setiap pembelian baru menghitung ulang rata-rata dari stok lama + pembelian baru.

### Pembelian Material

Menu **Pembelian Material** → **Input Pembelian**.

- **Tunai** — pilih rekening kas/bank yang dipakai membayar.
- **Kredit** — wajib pilih vendor; tercatat sebagai utang vendor.

Setiap pembelian otomatis: menambah stok, menghitung ulang MAC, dan membuat jurnal (**Persediaan Material** bertambah, Kas berkurang / Utang bertambah). Akun yang dibutuhkan ada di [Paket Akun per Fitur](#coa-paket).

### Penjualan Material

Menu **Penjualan Material** → **Buat Penjualan Baru**. Pilih **Metode Pembayaran**:

- **Tunai (langsung kas)** — uang langsung masuk ke rekening yang dipilih.
- **Invoice (piutang)** — dibuatkan tagihan; pembayaran dicatat nanti di **Tagihan & Piutang**.

Stok berkurang otomatis dan **HPP** dijurnal memakai MAC saat penjualan.

### Perhatian

- Lakukan input pembelian **sebelum** penjualan, agar HPP memakai harga pokok yang benar.
- Membatalkan pembelian mengurangi stok. Lakukan segera setelah salah input, sebelum ada penjualan material tersebut.
