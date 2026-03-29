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
