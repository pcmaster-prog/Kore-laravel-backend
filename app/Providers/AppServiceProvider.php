<?php

namespace App\Providers;

use App\Events\AttendanceCheckedIn;
use App\Events\TaskAssigned;
use App\Listeners\SendCheckInNotificationToManagers;
use App\Listeners\SendTaskAssignedNotification;
use App\Observers\AuditObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        // ── Event listeners ──────────────────────────────────────────────
        Event::listen(TaskAssigned::class, SendTaskAssignedNotification::class);
        Event::listen(AttendanceCheckedIn::class, SendCheckInNotificationToManagers::class);

        // ── Section 3.2: Per-user/IP rate limiting ───────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
