<?php

namespace App\Support;

use RuntimeException;

/**
 * Menghapus latar kertas dari foto tanda tangan / cap → PNG transparan
 * bertinta satu warna, dipotong rapat ke area tinta.
 *
 * Langkah:
 *  1. Nilai "gelap" tiap piksel, dihaluskan 5×5 supaya tekstur kertas/tinta
 *     tidak membuat goresan berbintik.
 *     - signature: kanal TERTERANG (max r,g,b). Garis buku berwarna (merah muda,
 *       kuning, biru muda) selalu terang di salah satu kanal → hilang.
 *     - stamp: luminans, supaya cap biru/merah tidak ikut terhapus.
 *  2. Terang kertas diperkirakan PER BLOK (persentil 90, dilatasi 3×3) lalu
 *     diinterpolasi → tahan bayangan & gradasi cahaya foto HP.
 *  3. Opasitas = selisih terhadap kertas. Gumpalan kecil (sisa titik garis,
 *     debu) dibuang; hanya goresan yang menyatu ke gumpalan besar yang tetap.
 *  4. Warna tinta diseragamkan (rata-rata goresan tebal, sedikit digelapkan).
 */
final class SignatureImageCleaner
{
    // Kotak kerja maks. 600×800 — memori ±10 array per piksel di PHP; gambar
    // sangat tinggi (screenshot panjang) dulu membuat PHP kehabisan memori.
    private const MAX_WIDTH = 600;
    private const MAX_HEIGHT = 800;
    // Tolak sebelum decode: PNG kecil bisa berdimensi raksasa (decompression bomb).
    private const MAX_SOURCE_PIXELS = 40_000_000;
    private const BLOCK = 32;
    private const LO = 40;    // selisih dari kertas ≤ ini → transparan
    private const HI = 90;    // selisih ≥ ini → tinta penuh

    /** @return string PNG binary */
    public static function clean(string $binary, string $mode = 'signature'): string
    {
        $size = @getimagesizefromstring($binary);
        if (! $size || $size[0] < 1 || $size[1] < 1) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }
        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw new RuntimeException('Ukuran gambar terlalu besar (maks. 40 megapiksel).');
        }
        $src = @imagecreatefromstring($binary);
        if (! $src) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }
        $src = self::applyExifOrientation($src, $binary);
        $img = self::normalize($src);
        $w = imagesx($img);
        $h = imagesy($img);
        $n = $w * $h;

        // 1. Nilai terang + alpha asli + warna.
        $v = array_fill(0, $n, 0);
        $a0 = array_fill(0, $n, 0);
        $rgb = array_fill(0, $n, 0);
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                $v[$i] = $mode === 'stamp' ? (int) (0.299 * $r + 0.587 * $g + 0.114 * $b) : max($r, $g, $b);
                $a0[$i] = ($c >> 24) & 0x7F;
                $rgb[$i] = $c & 0xFFFFFF;
            }
        }
        $bg = self::backgroundMap($v, $w, $h);
        $vs = self::boxBlur($v, $w, $h, 2);

        // 2. Opasitas per piksel.
        $op = array_fill(0, $n, 0.0);
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                $diff = self::bgAt($bg, $x, $y) - $vs[$i];
                $o = ($diff - self::LO) / (self::HI - self::LO);
                $op[$i] = $o <= 0 ? 0.0 : min(1.0, $o) * (1 - $a0[$i] / 127);
            }
        }

        // 3. Buang gumpalan kecil.
        $keep = self::keepMask($op, $w, $h);

        // 4. Warna tinta seragam.
        [$ir, $ig, $ib] = self::inkColor($op, $keep, $rgb, $n);

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                if (! $keep[$i] || $op[$i] <= 0.02) {
                    continue;
                }
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $ir, $ig, $ib, (int) round(127 * (1 - $op[$i]))));
                if ($op[$i] > 0.3) {
                    $minX = min($minX, $x); $maxX = max($maxX, $x);
                    $minY = min($minY, $y); $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < 0) {
            throw new RuntimeException('Goresan tinta tidak terdeteksi. Foto ulang dengan tinta gelap di kertas polos.');
        }

        // 5. Potong rapat + sedikit ruang.
        $pad = 6;
        $minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad); $maxY = min($h - 1, $maxY + $pad);
        $crop = imagecrop($out, ['x' => $minX, 'y' => $minY, 'width' => $maxX - $minX + 1, 'height' => $maxY - $minY + 1]);
        if ($crop === false) {
            $crop = $out;
        }
        imagealphablending($crop, false);
        imagesavealpha($crop, true);

        ob_start();
        imagepng($crop, null, 6);

        return (string) ob_get_clean();
    }

    /**
     * Foto HP posisi tegak disimpan mendatar + tag EXIF Orientation; GD
     * mengabaikan tag itu → tanda tangan tercetak miring 90°.
     */
    private static function applyExifOrientation(\GdImage $img, string $binary): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($binary, "\xFF\xD8")) {
            return $img;     // hanya JPEG yang membawa EXIF
        }
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        // imagerotate berlawanan arah jarum jam.
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, 270, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        return $rotated ?: $img;
    }

    /** Truecolor + perkecil ke kotak 600×800 (foto HP bisa 4000 px). */
    private static function normalize(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_WIDTH / $w, self::MAX_HEIGHT / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $img = imagecreatetruecolor($nw, $nh);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $img;
    }

    /** Rata-rata kotak (2r+1)² lewat integral image. */
    private static function boxBlur(array $v, int $w, int $h, int $r): array
    {
        $W = $w + 1;
        $s = array_fill(0, $W * ($h + 1), 0);
        for ($y = 0; $y < $h; $y++) {
            $row = 0;
            for ($x = 0; $x < $w; $x++) {
                $row += $v[$y * $w + $x];
                $s[($y + 1) * $W + $x + 1] = $s[$y * $W + $x + 1] + $row;
            }
        }
        $out = array_fill(0, $w * $h, 0.0);
        for ($y = 0; $y < $h; $y++) {
            $y0 = max(0, $y - $r); $y1 = min($h, $y + $r + 1);
            for ($x = 0; $x < $w; $x++) {
                $x0 = max(0, $x - $r); $x1 = min($w, $x + $r + 1);
                $sum = $s[$y1 * $W + $x1] - $s[$y0 * $W + $x1] - $s[$y1 * $W + $x0] + $s[$y0 * $W + $x0];
                $out[$y * $w + $x] = $sum / (($y1 - $y0) * ($x1 - $x0));
            }
        }

        return $out;
    }

    /**
     * Gumpalan (8-tetangga, opasitas > 0.15) yang ukurannya < 3% gumpalan
     * terbesar atau < 30 piksel dibuang.
     *
     * @return array<int,bool>
     */
    private static function keepMask(array $op, int $w, int $h): array
    {
        $n = $w * $h;
        $label = array_fill(0, $n, 0);
        $sizes = [0];
        $next = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($label[$i] || $op[$i] <= 0.15) {
                continue;
            }
            $next++;
            $label[$i] = $next;
            $stack = [$i];
            $size = 0;
            while ($stack) {
                $p = array_pop($stack);
                $size++;
                $px = $p % $w; $py = intdiv($p, $w);
                for ($dy = -1; $dy <= 1; $dy++) {
                    $ny = $py + $dy;
                    if ($ny < 0 || $ny >= $h) continue;
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $px + $dx;
                        if ($nx < 0 || $nx >= $w) continue;
                        $q = $ny * $w + $nx;
                        if (! $label[$q] && $op[$q] > 0.15) {
                            $label[$q] = $next;
                            $stack[] = $q;
                        }
                    }
                }
            }
            $sizes[$next] = $size;
        }

        $min = max(30, (int) (max($sizes) * 0.03));

        // Piksel samar (sisa garis buku yang menyentuh goresan) hanya dipertahankan
        // bila berada ≤ 2 px dari goresan tebal — itu tepi halus goresan, bukan garis.
        $strong = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            if ($op[$i] >= 0.75) {
                $x = $i % $w; $y = intdiv($i, $w);
                for ($dy = -2; $dy <= 2; $dy++) {
                    $ny = $y + $dy;
                    if ($ny < 0 || $ny >= $h) continue;
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $nx = $x + $dx;
                        if ($nx >= 0 && $nx < $w) $strong[$ny * $w + $nx] = true;
                    }
                }
            }
        }

        $keep = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            $keep[$i] = $label[$i] > 0 && $sizes[$label[$i]] >= $min && ($op[$i] >= 0.75 || $strong[$i]);
        }

        return $keep;
    }

    /** @return array{0:int,1:int,2:int} warna tinta: rata-rata goresan tebal, digelapkan 20%. */
    private static function inkColor(array $op, array $keep, array $rgb, int $n): array
    {
        [$r, $g, $b, $c] = [0, 0, 0, 0];
        for ($i = 0; $i < $n; $i++) {
            if ($keep[$i] && $op[$i] >= 0.9) {
                $r += ($rgb[$i] >> 16) & 0xFF; $g += ($rgb[$i] >> 8) & 0xFF; $b += $rgb[$i] & 0xFF; $c++;
            }
        }
        if ($c === 0) {
            return [20, 20, 20];
        }

        return [(int) ($r / $c * 0.8), (int) ($g / $c * 0.8), (int) ($b / $c * 0.8)];
    }

    /** @return array{0:int,1:int,2:array<int,array<int,float>>} [kolom, baris, grid terang kertas] */
    private static function backgroundMap(array $v, int $w, int $h): array
    {
        $cols = (int) ceil($w / self::BLOCK);
        $rows = (int) ceil($h / self::BLOCK);
        $grid = [];
        for ($by = 0; $by < $rows; $by++) {
            for ($bx = 0; $bx < $cols; $bx++) {
                $vals = [];
                for ($y = $by * self::BLOCK, $ye = min($h, $y + self::BLOCK); $y < $ye; $y++) {
                    for ($x = $bx * self::BLOCK, $xe = min($w, $x + self::BLOCK); $x < $xe; $x++) {
                        $vals[] = $v[$y * $w + $x];
                    }
                }
                sort($vals);
                $grid[$by][$bx] = (float) $vals[(int) floor((count($vals) - 1) * 0.9)];
            }
        }
        // Dilatasi 3×3: blok penuh goresan tebal memakai terang kertas tetangga.
        $dil = [];
        for ($by = 0; $by < $rows; $by++) {
            for ($bx = 0; $bx < $cols; $bx++) {
                $m = 0.0;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $m = max($m, $grid[$by + $dy][$bx + $dx] ?? 0.0);
                    }
                }
                $dil[$by][$bx] = $m;
            }
        }

        return [$cols, $rows, $dil];
    }

    /** Interpolasi bilinear antar pusat blok. */
    private static function bgAt(array $bg, int $x, int $y): float
    {
        [$cols, $rows, $g] = $bg;
        $fx = max(0.0, min($cols - 1.0, ($x + 0.5) / self::BLOCK - 0.5));
        $fy = max(0.0, min($rows - 1.0, ($y + 0.5) / self::BLOCK - 0.5));
        $x0 = (int) floor($fx); $y0 = (int) floor($fy);
        $x1 = min($cols - 1, $x0 + 1); $y1 = min($rows - 1, $y0 + 1);
        $tx = $fx - $x0; $ty = $fy - $y0;

        return ($g[$y0][$x0] * (1 - $tx) + $g[$y0][$x1] * $tx) * (1 - $ty)
             + ($g[$y1][$x0] * (1 - $tx) + $g[$y1][$x1] * $tx) * $ty;
    }
}
