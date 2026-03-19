# Kore API — Cambios Pendientes (Backend)

Stack: Laravel 11 · PostgreSQL · Railway (producción)

---

## 1. Eliminar Empleado

**Contexto:** Actualmente `UsersController` solo permite activar/desactivar un usuario (`PATCH /usuarios/{id}/toggle-status`). Se requiere borrado permanente.

**Endpoint nuevo:**
```
DELETE /api/v1/usuarios/{id}
```

**Lógica:**
- Solo admin puede ejecutarlo
- Borrar en cascada o con soft-delete:
  - `users` → el registro del usuario
  - `empleados` → el registro vinculado por `user_id`
  - `task_assignees` → asignaciones de tareas
  - `attendance_days` y `attendance_events` → registros de asistencia
  - `evidences` → evidencias subidas (y sus archivos físicos en S3/R2)
  - `payroll_entries` → entradas de nómina (solo si el periodo es `draft`, no tocar `approved`)
- Si el empleado tiene periodos de nómina aprobados, NO borrar las `payroll_entries` — solo desvincula el `empleado_id` o mantén el registro por integridad histórica
- Responder con `200 { message: 'Empleado eliminado' }` o `409` si tiene restricciones

**Archivo a modificar:** `app/Http/Controllers/Api/V1/UsersController.php`

Agregar método:
```php
public function destroy(Request $request, string $id)
{
    // 1. requireAdmin
    // 2. Buscar user + empleado vinculado
    // 3. Borrar archivos de evidencias del storage
    // 4. Borrar en cascada los modelos relacionados
    // 5. Borrar empleado y user
}
```

**Ruta a agregar en `routes/api.php`:**
```php
Route::delete('/usuarios/{id}', [UsersController::class, 'destroy']);
```

---

## 2. Registro de Empresa y Módulos

**Contexto:** Actualmente la empresa se crea manualmente en BD. Se necesita un flujo de registro público donde una empresa se registra, elige su plan/módulos, y se crea el admin inicial.

### 2a. Migración — tabla `empresa_modules`

```sql
CREATE TABLE empresa_modules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    module_slug VARCHAR(50) NOT NULL,  -- 'tareas' | 'asistencia' | 'nomina' | 'configuracion'
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(empresa_id, module_slug)
);
```

### 2b. Migración — campos adicionales en `empresas`

```sql
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS plan VARCHAR(20) DEFAULT 'starter';
-- 'starter' | 'pro' | 'enterprise'
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS logo_url VARCHAR(500) NULL;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS industry VARCHAR(100) NULL;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS employee_count_range VARCHAR(20) NULL;
-- '1-10' | '11-50' | '51-200' | '200+'
```

### 2c. Endpoint de registro público

```
POST /api/register
```

**Body:**
```json
{
  "empresa_nombre": "Mi Empresa SA",
  "industry": "Retail",
  "employee_count_range": "11-50",
  "admin_name": "Juan Pérez",
  "admin_email": "juan@empresa.com",
  "admin_password": "password123",
  "modules": ["tareas", "asistencia", "nomina"]
}
```

**Lógica:**
1. Crear `empresa` con los datos
2. Crear `user` con role `admin` vinculado a la empresa
3. Crear `empleado` vinculado al user
4. Insertar en `empresa_modules` los módulos seleccionados (+ `configuracion` siempre activo)
5. Generar token Sanctum y devolver `{ token, user, empresa }`

**Sin autenticación** — es ruta pública.

**Archivo nuevo:** `app/Http/Controllers/Api/V1/RegisterController.php`

**Ruta en `routes/api.php`** (fuera del grupo autenticado):
```php
Route::post('/register', [RegisterController::class, 'register']);
```

### 2d. Endpoint para listar/toggle módulos (solo admin)

```
GET  /api/v1/empresa/modulos       → lista módulos activos de la empresa
POST /api/v1/empresa/modulos       → activar o desactivar un módulo
```

**Body del POST:**
```json
{ "module_slug": "nomina", "enabled": false }
```

**Archivo a modificar:** crear `app/Http/Controllers/Api/V1/EmpresaController.php`

---

## 3. Restricción de Asistencia por Red WiFi

**Contexto:** Los empleados pueden marcar entrada desde cualquier lugar. Se requiere restringir el check-in/check-out a la red de la tienda, comparando la IP del request contra una IP configurada por empresa.

### 3a. Migración — campo en `empresas`

```sql
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS allowed_ip VARCHAR(45) NULL;
-- Ejemplo: '192.168.1.0/24' o '201.175.42.10'
-- NULL = sin restricción (comportamiento actual)
```

### 3b. Endpoint para configurar IP permitida (solo admin)

```
PATCH /api/v1/empresa/config
```

**Body:**
```json
{ "allowed_ip": "192.168.1.0/24" }
```

### 3c. Middleware o lógica en `AttendanceControllerV2`

En los métodos `checkIn`, `checkOut`, `breakStart`, `breakEnd`:

```php
private function validateNetworkAccess(Request $request, Empresa $empresa): bool
{
    $allowedIp = $empresa->allowed_ip;
    if (!$allowedIp) return true; // sin restricción

    $clientIp = $request->ip();
    // Si es CIDR (/24), verificar rango
    // Si es IP exacta, comparar directo
    return $this->ipMatchesCidr($clientIp, $allowedIp);
}
```

Si la IP no coincide, devolver:
```json
{
  "message": "No puedes marcar asistencia fuera de la red de la tienda.",
  "code": "NETWORK_RESTRICTED"
}
```
HTTP status `403`.

**Archivo a modificar:** `app/Http/Controllers/Api/V1/AttendanceControllerV2.php`

---

## 4. Supervisor y Admin pueden Marcar Asistencia

**Contexto:** Actualmente los endpoints de asistencia solo permiten el rol `empleado`. Los supervisores y admins también son empleados y deben poder marcar entrada/salida.

### 4a. Cambio en `AttendanceControllerV2`

En cada método (`checkIn`, `checkOut`, `breakStart`, `breakEnd`, `getStatus`), la lógica busca al empleado vinculado con:

```php
$emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
```

**El problema:** Los supervisores/admins también tienen su registro en `empleados` si fueron creados con el proceso de UsersController. Verificar que esto ocurra correctamente.

**Cambios necesarios:**
1. Quitar cualquier validación de rol `empleado` en los endpoints de asistencia para el propio usuario
2. Mantener la restricción de que un empleado NO puede marcar por otro — solo por sí mismo
3. El supervisor/admin sí puede ver y gestionar la asistencia de otros desde `ManagerAttendancePage`

**En `UsersController@store`:** Al crear un user con rol `supervisor` o `admin`, asegurarse de que también se crea su registro en `empleados` (si no existe ya esta lógica).

### 4b. Verificar en UsersController

```php
// Al crear cualquier user (admin, supervisor, empleado), siempre crear el empleado:
Empleado::create([
    'empresa_id'    => $empresaId,
    'user_id'       => $newUser->id,
    'full_name'     => $data['name'],
    'employee_code' => $data['employee_code'] ?? null,
    'position_title'=> $data['position_title'] ?? null,
    'hired_at'      => $data['hired_at'] ?? null,
    'status'        => 'active',
    'payment_type'  => $data['payment_type'] ?? 'hourly',
    'hourly_rate'   => $data['hourly_rate'] ?? 0,
    'daily_rate'    => $data['daily_rate'] ?? 0,
]);
```

---

## 5. Evidencias en Producción — Migrar a Cloudflare R2

**Contexto:** Railway tiene sistema de archivos efímero — los archivos del disco `public` desaparecen al reiniciar el contenedor. Las evidencias subidas dan 404.

### 5a. Variables de entorno en Railway

**⚠️ IMPORTANTE (Recordatorio): No olvides configurar estas variables manualmente en el panel de Railway después de crear el bucket en Cloudflare R2. Sin ellas, el disco S3 fallará aunque el código esté bien.**

Agregar en el panel de Railway → Variables:

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<R2_ACCESS_KEY>
AWS_SECRET_ACCESS_KEY=<R2_SECRET_KEY>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=kore-evidencias
AWS_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=https://<tu-dominio-publico-r2>  # opcional si tienes custom domain en R2
```

### 5b. `config/filesystems.php`

Verificar que el disco `s3` esté configurado así:

```php
's3' => [
    'driver'                  => 's3',
    'key'                     => env('AWS_ACCESS_KEY_ID'),
    'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
    'region'                  => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket'                  => env('AWS_BUCKET'),
    'endpoint'                => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
    'url'                     => env('AWS_URL'),
    'throw'                   => false,
],
```

### 5c. Instalar dependencia

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

### 5d. Cambio en `EvidencesController.php`

```php
// ❌ ANTES — disco hardcodeado:
$disk = 'public';

// ✅ DESPUÉS — toma el disco del .env:
$disk = config('filesystems.default', 's3');
```

### 5e. Bucket R2 — configuración

- Crear bucket: `kore-evidencias`
- En R2 → bucket → Settings → activar **Public Access** O configurar **Custom Domain**
- La URL pública será algo como: `https://pub-xxxx.r2.dev/kore/{empresa_id}/evidences/...`

### 5f. Migración de archivos existentes (si los hay)

Si ya hay evidencias en BD con `disk = 'public'` y paths en el servidor antiguo, ejecutar un comando artisan para re-subirlas a R2:

```bash
php artisan evidencias:migrate-to-r2
```

Crear este comando en `app/Console/Commands/MigrateEvidenciasToR2.php` que itere las evidencias con `disk = 'public'` e intente reubicarlas (si el archivo aún existe).

---

---

## 6. Día de Descanso — Marcado desde la App

**Contexto:** El sistema de nómina ya soporta días de descanso pagados para empleados con `payment_type = 'daily'`. Actualmente solo se puede registrar manualmente con SQL en la tabla `employee_calendar_overrides`. Se necesita que el empleado pueda marcarlo desde `EmployeeAttendancePage` sin tener que marcar entrada.

### 6a. Endpoint nuevo

```
POST /api/v1/asistencia/descanso
```

**Body:**
```json
{
  "date": "2026-03-09"  // opcional, default = hoy
}
```

**Lógica:**
1. Solo empleados con `payment_type = 'daily'` pueden marcar descanso (los de `hourly` no tienen este derecho)
2. Verificar que no haya ya un `attendance_day` con `first_check_in_at` para ese día — si ya marcó entrada, no puede marcarlo como descanso
3. Verificar que no haya ya un `employee_calendar_override` de tipo `rest` para ese día — no duplicar
4. Verificar que sea el día de descanso correspondiente a su semana — máximo 1 descanso pagado por semana (domingo a sábado). Si ya tiene uno esa semana, devolver error:
```json
{ "message": "Ya tienes un día de descanso registrado esta semana.", "code": "REST_ALREADY_USED" }
```
5. Insertar en `employee_calendar_overrides`:
```php
EmployeeCalendarOverride::create([
    'empresa_id'  => $empresaId,
    'empleado_id' => $emp->id,
    'date'        => $date,
    'type'        => 'rest',
    'is_paid'     => true,
]);
```
6. Devolver confirmación:
```json
{
  "message": "Día de descanso registrado correctamente.",
  "date": "2026-03-09",
  "is_paid": true
}
```

**Archivo a modificar:** `app/Http/Controllers/Api/V1/AttendanceControllerV2.php`

Agregar método `markRestDay()`.

### 6b. Endpoint para cancelar descanso (opcional pero recomendado)

```
DELETE /api/v1/asistencia/descanso/{date}
```

Permite al empleado cancelar el descanso marcado si aún no se ha generado/aprobado la nómina. Borra el registro de `employee_calendar_overrides`.

### 6c. Endpoint de estado del día — agregar campo `rest_day`

El endpoint existente `GET /api/v1/asistencia/estado` ya devuelve el estado del día (check_in, break, etc.). Agregar campo:

```json
{
  "status": "rest_day",          // nuevo status posible
  "is_paid_rest": true,
  "can_mark_rest": true,         // true si es daily y no ha marcado entrada hoy y no tiene descanso esta semana
  "rest_used_this_week": false,  // true si ya usó su descanso esta semana
  ...
}
```

### 6d. Ruta a agregar en `routes/api.php`

```php
Route::post('/asistencia/descanso',         [AttendanceControllerV2::class, 'markRestDay']);
Route::delete('/asistencia/descanso/{date}', [AttendanceControllerV2::class, 'cancelRestDay']);
```

---

## Resumen de archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `UsersController.php` | Agregar método `destroy()` |
| `RegisterController.php` | Crear — registro público de empresa |
| `EmpresaController.php` | Crear — módulos y config de empresa |
| `AttendanceControllerV2.php` | Quitar restricción de rol, agregar validación de IP, agregar `markRestDay()` y `cancelRestDay()` |
| `EvidencesController.php` | Cambiar `$disk` a dinámico |
| `config/filesystems.php` | Verificar configuración S3/R2 |
| `routes/api.php` | Agregar rutas de DELETE usuario, registro, módulos, config, descanso |
| Migración `empresa_modules` | Nueva tabla |
| Migración campos `empresas` | `plan`, `logo_url`, `industry`, `allowed_ip` |
| `composer.json` | Agregar `flysystem-aws-s3-v3` |
