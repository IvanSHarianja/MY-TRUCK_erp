@extends('pdf._layout', ['reportTitle' => 'LAPORAN PENYUSUTAN PER ASET'])

@use('App\Services\Accounting\AssetDepreciationReportService')

@php
    $fmt = fn ($n) => round((float) $n, 2) == 0 ? '–' : number_format((float) $n, 0, ',', '.');
@endphp

@section('content')
    @if ($filters['type'] ?? null)
        <div style="margin-bottom: 4px; font-size: 10px; font-style: italic;">
            Filter jenis aset: {{ AssetDepreciationReportService::typeLabel($filters['type']) }}
        </div>
    @endif
    @if ($filters['method'] ?? null)
        <div style="margin-bottom: 4px; font-size: 10px; font-style: italic;">
            Filter metode: {{ $filters['method'] }}
        </div>
    @endif

    <div style="margin-bottom: 8px; font-size: 10px;">
        {{ $period_label }} (as of {{ \Carbon\Carbon::parse($as_of)->translatedFormat('d F Y') }})
    </div>

    @if (empty($rows))
        <div style="padding: 24px; text-align: center; font-size: 11px; color: #666;">
            Belum ada aset dengan filter ini.
        </div>
    @else
        <table class="rpt" style="font-size: 9px;">
            <thead>
                <tr>
                    <th style="width: 16%;">Kode / Nama</th>
                    <th style="width: 10%;">Jenis</th>
                    <th style="width: 12%;">Metode</th>
                    <th style="width: 8%;">Tgl Beli</th>
                    <th class="text-right" style="width: 12%;">Harga Beli</th>
                    <th class="text-right" style="width: 12%;">Akumulasi</th>
                    <th class="text-right" style="width: 12%;">Nilai Buku</th>
                    <th class="text-right" style="width: 8%;">Sisa Umur</th>
                    <th class="text-right" style="width: 10%;">Est. Bln Depan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @if ($row['fully_depreciated']) style="background: #FEF3C7;" @endif>
                        <td>
                            <strong>[{{ $row['asset_code'] }}]</strong><br>
                            <span style="font-size: 8px; color: #555;">
                                {{ $row['name'] }}
                                @if ($row['status'] === 'non_aktif')
                                    <span style="color: #b91c1c;">(non-aktif)</span>
                                @endif
                            </span>
                        </td>
                        <td style="font-size: 8px;">
                            {{ AssetDepreciationReportService::typeLabel($row['type']) }}
                        </td>
                        <td style="font-size: 8px;">{{ $row['method_label'] }}</td>
                        <td style="font-size: 8px;">
                            {{ $row['purchase_date'] ? \Carbon\Carbon::parse($row['purchase_date'])->format('d-m-Y') : '–' }}
                        </td>
                        <td class="text-right">{{ $fmt($row['purchase_price']) }}</td>
                        <td class="text-right" style="color: #991B1B;">{{ $fmt($row['akumulasi']) }}</td>
                        <td class="text-right" style="font-weight: 600;">{{ $fmt($row['nilai_buku']) }}</td>
                        <td class="text-right" style="font-size: 8px;">
                            {{ number_format((float) $row['sisa_umur'], (float) $row['sisa_umur'] == (int) $row['sisa_umur'] ? 0 : 2, ',', '.') }}
                            <span style="color: #666;">{{ $row['sisa_umur_unit'] }}</span>
                        </td>
                        <td class="text-right" style="font-size: 8px;">
                            @if ($row['method'] === 'straight_line')
                                {{ $fmt($row['next_month_dep']) }}
                            @else
                                <span style="color: #999;">usage-based</span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <tr class="subtotal" style="background: #F3F4F6; font-weight: 700; border-top: 2px solid #333;">
                    <td colspan="4">TOTAL</td>
                    <td class="text-right">{{ $fmt($totals['purchase_price']) }}</td>
                    <td class="text-right">{{ $fmt($totals['akumulasi']) }}</td>
                    <td class="text-right">{{ $fmt($totals['nilai_buku']) }}</td>
                    <td></td>
                    <td class="text-right">{{ $fmt($totals['next_month_dep']) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 12px; padding: 8px; background: #F0F9FF; border: 1px solid #93C5FD; font-size: 8px; line-height: 1.5;">
            <strong>Catatan.</strong>
            Angka Akumulasi diambil langsung dari ledger (jurnal DEP-* / DEPUSE-* status posted, sudah net-off dengan pembalik void).
            Konsisten dengan Akumulasi Penyusutan di Neraca per tanggal yang sama.
            Baris berwarna kuning = aset sudah fully depreciated.
            Estimasi Bulan Depan hanya berlaku untuk metode Garis Lurus.
        </div>
    @endif
@endsection
