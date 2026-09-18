<?php

namespace App\Modules\Notification;

use App\Models\User;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Read-model for other modules' own events: never a second source of truth for bills/letters/activities.
 * notify()/notifyPermission() are called by other managers as a side effect, not as a user-driven action,
 * and take an explicit tenant id because platform-side flows (e.g. withdrawal resolution) run without ambient tenant context.
 */
final class NotificationManager
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private NotificationAccess $access, private ModuleGate $gate) {}

    private function moduleActive(int $tenant): bool
    {
        return DB::table('banjar_modules')->where('banjar_id', $tenant)->where('code', 'NOTIFICATION')->value('status') === 'ACTIVE';
    }

    public function visible(User $actor): Builder
    {
        $this->access->authorize($actor);

        return $this->query->table('notifications')->where('user_id', $actor->id);
    }

    public function unreadCount(User $actor): int
    {
        if (! $this->gate->allows('NOTIFICATION')) {
            return 0;
        }

        return $this->visible($actor)->whereNull('read_at')->count();
    }

    public function markRead(User $actor, int $id): ?object
    {
        $notification = $this->visible($actor)->where('id', $id)->first();
        abort_unless($notification, 404);
        if (! $notification->read_at) {
            $this->visible($actor)->where('id', $id)->update(['read_at' => now()]);
        }

        return $notification;
    }

    public function markAllRead(User $actor): void
    {
        $this->visible($actor)->whereNull('read_at')->update(['read_at' => now()]);
    }

    // Called from other modules' managers right after they create a state that needs someone's attention.
    public function notify(int $tenant, int $userId, string $type, string $title, string $body, ?string $url = null): void
    {
        if (! $this->moduleActive($tenant)) {
            return;
        }
        $id = DB::table('notifications')->insertGetId([
            'banjar_id' => $tenant, 'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'url' => $url,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! config('webpush.enabled')) {
            return;
        }
        $subscriptions = DB::table('push_subscriptions')->where('banjar_id', $tenant)->where('user_id', $userId)->whereNull('expires_at')->get(['id']);
        foreach ($subscriptions as $subscription) {
            // notification_id is freshly generated above, so this insert is always a first write, never a duplicate.
            $deliveryId = DB::table('notification_deliveries')->insertGetId([
                'banjar_id' => $tenant, 'notification_id' => $id, 'subscription_id' => $subscription->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            SendPushNotification::dispatch($tenant, $deliveryId)->onConnection('database')->afterCommit();
        }
    }

    // Approval/verification permissions are always tenant-wide (Access::assignments forces BANJAR scope for them), so a plain tenant-wide lookup is correct here.
    public function notifyPermission(int $tenant, string $permission, string $type, string $title, string $body, ?string $url = null): void
    {
        if (! $this->moduleActive($tenant)) {
            return;
        }
        $userIds = DB::table('banjar_user_memberships as m')->where('m.banjar_id', $tenant)->where('m.status', 'ACTIVE')
            ->join('tenant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.banjar_id', '=', 'm.banjar_id'))
            ->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('p.code', $permission)->where('u.status', 'ACTIVE')
            ->distinct()->pluck('m.user_id');
        foreach ($userIds as $userId) {
            $this->notify($tenant, (int) $userId, $type, $title, $body, $url);
        }
    }

    public function subscribe(User $actor, array $input): int
    {
        $this->access->authorize($actor);
        $data = Validator::make($input, [
            'endpoint' => ['required', 'string', 'max:2000', 'url'], 'p256dh' => ['required', 'string', 'max:255'], 'auth' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $hash = hash('sha256', $data['endpoint']);
        $tenant = $this->tenant->id();
        $existing = $this->query->table('push_subscriptions')->where('endpoint_hash', $hash)->first();
        $row = [
            'user_id' => $actor->id, 'endpoint_hash' => $hash, 'endpoint_encrypted' => Crypt::encryptString($data['endpoint']),
            'p256dh_key' => $data['p256dh'], 'auth_key' => $data['auth'], 'user_agent' => $data['user_agent'] ?? null,
            'expires_at' => null, 'last_used_at' => now(), 'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('push_subscriptions')->where('id', $existing->id)->update($row);

            return $existing->id;
        }

        return DB::table('push_subscriptions')->insertGetId($row + ['banjar_id' => $tenant, 'created_at' => now()]);
    }

    public function unsubscribe(User $actor, string $endpoint): void
    {
        $this->access->authorize($actor);
        // Soft-expire rather than delete: past deliveries may still reference this subscription (restrict FK).
        $this->query->table('push_subscriptions')->where('user_id', $actor->id)->where('endpoint_hash', hash('sha256', $endpoint))
            ->update(['expires_at' => now(), 'updated_at' => now()]);
    }
}
