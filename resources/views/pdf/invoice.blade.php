<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Invoice {{ $invoice->invoice_number }}</title>
<style>
    @page { margin: 20mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
        color: #222;
        line-height: 1.5;
    }
    .header {
        display: table;
        width: 100%;
        margin-bottom: 18px;
        border-bottom: 2px solid #0F172A;
        padding-bottom: 12px;
    }
    .header-left, .header-right {
        display: table-cell;
        vertical-align: top;
    }
    .header-right {
        text-align: right;
    }
    .company-name {
        font-size: 18px;
        font-weight: bold;
        color: #0F172A;
        letter-spacing: 0.5px;
    }
    .company-info {
        font-size: 10px;
        color: #555;
        margin-top: 4px;
        line-height: 1.5;
    }
    .invoice-title {
        font-size: 24px;
        font-weight: bold;
        color: #0F172A;
        letter-spacing: 2px;
    }
    .invoice-number {
        font-size: 14px;
        color: #555;
        margin-top: 4px;
    }
    .meta {
        display: table;
        width: 100%;
        margin-bottom: 18px;
    }
    .meta-left, .meta-right {
        display: table-cell;
        vertical-align: top;
        width: 50%;
    }
    .meta-right {
        text-align: right;
    }
    .meta-label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #888;
        font-weight: 600;
    }
    .meta-value {
        font-size: 11px;
        color: #222;
        margin-bottom: 6px;
        font-weight: 600;
    }
    .client-box {
        background: #F8FAFC;
        padding: 12px 14px;
        border-radius: 4px;
        margin-bottom: 16px;
        border-left: 3px solid #0F172A;
    }
    .client-label {
        font-size: 9px;
        text-transform: uppercase;
        color: #888;
        letter-spacing: 1px;
        font-weight: 600;
    }
    .client-name {
        font-size: 14px;
        font-weight: bold;
        color: #0F172A;
        margin-top: 2px;
    }
    .client-detail {
        font-size: 10px;
        color: #555;
        margin-top: 4px;
    }
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
    }
    table.items th {
        background: #0F172A;
        color: #fff;
        padding: 9px 12px;
        text-align: left;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    table.items th.text-right {
        text-align: right;
    }
    table.items td {
        padding: 10px 12px;
        border-bottom: 1px solid #E5E7EB;
        font-size: 11px;
    }
    table.items td.text-right {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .total-section {
        display: table;
        width: 100%;
        margin-top: 8px;
    }
    .total-left {
        display: table-cell;
        width: 55%;
        vertical-align: top;
    }
    .total-right {
        display: table-cell;
        width: 45%;
        vertical-align: top;
    }
    .total-table {
        width: 100%;
        border-collapse: collapse;
    }
    .total-table td {
        padding: 6px 0;
        font-size: 11px;
    }
    .total-table td:first-child {
        color: #555;
    }
    .total-table td.amount {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
        font-weight: 600;
    }
    .total-table tr.grand td {
        font-size: 14px;
        font-weight: bold;
        border-top: 2px solid #0F172A;
        padding-top: 10px;
        color: #0F172A;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .status-terbit { background: #DBEAFE; color: #1E40AF; }
    .status-sebagian { background: #FEF3C7; color: #92400E; }
    .status-lunas { background: #D1FAE5; color: #065F46; }
    .status-draft { background: #F3F4F6; color: #6B7280; }
    .status-void { background: #FEE2E2; color: #991B1B; }
    .terms {
        margin-top: 24px;
        padding: 12px 14px;
        background: #F9FAFB;
        border-radius: 4px;
        font-size: 10px;
        color: #555;
        line-height: 1.7;
    }
    .terms-title {
        font-weight: bold;
        color: #0F172A;
        margin-bottom: 4px;
    }
    .signature {
        margin-top: 30px;
        display: table;
        width: 100%;
    }
    .sign-block {
        display: table-cell;
        width: 33%;
        vertical-align: top;
        text-align: center;
    }
    .sign-label {
        font-size: 10px;
        color: #555;
        margin-bottom: 50px;
    }
    .sign-line {
        border-top: 1px solid #222;
        padding-top: 4px;
        font-size: 10px;
        font-weight: 600;
    }
    .footer {
        margin-top: 24px;
        text-align: center;
        font-size: 9px;
        color: #888;
        border-top: 1px solid #E5E7EB;
        padding-top: 8px;
    }
</style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <div class="company-name">{{ strtoupper($company->name) }}</div>
        <div class="company-info">
            @if ($company->address){{ $company->address }}<br>@endif
            @if ($company->phone)Tel: {{ $company->phone }}<br>@endif
            @if ($company->email)Email: {{ $company->email }}@endif
        </div>
    </div>
    <div class="header-right">
        <div class="invoice-title">INVOICE</div>
        <div class="invoice-number">{{ $invoice->invoice_number }}</div>
        <div style="margin-top: 8px;">
            <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
        </div>
    </div>
</div>

<div class="meta">
    <div class="meta-left">
        <div class="client-box">
            <div class="client-label">Ditagihkan Kepada</div>
            <div class="client-name">{{ $invoice->client->name }}</div>
            <div class="client-detail">
                @if ($invoice->client->address){{ $invoice->client->address }}<br>@endif
                @if ($invoice->client->phone)Tel: {{ $invoice->client->phone }}<br>@endif
                @if ($invoice->client->npwp)NPWP: {{ $invoice->client->npwp }}@endif
            </div>
        </div>
    </div>
    <div class="meta-right">
        <div class="meta-label">Tanggal Invoice</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($invoice->invoice_date)->translatedFormat('d F Y') }}</div>

        @if ($invoice->due_date)
        <div class="meta-label">Jatuh Tempo</div>
        <div class="meta-value">{{ \Carbon\Carbon::parse($invoice->due_date)->translatedFormat('d F Y') }}</div>
        @endif

        @if ($invoice->businessUnit)
        <div class="meta-label">Lini Bisnis</div>
        <div class="meta-value">[{{ $invoice->businessUnit->code }}] {{ $invoice->businessUnit->name }}</div>
        @endif
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 60%;">Deskripsi</th>
            <th class="text-right" style="width: 40%;">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $invoice->description ?: 'Penagihan jasa/produk sesuai kontrak' }}</td>
            <td class="text-right">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="total-section">
    <div class="total-left"></div>
    <div class="total-right">
        <table class="total-table">
            <tr>
                <td>Subtotal</td>
                <td class="amount">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
            </tr>
            @if ((float) $invoice->paid_amount > 0)
            <tr>
                <td>Sudah Dibayar</td>
                <td class="amount" style="color: #16a34a;">- Rp {{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td>{{ $invoice->isLunas() ? 'TOTAL DIBAYAR' : 'TOTAL TAGIHAN' }}</td>
                <td class="amount">Rp {{ number_format((float) $invoice->amount - (float) $invoice->paid_amount, 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>
</div>

@if ($invoice->payments->count() > 0)
<div style="margin-top: 16px;">
    <div style="font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;">Riwayat Pembayaran</div>
    <table class="items">
        <thead>
            <tr>
                <th>No. Bukti</th>
                <th>Tanggal</th>
                <th>Metode</th>
                <th class="text-right">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->payments as $payment)
            <tr>
                <td>{{ $payment->payment_number }}</td>
                <td>{{ \Carbon\Carbon::parse($payment->payment_date)->translatedFormat('d M Y') }}</td>
                <td>{{ optional($payment->cashAccount)->name ?? '—' }}</td>
                <td class="text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="terms">
    <div class="terms-title">Ketentuan Pembayaran:</div>
    1. Pembayaran dapat ditransfer ke rekening yang tercantum atau dibayarkan tunai ke kantor kami.<br>
    2. Mohon mencantumkan nomor invoice <strong>{{ $invoice->invoice_number }}</strong> saat melakukan transfer.<br>
    3. Invoice ini sah tanpa tanda tangan & cap. Konfirmasi pembayaran via email atau telepon.
</div>

<div class="signature">
    <div class="sign-block"></div>
    <div class="sign-block"></div>
    <div class="sign-block">
        <div class="sign-label">{{ $company->name }}<br>{{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</div>
        <div class="sign-line">{{ $company->owner_name ?? 'Bagian Keuangan' }}</div>
    </div>
</div>

<div class="footer">
    Dicetak otomatis oleh sistem MY-TRUCK · {{ \Carbon\Carbon::now()->translatedFormat('d M Y H:i') }}
</div>

</body>
</html>
