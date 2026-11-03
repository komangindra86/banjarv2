<?php

namespace App\Modules\Website;

use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\Storage;

// §18: logo Banjar diunggah sebagai WebP, padahal ikon aplikasi (iOS) dan pratinjau WhatsApp butuh PNG/JPEG
// berukuran pasti. Ikon dibuat sekali dari logo publik lalu disimpan; logo baru = media baru = berkas baru.
final class BrandIcon
{
    // [lebar, tinggi, porsi logo terhadap sisi terpendek, format]. Maskable butuh ruang aman ±20% di tepi.
    public const VARIANTS = [
        'icon-192' => [192, 192, .86, 'png'],
        'icon-512' => [512, 512, .86, 'png'],
        'maskable-512' => [512, 512, .62, 'png'],
        'apple-180' => [180, 180, .82, 'png'],
        'share' => [1200, 630, .72, 'jpg'],
    ];

    public function __construct(private CurrentBanjar $tenant, private TenantQuery $query, private PublicContent $public) {}

    public function url(string $variant): ?string
    {
        $logo = $this->logo();

        return $logo && $this->render($logo, $variant) ? '/brand/'.$this->tenant->id().'/'.$variant.'.'.self::VARIANTS[$variant][3].'?v='.$logo->id : null;
    }

    public function path(string $variant): ?string
    {
        $logo = $this->logo();

        return $logo ? $this->render($logo, $variant) : null;
    }

    private function logo(): ?object
    {
        $id = $this->tenant->optional() ? $this->query->table('website_profiles')->value('logo_id') : null;

        return $id && $this->public->image((int) $id) ? $this->query->table('media_files')->where('id', $id)->first() : null;
    }

    private function render(object $logo, string $variant): ?string
    {
        [$width, $height, $ratio, $format] = self::VARIANTS[$variant];
        $disk = Storage::disk('local');
        $target = 'banjars/'.$this->tenant->id().'/brand/'.$logo->id.'-'.$variant.'.'.$format;
        if ($disk->exists($target)) {
            return $disk->path($target);
        }
        $source = $disk->exists($logo->path) ? @imagecreatefromstring($disk->get($logo->path)) : false;
        if (! $source) {
            return null;
        }
        // Tepi kosong (transparan/putih) di berkas logo dibuang supaya logo tidak tampak mengecil di ikon.
        if ($cropped = @imagecropauto($source, IMG_CROP_SIDES)) {
            imagedestroy($source);
            $source = $cropped;
        }
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        $box = min($width, $height) * $ratio;
        $scale = min($box / imagesx($source), $box / imagesy($source));
        $w = (int) round(imagesx($source) * $scale);
        $h = (int) round(imagesy($source) * $scale);
        imagecopyresampled($canvas, $source, intdiv($width - $w, 2), intdiv($height - $h, 2), 0, 0, $w, $h, imagesx($source), imagesy($source));
        ob_start();
        $format === 'png' ? imagepng($canvas, null, 9) : imagejpeg($canvas, null, 88);
        $disk->put($target, ob_get_clean());
        imagedestroy($canvas);
        imagedestroy($source);

        return $disk->path($target);
    }
}
