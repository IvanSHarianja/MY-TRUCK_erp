<x-mail::message>
# Invoice Baru: {{ $invoice->invoice_number }}

Yth. **{{ $invoice->client->name }}**,

Kami sampaikan invoice baru atas transaksi dengan **{{ $invoice->company->name }}**:

<x-mail::panel>
**No. Invoice:** {{ $invoice->invoice_number }}
**Tanggal:** {{ \Carbon\Carbon::parse($invoice->invoice_date)->translatedFormat('d F Y') }}
**Jatuh Tempo:** {{ $invoice->due_date ? \Carbon\Carbon::parse($invoice->due_date)->translatedFormat('d F Y') : '-' }}
**Lini Bisnis:** {{ optional($invoice->businessUnit)->name ?? '-' }}

**Deskripsi:** {{ $invoice->description ?: 'Penagihan sesuai kontrak' }}

**Total Tagihan:** Rp {{ number_format($invoice->amount, 0, ',', '.') }}
</x-mail::panel>

Mohon dilakukan pembayaran sebelum tanggal jatuh tempo. Untuk konfirmasi pembayaran, silakan hubungi kami via email atau telepon.

Terima kasih atas kerjasamanya.

Hormat kami,<br>
**{{ $invoice->company->name }}**
</x-mail::message>
