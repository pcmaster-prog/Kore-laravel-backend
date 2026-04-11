# Kore — Notificaciones Push + Editar Asistencia + Cronómetro Comida: Backend

Stack: Laravel 11 · PostgreSQL · Railway · Firebase Cloud Messaging v1

---

## Variables de entorno a agregar en Railway

```env
FIREBASE_PROJECT_ID=kore-ops
FIREBASE_CLIENT_EMAIL=<client_email del JSON de cuenta de servicio>
FIREBASE_PRIVATE_KEY=<private_key del JSON — incluyendo los \n>
FIREBASE_VAPID_KEY=BHaKLf7ppoyI5o98NwBO506hcSkX9Sg1HAvEhP5G18oBdTpm6AXT9iTd9JwsGPc3OvWOj71OfxR4EScAfLoNBEc
```

**IMPORTANTE:** El JSON completo de la cuenta de servicio de Firebase
te lo pasará el cliente directamente. Extrae `client_email` y `private_key`
de ese archivo para las variables de entorno.

---

## PARTE 1 — Notificaciones Push

### 1a. Instalar SDK de Firebase para Laravel

```bash
composer require kreait/firebase-php
composer require kreait/laravel-firebase
```

### 1b. Configurar `config/firebase.php`

Publicar la configuración:
```bash
php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider"
```

En `.env` / Railway agregar:
```env
FIREBASE_CREDENTIALS={"type":"service_account","project_id":"kore-ops","private_key_id":"...","private_key":"...","client_email":"...","client_id":"108668666825488907281","auth_uri":"...","token_uri":"..."}
```

O mejor — usar un archivo JSON:
```env
GOOGLE_APPLICATION_CREDENTIALS=/app/storage/firebase-credentials.json
```

### 1c. Migración — tabla `fcm_tokens`

```php
Schema::create('fcm_tokens', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->string('token', 500);           // Token FCM del dispositivo
    $table->string('platform', 20)          // 'web' | 'android' | 'ios'
          ->default('web');
    $table->string('user_agent', 300)->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'token']);
    $table->index(['empresa_id', 'user_id']);
});
```

### 1d. Modelo `FcmToken`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FcmToken extends Model
{
    use HasUuids;

    protected $table = 'fcm_tokens';

    protected $fillable = [
        'user_id', 'empresa_id', 'token', 'platform', 'user_agent', 'last_used_at'
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### 1e. Servicio `NotificationService`

**Crear `app/Services/NotificationService.php`:**

```php
<?php
namespace App\Services;

use App\Models\FcmToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(private Messaging $messaging) {}

    /**
     * Enviar notificación a un usuario específico
     */
    public function sendToUser(
        string $userId,
        string $title,
        string $body,
        array $data = []
    ): void {
        $tokens = FcmToken::where('user_id', $userId)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) return;

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Enviar notificación a múltiples usuarios
     */
    public function sendToUsers(
        array $userIds,
        string $title,
        string $body,
        array $data = []
    ): void {
        $tokens = FcmToken::whereIn('user_id', $userIds)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) return;

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Enviar notificación a todos los admins/supervisores de una empresa
     */
    public function sendToManagers(
        string $empresaId,
        string $title,
        string $body,
        array $data = []
    ): void {
        $managerIds = \App\Models\User::where('empresa_id', $empresaId)
            ->whereIn('role', ['admin', 'supervisor'])
            ->pluck('id')
            ->toArray();

        if (empty($managerIds)) return;

        $this->sendToUsers($managerIds, $title, $body, $data);
    }

    /**
     * Enviar a lista de tokens
     */
    private function sendToTokens(
        array $tokens,
        string $title,
        string $body,
        array $data = []
    ): void {
        if (empty($tokens)) return;

        try {
            $notification = Notification::create($title, $body);

            // Convertir todos los valores de data a string (requerido por FCM)
            $stringData = collect($data)->map(fn($v) => (string) $v)->toArray();

            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($stringData);

            // Enviar a múltiples tokens
            $this->messaging->sendMulticast($message, $tokens);

        } catch (\Throwable $e) {
            Log::warning('Error enviando notificación FCM: ' . $e->getMessage());
        }
    }

    /**
     * Eliminar tokens inválidos
     */
    public function removeInvalidToken(string $token): void
    {
        FcmToken::where('token', $token)->delete();
    }
}
```

### 1f. Controller `FcmTokenController`

**Crear `app/Http/Controllers/Api/V1/FcmTokenController.php`:**

```php
<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    // POST /fcm/token — registrar token del dispositivo
    public function store(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'token'    => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'in:web,android,ios'],
        ]);

        FcmToken::updateOrCreate(
            ['user_id' => $u->id, 'token' => $data['token']],
            [
                'empresa_id'   => $u->empresa_id,
                'platform'     => $data['platform'] ?? 'web',
                'user_agent'   => $request->userAgent(),
                'last_used_at' => now(),
            ]
        );

        return response()->json(['message' => 'Token registrado']);
    }

    // DELETE /fcm/token — eliminar token al cerrar sesión
    public function destroy(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        FcmToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Token eliminado']);
    }
}
```

### 1g. Disparar notificaciones en eventos existentes

**En `AttendanceController` — al marcar entrada:**

```php
use App\Services\NotificationService;

// Después de registrar el check-in:
app(NotificationService::class)->sendToManagers(
    empresaId: $empresaId,
    title: '📍 Entrada registrada',
    body: "{$emp->full_name} marcó entrada a las " . now()->format('H:i'),
    data: [
        'type'        => 'attendance.check_in',
        'empleado_id' => $emp->id,
        'empresa_id'  => $empresaId,
    ]
);
```

**En `AttendanceController` — al marcar salida:**

```php
app(NotificationService::class)->sendToManagers(
    empresaId: $empresaId,
    title: '🚪 Salida registrada',
    body: "{$emp->full_name} marcó salida a las " . now()->format('H:i'),
    data: ['type' => 'attendance.check_out', 'empleado_id' => $emp->id]
);
```

**En `TasksController` — al completar tarea (done_pending):**

```php
app(NotificationService::class)->sendToManagers(
    empresaId: $u->empresa_id,
    title: '✅ Tarea lista para revisar',
    body: "{$emp->full_name} completó: {$task->title}",
    data: [
        'type'          => 'task.done_pending',
        'task_id'       => $task->id,
        'assignment_id' => $a->id,
    ]
);
```

**En `TasksController` — al asignar tarea al empleado:**

```php
app(NotificationService::class)->sendToUser(
    userId: $newUser->id,  // el empleado asignado
    title: '📋 Nueva tarea asignada',
    body: "Se te asignó: {$task->title}",
    data: ['type' => 'task.assigned', 'task_id' => $task->id]
);
```

### 1h. Rutas FCM en `api.php`

```php
use App\Http\Controllers\Api\V1\FcmTokenController;

Route::post('/fcm/token',    [FcmTokenController::class, 'store']);
Route::delete('/fcm/token',  [FcmTokenController::class, 'destroy']);
```

---

## PARTE 2 — Editar Asistencia (Admin/Supervisor)

### 2a. Nuevo endpoint en `AttendanceController`

```
PATCH /asistencia/ajustar/{empleado_id}/{fecha}
```

**Lógica:**

```php
// PATCH /asistencia/ajustar/{empleadoId}/{fecha}
public function ajustar(Request $request, string $empleadoId, string $fecha)
{
    $u = $request->user();
    if (!in_array($u->role, ['admin', 'supervisor'])) {
        return response()->json(['message' => 'No autorizado'], 403);
    }

    $data = $request->validate([
        'first_check_in_at'  => ['nullable', 'date_format:H:i'],
        'last_check_out_at'  => ['nullable', 'date_format:H:i'],
        'motivo'             => ['required', 'string', 'max:300'],
    ]);

    $emp = Empleado::where('empresa_id', $u->empresa_id)
        ->where('id', $empleadoId)
        ->firstOrFail();

    $day = AttendanceDay::firstOrCreate(
        [
            'empresa_id'  => $u->empresa_id,
            'empleado_id' => $emp->id,
            'date'        => $fecha,
        ],
        ['status' => 'present']
    );

    // Actualizar solo los campos que vienen en el request
    if (array_key_exists('first_check_in_at', $data) && $data['first_check_in_at']) {
        $day->first_check_in_at = \Carbon\Carbon::parse(
            $fecha . ' ' . $data['first_check_in_at']
        );
    }

    if (array_key_exists('last_check_out_at', $data) && $data['last_check_out_at']) {
        $day->last_check_out_at = \Carbon\Carbon::parse(
            $fecha . ' ' . $data['last_check_out_at']
        );
    }

    $day->save();

    // Log de auditoría
    \App\Services\ActivityLogger::log(
        $u->empresa_id,
        $u->id,
        null,
        'attendance.adjusted',
        'attendance_day',
        $day->id,
        [
            'empleado_name'     => $emp->full_name,
            'fecha'             => $fecha,
            'motivo'            => $data['motivo'],
            'adjusted_check_in' => $data['first_check_in_at'] ?? null,
            'adjusted_check_out'=> $data['last_check_out_at'] ?? null,
            'adjusted_by'       => $u->name,
        ],
        $request
    );

    return response()->json([
        'message' => 'Asistencia ajustada correctamente',
        'day'     => $day,
    ]);
}
```

### 2b. Ruta en `api.php`

```php
Route::patch('/asistencia/ajustar/{empleadoId}/{fecha}',
    [AttendanceController::class, 'ajustar']);
```

---

## PARTE 3 — Cronómetro de Comida

### 3a. Migración — agregar campos a `attendance_days`

```php
Schema::table('attendance_days', function (Blueprint $table) {
    $table->timestamp('lunch_start_at')->nullable()->after('last_check_out_at');
    $table->timestamp('lunch_end_at')->nullable()->after('lunch_start_at');
    // lunch_duration_minutes se calcula en el controller, no se guarda
});
```

### 3b. Endpoints en `AttendanceController`

```
POST /asistencia/comida/iniciar   → iniciarComida()
POST /asistencia/comida/terminar  → terminarComida()
```

**`iniciarComida()`:**

```php
public function iniciarComida(Request $request)
{
    [$u, $empresaId, $emp] = $this->authEmployee($request);

    $hoy = now()->toDateString();
    $day = AttendanceDay::where('empresa_id', $empresaId)
        ->where('empleado_id', $emp->id)
        ->where('date', $hoy)
        ->first();

    if (!$day || !$day->first_check_in_at) {
        return response()->json(['message' => 'Debes marcar entrada primero'], 422);
    }

    if ($day->lunch_start_at) {
        return response()->json(['message' => 'Ya iniciaste tu tiempo de comida'], 409);
    }

    $day->lunch_start_at = now();
    $day->save();

    // Notificar al supervisor
    app(NotificationService::class)->sendToManagers(
        empresaId: $empresaId,
        title: '🍽️ Inicio de comida',
        body: "{$emp->full_name} inició su tiempo de comida",
        data: ['type' => 'attendance.lunch_start', 'empleado_id' => $emp->id]
    );

    return response()->json([
        'message'         => 'Tiempo de comida iniciado',
        'lunch_start_at'  => $day->lunch_start_at->toISOString(),
        'lunch_limit_at'  => $day->lunch_start_at->addMinutes(30)->toISOString(),
    ]);
}
```

**`terminarComida()`:**

```php
public function terminarComida(Request $request)
{
    [$u, $empresaId, $emp] = $this->authEmployee($request);

    $hoy = now()->toDateString();
    $day = AttendanceDay::where('empresa_id', $empresaId)
        ->where('empleado_id', $emp->id)
        ->where('date', $hoy)
        ->first();

    if (!$day?->lunch_start_at) {
        return response()->json(['message' => 'No has iniciado tu tiempo de comida'], 422);
    }

    if ($day->lunch_end_at) {
        return response()->json(['message' => 'Tu tiempo de comida ya terminó'], 409);
    }

    $day->lunch_end_at = now();
    $day->save();

    $minutos = round($day->lunch_start_at->diffInMinutes($day->lunch_end_at));
    $excedio = $minutos > 30;

    // Notificar si se pasó del tiempo
    if ($excedio) {
        app(NotificationService::class)->sendToManagers(
            empresaId: $empresaId,
            title: '⚠️ Tiempo de comida excedido',
            body: "{$emp->full_name} tardó {$minutos} min en comida (límite: 30 min)",
            data: [
                'type'        => 'attendance.lunch_overtime',
                'empleado_id' => $emp->id,
                'minutos'     => $minutos,
            ]
        );
    }

    return response()->json([
        'message'        => 'Tiempo de comida terminado',
        'lunch_start_at' => $day->lunch_start_at->toISOString(),
        'lunch_end_at'   => $day->lunch_end_at->toISOString(),
        'minutos'        => $minutos,
        'excedio'        => $excedio,
    ]);
}
```

### 3c. Incluir datos de comida en el endpoint de asistencia por fecha

En el método que devuelve la asistencia del día para el manager,
agregar estos campos al presenter:

```php
'lunch_start_at'       => $day->lunch_start_at?->toISOString(),
'lunch_end_at'         => $day->lunch_end_at?->toISOString(),
'lunch_minutes'        => $day->lunch_start_at && $day->lunch_end_at
    ? round($day->lunch_start_at->diffInMinutes($day->lunch_end_at))
    : ($day->lunch_start_at ? round($day->lunch_start_at->diffInMinutes(now())) : null),
'lunch_active'         => $day->lunch_start_at && !$day->lunch_end_at,
'lunch_overtime'       => $day->lunch_start_at && !$day->lunch_end_at
    ? now()->diffInMinutes($day->lunch_start_at) > 30
    : false,
```

### 3d. Rutas en `api.php`

```php
Route::post('/asistencia/comida/iniciar',   [AttendanceController::class, 'iniciarComida']);
Route::post('/asistencia/comida/terminar',  [AttendanceController::class, 'terminarComida']);
Route::patch('/asistencia/ajustar/{empleadoId}/{fecha}', [AttendanceController::class, 'ajustar']);
```

---

## Resumen de archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `composer.json` | Modificar — agregar `kreait/laravel-firebase` |
| `database/migrations/..._create_fcm_tokens_table.php` | Crear |
| `database/migrations/..._add_lunch_fields_to_attendance_days.php` | Crear |
| `app/Models/FcmToken.php` | Crear |
| `app/Services/NotificationService.php` | Crear |
| `app/Http/Controllers/Api/V1/FcmTokenController.php` | Crear |
| `app/Http/Controllers/Api/V1/AttendanceController.php` | Modificar — agregar `ajustar()`, `iniciarComida()`, `terminarComida()` |
| `app/Http/Controllers/Api/V1/TasksController.php` | Modificar — disparar notificaciones en assign y done_pending |
| `routes/api.php` | Modificar — agregar rutas FCM, comida y ajuste asistencia |
