<x-filament-panels::page>
    <div style="margin-bottom: 12px; padding: 12px 16px; background: rgb(var(--info-50)); border-left: 3px solid rgb(var(--info-500)); border-radius: 6px;">
        <div style="font-weight: 600; color: rgb(var(--info-800)); margin-bottom: 4px;">📋 Riwayat Aktivitas Sistem</div>
        <div style="font-size: 13px; color: rgb(var(--gray-700));">
            Halaman ini menampilkan semua perubahan penting pada Invoice, Payment, Kontrak, Proyek, dan Penjualan Material — siapa yang mengubah, kapan, dan apa yang berubah.
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
