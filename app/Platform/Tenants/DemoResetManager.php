<?php

namespace App\Platform\Tenants;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Banjar demo untuk reviewer (akun publik, kata sandi bersama): snapshot() menyimpan kondisi awal, reset()
 * mengembalikannya persis — data, berkas (foto, PDF), keanggotaan, dan akun yang dibuat reviewer ikut dihapus.
 * Hanya berjalan untuk Banjar yang ditetapkan di DEMO_BANJAR_ID; Banjar lain tidak pernah disentuh.
 */
final class DemoResetManager
{
    // banjars.primary_domain_id merujuk tenant_domains; domain hanya diatur Super Admin, jadi tidak ikut dipulihkan.
    private const SKIP = ['tenant_domains'];

    public function snapshot(int $banjarId): array
    {
        $this->guard($banjarId);
        $tables = [];
        foreach ($this->tables() as $table) {
            $tables[$table] = DB::table($table)->where('banjar_id', $banjarId)->orderBy($this->key($table))->get()->map(fn ($row) => (array) $row)->all();
        }
        $userIds = DB::table('banjar_user_memberships')->where('banjar_id', $banjarId)->pluck('user_id');
        // Hanya akun yang seluruh keanggotaannya di Banjar demo yang ikut dipulihkan (akun lintas Banjar tidak disentuh).
        $users = DB::table('users')->whereIn('id', $userIds)
            ->whereNotExists(fn ($q) => $q->from('banjar_user_memberships as m')->whereColumn('m.user_id', 'users.id')->where('m.banjar_id', '<>', $banjarId))
            ->whereNotExists(fn ($q) => $q->from('platform_role_assignments as p')->whereColumn('p.user_id', 'users.id'))
            ->get()->map(fn ($row) => (array) $row)->all();
        $disk = Storage::disk('local');
        $base = $this->baseDir($banjarId);
        $disk->deleteDirectory($base);
        $files = $disk->allFiles('banjars/'.$banjarId);
        foreach ($files as $file) {
            $disk->copy($file, $base.'/files/'.$file);
        }
        $disk->put($base.'/data.json', json_encode(['banjar_id' => $banjarId, 'created_at' => now()->toIso8601String(), 'tables' => $tables, 'users' => $users], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['tables' => count(array_filter($tables)), 'rows' => array_sum(array_map('count', $tables)), 'users' => count($users), 'files' => count($files)];
    }

    public function reset(int $banjarId): array
    {
        $this->guard($banjarId);
        $disk = Storage::disk('local');
        $base = $this->baseDir($banjarId);
        if (! $disk->exists($base.'/data.json')) {
            throw new RuntimeException('Belum ada snapshot Banjar demo. Jalankan demo:snapshot dulu.');
        }
        $snapshot = json_decode($disk->get($base.'/data.json'), true, 512, JSON_THROW_ON_ERROR);
        if ((int) $snapshot['banjar_id'] !== $banjarId) {
            throw new RuntimeException('Snapshot milik Banjar lain.');
        }
        $baselineUsers = array_column($snapshot['users'], null, 'id');
        $removed = 0;
        DB::transaction(function () use ($banjarId, $snapshot, $baselineUsers, &$removed) {
            DB::table('banjars')->where('id', $banjarId)->lockForUpdate()->first();
            $members = DB::table('banjar_user_memberships')->where('banjar_id', $banjarId)->pluck('user_id')->all();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($this->tables() as $table) {
                    DB::table($table)->where('banjar_id', $banjarId)->delete();
                }
                foreach ($snapshot['tables'] as $table => $rows) {
                    if (! in_array($table, $this->tables(), true)) {
                        continue;
                    }
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
                // Akun reviewer awal dikembalikan apa adanya (kata sandi bersama, terkunci).
                foreach ($baselineUsers as $id => $user) {
                    if (DB::table('banjar_user_memberships')->where('user_id', $id)->where('banjar_id', '<>', $banjarId)->exists()) {
                        continue;
                    }
                    DB::table('users')->updateOrInsert(['id' => $id], $user);
                }
                // Akun yang dibuat reviewer (mis. aktifkan akun warga) dihapus bila tidak lagi terdaftar di Banjar mana pun.
                foreach (array_diff($members, array_keys($baselineUsers)) as $userId) {
                    if (DB::table('banjar_user_memberships')->where('user_id', $userId)->exists() || DB::table('platform_role_assignments')->where('user_id', $userId)->exists()) {
                        continue;
                    }
                    DB::table('sessions')->where('user_id', $userId)->delete();
                    $removed += DB::table('users')->where('id', $userId)->delete();
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
        // Berkas: semua unggahan reviewer dibuang, berkas awal dikembalikan.
        $disk->deleteDirectory('banjars/'.$banjarId);
        $files = $disk->allFiles($base.'/files');
        foreach ($files as $file) {
            $disk->copy($file, substr($file, strlen($base.'/files/')));
        }

        return ['rows' => array_sum(array_map('count', $snapshot['tables'])), 'files' => count($files), 'users_removed' => $removed, 'snapshot' => $snapshot['created_at']];
    }

    private function guard(int $banjarId): void
    {
        $demo = (int) config('platform.demo_banjar_id');
        if (! $demo || $demo !== $banjarId) {
            throw new RuntimeException('Banjar '.$banjarId.' bukan Banjar demo (DEMO_BANJAR_ID). Tidak ada yang diubah.');
        }
        if (! DB::table('banjars')->where('id', $banjarId)->exists()) {
            throw new RuntimeException('Banjar demo tidak ditemukan.');
        }
    }

    /** Semua tabel data Banjar (kolom banjar_id), dibaca dari skema agar tabel baru ikut terhapus. @return list<string> */
    private function tables(): array
    {
        static $tables;

        return $tables ??= collect(DB::select('SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? ORDER BY 1', [DB::getDatabaseName(), 'banjar_id']))
            ->pluck('t')->diff(self::SKIP)->values()->all();
    }

    private function key(string $table): string
    {
        return collect(DB::select('SHOW COLUMNS FROM `'.str_replace('`', '', $table).'`'))->pluck('Field')->contains('id') ? 'id' : 'banjar_id';
    }

    private function baseDir(int $banjarId): string
    {
        return 'demo-baseline/'.$banjarId;
    }
}
