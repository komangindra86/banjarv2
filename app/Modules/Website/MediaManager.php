<?php

namespace App\Modules\Website;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// §18/§58: foto dipilih langsung dari formulir (bisa beberapa sekaligus), tanpa dialog dan tanpa pilihan
// Publik/Privat. Berkas tetap baru tampil di website setelah dipasang pada konten terbit (PublicContent).
final class MediaManager
{
    // Sama dengan max_file_uploads PHP di server; foto sudah diperkecil di browser sebelum dikirim.
    public const MAX_FILES = 20;

    private const MAX_SIDE = 2000;

    public function __construct(private ContentManager $content, private CurrentBanjar $tenant, private TenantQuery $query, private Audit $audit) {}

    // Unggahan dari halaman Semua foto & berkas.
    public function upload(User $actor, array $input): array
    {
        $this->content->authorize($actor, 'berita');

        return $this->storeMany($actor, $input['files'] ?? ($input['file'] ?? []), 'files', true);
    }

    /**
     * Memeriksa semua berkas lebih dulu, lalu menyimpannya; urutan id mengikuti urutan pilihan. Pemanggil yang
     * memeriksa izin (konten, profil, notulen).
     *
     * @return list<int>
     */
    public function storeMany(User $actor, mixed $files, string $field, bool $allowPdf = false, string $visibility = 'PUBLIC'): array
    {
        $files = array_values(array_filter(is_array($files) ? $files : [$files], fn ($file) => $file !== null && $file !== ''));
        if (! $files) {
            return [];
        }
        $types = 'image/jpeg,image/png,image/webp'.($allowPdf ? ',application/pdf' : '');
        Validator::make([$field => $files], [$field => ['array', 'max:'.self::MAX_FILES], $field.'.*' => ['file', 'mimetypes:'.$types, 'max:10240']], [
            $field.'.max' => 'Paling banyak '.self::MAX_FILES.' berkas sekali simpan.',
            $field.'.*.mimetypes' => $allowPdf ? 'Pilih foto (JPG, PNG, WebP) atau PDF.' : 'Pilih berkas foto (JPG, PNG, atau WebP).',
            $field.'.*.max' => 'Ukuran tiap berkas maksimal 10 MB.',
            $field.'.*.file' => 'Berkas gagal terkirim. Silakan pilih ulang.',
        ])->validate();
        $prepared = [];
        foreach ($files as $file) {
            $prepared[] = $this->prepare($file, $field);
        }
        $ids = [];
        foreach ($prepared as [$file, $bytes, $mime, $extension]) {
            $ids[] = $this->store($actor, $file, $bytes, $mime, $extension, $visibility, $field);
        }

        return $ids;
    }

    /** @return array{UploadedFile, string, string, string} */
    private function prepare(UploadedFile $file, string $field): array
    {
        $mime = $file->getMimeType();
        $bytes = file_get_contents($file->getRealPath());
        if (! str_starts_with($mime, 'image/')) {
            return [$file, $bytes, $mime, 'pdf'];
        }
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000 || max($dimensions[0], $dimensions[1]) > 8000) {
            throw ValidationException::withMessages([$field => 'Foto maksimal 8.000 piksel per sisi dan 20 megapiksel.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages([$field => 'Foto “'.Str::limit($file->getClientOriginalName(), 60).'” tidak dapat dibaca.']);
        }
        // Foto kamera HP diperkecil agar website cepat dibuka; re-encode juga membuang metadata dan muatan tersembunyi.
        if (max(imagesx($image), imagesy($image)) > self::MAX_SIDE) {
            $scale = self::MAX_SIDE / max(imagesx($image), imagesy($image));
            $resized = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale), IMG_BICUBIC);
            imagedestroy($image);
            $image = $resized;
        }
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return [$file, $bytes, 'image/webp', 'webp'];
    }

    private function store(User $actor, UploadedFile $file, string $bytes, string $mime, string $extension, string $visibility, string $field): int
    {
        $path = 'banjars/'.$this->tenant->id().'/media/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages([$field => 'Berkas gagal disimpan. Silakan coba lagi.']);
        }
        try {
            return DB::transaction(function () use ($actor, $file, $path, $mime, $bytes, $visibility) {
                $id = DB::table('media_files')->insertGetId(['banjar_id' => $this->tenant->id(), 'path' => $path, 'name' => Str::limit(basename($file->getClientOriginalName()), 240, ''), 'mime' => $mime, 'size' => strlen($bytes), 'visibility' => $visibility, 'uploader_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->record($this->tenant->id(), $actor->id, 'media.uploaded', 'media_files', $id);

                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
