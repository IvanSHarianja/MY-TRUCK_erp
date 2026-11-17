<x-filament-widgets::widget>
    @if ($missing !== [] || $guessed > 0)
        <div class="coa-notice" role="status">
            @if ($missing !== [])
                <details class="coa-notice-block">
                    <summary class="coa-notice-summary">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="coa-notice-icon" />
                        <span>
                            <strong>{{ count($missing) }} fitur belum bisa membuat jurnal otomatis</strong>
                            karena Peran akunnya belum diisi.
                        </span>
                        <span class="coa-notice-toggle">
                            <span class="coa-notice-toggle-open">Lihat rincian ▾</span>
                            <span class="coa-notice-toggle-close">Tutup rincian ▴</span>
                        </span>
                    </summary>

                    <dl class="coa-notice-list">
                        @foreach ($missing as $feature => $roles)
                            <div class="coa-notice-row">
                                <dt>{{ $feature }}</dt>
                                <dd>
                                    @foreach ($roles as $role)
                                        <span class="coa-notice-chip">{{ $role }}</span>
                                    @endforeach
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="coa-notice-help">
                        Cara melengkapi: buka akun yang sesuai → <strong>Edit</strong> → pilih <strong>Peran</strong>.
                        Kalau akunnya belum ada, buat lewat <strong>Sub-Akun</strong> pada akun induknya.
                        Daftar lengkap ada di Panduan → <em>Paket Akun per Fitur</em>.
                    </p>
                </details>
            @endif

            @if ($guessed > 0)
                <div class="coa-notice-block coa-notice-inline">
                    <x-filament::icon icon="heroicon-o-light-bulb" class="coa-notice-icon" />
                    <span>{{ $guessed }} akun Perannya ditebak dari nama saat import.</span>
                    <a href="{{ $guessedUrl }}" class="coa-notice-link">Periksa akun ini</a>
                </div>
            @endif
        </div>
    @endif
</x-filament-widgets::widget>
