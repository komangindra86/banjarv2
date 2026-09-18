<?php

namespace App\Modules\Notification;

use App\Platform\Tenancy\TenantQuery;
use App\Platform\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public int $banjarId, public int $deliveryId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TenantRunner $runner, TenantQuery $query): void
    {
        if (! config('webpush.enabled')) {
            return;
        }
        $runner->run($this->banjarId, function () use ($query) {
            $delivery = $query->table('notification_deliveries')->where('id', $this->deliveryId)->first();
            if (! $delivery || $delivery->status !== 'PENDING') {
                return;
            }
            $notification = $query->table('notifications')->where('id', $delivery->notification_id)->first();
            $subscription = $query->table('push_subscriptions')->where('id', $delivery->subscription_id)->first();
            if (! $notification || ! $subscription) {
                $query->table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'FAILED', 'last_error' => 'Data tidak ditemukan.', 'updated_at' => now()]);

                return;
            }
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('webpush.subject'), 'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key'),
            ]]);
            $sub = Subscription::create([
                'endpoint' => Crypt::decryptString($subscription->endpoint_encrypted),
                'keys' => ['p256dh' => $subscription->p256dh_key, 'auth' => $subscription->auth_key],
            ]);
            // Generic lock-screen payload only; never the notification body's tenant-specific detail source, matching blueprint's redaction rule.
            $payload = json_encode(['title' => $notification->title, 'body' => $notification->body, 'url' => $notification->url], JSON_THROW_ON_ERROR);
            $report = $webPush->sendOneNotification($sub, $payload);
            $expired = in_array($report->getResponse()?->getStatusCode(), [404, 410], true);
            $retry = false;
            DB::transaction(function () use ($query, $delivery, $subscription, $report, $expired, &$retry) {
                if ($report->isSuccess()) {
                    $query->table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'SENT', 'sent_at' => now(), 'updated_at' => now()]);
                    $query->table('push_subscriptions')->where('id', $subscription->id)->update(['last_used_at' => now(), 'updated_at' => now()]);
                } elseif ($expired) {
                    $query->table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'EXPIRED', 'last_error' => mb_substr($report->getReason(), 0, 500), 'updated_at' => now()]);
                    $query->table('push_subscriptions')->where('id', $subscription->id)->update(['expires_at' => now(), 'updated_at' => now()]);
                } else {
                    $attempts = $delivery->attempts + 1;
                    $retry = $attempts < 10;
                    $query->table('notification_deliveries')->where('id', $delivery->id)
                        ->update(['status' => $retry ? 'PENDING' : 'FAILED', 'attempts' => $attempts, 'last_error' => mb_substr($report->getReason(), 0, 500), 'updated_at' => now()]);
                }
            });
            if ($retry) {
                throw new \RuntimeException('Push delivery failed, retrying: '.$report->getReason());
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notification_deliveries')->where('banjar_id', $this->banjarId)->where('id', $this->deliveryId)
            ->where('status', 'PENDING')->update(['status' => 'FAILED', 'last_error' => 'Batas percobaan tercapai.', 'updated_at' => now()]);
    }
}
