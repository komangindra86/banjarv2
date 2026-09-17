<?php

namespace App\Modules\Payment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WithdrawalProof
{
    public const RULES = ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120'];

    /**
     * Stores the transfer proof encrypted on the private disk before the financial transaction.
     *
     * @return array{proof_path: string, proof_mime: string, proof_sha256: string, proof_size: int}
     */
    public function store(int $tenant, UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $bytes = (string) file_get_contents($file->getRealPath());
        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-')) {
                throw ValidationException::withMessages(['proof' => 'Berkas PDF tidak valid.']);
            }
        } else {
            $dimensions = @getimagesize($file->getRealPath());
            $image = $dimensions && $dimensions[0] * $dimensions[1] <= 20000000 && max($dimensions[0], $dimensions[1]) <= 8000 ? @imagecreatefromstring($bytes) : false;
            if (! $image) {
                throw ValidationException::withMessages(['proof' => 'Foto bukti tidak dapat dibaca atau terlalu besar.']);
            }
            // Re-encode to strip metadata and any embedded non-image payload.
            imagesavealpha($image, true);
            ob_start();
            imagewebp($image, null, 85);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);
            $mime = 'image/webp';
        }
        $path = 'banjars/'.$tenant.'/withdrawals/'.Str::uuid().'.bin';
        if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
            throw ValidationException::withMessages(['proof' => 'Bukti gagal disimpan. Silakan coba lagi.']);
        }

        return ['proof_path' => $path, 'proof_mime' => $mime, 'proof_sha256' => hash('sha256', $bytes), 'proof_size' => strlen($bytes)];
    }

    public function discard(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    public function read(object $withdrawal): string
    {
        abort_unless($withdrawal->proof_path, 404);
        $cipher = Storage::disk('local')->get($withdrawal->proof_path);
        abort_unless(is_string($cipher), 404);
        $bytes = Crypt::decryptString($cipher);
        abort_unless(hash_equals($withdrawal->proof_sha256, hash('sha256', $bytes)), 409, 'Integritas bukti tidak sesuai.');

        return $bytes;
    }
}
