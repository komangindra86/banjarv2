<?php

namespace App\Modules\Notification;

use App\Modules\Activity\ActivitySchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifikasi untuk warga (kotak masuk + push ke HP bila diaktifkan): pengumuman dan kegiatan publik yang terbit,
 * serta tagihan yang diterbitkan untuk KK-nya. Semua query memakai banjar_id eksplisit karena dijalankan dari
 * antrean/scheduler, bukan dari request Banjar. Isi notifikasi di layar kunci tanpa nominal atau data pribadi.
 */
final class WargaNotifier
{
    public function __construct(private NotificationManager $notifications) {}

    /**
     * Kirim notifikasi untuk pengumuman & kegiatan yang sudah tiba waktu terbitnya dan belum dikirim. Konten yang
     * dijadwalkan terbit nanti menunggu sampai waktunya (scheduler tiap menit). @return int jumlah konten diproses
     */
    public function publishDue(int $tenant): int
    {
        $processed = 0;
        foreach (['announcements' => 'WEBSITE', 'activities' => 'ACTIVITY'] as $table => $module) {
            $rows = DB::table($table)->where('banjar_id', $tenant)->where('status', 'PUBLISHED')
                ->where('published_at', '<=', now())->whereNull('notified_at')->orderBy('id')->limit(100)->get();
            foreach ($rows as $row) {
                // Klaim atomik: job antrean dan scheduler yang berjalan bersamaan tidak mengirim dua kali.
                if (! DB::table($table)->where('id', $row->id)->whereNull('notified_at')->update(['notified_at' => now()])) {
                    continue;
                }
                $processed++;
                if (! $this->moduleActive($tenant, $module)) {
                    continue;
                }
                $table === 'announcements' ? $this->announcement($tenant, $row) : $this->activity($tenant, $row);
            }
        }

        return $processed;
    }

    public function billsIssued(int $tenant, int $eventId): void
    {
        $event = DB::table('billing_events')->where('banjar_id', $tenant)->where('id', $eventId)->first();
        if (! $event || $event->status !== 'ISSUED') {
            return;
        }
        // Tagihan selalu untuk kepala keluarga (§23): hanya kepala KK yang punya akun aktif di Banjar ini.
        $userIds = DB::table('bills as b')->where('b.banjar_id', $tenant)->where('b.billing_event_id', $eventId)->where('b.status', 'UNPAID')
            ->join('households as h', fn ($j) => $j->on('h.id', '=', 'b.kk_id')->on('h.banjar_id', '=', 'b.banjar_id'))
            ->join('krama as k', fn ($j) => $j->on('k.id', '=', 'h.head_krama_id')->on('k.banjar_id', '=', 'b.banjar_id'))
            ->join('banjar_user_memberships as m', fn ($j) => $j->on('m.user_id', '=', 'k.user_id')->on('m.banjar_id', '=', 'b.banjar_id'))
            ->join('users as u', 'u.id', '=', 'k.user_id')
            ->where('m.status', 'ACTIVE')->where('u.status', 'ACTIVE')->distinct()->pluck('k.user_id');
        $url = $this->moduleActive($tenant, 'PAYMENT') ? '/payments' : '/portal/beranda';
        foreach ($userIds as $userId) {
            $this->notifications->notify($tenant, (int) $userId, 'bill.issued', 'Tagihan baru', Str::limit('Ada tagihan baru: '.$event->title.'. Buka untuk melihat dan membayar.', 250), $url);
        }
    }

    private function announcement(int $tenant, object $row): void
    {
        if ($row->expires_at && Carbon::parse($row->expires_at)->isPast()) {
            return;
        }
        $this->notifications->notifyPermission($tenant, 'krama.self', 'announcement.published',
            $row->priority === 'IMPORTANT' ? 'Pengumuman penting' : 'Pengumuman baru', Str::limit($row->title, 250), '/portal/pengumuman/'.$row->slug);
    }

    private function activity(int $tenant, object $row): void
    {
        // Kegiatan internal (mis. paruman tertutup) dan kegiatan yang sudah selesai tidak diumumkan ke semua warga.
        $notOver = ActivitySchedule::notOver(DB::table('activities')->where('id', $row->id))->exists();
        if ($row->visibility !== 'PUBLIC' || ! $notOver) {
            return;
        }
        $when = Carbon::parse($row->starts_at)->timezone('Asia/Makassar')->translatedFormat('l, d M Y · H:i');
        $this->notifications->notifyPermission($tenant, 'krama.self', 'activity.published', 'Kegiatan baru',
            Str::limit($row->title.' — '.$when.' WITA'.($row->location ? ' · '.$row->location : ''), 250), '/aktivitas');
    }

    private function moduleActive(int $tenant, string $code): bool
    {
        $status = DB::table('banjar_modules')->where('banjar_id', $tenant)->where('code', $code)->value('status');

        return $status === 'ACTIVE';
    }
}
