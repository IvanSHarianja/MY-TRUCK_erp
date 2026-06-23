<x-mail::message>
# ⚠️ Pengingat: Invoice Sudah Jatuh Tempo

Yth. **{{ $invoice->client->name }}**,

Mohon maaf, kami mengingatkan bahwa invoice berikut **sudah melewati tanggal jatuh tempo** sebanyak **{{ $umurHari }} hari**:

<x-mail::panel>
**No. Invoice:** {{ $invoice->invoice_number }}
**Tanggal Invoice:** {{ \Carbon\Carbon::parse($invoice->invoice_date)->translatedFormat('d F Y') }}
**Jatuh Tempo:** {{ $invoice->due_date ? \Carbon\Carbon::parse($invoice->due_date)->translatedFormat('d F Y') : '-' }}
**Umur Invoice:** {{ $umurHari }} hari

**Total Tagihan:** Rp {{ number_format($invoice->amount, 0, ',', '.') }}
**Sudah Dibayar:** Rp {{ number_format($invoice->paid_amount, 0, ',', '.') }}
**Sisa Tagihan:** Rp {{ number_format($invoice->amount - $invoice->paid_amount, 0, ',', '.') }}
</x-mail::panel>

Mohon segera melakukan pembayaran. Jika ada kendala, silakan hubungi kami untuk diskusi solusi.

Terima kasih atas perhatiannya.

Hormat kami,<br>
**{{ $invoice->company->name }}**
</x-mail::message>
