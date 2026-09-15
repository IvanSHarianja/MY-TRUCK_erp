Semua invoice ke klien dan pembayarannya dikelola di **Tagihan & Piutang**.

### Dari mana invoice berasal

- **Otomatis** — dari aksi **Tagih** di Rental Alat Berat / Kontrak Armada, **Tagih Termin** di Proyek Borongan, atau penjualan material dengan metode Invoice. Invoice ini langsung terbit.
- **Manual** — **Buat Invoice Baru** untuk tagihan di luar alur di atas. Invoice manual tersimpan sebagai **draft** sampai Anda klik **Terbitkan**.

Saat terbit, sistem mencatat piutang dan pendapatan, lalu mengirim email ke klien bila email klien terisi.

### Terima pembayaran

Aksi **Terima Pembayaran** → isi **Tanggal Bayar**, rekening penerima (**Diterima ke Akun**), **Nominal**, dan nomor bukti transfer. Bukti transfer (foto) bisa dilampirkan.

- Pembayaran boleh **sebagian** (cicilan). Kolom **Sisa** menunjukkan yang belum dibayar.
- Status berubah otomatis: terbit → sebagian → lunas.

### Memantau piutang

- Kolom **Umur** dan filter **Aging** menunjukkan berapa lama tagihan belum dibayar.
- Filter **Jatuh Tempo** untuk melihat tagihan yang sudah lewat tempo.
- **Cetak PDF** untuk mengirim invoice secara manual.

### Void invoice

Aksi **Void** membatalkan invoice dan membalik jurnalnya. Log jam kerja/ritase/termin yang ditagih lewat invoice tersebut **kembali berstatus belum ditagih** — bisa dikoreksi lalu ditagih ulang.

> Invoice yang **sudah ada pembayarannya tidak bisa di-void**. Pembatalan pembayaran belum tersedia di aplikasi — hubungi admin sistem. Jangan mem-void jurnal pembayaran (No. Bukti *PAY…*) dari Jurnal Umum: kas akan terbalik, tetapi status invoice tidak ikut berubah.
