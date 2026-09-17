<?php

namespace App\Providers;

use App\Modules\Payment\MidtransPaymentGateway;
use App\Modules\Payment\PaymentGatewayInterface;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentBanjar::class);
        $this->app->bind(PaymentGatewayInterface::class, MidtransPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn ($request) => Limit::perMinute(20)->by($request->ip()));
        View::composer('*', function ($view) {
            $view->with('currentBanjar', app(CurrentBanjar::class)->optional());
        });
        View::composer(['admin.*', 'activity.*', 'asset.*'], function ($view) {
            $view->with('access', app(Access::class));
            $view->with('gate', app(ModuleGate::class));
        });
        View::composer('finance.*', function ($view) {
            $access = app(Access::class);
            $view->with('fa', $access);
            foreach (['canCash' => 'cash.manage', 'canIssue' => 'billing.issue', 'canDedosan' => 'dedosan.issue', 'canApprove' => 'dedosan.approve'] as $key => $permission) {
                $view->with($key, $access->tenant(auth()->user(), $permission));
            }
        });
        View::composer('asset.*', function ($view) {
            $access = app(Access::class);
            foreach (['canManage' => 'asset.manage', 'canDispose' => 'asset.dispose'] as $key => $permission) {
                $view->with($key, $access->tenant(auth()->user(), $permission));
            }
        });
    }
}
