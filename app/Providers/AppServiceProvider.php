<?php

namespace App\Providers;

use App\Events\AttendanceCheckedIn;
use App\Events\TaskAssigned;
use App\Listeners\AssignTasksOnCheckIn;
use App\Listeners\SendTaskAssignedNotification;
use App\Models\Empleado;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
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
        User::observe(AuditObserver::class);
        Empleado::observe(AuditObserver::class);
        PayrollPeriod::observe(AuditObserver::class);
        PayrollEntry::observe(AuditObserver::class);

        // ── Event listeners ──────────────────────────────────────────────
        Event::listen(TaskAssigned::class, SendTaskAssignedNotification::class);
        Event::listen(AttendanceCheckedIn::class, AssignTasksOnCheckIn::class);

        // ── Section 3.2: Per-user/IP rate limiting ───────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
