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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MediaManager
{
    public function __construct(private ContentManager $content, private CurrentBanjar $tenant, private TenantQuery $query, private Audit $audit) {}

    public function upload(User $actor, array $input): int
    {
        $this->content->authorize($actor, 'berita');
        $data = Validator::make($input, ['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'], 'visibility' => ['required', Rule::in(['PUBLIC', 'PRIVATE'])]])->validate();
        /** @var UploadedFile $file */
        $file = $data['file'];
        $mime = $file->getMimeType();
        $bytes = file_get_contents($file->getRealPath());
        $extension = 'pdf';
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000 || max($dimensions[0], $dimensions[1]) > 8000) {
                throw ValidationException::withMessages(['file' => 'Foto maksimal 8.000 piksel per sisi dan 20 megapiksel.']);
            }
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                throw ValidationException::withMessages(['file' => 'Foto tidak dapat dibaca.']);
            }
            // Re-encode raster files to discard metadata and embedded non-image payloads.
            imagesavealpha($image, true);
            ob_start();
            imagewebp($image, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $mime = 'image/webp';
            $extension = 'webp';
        }
        $path = 'banjars/'.$this->tenant->id().'/media/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['file' => 'Berkas gagal disimpan. Silakan coba lagi.']);
        }
        try {
            return DB::transaction(function () use ($actor, $file, $data, $path, $mime, $bytes) {
                $id = DB::table('media_files')->insertGetId(['banjar_id' => $this->tenant->id(), 'path' => $path, 'name' => Str::limit(basename($file->getClientOriginalName()), 240, ''), 'mime' => $mime, 'size' => strlen($bytes), 'visibility' => $data['visibility'], 'uploader_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->record($this->tenant->id(), $actor->id, 'media.uploaded', 'media_files', $id);

                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function visibility(User $actor, int $id, array $input): void
    {
        $this->content->authorize($actor, 'berita');
        $data = Validator::make($input, ['visibility' => ['required', Rule::in(['PUBLIC', 'PRIVATE'])]])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            abort_unless($this->query->table('media_files')->where('id', $id)->lockForUpdate()->first(), 404);
            $this->query->table('media_files')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'media.visibility_changed', 'media_files', $id);
        });
    }
}
