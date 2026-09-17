<?php

use App\Models\User;
use App\Modules\Activity\ActivityManager;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\SettlementManager;
use App\Modules\Payment\WithdrawalManager;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Standalone child process: independent MySQL connection and application instance.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
    throw new RuntimeException('Race worker requires banjarv2_testing.');
}
$data = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
app(CurrentBanjar::class)->set(DB::table('banjars')->find($data['tenant']));
$actor = User::findOrFail($data['actor']);
if (str_starts_with($data['action'], 'payment-')) {
    config(['payment.enabled' => true, 'payment.server_key' => 'race-key', 'payment.merchant_id' => 'RACE', 'payment.production' => false, 'payment.settlement_hold_days' => 0]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $order = $request->method() === 'POST' ? $request['transaction_details']['order_id'] : basename(dirname(parse_url($request->url(), PHP_URL_PATH)));
        $p = DB::table('payments')->where('order_id', $order)->firstOrFail();

        return Http::response(['order_id' => $order, 'transaction_id' => 'race-'.$p->id, 'merchant_id' => 'RACE', 'payment_type' => 'qris', 'currency' => 'IDR', 'gross_amount' => $p->gross_amount.'.00',
            'transaction_status' => $request->method() === 'POST' ? 'pending' : 'settlement', 'status_code' => '200', 'fraud_status' => 'accept']);
    });
}
$proof = null;
if ($data['action'] === 'withdraw-paid') {
    // Prepare the upload before the barrier so both racers reach the critical section together.
    $image = imagecreatetruecolor(40, 20);
    $path = tempnam(sys_get_temp_dir(), 'proof').'.png';
    imagepng($image, $path);
    $proof = new UploadedFile($path, 'bukti.png', 'image/png', null, true);
}
echo "READY\n";
fflush(STDOUT);
file_put_contents($data['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($data['gate'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
try {
    $finance = app(FinanceManager::class);
    match ($data['action']) {
        'issue' => $finance->issue($actor, $data['id']),
        'settle' => $finance->settle($actor, $data['id'], $data['input']),
        'cash' => $finance->cash($actor, $data['input']),
        'reverse' => $finance->reverse($actor, $data['id'], ['reason' => 'Koreksi paralel pengujian']),
        'payment-create' => app(PaymentManager::class)->create($actor, $data['id']),
        'payment-sync' => app(PaymentManager::class)->sync($data['id']),
        'payment-release' => app(SettlementManager::class)->release($data['id']),
        'activity-generate' => app(ActivityManager::class)->generate($actor, $data['id']),
        'activity-record' => app(ActivityManager::class)->record($actor, $data['id'], $data['participant'], ['status' => 'TIDAK_HADIR']),
        'activity-verify' => app(ActivityManager::class)->verify($actor, $data['id']),
        'withdraw-request' => app(WithdrawalManager::class)->request($actor, $data['input']),
        'withdraw-cancel' => app(WithdrawalManager::class)->cancel($actor, $data['id'], ['note' => 'Pembatalan paralel pengujian']),
        'withdraw-claim' => app(WithdrawalManager::class)->claim($actor, $data['id']),
        'withdraw-paid' => app(WithdrawalManager::class)->markPaid($actor, $data['id'], ['bank_reference' => $data['reference'], 'transferred_at' => now('Asia/Makassar')->format('Y-m-d\TH:i'), 'proof' => $proof]),
        'withdraw-failed' => app(WithdrawalManager::class)->markFailed($actor, $data['id'], ['note' => 'Kegagalan paralel pengujian', 'confirm_not_sent' => '1']),
    };
    echo "SUCCESS\n";
} catch (ValidationException|HttpException $e) {
    echo "REJECTED\n";
}
