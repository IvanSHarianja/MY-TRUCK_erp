{{--
    Pop-up Panduan Pengguna (slide-over), dipasang di semua halaman panel.

    Dibuka lewat event browser:  $dispatch('panduan-buka', { key: 'jurnal-umum' })
    (key null = buka dari atas). Isi di-fetch sekali saat pertama dibuka, lalu
    dipakai ulang selama halaman belum berganti.
--}}
{{--
    autofocus dimatikan: fokus otomatis Filament jatuh ke tautan pertama daftar
    isi dan menarik scroll kembali ke atas. Fokus dipindah manual ke bagian yang
    dituju (focus preventScroll) di gulir().
--}}
<x-filament::modal
    id="panduan-modal"
    slide-over
    width="5xl"
    heading="Panduan Pengguna"
    :autofocus="false"
    sticky-header
    class="panduan-modal"
>
    <div
        x-data="{
            html: '',
            status: 'idle',
            async buka(key) {
                this.$dispatch('open-modal', { id: 'panduan-modal' });
                if (this.status !== 'ready') {
                    await this.muat();
                }
                this.$nextTick(() => this.gulir(key));
            },
            async muat() {
                this.status = 'loading';
                try {
                    const res = await fetch(@js(route('panduan.konten')), {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (! res.ok) {
                        throw new Error(res.status);
                    }
                    this.html = await res.text();
                    this.status = 'ready';
                } catch (e) {
                    this.status = 'error';
                }
            },
            gulir(key, percobaan = 0) {
                const jendela = this.$root.closest('.fi-modal-window');
                // Jendela slide-over bisa belum ter-layout tepat setelah open-modal;
                // elemen di dalamnya belum bisa di-scroll — tunggu (maks ±1 detik).
                if (jendela && jendela.getClientRects().length === 0 && percobaan < 60) {
                    requestAnimationFrame(() => this.gulir(key, percobaan + 1));
                    return;
                }

                this.$root.querySelectorAll('.panduan-section-aktif')
                    .forEach((el) => el.classList.remove('panduan-section-aktif'));

                const target = key ? document.getElementById('panduan-' + key) : null;
                if (target) {
                    target.classList.add('panduan-section-aktif');
                    target.scrollIntoView({ block: 'start' });
                } else {
                    // Buka dari atas: set langsung ke 0 — scrollIntoView pada elemen
                    // teratas membuat awal daftar isi tertutup header yang sticky.
                    jendela?.scrollTo({ top: 0 });
                }
                // Fokus masuk ke pop-up (keyboard/screen reader) tanpa menggeser scroll.
                (target ?? this.$refs.atas).focus({ preventScroll: true });
            },
            klik(event) {
                const link = event.target.closest('a');
                const href = link?.getAttribute('href') ?? '';
                if (! href.startsWith('#')) {
                    return;
                }
                event.preventDefault();
                this.gulir(href.slice(1));
            },
        }"
        x-on:panduan-buka.window="buka($event.detail?.key)"
        x-on:click="klik($event)"
    >
        <div x-ref="atas" tabindex="-1" class="panduan-focus-target"></div>

        <div x-show="status === 'loading'" class="panduan-status">Memuat panduan…</div>

        <div x-show="status === 'error'" x-cloak class="panduan-status">
            Panduan gagal dimuat. Periksa koneksi, lalu
            <button type="button" class="panduan-retry" x-on:click.stop="muat()">coba lagi</button>.
            Bila tetap gagal, muat ulang halaman (sesi login mungkin sudah berakhir).
        </div>

        <div x-show="status === 'ready'" x-html="html"></div>
    </div>
</x-filament::modal>
