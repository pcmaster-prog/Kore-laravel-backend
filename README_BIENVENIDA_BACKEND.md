# Kore — Correo de Bienvenida + Documentos + Cambiar Contraseña: Backend

Stack: Laravel 11 · PostgreSQL · Railway · Resend

---

## Variables de entorno requeridas (ya configuradas en Railway)

```env
RESEND_API_KEY=re_TsqiToQW_CyG2B3fbZFZbSaWQmMcprGN4
MAIL_FROM_ADDRESS=onboarding@resend.dev
MAIL_FROM_NAME=Kore Ops Suite
```

---

## 1. Instalar Resend para Laravel

```bash
composer require resend/resend-laravel
```

En `config/services.php` agregar:

```php
'resend' => [
    'key' => env('RESEND_API_KEY'),
],
```

En `config/mail.php`, cambiar el mailer default:

```php
'default' => env('MAIL_MAILER', 'resend'),

'mailers' => [
    'resend' => [
        'transport' => 'resend',
    ],
    // ... otros mailers existentes
],
```

---

## 2. Migración — Documentos de empresa

Agregar columna `documentos` a la tabla `empresas` para guardar los archivos
que se adjuntarán a los correos de bienvenida:

```php
Schema::table('empresas', function (Blueprint $table) {
    $table->jsonb('documentos')->nullable()->default('[]')->after('settings');
    // Estructura: [{ "nombre": "Contrato", "url": "https://...", "path": "kore/empresa_id/docs/..." }]
});
```

---

## 3. Mailable — `BienvenidaEmpleado`

**Crear `app/Mail/BienvenidaEmpleado.php`:**

```php
<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class BienvenidaEmpleado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $empleadoNombre,
        public string $empresaNombre,
        public string $email,
        public string $passwordTemporal,
        public string $appUrl,
        public array  $documentos = [], // [{ nombre, url }]
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "¡Bienvenido a {$this->empresaNombre}! Tus credenciales de acceso",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bienvenida-empleado',
        );
    }

    public function attachments(): array
    {
        // Adjuntar documentos desde URL
        return collect($this->documentos)->map(function ($doc) {
            return Attachment::fromUrl($doc['url'])
                ->as($doc['nombre'] . '.pdf')
                ->withMime('application/pdf');
        })->toArray();
    }
}
```

---

## 4. Vista del correo

**Crear `resources/views/emails/bienvenida-empleado.blade.php`:**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bienvenido a {{ $empresaNombre }}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #F5F3EE; margin: 0; padding: 20px; }
    .container { max-width: 560px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: #1E2D4A; padding: 32px; text-align: center; }
    .header h1 { color: white; margin: 0; font-size: 24px; }
    .header p { color: rgba(255,255,255,0.7); margin: 8px 0 0; font-size: 14px; }
    .body { padding: 32px; }
    .greeting { font-size: 18px; font-weight: 600; color: #1E2D4A; margin-bottom: 8px; }
    .text { color: #374151; font-size: 15px; line-height: 1.6; margin-bottom: 24px; }
    .credentials { background: #F5F3EE; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
    .credential-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #E8E6E0; }
    .credential-row:last-child { border-bottom: none; }
    .credential-label { color: #6B7280; font-size: 13px; }
    .credential-value { color: #1E2D4A; font-size: 13px; font-weight: 600; }
    .btn { display: block; background: #1E2D4A; color: white; text-decoration: none; text-align: center; padding: 14px 24px; border-radius: 999px; font-weight: 600; font-size: 15px; margin-bottom: 24px; }
    .warning { background: #fef3c7; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 24px; }
    .footer { background: #F5F3EE; padding: 20px 32px; text-align: center; color: #9CA3AF; font-size: 12px; }
    @if(count($documentos) > 0)
    .docs { margin-bottom: 24px; }
    .docs-title { font-size: 13px; font-weight: 600; color: #1E2D4A; margin-bottom: 8px; }
    .doc-item { font-size: 13px; color: #374151; padding: 4px 0; }
    @endif
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>Kore Ops Suite</h1>
      <p>{{ $empresaNombre }}</p>
    </div>
    <div class="body">
      <div class="greeting">¡Bienvenido, {{ $empleadoNombre }}! 👋</div>
      <p class="text">
        Tu cuenta ha sido creada en <strong>{{ $empresaNombre }}</strong>. 
        A continuación encontrarás tus credenciales de acceso al sistema Kore.
      </p>

      <div class="credentials">
        <div class="credential-row">
          <span class="credential-label">Correo electrónico</span>
          <span class="credential-value">{{ $email }}</span>
        </div>
        <div class="credential-row">
          <span class="credential-label">Contraseña temporal</span>
          <span class="credential-value">{{ $passwordTemporal }}</span>
        </div>
      </div>

      <a href="{{ $appUrl }}" class="btn">Acceder a Kore →</a>

      <div class="warning">
        ⚠️ Por seguridad, te recomendamos cambiar tu contraseña después de tu primer inicio de sesión desde tu perfil.
      </div>

      @if(count($documentos) > 0)
      <div class="docs">
        <div class="docs-title">📎 Documentos adjuntos</div>
        @foreach($documentos as $doc)
        <div class="doc-item">• {{ $doc['nombre'] }}</div>
        @endforeach
        <p style="font-size:12px;color:#6B7280;margin-top:8px;">
          Los documentos se encuentran adjuntos a este correo.
        </p>
      </div>
      @endif

      <p class="text" style="margin-bottom:0;">
        Si tienes alguna duda, contacta a tu administrador.<br>
        <strong>Equipo Kore Ops Suite</strong>
      </p>
    </div>
    <div class="footer">
      Este correo fue enviado automáticamente por Kore Ops Suite.<br>
      Por favor no respondas a este mensaje.
    </div>
  </div>
</body>
</html>
```

---

## 5. Modificar `UsersController` — enviar correo al crear empleado

**En el método `store()` de `UsersController.php`:**

```php
use App\Mail\BienvenidaEmpleado;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

public function store(Request $request)
{
    // ... validación y creación existente ...

    // Generar contraseña temporal segura
    $passwordTemporal = $this->generarPasswordTemporal();

    // Crear usuario con la contraseña generada
    $newUser = User::create([
        // ... campos existentes ...
        'password' => bcrypt($passwordTemporal),
    ]);

    // ... crear empleado vinculado (código existente) ...

    // Obtener documentos de la empresa
    $empresa = Empresa::find($empresaId);
    $documentos = $empresa->documentos ?? [];

    // Enviar correo de bienvenida
    try {
        Mail::to($newUser->email)->send(new BienvenidaEmpleado(
            empleadoNombre: $newUser->name,
            empresaNombre:  $empresa->name,
            email:          $newUser->email,
            passwordTemporal: $passwordTemporal,
            appUrl:         config('app.frontend_url', 'https://kore-react-frontend.vercel.app'),
            documentos:     $documentos,
        ));
    } catch (\Exception $e) {
        // Si falla el correo, no fallar la creación del usuario
        // Solo logear el error
        \Log::warning("No se pudo enviar correo de bienvenida a {$newUser->email}: " . $e->getMessage());
    }

    // ... respuesta existente ...
}

private function generarPasswordTemporal(): string
{
    // Genera algo como: Kore#4829
    $palabras = ['Kore', 'Team', 'Work', 'Join', 'Star'];
    $palabra = $palabras[array_rand($palabras)];
    $numero = rand(1000, 9999);
    $especial = ['#', '@', '!'][rand(0, 2)];
    return $palabra . $especial . $numero;
}
```

**Agregar en `.env` y en Railway:**
```env
APP_FRONTEND_URL=https://kore-react-frontend.vercel.app
```

---

## 6. Endpoint de documentos de empresa

**Crear `app/Http/Controllers/Api/V1/EmpresaDocumentosController.php`:**

```
GET    /empresa/documentos          → index()    Lista documentos actuales
POST   /empresa/documentos          → upload()   Subir nuevo documento
DELETE /empresa/documentos/{index}  → destroy()  Eliminar documento por índice
```

**`index()`:**
```php
public function index(Request $request)
{
    $empresa = Empresa::find($request->user()->empresa_id);
    return response()->json([
        'documentos' => $empresa->documentos ?? [],
    ]);
}
```

**`upload()`:**
- Solo admin
- Validar: `file` required, mimes: `pdf`, max: `10240` (10MB)
- Subir a S3: `kore/{empresa_id}/documentos/{filename}`
- Guardar en array `documentos` de la empresa:
```php
$empresa->documentos = array_merge($empresa->documentos ?? [], [[
    'nombre' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
    'url'    => Storage::disk('s3')->url($path),
    'path'   => $path,
    'size'   => $file->getSize(),
    'uploaded_at' => now()->toISOString(),
]]);
$empresa->save();
```

**`destroy()`:**
- Solo admin
- Eliminar el documento del array por índice
- Opcionalmente eliminar el archivo de S3

---

## 7. Cambiar contraseña — endpoint en `ProfileController`

**Agregar método `changePassword()` en `ProfileController.php`:**

```php
// POST /mi-perfil/password
public function changePassword(Request $request)
{
    $u = $request->user();

    $data = $request->validate([
        'current_password' => ['required', 'string'],
        'new_password'     => ['required', 'string', 'min:6', 'confirmed'],
        // confirmed = requiere new_password_confirmation en el body
    ]);

    // Verificar contraseña actual
    if (!Hash::check($data['current_password'], $u->password)) {
        return response()->json([
            'message' => 'La contraseña actual no es correcta.',
        ], 422);
    }

    $u->password = Hash::make($data['new_password']);
    $u->save();

    return response()->json([
        'message' => 'Contraseña actualizada correctamente.',
    ]);
}
```

**Ruta en `api.php`:**
```php
Route::post('/mi-perfil/password', [ProfileController::class, 'changePassword']);
```

---

## 8. Rutas nuevas en `api.php`

```php
use App\Http\Controllers\Api\V1\EmpresaDocumentosController;

// Documentos de empresa (solo admin)
Route::get('/empresa/documentos',           [EmpresaDocumentosController::class, 'index']);
Route::post('/empresa/documentos',          [EmpresaDocumentosController::class, 'upload']);
Route::delete('/empresa/documentos/{index}',[EmpresaDocumentosController::class, 'destroy']);

// Cambiar contraseña (todos los roles)
Route::post('/mi-perfil/password', [ProfileController::class, 'changePassword']);
```

---

## 9. Resumen de archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `composer.json` | Agregar `resend/resend-laravel` |
| `config/services.php` | Agregar config Resend |
| `config/mail.php` | Cambiar mailer default a resend |
| `database/migrations/..._add_documentos_to_empresas.php` | Crear |
| `app/Mail/BienvenidaEmpleado.php` | Crear |
| `resources/views/emails/bienvenida-empleado.blade.php` | Crear |
| `app/Http/Controllers/Api/V1/UsersController.php` | Modificar — generar password y enviar correo |
| `app/Http/Controllers/Api/V1/EmpresaDocumentosController.php` | Crear |
| `app/Http/Controllers/Api/V1/ProfileController.php` | Modificar — agregar changePassword() |
| `routes/api.php` | Modificar — rutas documentos y password |
| `.env` + Railway variables | Agregar APP_FRONTEND_URL |
