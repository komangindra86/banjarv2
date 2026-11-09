<?php

use App\Modules\Notification\WargaNotifier;
use App\Platform\Tenants\DemoResetManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping(60);
// Notifikasi warga untuk pengumuman/kegiatan yang dijadwalkan terbit (yang langsung terbit dikirim lewat antrean saat disimpan).
Schedule::command('notifications:publish-due')->everyMinute()->withoutOverlapping(10);
// Banjar demo reviewer kembali ke kondisi awal setiap malam (data, foto, dan akun buatan reviewer dihapus).
Schedule::command('demo:reset')->dailyAt('03:00')->timezone('Asia/Makassar')->withoutOverlapping(30)->when(fn () => (bool) config('platform.demo_banjar_id'));

Artisan::command('demo:snapshot', function (DemoResetManager $demo) {
    $result = $demo->snapshot((int) config('platform.demo_banjar_id'));
    $this->info("Snapshot Banjar demo disimpan: {$result['rows']} baris dari {$result['tables']} tabel, {$result['users']} akun, {$result['files']} berkas.");
})->purpose('Simpan kondisi awal Banjar demo (DEMO_BANJAR_ID) sebagai titik pemulihan');

Artisan::command('demo:reset', function (DemoResetManager $demo) {
    $result = $demo->reset((int) config('platform.demo_banjar_id'));
    $this->info("Banjar demo dipulihkan ke snapshot {$result['snapshot']}: {$result['rows']} baris, {$result['files']} berkas, {$result['users_removed']} akun reviewer dihapus.");
})->purpose('Kembalikan Banjar demo (DEMO_BANJAR_ID) ke snapshot; data, berkas, dan akun buatan reviewer dihapus');

Artisan::command('notifications:publish-due', function (WargaNotifier $notifier) {
    $total = 0;
    foreach (DB::table('banjars')->where('status', 'ACTIVE')->pluck('id') as $banjarId) {
        $total += $notifier->publishDue((int) $banjarId);
    }
    if ($total) {
        $this->info("{$total} pengumuman/kegiatan dikirim notifikasinya ke warga.");
    }
})->purpose('Kirim notifikasi warga untuk pengumuman dan kegiatan yang sudah tiba waktu terbitnya');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
