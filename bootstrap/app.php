<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', 
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
         $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No autenticado'
                ], 401);
            }
            return null; // deja el comportamiento normal fuera de /api
        });

        // Section 3.1: Global API exception handler — prevents stack traces in production
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') && !($e instanceof AuthenticationException)) {
                $errorId = uniqid('err_');

                \Illuminate\Support\Facades\Log::error("API Error [{$errorId}]", [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                    'url'       => $request->fullUrl(),
                    'user_id'   => $request->user()?->id,
                ]);

                // Validation exceptions: devolver los errores normalmente
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return null; // dejar que Laravel lo maneje
                }

                // Model not found → 404
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'message'  => 'Recurso no encontrado',
                        'error_id' => $errorId,
                    ], 404);
                }

                // HTTP exceptions (403, 409, etc.) → respetar su código
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    return response()->json([
                        'message'  => $e->getMessage() ?: 'Error de solicitud',
                        'error_id' => $errorId,
                    ], $e->getStatusCode());
                }

                // Todo lo demás → 500 genérico (sin stack trace)
                return response()->json([
                    'message'  => 'Error interno del servidor',
                    'error_id' => $errorId,
                ], 500);
            }
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // Cierre automático de asistencia — revisa cada minuto qué empresas deben cerrar hoy
        $schedule->command('attendance:auto-close')->everyMinute()->withoutOverlapping();
    })
    ->create();