<?php

namespace App\Traits;

use App\Models\User;

/**
 * Helper para bloqueo progresivo de cuentas tras intentos fallidos.
 */
trait HandlesLoginLockout
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    /**
     * Verifica si la cuenta está bloqueada. Si lo está, devuelve el mensaje.
     */
    protected function accountIsLocked(User $user): ?string
    {
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $remaining = $user->locked_until->diffForHumans(['parts' => 2, 'short' => true]);

            return "Cuenta bloqueada por seguridad. Inténtalo de nuevo en {$remaining}.";
        }

        return null;
    }

    /**
     * Registra un intento fallido y bloquea la cuenta si se supera el límite.
     */
    protected function recordFailedLogin(User $user): void
    {
        $user->failed_login_attempts = ($user->failed_login_attempts ?? 0) + 1;
        $user->last_failed_login_at = now();

        if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
            $user->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->save();
    }

    /**
     * Resetea los intentos fallidos tras un login exitoso.
     */
    protected function resetFailedLoginAttempts(User $user): void
    {
        if ($user->failed_login_attempts > 0 || $user->locked_until || $user->last_failed_login_at) {
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->last_failed_login_at = null;
            $user->save();
        }
    }
}
