<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\PayrollReceipt;
use App\Models\GratificationReceipt;
use App\Policies\PayrollReceiptPolicy;
use App\Policies\GratificationReceiptPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        PayrollReceipt::class => PayrollReceiptPolicy::class,
        GratificationReceipt::class => GratificationReceiptPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('admin', function ($user) {
            return $user->role === 'admin';
        });

        Gate::define('supervisor', function ($user) {
            return in_array($user->role, ['admin', 'supervisor']);
        });

        Gate::define('empleado', function ($user) {
            return in_array($user->role, ['empleado', 'admin', 'supervisor']);
        });

        Gate::define('manage-users', function ($user) {
            return $user->role === 'admin';
        });

        Gate::define('manage-tasks', function ($user) {
            return in_array($user->role, ['admin', 'supervisor']);
        });

        Gate::define('manage-payroll', function ($user) {
            return $user->role === 'admin';
        });

        Gate::define('manage-attendance', function ($user) {
            return in_array($user->role, ['admin', 'supervisor']);
        });
    }
}
