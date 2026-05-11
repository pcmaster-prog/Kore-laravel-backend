# Kore — Gondola Refill Management: Backend

Stack: Laravel 11 · PostgreSQL · Railway

---

## Resumen del Feature

Sistema de gestión de relleno de góndolas para tienda retail. El admin configura
góndolas con sus productos. Los supervisores/admins crean órdenes de relleno y las
asignan a empleados. El empleado registra cantidades y sube evidencia. El admin aprueba.

---

## 1. Migraciones

### 1a. Tabla `gondolas`

```sql
CREATE TABLE gondolas (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre VARCHAR(100) NOT NULL,          -- "Góndola 1 - Cartones"
    descripcion VARCHAR(300) NULL,
    ubicacion VARCHAR(100) NULL,           -- "Pasillo 3, lado derecho"
    orden INTEGER DEFAULT 0,               -- para ordenar visualmente
    activo BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_gondolas_empresa ON gondolas(empresa_id, activo);
```

### 1b. Tabla `gondola_productos`

```sql
CREATE TABLE gondola_productos (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    gondola_id UUID NOT NULL REFERENCES gondolas(id) ON DELETE CASCADE,
    clave VARCHAR(50) NULL,                -- "C28D", "Rp23" (código del producto)
    nombre VARCHAR(150) NOT NULL,          -- "Cartón Red. Dorado 28cm"
    descripcion VARCHAR(300) NULL,
    unidad VARCHAR(20) NOT NULL DEFAULT 'pz',  -- 'pz' | 'kg' | 'caja' | 'media_caja'
    foto_url VARCHAR(500) NULL,            -- URL de foto de referencia en S3/Backblaze
    orden INTEGER DEFAULT 0,
    activo BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_gondola_productos_gondola ON gondola_productos(gondola_id, activo);
CREATE INDEX idx_gondola_productos_empresa ON gondola_productos(empresa_id);
```

### 1c. Tabla `gondola_ordenes`

```sql
CREATE TABLE gondola_ordenes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    gondola_id UUID NOT NULL REFERENCES gondolas(id) ON DELETE CASCADE,
    empleado_id UUID NOT NULL REFERENCES empleados(id) ON DELETE CASCADE,
    -- Status flow: pendiente → en_proceso → completado → aprobado | rechazado
    status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    evidencia_url VARCHAR(500) NULL,       -- foto de evidencia subida por empleado
    notas_empleado VARCHAR(500) NULL,      -- notas opcionales del empleado
    notas_rechazo VARCHAR(500) NULL,       -- motivo si el admin rechaza
    approved_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    completed_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_gondola_ordenes_empresa ON gondola_ordenes(empresa_id, status);
CREATE INDEX idx_gondola_ordenes_empleado ON gondola_ordenes(empleado_id, status);
CREATE INDEX idx_gondola_ordenes_gondola ON gondola_ordenes(gondola_id);
```

### 1d. Tabla `gondola_orden_items`

```sql
CREATE TABLE gondola_orden_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    orden_id UUID NOT NULL REFERENCES gondola_ordenes(id) ON DELETE CASCADE,
    gondola_producto_id UUID NOT NULL REFERENCES gondola_productos(id) ON DELETE CASCADE,
    -- Snapshot del producto al momento de crear la orden
    clave VARCHAR(50) NULL,
    nombre VARCHAR(150) NOT NULL,
    unidad VARCHAR(20) NOT NULL,
    -- Cantidad que el empleado registró
    cantidad DECIMAL(10,2) NULL,           -- NULL = aún no llenado
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX idx_gondola_orden_items_orden ON gondola_orden_items(orden_id);
```

---

## 2. Modelos Eloquent

### `app/Models/Gondola.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Gondola extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'ubicacion', 'orden', 'activo'
    ];

    protected $casts = ['activo' => 'boolean'];

    public function productos()
    {
        return $this->hasMany(GondolaProducto::class)
            ->where('activo', true)
            ->orderBy('orden');
    }

    public function ordenes()
    {
        return $this->hasMany(GondolaOrden::class);
    }

    public function ultimaOrden()
    {
        return $this->hasOne(GondolaOrden::class)->latestOfMany();
    }
}
```

### `app/Models/GondolaProducto.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaProducto extends Model
{
    use HasUuids;

    protected $table = 'gondola_productos';

    protected $fillable = [
        'empresa_id', 'gondola_id', 'clave', 'nombre',
        'descripcion', 'unidad', 'foto_url', 'orden', 'activo'
    ];

    protected $casts = ['activo' => 'boolean'];

    public function gondola()
    {
        return $this->belongsTo(Gondola::class);
    }
}
```

### `app/Models/GondolaOrden.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaOrden extends Model
{
    use HasUuids;

    protected $table = 'gondola_ordenes';

    protected $fillable = [
        'empresa_id', 'gondola_id', 'empleado_id', 'status',
        'evidencia_url', 'notas_empleado', 'notas_rechazo',
        'approved_by', 'completed_at', 'approved_at'
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function gondola()
    {
        return $this->belongsTo(Gondola::class);
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function items()
    {
        return $this->hasMany(GondolaOrdenItem::class, 'orden_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

### `app/Models/GondolaOrdenItem.php`

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaOrdenItem extends Model
{
    use HasUuids;

    protected $table = 'gondola_orden_items';

    protected $fillable = [
        'empresa_id', 'orden_id', 'gondola_producto_id',
        'clave', 'nombre', 'unidad', 'cantidad'
    ];

    protected $casts = ['cantidad' => 'float'];

    public function producto()
    {
        return $this->belongsTo(GondolaProducto::class, 'gondola_producto_id');
    }
}
```

---

## 3. Controllers

### 3a. `GondolasController` — CRUD de góndolas y productos

**Archivo:** `app/Http/Controllers/Api/V1/GondolasController.php`

#### Endpoints:

```
GET    /gondolas                          → index()      Lista góndolas con contador
POST   /gondolas                          → store()      Crear góndola
GET    /gondolas/{id}                     → show()       Detalle con productos
PATCH  /gondolas/{id}                     → update()     Editar góndola
DELETE /gondolas/{id}                     → destroy()    Desactivar (soft delete)

GET    /gondolas/{id}/productos           → productos()  Lista productos de góndola
POST   /gondolas/{id}/productos           → addProducto() Agregar producto
PATCH  /gondolas/{gondolaId}/productos/{productoId}  → updateProducto()
DELETE /gondolas/{gondolaId}/productos/{productoId}  → removeProducto()
POST   /gondolas/{gondolaId}/productos/{productoId}/foto → uploadFoto()
```

**Lógica de cada método:**

**`index()`**
- Solo admin/supervisor
- Retorna lista de góndolas activas de la empresa
- Incluir: `productos_count`, `ultima_orden` (fecha y status), `pendientes_count`

```json
[
  {
    "id": "...",
    "nombre": "Góndola 1 - Cartones",
    "descripcion": "...",
    "ubicacion": "Pasillo 3",
    "orden": 1,
    "productos_count": 12,
    "ultima_orden": { "created_at": "2026-03-25T10:00:00Z", "status": "aprobado" },
    "ordenes_pendientes": 0
  }
]
```

**`store()`**
- Validar: `nombre` required, `descripcion` nullable, `ubicacion` nullable
- Crear con `empresa_id` del usuario autenticado

**`show()`**
- Retorna góndola con sus productos activos ordenados por `orden`

**`update()`**
- Solo admin
- Permite actualizar nombre, descripcion, ubicacion, orden

**`destroy()`**
- Soft delete: poner `activo = false`
- Si tiene órdenes pendientes, devolver 409

**`addProducto()`**
- Validar: `nombre` required, `clave` nullable, `unidad` required in [pz,kg,caja,media_caja], `descripcion` nullable
- Crear con snapshot de datos

**`uploadFoto()`**
- Recibe `file` (image, max 2MB)
- Sube a S3 en path `kore/{empresa_id}/gondola-productos/{producto_id}/`
- Actualiza `foto_url` del producto

---

### 3b. `GondolaOrdenesController` — Gestión de órdenes de relleno

**Archivo:** `app/Http/Controllers/Api/V1/GondolaOrdenesController.php`

#### Endpoints:

```
GET  /gondola-ordenes              → index()     Lista órdenes (admin/supervisor)
POST /gondola-ordenes              → store()     Crear orden y asignar empleado
GET  /gondola-ordenes/{id}         → show()      Detalle con items
POST /gondola-ordenes/{id}/iniciar → iniciar()   Empleado inicia la orden
POST /gondola-ordenes/{id}/completar → completar() Empleado completa con cantidades + evidencia
POST /gondola-ordenes/{id}/aprobar → aprobar()  Admin aprueba
POST /gondola-ordenes/{id}/rechazar → rechazar() Admin rechaza con nota

GET  /mis-ordenes-gondola          → misOrdenes() Órdenes del empleado autenticado
```

**Lógica detallada:**

**`store()` — Crear orden**
- Solo admin/supervisor
- Validar: `gondola_id` required, `empleado_id` required, `notas` nullable
- Al crear, copiar snapshot de todos los productos activos de la góndola como `gondola_orden_items`:
  ```php
  foreach ($gondola->productos as $producto) {
      GondolaOrdenItem::create([
          'empresa_id'         => $empresaId,
          'orden_id'           => $orden->id,
          'gondola_producto_id'=> $producto->id,
          'clave'              => $producto->clave,
          'nombre'             => $producto->nombre,
          'unidad'             => $producto->unidad,
          'cantidad'           => null, // el empleado lo llena
      ]);
  }
  ```
- Status inicial: `pendiente`

**`iniciar()` — Empleado inicia**
- Solo el empleado asignado
- Cambia status a `en_proceso`
- Registra timestamp

**`completar()` — Empleado completa**
- Solo el empleado asignado
- Validar que status sea `en_proceso` o `pendiente`
- Body:
  ```json
  {
    "items": [
      { "id": "item_uuid", "cantidad": 3.5 },
      { "id": "item_uuid", "cantidad": 12 }
    ],
    "notas_empleado": "Faltó stock de C28D en bodega",
    "evidencia_url": "url_ya_subida" // o recibir como multipart
  }
  ```
- Actualizar cantidades de cada item
- Subir evidencia si viene como archivo
- Cambiar status a `completado`
- Registrar `completed_at`

**`aprobar()` — Admin aprueba**
- Solo admin/supervisor
- Status debe ser `completado`
- Cambiar status a `aprobado`
- Registrar `approved_by` y `approved_at`

**`rechazar()` — Admin rechaza**
- Solo admin/supervisor
- Body: `{ "notas_rechazo": "La foto no es clara" }`
- Status debe ser `completado`
- Cambiar status a `rechazado`
- Guardar `notas_rechazo`
- El empleado puede volver a completar (status vuelve a `en_proceso`)

**`misOrdenes()` — Vista empleado**
- Solo devuelve órdenes del empleado autenticado
- Filtrar por status: `pendiente`, `en_proceso`, `rechazado` (las activas)
- Incluir items con `foto_url` del producto

**`index()` — Vista manager**
- Filtros opcionales: `status`, `gondola_id`, `empleado_id`, `fecha`
- Ordenar por `created_at` DESC
- Paginación de 20

---

## 4. Formato de Respuesta (presenters)

### Orden completa:
```json
{
  "id": "uuid",
  "gondola": { "id": "...", "nombre": "Góndola 1 - Cartones" },
  "empleado": { "id": "...", "full_name": "Juan Pérez", "position_title": "..." },
  "status": "completado",
  "notas_empleado": "...",
  "notas_rechazo": null,
  "evidencia_url": "https://...",
  "completed_at": "2026-03-25T14:30:00Z",
  "approved_at": null,
  "created_at": "2026-03-25T10:00:00Z",
  "items": [
    {
      "id": "uuid",
      "gondola_producto_id": "uuid",
      "clave": "C28D",
      "nombre": "Cartón Red. Dorado 28cm",
      "unidad": "pz",
      "cantidad": 12,
      "foto_url": "https://..."
    }
  ]
}
```

---

## 5. Rutas en `routes/api.php`

```php
use App\Http\Controllers\Api\V1\GondolasController;
use App\Http\Controllers\Api\V1\GondolaOrdenesController;

// ── Góndolas (admin/supervisor) ───────────────────────────────────────────
Route::middleware(['module:tareas'])->group(function () {
    Route::get('/gondolas',                              [GondolasController::class, 'index']);
    Route::post('/gondolas',                             [GondolasController::class, 'store']);
    Route::get('/gondolas/{id}',                         [GondolasController::class, 'show']);
    Route::patch('/gondolas/{id}',                       [GondolasController::class, 'update']);
    Route::delete('/gondolas/{id}',                      [GondolasController::class, 'destroy']);
    Route::get('/gondolas/{id}/productos',               [GondolasController::class, 'productos']);
    Route::post('/gondolas/{id}/productos',              [GondolasController::class, 'addProducto']);
    Route::patch('/gondolas/{gId}/productos/{pId}',      [GondolasController::class, 'updateProducto']);
    Route::delete('/gondolas/{gId}/productos/{pId}',     [GondolasController::class, 'removeProducto']);
    Route::post('/gondolas/{gId}/productos/{pId}/foto',  [GondolasController::class, 'uploadFoto']);

    // Órdenes — gestión (admin/supervisor)
    Route::get('/gondola-ordenes',                       [GondolaOrdenesController::class, 'index']);
    Route::post('/gondola-ordenes',                      [GondolaOrdenesController::class, 'store']);
    Route::get('/gondola-ordenes/{id}',                  [GondolaOrdenesController::class, 'show']);
    Route::post('/gondola-ordenes/{id}/aprobar',         [GondolaOrdenesController::class, 'aprobar']);
    Route::post('/gondola-ordenes/{id}/rechazar',        [GondolaOrdenesController::class, 'rechazar']);

    // Órdenes — empleado
    Route::get('/mis-ordenes-gondola',                   [GondolaOrdenesController::class, 'misOrdenes']);
    Route::post('/gondola-ordenes/{id}/iniciar',         [GondolaOrdenesController::class, 'iniciar']);
    Route::post('/gondola-ordenes/{id}/completar',       [GondolaOrdenesController::class, 'completar']);
});
```

---

## 6. Módulo en `empresa_modules`

Agregar `gondolas` como módulo disponible. Insertarlo junto con los otros módulos
al registrar una nueva empresa en `RegisterController`:

```php
$modulos = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas'];
```

Y en el middleware usar `module:gondolas` para las rutas de góndolas
en lugar de `module:tareas` si se quiere control independiente.
Por simplicidad inicial puede usar `module:tareas`.

---

## 7. Resumen de archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `database/migrations/..._create_gondolas_table.php` | Crear |
| `database/migrations/..._create_gondola_productos_table.php` | Crear |
| `database/migrations/..._create_gondola_ordenes_table.php` | Crear |
| `database/migrations/..._create_gondola_orden_items_table.php` | Crear |
| `app/Models/Gondola.php` | Crear |
| `app/Models/GondolaProducto.php` | Crear |
| `app/Models/GondolaOrden.php` | Crear |
| `app/Models/GondolaOrdenItem.php` | Crear |
| `app/Http/Controllers/Api/V1/GondolasController.php` | Crear |
| `app/Http/Controllers/Api/V1/GondolaOrdenesController.php` | Crear |
| `routes/api.php` | Modificar — agregar rutas |
| `app/Http/Controllers/Api/V1/RegisterController.php` | Modificar — agregar módulo gondolas |
