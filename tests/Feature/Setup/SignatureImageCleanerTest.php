<?php

namespace Tests\Feature\Setup;

use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Support\SignatureImageCleaner;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Foto tanda tangan di kertas bergaris → PNG transparan, tinta saja. */
class SignatureImageCleanerTest extends TestCase
{
    /** Kertas krem bergradasi + garis merah muda & kuning + goresan tinta coklat tua. */
    private function photo(): string
    {
        $im = imagecreatetruecolor(400, 300);
        for ($x = 0; $x < 400; $x++) {
            $c = 235 - (int) ($x / 400 * 40);          // bayangan: kanan lebih gelap
            imageline($im, $x, 0, $x, 299, imagecolorallocate($im, $c, $c - 5, $c - 20));
        }
        imagesetthickness($im, 2);
        imageline($im, 0, 60, 399, 60, imagecolorallocate($im, 225, 80, 160));   // garis merah muda
        imageline($im, 0, 200, 399, 200, imagecolorallocate($im, 230, 215, 70)); // garis kuning
        imagesetthickness($im, 8);
        imageline($im, 100, 80, 300, 220, imagecolorallocate($im, 70, 45, 40));  // tinta
        imageline($im, 100, 220, 300, 80, imagecolorallocate($im, 70, 45, 40));
        ob_start();
        imagejpeg($im, null, 92);

        return ob_get_clean();
    }

    private function alphaAt(\GdImage $im, int $x, int $y): int
    {
        return (imagecolorat($im, $x, $y) >> 24) & 0x7F;   // 127 = transparan
    }

    public function test_latar_dan_garis_buku_jadi_transparan_tinta_tetap(): void
    {
        $out = imagecreatefromstring(SignatureImageCleaner::clean($this->photo()));
        $w = imagesx($out);
        $h = imagesy($out);

        // Dipotong rapat ke area goresan (±200×140 + ruang 6 px).
        $this->assertLessThan(230, $w);
        $this->assertLessThan(170, $h);

        // Pusat silang = tinta pekat & gelap.
        $c = imagecolorat($out, intdiv($w, 2), intdiv($h, 2));
        $this->assertLessThan(20, ($c >> 24) & 0x7F);
        $this->assertLessThan(90, ($c >> 16) & 0xFF);

        // Sudut kertas & garis merah muda (y=60 asli → y≈60-80+6 di hasil, bukan di goresan) transparan.
        $this->assertSame(127, $this->alphaAt($out, 2, 2));
        $this->assertSame(127, $this->alphaAt($out, intdiv($w, 2), 0));
        $this->assertSame(127, $this->alphaAt($out, $w - 3, $h - 3));
    }

    public function test_gambar_tanpa_tinta_ditolak(): void
    {
        $im = imagecreatetruecolor(100, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 230));
        ob_start();
        imagepng($im);

        $this->expectException(\RuntimeException::class);
        SignatureImageCleaner::clean(ob_get_clean());
    }

    public function test_upload_owner_disimpan_sebagai_png_bersih(): void
    {
        Storage::fake('local');
        $company = $this->createTenant();
        $this->actingAsTenant($this->createTenantUser($company), $company);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditCompanyProfile::class, ['tenant' => $company])
            ->fillForm(['signature_path' => UploadedFile::fake()->createWithContent('ttd.jpg', $this->photo())])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $company->fresh()->signature_path;
        $this->assertStringEndsWith('.png', $path);
        $img = imagecreatefromstring(Storage::disk('local')->get($path));
        $this->assertSame(127, $this->alphaAt($img, 1, 1));
    }
}
