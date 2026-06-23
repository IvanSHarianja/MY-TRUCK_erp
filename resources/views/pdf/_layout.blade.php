<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{{ $title ?? 'Laporan' }}</title>
<style>
    @page { margin: 18mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10px;
        color: #222;
        line-height: 1.4;
    }
    .header {
        text-align: center;
        margin-bottom: 16px;
        border-bottom: 2px solid #0F172A;
        padding-bottom: 10px;
    }
    .company-name {
        font-size: 16px;
        font-weight: bold;
        color: #0F172A;
        letter-spacing: 1px;
    }
    .report-title {
        font-size: 14px;
        font-weight: bold;
        margin-top: 6px;
    }
    .period {
        font-size: 11px;
        color: #555;
        margin-top: 4px;
    }
    table.rpt {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    table.rpt th {
        background: #E5E7EB;
        color: #1F2937;
        padding: 6px 8px;
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #9CA3AF;
    }
    table.rpt th.text-right { text-align: right; }
    table.rpt td {
        padding: 5px 8px;
        border-bottom: 1px solid #E5E7EB;
        font-size: 10px;
    }
    table.rpt td.text-right {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    table.rpt td.indent {
        padding-left: 24px;
    }
    table.rpt tr.section td {
        background: #F3F4F6;
        font-weight: bold;
        color: #0F172A;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    table.rpt tr.subtotal td {
        font-weight: bold;
        border-top: 1px solid #1F2937;
    }
    table.rpt tr.grand td {
        background: #0F172A;
        color: #fff;
        font-weight: bold;
        font-size: 11px;
        padding: 8px;
    }
    .footer {
        margin-top: 18px;
        text-align: center;
        font-size: 8px;
        color: #888;
        border-top: 1px solid #E5E7EB;
        padding-top: 6px;
    }
    .balance-check {
        margin-top: 10px;
        padding: 8px 12px;
        border-radius: 3px;
        font-weight: bold;
        font-size: 10px;
        text-align: center;
    }
    .balance-ok {
        background: #D1FAE5;
        color: #065F46;
        border: 1px solid #10B981;
    }
    .balance-bad {
        background: #FEE2E2;
        color: #991B1B;
        border: 1px solid #EF4444;
    }
</style>
</head>
<body>

<div class="header">
    <div class="company-name">{{ strtoupper($company->name) }}</div>
    <div class="report-title">{{ $reportTitle ?? 'LAPORAN' }}</div>
    <div class="period">Periode: {{ $periodLabel }}</div>
</div>

@yield('content')

<div class="footer">
    Dicetak otomatis oleh sistem MY-TRUCK · {{ \Carbon\Carbon::now()->translatedFormat('d M Y H:i') }}
</div>

</body>
</html>
