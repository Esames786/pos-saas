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
