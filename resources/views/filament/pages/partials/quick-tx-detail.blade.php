@php
    $fmt = fn ($n) => $n > 0 ? 'Rp ' . number_format($n, 0, ',', '.') : '–';

    // Inline style dipakai untuk properti layout kritis (padding, gap, width kolom)
    // karena partial ini di-render dalam modal Filament yang kadang tidak ter-scan
    // oleh Tailwind JIT — class seperti p-2/w-32/space-y-3 bisa hilang. Typography
    // (font size, weight) tetap boleh pakai class Tailwind karena masuk base CSS.
    $labelStyle = 'font-size:11px;text-transform:uppercase;letter-spacing:0.04em;opacity:0.6;margin-bottom:2px;';
    $valueStyle = 'font-weight:600;font-size:14px;';
    $cellPad    = 'padding:8px 12px;';
    $numCol     = 'width:140px;';
@endphp

<div style="font-size:14px;line-height:1.5;">
    {{-- ===== META HEADER ===== --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin-bottom:16px;">
        <div>
            <div style="{{ $labelStyle }}">Tanggal</div>
            <div style="{{ $valueStyle }}">{{ $journal->entry_date->format('d/m/Y') }}</div>
        </div>
        <div>
            <div style="{{ $labelStyle }}">No. Dokumen</div>
            <div style="{{ $valueStyle }}">{{ $journal->document_number ?? '—' }}</div>
        </div>
        <div>
            <div style="{{ $labelStyle }}">Lini Bisnis</div>
            <div style="{{ $valueStyle }}">
                @if ($journal->businessUnit)
                    [{{ $journal->businessUnit->code }}] {{ $journal->businessUnit->name }}
                @else
                    –
                @endif
            </div>
        </div>
        <div>
            <div style="{{ $labelStyle }}">Status</div>
            <div style="{{ $valueStyle }}text-transform:uppercase;">{{ $journal->status }}</div>
        </div>
        <div style="grid-column:span 2;">
            <div style="{{ $labelStyle }}">Keterangan</div>
            <div>{{ $journal->description }}</div>
        </div>
    </div>

    {{-- ===== TABEL LINES ===== --}}
    <div style="border:1px solid rgba(127,127,127,0.25);border-radius:6px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:rgba(127,127,127,0.08);">
                    <th style="{{ $cellPad }}text-align:left;font-weight:600;">Akun</th>
                    <th style="{{ $cellPad }}{{ $numCol }}text-align:right;font-weight:600;">Debit</th>
                    <th style="{{ $cellPad }}{{ $numCol }}text-align:right;font-weight:600;">Kredit</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($journal->lines as $line)
                    <tr style="border-top:1px solid rgba(127,127,127,0.15);">
                        <td style="{{ $cellPad }}">
                            <div>[{{ $line->account->code ?? '—' }}] {{ $line->account->name ?? '—' }}</div>
                            @if ($line->description && $line->description !== $journal->description)
                                <div style="font-size:11px;opacity:0.6;margin-top:2px;">{{ $line->description }}</div>
                            @endif
                        </td>
                        <td style="{{ $cellPad }}{{ $numCol }}text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap;">
                            {{ $fmt($line->debit) }}
                        </td>
                        <td style="{{ $cellPad }}{{ $numCol }}text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap;">
                            {{ $fmt($line->kredit) }}
                        </td>
                    </tr>
                @endforeach
                <tr style="border-top:2px solid rgba(127,127,127,0.35);background:rgba(127,127,127,0.05);font-weight:700;">
                    <td style="{{ $cellPad }}text-align:right;">Total</td>
                    <td style="{{ $cellPad }}{{ $numCol }}text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap;">
                        {{ $fmt($journal->total_debit) }}
                    </td>
                    <td style="{{ $cellPad }}{{ $numCol }}text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap;">
                        {{ $fmt($journal->total_kredit) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- ===== BANNER JURNAL PEMBALIK (bila void & sudah punya reversal) ===== --}}
    @if ($journal->isVoid() && $journal->reversedBy)
        <div style="margin-top:16px;padding:12px 14px;border-radius:6px;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.4);">
            <div style="{{ $labelStyle }}">Jurnal Pembalik</div>
            <div style="font-weight:600;">
                {{ $journal->reversedBy->entry_number }}
                <span style="opacity:0.7;">— {{ $journal->reversedBy->entry_date->format('d/m/Y') }}</span>
            </div>
        </div>
    @endif
</div>
