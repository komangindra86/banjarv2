<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Finance\FinanceManager;
use App\Modules\Payment\BankAccountManager;
use App\Modules\Payment\PaymentManager;
use App\Modules\Payment\SettlementManager;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\DB;

final class WithdrawalFixture
{
    /**
     * Finance fixture plus AVAILABLE QRIS funds produced through the real payment flow (iPaymu charge → paid →
     * release after a zero-day hold; Banjar receives the full bill amount), and a verified primary account.
     */
    public static function create(int $paidBills = 3): array
    {
        $fixture = FinanceFixture::create();
        DB::table('banjar_modules')->where('banjar_id', $fixture['tenant'])->where('code', 'PAYMENT')->update(['status' => 'ACTIVE']);
        IpaymuFake::configure(['payment.settlement_hold_days' => 0]);
        $fake = (new IpaymuFake)->install();
        $fake->defaultStatus = 1;
        $treasurer = User::findOrFail($fixture['users']['PETENGEN']);
        $kesinoman = User::findOrFail($fixture['users']['KESINOMAN']);
        app(FinanceManager::class)->issue($kesinoman, $fixture['event']);
        $submitter = self::platformUser();
        $approver = self::platformUser();
        foreach (DB::table('bills')->where('banjar_id', $fixture['tenant'])->where('status', 'UNPAID')->orderBy('id')->limit($paidBills)->pluck('id') as $bill) {
            app(CurrentBanjar::class)->set(DB::table('banjars')->find($fixture['tenant']));
            $payment = app(PaymentManager::class)->create(FinanceFixture::payer($fixture['tenant'], $bill), $bill);
            app(PaymentManager::class)->sync($payment);
            app(SettlementManager::class)->release($payment);
        }
        app(CurrentBanjar::class)->set(DB::table('banjars')->find($fixture['tenant']));
        app(BankAccountManager::class)->submit($treasurer, ['bank_code' => '129', 'account_number' => '0123456789018219', 'account_holder_name' => 'Banjar Uji Keuangan']);
        $account = DB::table('banjar_bank_accounts')->where('banjar_id', $fixture['tenant'])->value('id');
        app(BankAccountManager::class)->review($approver, $account, ['decision' => 'VERIFY']);

        return $fixture + ['platform' => [$submitter->id, $approver->id], 'bank_account' => $account];
    }

    public static function platformUser(): User
    {
        $user = User::factory()->create();
        DB::table('platform_role_assignments')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);

        return $user;
    }
}
