<?php

namespace App\Modules\Krama;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// §23/§53: akun portal warga. Admin Banjar cukup klik "Aktifkan akun warga"; warga login dengan NIK dan
// kata sandi awal DEFAULT_PASSWORD, lalu wajib menggantinya saat pertama masuk.
final class AccountManager
{
    public const DEFAULT_PASSWORD = 'balisantih';

    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private KramaAccess $access, private Audit $audit) {}

    // HMAC, bukan NIK asli: NIK tetap hanya tersimpan terenkripsi di tabel krama.
    public static function nikHash(string $nik): string
    {
        return hash_hmac('sha256', $nik, (string) config('app.key'));
    }

    // Akun warga murni: dibuat lewat aktivasi NIK dan tidak memegang peran selain Krama di Banjar mana pun.
    public static function wargaOnly(int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->whereNotNull('nik_hash')->exists()
            && ! DB::table('platform_role_assignments')->where('user_id', $userId)->exists()
            && ! DB::table('banjar_user_memberships as m')->join('tenant_role_assignments as a', 'a.membership_id', '=', 'm.id')
                ->join('roles as r', 'r.id', '=', 'a.role_id')->where('m.user_id', $userId)->where('r.code', '<>', 'KRAMA')->exists();
    }

    public function activate(User $actor, int $kramaId): void
    {
        $this->access->authorize($actor, 'krama.configure');
        DB::transaction(function () use ($actor, $kramaId) {
            DB::table('banjars')->where('id', $this->tenant->id())->lockForUpdate()->first();
            $krama = $this->query->table('krama')->where('id', $kramaId)->first();
            abort_unless($krama, 404);
            if ($krama->user_id) {
                $this->fail('Krama ini sudah memiliki akun.');
            }
            if ($krama->status !== 'ACTIVE') {
                $this->fail('Hanya krama berstatus aktif yang bisa diberi akun.');
            }
            if (! $krama->nik) {
                $this->fail('Isi NIK krama ini dulu — NIK dipakai sebagai nama pengguna untuk login.');
            }
            $hash = self::nikHash(Crypt::decryptString($krama->nik));
            // NIK yang sama di Banjar lain = orang yang sama: pakai akun yang sudah ada, kata sandinya tidak diubah.
            $user = User::where('nik_hash', $hash)->first();
            if ($user && ! self::wargaOnly($user->id)) {
                $this->fail('NIK ini sudah dipakai akun lain. Hubungi Super Admin.');
            }
            if ($user && $this->query->table('krama')->where('user_id', $user->id)->exists()) {
                $this->fail('Akun dengan NIK ini sudah terhubung ke krama lain di Banjar ini.');
            }
            $created = ! $user;
            if ($created) {
                $user = new User(['name' => $krama->name, 'email' => null, 'password' => self::DEFAULT_PASSWORD]);
                $user->forceFill(['nik_hash' => $hash, 'must_change_password' => true])->save();
            }
            $membership = $this->query->table('banjar_user_memberships')->where('user_id', $user->id)->first();
            if ($membership) {
                $this->query->table('banjar_user_memberships')->where('id', $membership->id)->update(['status' => 'ACTIVE', 'updated_at' => now()]);
                $membershipId = $membership->id;
            } else {
                $membershipId = DB::table('banjar_user_memberships')->insertGetId(['banjar_id' => $this->tenant->id(), 'user_id' => $user->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('tenant_role_assignments')->insertOrIgnore(['banjar_id' => $this->tenant->id(), 'membership_id' => $membershipId, 'role_id' => DB::table('roles')->where('code', 'KRAMA')->value('id'), 'scope_type' => 'BANJAR']);
            $this->query->table('krama')->where('id', $kramaId)->update(['user_id' => $user->id, 'version' => $krama->version + 1, 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'krama.account_activated', 'krama', $kramaId, ['user_id' => null], ['user_id' => $user->id, 'new_account' => $created]);
        });
    }

    // Warga lupa kata sandi: Admin Banjar mengembalikannya ke kata sandi awal; warga wajib menggantinya lagi.
    public function resetPassword(User $actor, int $kramaId): void
    {
        $this->access->authorize($actor, 'krama.configure');
        $krama = $this->query->table('krama')->where('id', $kramaId)->first();
        abort_unless($krama, 404);
        if (! $krama->user_id || ! self::wargaOnly($krama->user_id)) {
            // Akun pengurus/platform tidak boleh direset dari data krama (mencegah ambil alih akun berhak lebih tinggi).
            $this->fail('Kata sandi akun ini tidak dapat direset dari sini.');
        }
        DB::table('users')->where('id', $krama->user_id)->update(['password' => Hash::make(self::DEFAULT_PASSWORD), 'must_change_password' => true, 'remember_token' => null, 'updated_at' => now()]);
        $this->audit->record($this->tenant->id(), $actor->id, 'krama.account_password_reset', 'krama', $kramaId, [], ['user_id' => $krama->user_id]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['account' => $message]);
    }
}
