<?php

namespace App\Platform\Support;

use Closure;

/**
 * Hasil pemeriksaan yang sama dalam satu request HTTP (status modul, izin peran, status akun) cukup dibaca sekali.
 * Hanya aktif selama middleware EnableRequestMemo memproses request; di luar itu (queue worker, scheduler, kode
 * yang dipanggil langsung) setiap pemeriksaan selalu membaca database agar perubahan langsung berlaku.
 */
final class RequestMemo
{
    public const ENABLED = '_request_memo_enabled';

    private const KEY = '_request_memo';

    public static function remember(string $key, Closure $resolve): mixed
    {
        $attributes = app('request')->attributes;
        if (! $attributes->get(self::ENABLED)) {
            return $resolve();
        }
        $memo = $attributes->get(self::KEY, []);
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $resolve();
            $attributes->set(self::KEY, $memo);
        }

        return $memo[$key];
    }

    /** Dipanggil setelah mengubah modul, keanggotaan, peran, atau status akun dalam request yang sama. */
    public static function flush(): void
    {
        app('request')->attributes->remove(self::KEY);
    }

    public static function stop(): void
    {
        app('request')->attributes->remove(self::KEY);
        app('request')->attributes->remove(self::ENABLED);
    }
}
