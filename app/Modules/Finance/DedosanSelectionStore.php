<?php

namespace App\Modules\Finance;

use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\Cache;

// Simpan pilihan checkbox lintas halaman/filter di server (bukan client-side), per Banjar+event+pengguna.
// "Pilih semua hasil filter" memateraikan hasil query saat itu ke dalam set ini; perubahan filter
// berikutnya tidak diam-diam menambah pilihan lama karena set sudah dibekukan pada saat dipilih.
final class DedosanSelectionStore
{
    private const TTL_HOURS = 4;

    public function __construct(private CurrentBanjar $tenant) {}

    private function key(int $eventId, int $userId): string
    {
        return "finance:dedosan-selection:{$this->tenant->id()}:{$eventId}:{$userId}";
    }

    public function ids(int $eventId, int $userId): array
    {
        return Cache::get($this->key($eventId, $userId), []);
    }

    public function add(int $eventId, int $userId, array $ids): array
    {
        $merged = array_values(array_unique(array_merge($this->ids($eventId, $userId), array_map('intval', $ids))));
        Cache::put($this->key($eventId, $userId), $merged, now()->addHours(self::TTL_HOURS));

        return $merged;
    }

    public function remove(int $eventId, int $userId, array $ids): array
    {
        $remaining = array_values(array_diff($this->ids($eventId, $userId), array_map('intval', $ids)));
        Cache::put($this->key($eventId, $userId), $remaining, now()->addHours(self::TTL_HOURS));

        return $remaining;
    }

    public function toggle(int $eventId, int $userId, int $kramaId, bool $checked): array
    {
        return $checked ? $this->add($eventId, $userId, [$kramaId]) : $this->remove($eventId, $userId, [$kramaId]);
    }

    public function clear(int $eventId, int $userId): void
    {
        Cache::forget($this->key($eventId, $userId));
    }
}
