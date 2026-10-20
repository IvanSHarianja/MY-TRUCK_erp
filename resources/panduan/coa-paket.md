Fitur otomatis MY-TRUCK selalu menjurnal ke **pasangan akun**. Kalau salah satu akun dalam paket tidak ada (atau nonaktif, atau Perannya belum diisi), fitur tersebut gagal atau jurnalnya tidak lengkap. Gunakan tabel di bawah sebagai checklist setiap kali menambah akun atau mulai memakai fitur baru.

Kolom **Peran** adalah pilihan di field *Peran Akun (Role)*. Kode dalam kurung adalah kode di template standar — bila Anda memakai kode sendiri, yang penting Perannya benar.

> Template **Standar** saat daftar PT sudah berisi semua akun di halaman ini. Checklist ini terutama penting bila Anda memilih **Kosongan**, **Import Excel**, atau menambah/menonaktifkan akun sendiri.

### Akun inti — dipakai lintas fitur

| Akun | Peran | Dipakai untuk | Tingkat |
|---|---|---|---|
| Kas & Bank (sub-akun 111100) | Kas & Bank Utama | Semua penerimaan dan pembayaran | **Wajib** |
| Piutang Usaha (111200) | Piutang Usaha | Semua invoice | **Wajib** |
| Utang Usaha Vendor (221100) | Utang Usaha Vendor | Pembelian kredit, Pembayaran Utang Usaha | Wajib bila membeli secara kredit |
| Modal Pemilik (331100) | Modal Pemilik / Disetor | Saldo awal, Setoran Modal | Sangat disarankan |
| Prive (331200) | Prive / Pengambilan Modal | Pengambilan Pribadi | Bila dipakai |
| Laba Ditahan (331300) | Laba Ditahan | Laporan Perubahan Ekuitas | Disarankan — tanpa akun ini nilainya tampil 0 |

### Menambah rekening bank atau kas

| Jika menambah… | Wajib | Catatan |
|---|---|---|
| Rekening bank baru | Buat sebagai **sub-akun 111100** dengan Peran **Kas & Bank Utama** | Pakai **+ Sub-Akun** pada 111100 — peran otomatis terwarisi |
| Kas kecil lapangan baru | Sub-akun **111110**, Peran **Kas Kecil Lapangan** | |
| Rekening apa pun | Jurnal **Saldo Awal** untuk rekening itu | Tanpa saldo awal, rekening tampil minus |

### Menambah aset tetap

Setiap kelompok aset tetap butuh **tiga akun sekaligus**: akun aset, akun akumulasi penyusutannya, dan beban penyusutan.

| Jenis aset (di Armada & Alat Berat) | Akun Aset | Akun Akumulasi (wajib) | Beban (wajib) |
|---|---|---|---|
| Dump truck, excavator, bulldozer, wheel loader | Aset Tetap — Armada & Alat Berat (112100) | Akumulasi Penyusutan Armada (112105) | Beban Penyusutan (552100) |
| Kendaraan operasional | Aset Tetap — Kendaraan Operasional (112120) | Akumulasi Penyusutan Kendaraan (112125) | Beban Penyusutan (552100) |
| Peralatan kantor & lainnya | Aset Tetap — Peralatan Kantor (112110) | Akumulasi Penyusutan Peralatan (112115) | Beban Penyusutan (552100) |

- Akun akumulasi: Kategori **Aset**, Sub-Kategori **Aset Tetap**, Saldo Normal **Kredit**.
- Menambah unit di **Armada & Alat Berat** tidak otomatis mencatat pembelian aset. Catat pembeliannya lewat **Jurnal Umum**: debit akun Aset Tetap, kredit Kas/Bank atau Utang.

### Paket per lini bisnis

**Rental Alat Berat (RENT)**

| Akun | Peran | Wajib jika… |
|---|---|---|
| Pendapatan Sewa Alat Berat (441100) | Pendapatan Sewa Alat Berat (RENT) | Selalu (untuk Tagih) |
| Beban BBM Solar (551100) | Beban BBM Solar | Ada kontrak **All In** |
| Beban Gaji & Tunjangan (552200) | Beban Gaji & Tunjangan | Ada kontrak **All In** atau **Semi** |
| Beban Premi & Uang Jalan (551200) | Beban Premi & Uang Jalan | Kontrak mengisi premi operator |
| Akumulasi Armada + Beban Penyusutan | lihat tabel aset tetap | Unit memakai penyusutan **Per Jam Kerja** |

**Jasa Angkutan Armada (ARMD)**

| Akun | Peran | Wajib jika… |
|---|---|---|
| Pendapatan Ritase Dump Truck (441200) | Pendapatan Ritase Dump Truck (ARMD) | Selalu (untuk Tagih) |
| Beban BBM Solar (551100) | Beban BBM Solar | Ada kontrak **All In** |
| Beban Gaji & Tunjangan (552200) | Beban Gaji & Tunjangan | Ada kontrak **All In** atau **Semi** (gaji & uang makan supir) |
| Beban Premi & Uang Jalan (551200) | Beban Premi & Uang Jalan | Kontrak mengisi uang jalan atau premi |
| Akumulasi Armada + Beban Penyusutan | lihat tabel aset tetap | Dump truck memakai penyusutan **Per Rit** |

> **Penting untuk Rental & Armada:** bila akun beban BBM, gaji, atau premi tidak ada/nonaktif, jurnal biaya log saat ini tetap tercatat **tanpa sisi debit**, sehingga Neraca Saldo tidak balance. Pastikan paket akun ini lengkap **sebelum** input jam kerja atau ritase pertama.

**Penjualan Material (MATL)**

| Akun | Peran | Dipakai saat |
|---|---|---|
| Pendapatan Penjualan Material (441300) | Pendapatan Penjualan Material (MATL) | Setiap penjualan |
| Persediaan Material (111260) | **Persediaan Material** | Pembelian material (persediaan bertambah) & HPP (persediaan berkurang) |
| Beban HPP Material (551300) | Beban HPP Material | Setiap penjualan (HPP) |
| Utang Usaha Vendor (221100) | Utang Usaha Vendor | Pembelian **kredit** |

Persediaan material terpisah dari **Persediaan Solar (Depo) (111220)**. Cukup **satu** akun berperan Persediaan Material — pembelian dan HPP selalu memakai akun yang sama.

> **PT yang dibuat sebelum peran ini ada** belum punya akun Persediaan Material; sistem tetap mencatat persediaan material ke **111220 Persediaan Solar (Depo)** seperti sebelumnya. Untuk memisahkannya, lakukan langkah berikut **di hari yang sama, sebelum ada pembelian atau penjualan material berikutnya**:
>
> 1. Di **Daftar Akun**, buat akun **111260 Persediaan Material** — Kategori *Aset*, Peran *Persediaan Material*.
> 2. Hitung nilai persediaan material saat ini: di menu **Material**, kalikan **Stok × MAC (Harga Pokok)** tiap material, lalu jumlahkan.
> 3. Buat jurnal di **Jurnal Umum** (Jenis Bukti *Penyesuaian*) sebesar nilai tersebut: debit *Persediaan Material*, kredit *Persediaan Solar (Depo)*, lalu **Post**.
>
> Kalau langkah 3 dilewati, HPP penjualan stok lama akan mengurangi akun baru sehingga saldonya minus, sementara nilai stok lama tertinggal di akun solar.

**Borongan Pengurugan (BONG)**

| Akun | Peran | Dipakai saat |
|---|---|---|
| Pendapatan Borongan Pengurugan (441400) | Pendapatan Borongan Pengurugan (BONG) | Tagih Termin |
| Uang Muka Proyek Diterima (221170) | Uang Muka Proyek Diterima | Terima DP (Kategori **Kewajiban**, bukan pendapatan) |

**Invoice manual dengan lini UMUM** memakai **Pendapatan Lain-lain (441900)**, Peran *Pendapatan Lain-lain* — kecuali Anda memilih akun pendapatan lain secara manual di form invoice.

### Pemeliharaan aset

| Akun | Peran | Wajib jika… |
|---|---|---|
| Beban Maintenance & Suku Cadang (551400) | Beban Maintenance & Sparepart | Selalu |
| Kas & Bank | Kas & Bank Utama | Ada biaya jasa/montir yang dibayar |
| Persediaan Sparepart (111270) | **Persediaan Sparepart** | Memakai sparepart dari stok |

**Sparepart dari stok.** Material berjenis *Sparepart* dibeli lewat **Pembelian Material**
(Dr Persediaan Sparepart) lalu dipakai saat servis (Cr Persediaan Sparepart, Dr Beban
Maintenance). Biayanya baru muncul di Laba Rugi **saat dipakai**, bukan saat dibeli.

> Tanpa akun Persediaan Sparepart, servis yang memakai sparepart dari stok **ditolak** —
> stok tidak akan berkurang diam-diam. Servis tanpa sparepart tidak terpengaruh.

### Transaksi & Beban

Setiap jenis transaksi mencari satu akun tetap berdasarkan Peran:

| Jenis transaksi | Peran akun yang dicari |
|---|---|
| Beban Solar / BBM | Beban BBM Solar |
| Beban Gaji & Tunjangan | Beban Gaji & Tunjangan |
| Beban Maintenance & Sparepart | Beban Maintenance & Sparepart |
| Beban Mobilisasi / Demobilisasi | Beban Mobilisasi/Demobilisasi |
| Beban Subkontraktor | Beban Subkontraktor |
| Beban Pajak & Perizinan | Beban Pajak & Perizinan |
| Beban Administrasi & Umum | Beban Administrasi & Umum |
| Beban Penyusutan (penyesuaian) | Beban Penyusutan |
| Pendapatan Lain-lain | Pendapatan Lain-lain |
| Pembayaran Utang Usaha | Utang Usaha Vendor |
| Setoran Modal Pemilik | Modal Pemilik / Disetor |
| Pengambilan Pribadi (Prive) | Prive / Pengambilan Modal |

Lawannya adalah rekening Kas/Bank yang Anda pilih, atau akun utang vendor yang Anda pilih untuk metode **Utang (belum dibayar)**.

### Apa yang terjadi kalau akun dalam paket tidak ada

| Fitur | Akibat |
|---|---|
| Invoice (Tagih, Tagih Termin, Terbitkan), Terima DP, Pembelian Material, pendapatan Penjualan Material, Transaksi & Beban | **Ditolak** dengan pesan yang menyebut akun yang kurang — aman, tinggal lengkapi akunnya lalu ulangi |
| Biaya log jam kerja / ritase — akun **Kas** tidak ada | Jurnal biaya **dilewati tanpa pesan** — biaya tidak tercatat |
| Biaya log jam kerja / ritase — akun **beban** tidak ada | Jurnal tercatat **tidak balance** (lihat peringatan di atas) |
| Pemeliharaan aset | Jurnal biaya **dilewati tanpa pesan** |
| HPP penjualan material | Jurnal HPP **dilewati** — laba kotor terlihat terlalu besar |
| Penyusutan per jam / per rit | Jurnal penyusutan **dilewati tanpa pesan** |
| Penyusutan bulanan otomatis | Penyusutan bulan itu **tidak terbentuk** |

Karena beberapa fitur gagal tanpa pesan, periksa paket akun **sebelum** mulai bertransaksi, bukan setelah laporan terlihat janggal.
