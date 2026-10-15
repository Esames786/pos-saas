<?php

namespace App\Services\OnlineOrdering;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ONLINE-ORDERING-1 — website ki tasveer: aik dafa chhoti kar ke rakho, har customer ko bari na bhejo.
 *
 * Purani website ki tasveerein 800×800 aur 1–1.5 MB thin. Yahan har tasveer ke do WebP bante hain:
 * 800px (popup) aur 400px (card). Card par 400 wali — page halka rehta hai. Asal file rakhi nahi jati.
 */
class WebImageService
{
    private const SIZES = [800 => '', 400 => '-400'];

    private const QUALITY = 78;

    /** @return string 800px wali file ka path (public disk par); 400 wali isi naam par "-400" ke saath. */
    public function store(string $sourcePath, string $tenantCode, string $name): string
    {
        $bytes = @file_get_contents($sourcePath);
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (! $src) {
            throw new RuntimeException('Tasveer parhi nahi ja saki: '.basename($sourcePath));
        }

        $base = 'web-menu/'.Str::slug($tenantCode).'/'.Str::slug(Str::limit($name, 60, '')).'-'.Str::lower(Str::random(8));

        foreach (self::SIZES as $width => $suffix) {
            $img = $this->fit($src, $width);
            ob_start();
            imagewebp($img, null, self::QUALITY);
            Storage::disk('public')->put($base.$suffix.'.webp', (string) ob_get_clean());
            if ($img !== $src) {
                imagedestroy($img);
            }
        }
        imagedestroy($src);

        return $base.'.webp';
    }

    /**
     * Banner / logo: diye gaye chaurai ke WebP (masalan banner 1920 + 960, logo 320). Purani website ke banner
     * 5120×1600 aur 5–7 MB the — customer ko ~150 KB jata hai.
     *
     * @param  list<int>  $widths
     * @return array<int, string> chaurai => path
     */
    public function storeWidths(string $sourcePath, string $tenantCode, string $name, array $widths, int $quality = 74): array
    {
        $bytes = @file_get_contents($sourcePath);
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (! $src) {
            throw new RuntimeException('Tasveer parhi nahi ja saki: '.basename($sourcePath));
        }
        $base = 'web-site/'.Str::slug($tenantCode).'/'.Str::slug(Str::limit($name, 40, '')).'-'.Str::lower(Str::random(8));
        $out = [];
        foreach ($widths as $width) {
            $img = $this->fit($src, $width);
            ob_start();
            imagewebp($img, null, $quality);
            Storage::disk('public')->put($path = $base.'-'.$width.'.webp', (string) ob_get_clean());
            if ($img !== $src) {
                imagedestroy($img);
            }
            $out[$width] = $path;
        }
        imagedestroy($src);

        return $out;
    }

    /**
     * App icon ("Add to home screen"): chaukor PNG, beech se kata. Path diye gaye naam par, masalan
     * logo-abc-192.png — logo ke saath bante aur mit-te hain.
     *
     * @param  list<int>  $sizes
     */
    public function storeSquarePngs(string $sourcePath, string $basePath, array $sizes): void
    {
        $bytes = @file_get_contents($sourcePath);
        $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (! $src) {
            return;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        foreach ($sizes as $size) {
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
            ob_start();
            imagepng($dst, null, 8);
            Storage::disk('public')->put($basePath.'-'.$size.'.png', (string) ob_get_clean());
            imagedestroy($dst);
        }
        imagedestroy($src);
    }

    /** @param  list<string|null>  $paths */
    public function deleteFiles(array $paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths) {
            Storage::disk('public')->delete($paths);
        }
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete([$path, self::thumb($path)]);
        }
    }

    /** 400px wali file ka path. */
    public static function thumb(string $path): string
    {
        return preg_replace('/\.webp$/', '-400.webp', $path);
    }

    /** Chhoti tasveer ko bara nahi karte; sirf bari ko `width` tak. Transparency salamat. */
    private function fit(\GdImage $src, int $width): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $width) {
            imagepalettetotruecolor($src);
            imagealphablending($src, false);
            imagesavealpha($src, true);

            return $src;
        }

        $height = (int) round($h * $width / $w);
        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $w, $h);

        return $dst;
    }
}
