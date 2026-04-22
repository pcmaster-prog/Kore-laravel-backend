<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Observers\AuditObserver;

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
        // ── Section 2.3: Audit logging via observers ─────────────────────
        \App\Models\User::observe(AuditObserver::class);
        \App\Models\Empleado::observe(AuditObserver::class);
        \App\Models\PayrollPeriod::observe(AuditObserver::class);
        \App\Models\PayrollEntry::observe(AuditObserver::class);

        // ── Section 3.2: Per-user/IP rate limiting ───────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
