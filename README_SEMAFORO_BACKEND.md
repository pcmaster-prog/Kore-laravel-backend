# Kore — Semáforo de Desempeño: Backend

Stack: Laravel 11 · PostgreSQL · Railway

---

## Resumen del Feature

Sistema de evaluación de desempeño para empleados nuevos. El admin activa
la evaluación para un empleado específico. Admin, supervisores y compañeros
evalúan. El admin desactiva cuando termina y ve el resultado final.

**IMPORTANTE:** Este módulo se integra al sistema existente de Kore sin
modificar ningún controller, modelo o migración existente.

---

## 1. Migraciones (3 tablas nuevas)

### 1a. `employee_evaluations` — Control de qué empleados están siendo evaluados

```php
Schema::create('employee_evaluations', function (Blueprint $table) {
    $table->uuid('id')->primary()
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
    $table->foreignUuid('activated_by')->constrained('users')->cascadeOnDelete();
    $table->boolean('is_active')->default(true);
    $table->timestamp('activated_at')->useCurrent();
    $table->timestamp('deactivated_at')->nullable();
    $table->timestamps();

    $table->index(['empresa_id', 'is_active']);
    $table->index(['empleado_id', 'is_active']);
});
```

### 1b. `desempeno_evaluaciones` — Evaluaciones de admin y supervisor (8 criterios)

```php
Schema::create('desempeno_evaluaciones', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('employee_evaluation_id')->constrained('employee_evaluations')->cascadeOnDelete();
    $table->foreignUuid('evaluador_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('evaluado_id')->constrained('empleados')->cascadeOnDelete();
    $table->string('evaluador_rol', 20); // 'admin' | 'supervisor'

    // 8 criterios — 1 a 5
    $table->unsignedTinyInteger('puntualidad');
    $table->unsignedTinyInteger('responsabilidad');
    $table->unsignedTinyInteger('actitud_trabajo');
    $table->unsignedTinyInteger('orden_limpieza');
    $table->unsignedTinyInteger('atencion_cliente');
    $table->unsignedTinyInteger('trabajo_equipo');
    $table->unsignedTinyInteger('iniciativa');
    $table->unsignedTinyInteger('aprendizaje_adaptacion');

    // Acciones a tomar (array)
    $table->json('acciones')->nullable();
    // Valores posibles: 'mantener_desempeno', 'capacitacion', 'llamada_atencion', 'seguimiento_30_dias'

    $table->text('observaciones')->nullable();
    $table->timestamps();

    // Un evaluador solo puede evaluar una vez por activación
    $table->unique(['employee_evaluation_id', 'evaluador_id']);
    $table->index(['empresa_id', 'evaluado_id']);
});
```

### 1c. `desempeno_peer_evaluaciones` — Evaluaciones entre compañeros (4 criterios, anónimas)

```php
Schema::create('desempeno_peer_evaluaciones', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('employee_evaluation_id')->constrained('employee_evaluations')->cascadeOnDelete();
    $table->foreignUuid('evaluador_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('evaluado_id')->constrained('empleados')->cascadeOnDelete();

    // 4 criterios — 1 a 5
    $table->unsignedTinyInteger('colaboracion');
    $table->unsignedTinyInteger('puntualidad');
    $table->unsignedTinyInteger('actitud');
    $table->unsignedTinyInteger('comunicacion');

    $table->timestamps();

    // Un compañero solo puede evaluar una vez por activación
    $table->unique(['employee_evaluation_id', 'evaluador_id']);
    // No puede evaluarse a sí mismo (se valida en el controller)
    $table->index(['empresa_id', 'evaluado_id']);
});
```

---

## 2. Modelos Eloquent

### `app/Models/EmployeeEvaluation.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeEvaluation extends Model
{
    use HasUuids;

    protected $table = 'employee_evaluations';

    protected $fillable = [
        'empresa_id', 'empleado_id', 'activated_by',
        'is_active', 'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'activated_at'    => 'datetime',
        'deactivated_at'  => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function activatedBy()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function evaluaciones()
    {
        return $this->hasMany(DesempenoEvaluacion::class);
    }

    public function peerEvaluaciones()
    {
        return $this->hasMany(DesempenoPeerEvaluacion::class);
    }
}
```

### `app/Models/DesempenoEvaluacion.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DesempenoEvaluacion extends Model
{
    use HasUuids;

    protected $table = 'desempeno_evaluaciones';

    protected $fillable = [
        'empresa_id', 'employee_evaluation_id', 'evaluador_id', 'evaluado_id',
        'evaluador_rol',
        'puntualidad', 'responsabilidad', 'actitud_trabajo', 'orden_limpieza',
        'atencion_cliente', 'trabajo_equipo', 'iniciativa', 'aprendizaje_adaptacion',
        'acciones', 'observaciones',
    ];

    protected $casts = [
        'acciones' => 'array',
    ];

    public function evaluador()
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function evaluado()
    {
        return $this->belongsTo(Empleado::class, 'evaluado_id');
    }

    // Calcula el total sobre 40
    public function getTotalAttribute(): int
    {
        return $this->puntualidad + $this->responsabilidad + $this->actitud_trabajo
             + $this->orden_limpieza + $this->atencion_cliente + $this->trabajo_equipo
             + $this->iniciativa + $this->aprendizaje_adaptacion;
    }

    // Calcula el porcentaje sobre 100
    public function getPorcentajeAttribute(): float
    {
        return round(($this->total / 40) * 100, 1);
    }
}
```

### `app/Models/DesempenoPeerEvaluacion.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DesempenoPeerEvaluacion extends Model
{
    use HasUuids;

    protected $table = 'desempeno_peer_evaluaciones';

    protected $fillable = [
        'empresa_id', 'employee_evaluation_id', 'evaluador_id', 'evaluado_id',
        'colaboracion', 'puntualidad', 'actitud', 'comunicacion',
    ];

    public function evaluador()
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    // Promedio sobre 5
    public function getPromedioAttribute(): float
    {
        return round(($this->colaboracion + $this->puntualidad
                    + $this->actitud + $this->comunicacion) / 4, 2);
    }

    // Porcentaje sobre 100
    public function getPorcentajeAttribute(): float
    {
        return round($this->promedio * 20, 1);
    }
}
```

---

## 3. Controllers

### `app/Http/Controllers/Api/V1/SemaforoController.php`

#### ENDPOINTS ADMIN:

```
GET    /semaforo/empleados                    → index()
POST   /semaforo/empleados/{empleadoId}/activar   → activar()
POST   /semaforo/empleados/{empleadoId}/desactivar → desactivar()
GET    /semaforo/empleados/{empleadoId}/resultado  → resultado()
POST   /semaforo/evaluaciones                 → evaluarAdmin()
```

#### ENDPOINTS SUPERVISOR:

```
GET    /semaforo/mis-evaluaciones-pendientes  → pendientesSupervisor()
POST   /semaforo/evaluaciones                 → evaluarAdmin() (mismo endpoint, el rol se detecta del token)
```

#### ENDPOINTS EMPLEADO:

```
GET    /semaforo/companeros                   → companeros()
POST   /semaforo/peer-evaluaciones            → peerEvaluar()
```

---

### Lógica detallada de cada método:

**`index()` — Admin: lista empleados con evaluación activa o pasada**
- Solo admin
- Devuelve empleados activos de la empresa con su estado de evaluación:

```json
[
  {
    "empleado": { "id": "...", "full_name": "...", "position_title": "..." },
    "evaluation": {
      "id": "...",
      "is_active": true,
      "activated_at": "2026-03-25T10:00:00Z",
      "evaluaciones_count": 2,
      "peer_evaluaciones_count": 3,
      "semaforo": "verde" | "amarillo" | "rojo" | null
    }
  }
]
```

**`activar()` — Admin activa evaluación para un empleado**
- Solo admin
- Si ya existe una evaluación activa para ese empleado → devolver 409
- Crear `EmployeeEvaluation` con `is_active = true`, `activated_by = user->id`
- Responder con la evaluación creada

**`desactivar()` — Admin desactiva evaluación**
- Solo admin
- Buscar `EmployeeEvaluation` activa del empleado
- Poner `is_active = false`, `deactivated_at = now()`
- Ya no aparece en listas de pendientes para evaluar
- El resultado sigue visible para el admin

**`resultado()` — Admin ve el resultado completo**
- Solo admin
- Buscar la evaluación más reciente del empleado (activa o no)
- Calcular score final:
  ```php
  // Score de evaluaciones admin/supervisor (promedio de todos los que evaluaron)
  $evalScore = $evaluation->evaluaciones->avg(fn($e) =>
      ($e->puntualidad + $e->responsabilidad + $e->actitud_trabajo +
       $e->orden_limpieza + $e->atencion_cliente + $e->trabajo_equipo +
       $e->iniciativa + $e->aprendizaje_adaptacion) / 40 * 100
  );

  // Score peer (promedio de todos los compañeros)
  $peerScore = $evaluation->peerEvaluaciones->avg(fn($p) =>
      ($p->colaboracion + $p->puntualidad + $p->actitud + $p->comunicacion) / 4 * 100
  );

  // Score final combinado (70% eval, 30% peer)
  $hasEval = $evaluation->evaluaciones->isNotEmpty();
  $hasPeer = $evaluation->peerEvaluaciones->isNotEmpty();

  if ($hasEval && $hasPeer) {
      $finalScore = ($evalScore * 0.70) + ($peerScore * 0.30);
  } elseif ($hasEval) {
      $finalScore = $evalScore;
  } elseif ($hasPeer) {
      $finalScore = $peerScore;
  } else {
      $finalScore = null;
  }

  // Semáforo
  $semaforo = match(true) {
      $finalScore >= 80 => 'verde',
      $finalScore >= 60 => 'amarillo',
      default           => 'rojo',
  };
  ```
- Devolver:
```json
{
  "empleado": { "id": "...", "full_name": "...", "position_title": "..." },
  "is_active": false,
  "activated_at": "...",
  "deactivated_at": "...",
  "final_score": 87.5,
  "semaforo": "verde",
  "eval_score": 90.0,
  "peer_score": 80.0,
  "evaluaciones": [
    {
      "evaluador": { "full_name": "Adan Cuellar", "role": "admin" },
      "evaluador_rol": "admin",
      "puntualidad": 4, "responsabilidad": 5, "actitud_trabajo": 4,
      "orden_limpieza": 4, "atencion_cliente": 5, "trabajo_equipo": 4,
      "iniciativa": 3, "aprendizaje_adaptacion": 4,
      "total": 33, "porcentaje": 82.5,
      "acciones": ["mantener_desempeno"],
      "observaciones": "Buen arranque",
      "created_at": "..."
    }
  ],
  "peer_evaluaciones": [
    {
      "evaluador": { "full_name": "Kevin Alfredo" }, // Admin ve quién evaluó
      "colaboracion": 4, "puntualidad": 5, "actitud": 4, "comunicacion": 4,
      "promedio": 4.25, "porcentaje": 85.0
    }
  ],
  "peer_count": 3
}
```

**`evaluarAdmin()` — Admin o supervisor evalúa a un empleado**
- Admin y supervisor pueden usar este endpoint
- Validar:
  - El `empleado_id` tiene evaluación activa (`is_active = true`)
  - El evaluador no ha evaluado ya a este empleado en esta activación
  - El evaluador es admin o supervisor
- Body:
```json
{
  "empleado_id": "uuid",
  "puntualidad": 4,
  "responsabilidad": 5,
  "actitud_trabajo": 4,
  "orden_limpieza": 4,
  "atencion_cliente": 5,
  "trabajo_equipo": 4,
  "iniciativa": 3,
  "aprendizaje_adaptacion": 4,
  "acciones": ["mantener_desempeno", "capacitacion"],
  "observaciones": "Buen arranque"
}
```
- El campo `evaluador_rol` se detecta automáticamente del token (`$u->role`)
- Si ya existe evaluación de este evaluador → devolver 409 con mensaje "Ya evaluaste a este empleado"
- Crear `DesempenoEvaluacion`
- Responder con el score calculado

**`pendientesSupervisor()` — Supervisor ve qué empleados puede evaluar**
- Solo supervisor
- Lista empleados con `is_active = true` que el supervisor **no ha evaluado aún**
- Respuesta igual que `index()` pero filtrada

**`companeros()` — Empleado ve a quién puede evaluar**
- Solo empleado
- Lista compañeros activos de la empresa con evaluación activa (`is_active = true`)
- Excluir al propio usuario
- Incluir `already_evaluated: true/false` por compañero

```json
{
  "companeros": [
    {
      "empleado": { "id": "...", "full_name": "...", "position_title": "..." },
      "evaluation_id": "uuid",
      "already_evaluated": false
    }
  ],
  "progress": { "evaluated": 1, "total": 3 }
}
```

**`peerEvaluar()` — Empleado evalúa a un compañero**
- Solo empleado con rol `empleado`
- Validar:
  - `evaluado_id` != `evaluador_id` (no puede evaluarse a sí mismo)
  - El evaluado tiene evaluación activa
  - El evaluador no ha evaluado ya a este compañero en esta activación
  - Ambos son de la misma empresa
- Body:
```json
{
  "employee_evaluation_id": "uuid",
  "evaluado_empleado_id": "uuid",
  "colaboracion": 4,
  "puntualidad": 5,
  "actitud": 4,
  "comunicacion": 4
}
```
- Crear `DesempenoPeerEvaluacion`
- **NUNCA** devolver `evaluador_id` en respuestas accesibles por empleados

---

## 4. Módulo en `empresa_modules`

Agregar `semaforo` como módulo. En `RegisterController.php`:

```php
$defaultModules = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas', 'semaforo'];
```

Las rutas usarán `middleware('module:semaforo')`.

---

## 5. Rutas en `routes/api.php`

```php
use App\Http\Controllers\Api\V1\SemaforoController;

Route::middleware(['module:semaforo'])->group(function () {
    // Admin
    Route::get('/semaforo/empleados',                            [SemaforoController::class, 'index']);
    Route::post('/semaforo/empleados/{empleadoId}/activar',      [SemaforoController::class, 'activar']);
    Route::post('/semaforo/empleados/{empleadoId}/desactivar',   [SemaforoController::class, 'desactivar']);
    Route::get('/semaforo/empleados/{empleadoId}/resultado',     [SemaforoController::class, 'resultado']);

    // Admin + Supervisor
    Route::post('/semaforo/evaluaciones',                        [SemaforoController::class, 'evaluarAdmin']);
    Route::get('/semaforo/mis-evaluaciones-pendientes',          [SemaforoController::class, 'pendientesSupervisor']);

    // Empleado
    Route::get('/semaforo/companeros',                           [SemaforoController::class, 'companeros']);
    Route::post('/semaforo/peer-evaluaciones',                   [SemaforoController::class, 'peerEvaluar']);
});
```

---

## 6. Seguridad crítica

```php
// En peerEvaluar() y companeros() — NUNCA exponer identidad del evaluador
// La respuesta del empleado NUNCA debe contener evaluador_id ni nombre del evaluador

// En resultado() — solo admin puede llamar este endpoint
// Validar: $u->role === 'admin'

// En todas las queries — siempre filtrar por empresa_id del usuario autenticado
// NUNCA permitir cross-company data access
```

---

## 7. SQL para empresas existentes en Railway

```sql
INSERT INTO empresa_modules (id, empresa_id, module_slug, enabled, created_at, updated_at)
SELECT gen_random_uuid(), id, 'semaforo', true, NOW(), NOW()
FROM empresas
ON CONFLICT (empresa_id, module_slug) DO NOTHING;
```

---

## 8. Resumen de archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `database/migrations/..._create_employee_evaluations_table.php` | Crear |
| `database/migrations/..._create_desempeno_evaluaciones_table.php` | Crear |
| `database/migrations/..._create_desempeno_peer_evaluaciones_table.php` | Crear |
| `app/Models/EmployeeEvaluation.php` | Crear |
| `app/Models/DesempenoEvaluacion.php` | Crear |
| `app/Models/DesempenoPeerEvaluacion.php` | Crear |
| `app/Http/Controllers/Api/V1/SemaforoController.php` | Crear |
| `routes/api.php` | Modificar — agregar rutas semáforo |
| `app/Http/Controllers/Api/V1/RegisterController.php` | Modificar — agregar módulo semaforo |
