<?php

namespace App\Modules\Activity;

use Illuminate\Database\Query\Builder;

/**
 * Kegiatan yang "belum selesai" untuk daftar terdekat (beranda website & portal): jam selesainya masih di depan,
 * atau — bila tanpa jam selesai — dimulai hari ini (WITA) atau nanti. Kegiatan yang sedang berlangsung tetap tampil.
 */
final class ActivitySchedule
{
    public static function notOver(Builder $query, string $table = ''): Builder
    {
        $prefix = $table !== '' ? $table.'.' : '';
        $today = now('Asia/Makassar')->startOfDay()->utc();

        return $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereNotNull($prefix.'ends_at')->where($prefix.'ends_at', '>=', now()))
            ->orWhere(fn ($q) => $q->whereNull($prefix.'ends_at')->where($prefix.'starts_at', '>=', $today)));
    }
}
