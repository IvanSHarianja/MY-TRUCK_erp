Semua invoice ke klien dan pembayarannya dikelola di **Tagihan & Piutang**.

### Dari mana invoice berasal

- **Otomatis** — dari aksi **Tagih** di Rental Alat Berat / Kontrak Armada, **Tagih Termin** di Proyek Borongan, atau penjualan material dengan metode Invoice. Invoice ini langsung terbit.
- **Manual** — **Buat Invoice Baru** untuk tagihan di luar alur di atas. Invoice manual tersimpan sebagai **draft** sampai Anda klik **Terbitkan**.

Saat terbit, sistem mencatat piutang dan pendapatan, lalu mengirim email ke klien bila email klien terisi.

### Terima pembayaran

Aksi **Terima Pembayaran** → isi **Tanggal Bayar**, rekening penerima (**Diterima ke Akun**), **Nominal**, dan nomor bukti transfer. Bukti transfer (foto) bisa dilampirkan.

- Pembayaran boleh **sebagian** (cicilan). Kolom **Sisa** menunjukkan yang belum dibayar.
- Status berubah otomatis: terbit → sebagian → lunas.

### Memantau piutang & keterlambatan

- Kolom **Keterlambatan** dihitung dari **jatuh tempo**: "Jatuh tempo 5 hari lagi", "Telat 12 hari", "Lunas tepat waktu", atau "Lunas, telat 3 hari" (dari tanggal pembayaran terakhir).
- Filter **Keterlambatan** memilah tagihan per kelompok: belum jatuh tempo, telat 1–30, 31–60, 61–90, lebih dari 90 hari, atau lunas tapi telat.
- **Cetak PDF** untuk mengirim invoice secara manual.

### Tanda tangan & cap di invoice

Invoice PDF ditandatangani atas nama **Nama Pemilik / Direktur** di **Profil Perusahaan** (kosong → "Bagian Keuangan"), dengan tanggal sesuai tanggal invoice.

- Owner dapat mengunggah **gambar tanda tangan** dan **cap perusahaan** (maks. 5 MB) di **Profil Perusahaan → Tanda Tangan & Cap Invoice**. Peran lain hanya melihat.
- Cukup **foto tanda tangan di kertas** dengan HP. Saat disimpan, latar kertas, bayangan, dan garis buku dihapus otomatis, warna tinta diratakan, dan gambar dipotong rapat ke area tanda tangan. Hasil terbaik: tinta gelap, cahaya rata, tanpa bayangan tangan.
- Bila tanda tangan diunggah, invoice mencantumkan bahwa dokumen *ditandatangani secara elektronik*. Tanpa gambar, invoice menyatakan *sah tanpa tanda tangan basah*.
- Invoice **Draft** atau **Void** tetap bisa dicetak untuk pratinjau/arsip, tetapi **tanpa tanda tangan & cap**, dengan tulisan besar DRAFT / VOID dan keterangan bahwa dokumen itu bukan tagihan yang sah.

### Laporan Piutang & export Excel

Tombol **Laporan Piutang** di atas tabel membuka laporan berisi:

- **Umur piutang per klien** per tanggal *Sampai*, termasuk rata-rata hari telat bayar tiap klien.
- **Invoice belum lunas**, diurutkan dari yang paling lama telat.
- **Riwayat pembayaran** dalam periode *Dari–Sampai*, dengan ketepatan tiap pembayaran.

Pilih klien tertentu bila perlu, lalu **Export Excel** untuk mengunduh ketiga bagian tersebut (3 sheet).

### Riwayat pembayaran per klien

Buka **Master Data → Klien → (pilih klien)**. Di atas tampil ringkasan total ditagih, sudah dibayar, sisa piutang, dan rata-rata telat bayar. Di bawahnya tab **Riwayat Pembayaran** berisi semua pembayaran klien itu beserta ketepatannya.

### Void invoice

Aksi **Void** membatalkan invoice dan membalik jurnalnya. Log jam kerja/ritase/termin yang ditagih lewat invoice tersebut **kembali berstatus belum ditagih** — bisa dikoreksi lalu ditagih ulang.

> Invoice yang **sudah ada pembayarannya tidak bisa di-void**. Pembatalan pembayaran belum tersedia di aplikasi — hubungi admin sistem. Jangan mem-void jurnal pembayaran (No. Bukti *PAY…*) dari Jurnal Umum: kas akan terbalik, tetapi status invoice tidak ikut berubah.
