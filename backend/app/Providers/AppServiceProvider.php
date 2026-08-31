<?php

namespace App\Providers;

use App\Models\Customer;
use App\Observers\CustomerObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Customer observer for automatic queue sync
        Customer::observe(CustomerObserver::class);

        // Customer portal tokens are intentionally separate from Laravel's
        // staff guard. Key this workflow by the signed bearer token so dozens
        // of subscribers behind one ISP/NAT address do not throttle each other.
        RateLimiter::for('customer-location', function (Request $request): Limit {
            $token = (string) $request->bearerToken();
            $identity = $token !== '' ? hash('sha256', $token) : 'ip:'.$request->ip();
            return Limit::perMinute(12)->by('customer-location:'.$identity)
                ->response(fn () => response()->json([
                    'status' => 'error',
                    'message' => 'Location sharing was attempted several times. Please wait one minute, then try again once.',
                ], 429));
        });
    }
}
