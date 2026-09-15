{{--
    Isi pop-up Panduan — dirender server (UserGuide::renderedContent, di-cache)
    lalu disisipkan ke modal via fetch. id bagian diberi awalan "panduan-" supaya
    tidak bentrok dengan id elemen lain di halaman; tautan "#key" di Markdown
    diterjemahkan oleh handler klik di panduan-modal.
--}}
<div class="panduan-layout">
    <nav class="panduan-toc" aria-label="Daftar isi panduan">
        @foreach ($toc as $group => $items)
            <div class="panduan-toc-group">
                <div class="panduan-toc-group-title">{{ $group }}</div>
                <ul>
                    @foreach ($items as $item)
                        <li><a href="#{{ $item['key'] }}">{{ $item['title'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="panduan-content">
        @foreach ($sections as $section)
            <section id="panduan-{{ $section['key'] }}" class="panduan-section panduan-focus-target" tabindex="-1">
                <div class="panduan-section-group">{{ $section['group'] }}</div>
                <h2 class="panduan-section-title">{{ $section['title'] }}</h2>
                <div class="fi-prose panduan-prose">
                    {{ $section['html'] }}
                </div>
                <a href="#" class="panduan-back-top">↑ Kembali ke daftar isi</a>
            </section>
        @endforeach
    </div>
</div>
