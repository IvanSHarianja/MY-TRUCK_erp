<x-mail::message>
# Konfirmasi Pembayaran Diterima

Yth. **{{ $payment->invoice->client->name }}**,

Dengan ini kami konfirmasi bahwa pembayaran Anda telah kami terima:

<x-mail::panel>
**No. Bukti:** {{ $payment->payment_number }}
**Tanggal:** {{ \Carbon\Carbon::parse($payment->payment_date)->translatedFormat('d F Y') }}
**Untuk Invoice:** {{ $payment->invoice->invoice_number }}
**Metode:** {{ optional($payment->cashAccount)->name ?? '-' }}
**Nominal Diterima:** Rp {{ number_format($payment->amount, 0, ',', '.') }}

@if ((float) $payment->invoice->paid_amount < (float) $payment->invoice->amount)
**Status Invoice:** Sebagian (sisa Rp {{ number_format($payment->invoice->amount - $payment->invoice->paid_amount, 0, ',', '.') }})
@else
**Status Invoice:** ✓ LUNAS
@endif
</x-mail::panel>

Terima kasih atas pembayarannya. Senang bekerjasama dengan Anda.

Hormat kami
</x-mail::message>
