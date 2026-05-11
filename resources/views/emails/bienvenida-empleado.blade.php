<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bienvenido a {{ $empresaNombre }}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #F5F3EE; margin: 0; padding: 20px; }
    .container { max-width: 580px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: #1E2D4A; padding: 36px 32px; text-align: center; }
    .header h1 { color: white; margin: 0; font-size: 26px; letter-spacing: 0.5px; }
    .header p { color: rgba(255,255,255,0.6); margin: 6px 0 0; font-size: 13px; }
    .body { padding: 36px 32px; }
    .greeting { font-size: 17px; color: #1E2D4A; margin-bottom: 20px; line-height: 1.7; }
    .text { color: #374151; font-size: 15px; line-height: 1.8; margin-bottom: 20px; }
    .docs-section { background: #F5F3EE; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px; }
    .docs-section p { color: #374151; font-size: 14px; margin: 0 0 12px; }
    .doc-item { color: #1E2D4A; font-size: 14px; padding: 4px 0; font-weight: 500; }
    .credentials { background: #1E2D4A; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px; }
    .credentials-title { color: rgba(255,255,255,0.6); font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 12px; }
    .credential-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .credential-row:last-child { border-bottom: none; }
    .credential-label { color: rgba(255,255,255,0.6); font-size: 13px; }
    .credential-value { color: white; font-size: 13px; font-weight: 600; }
    .btn { display: block; background: #1E2D4A; color: white !important; text-decoration: none; text-align: center; padding: 15px 24px; border-radius: 999px; font-weight: 600; font-size: 15px; margin-bottom: 24px; }
    .warning { background: #fef3c7; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 24px; }
    .closing { color: #374151; font-size: 15px; line-height: 1.8; margin-bottom: 8px; }
    .signature { color: #1E2D4A; font-size: 15px; font-weight: 600; margin-top: 16px; }
    .footer { background: #F5F3EE; padding: 20px 32px; text-align: center; color: #9CA3AF; font-size: 12px; border-top: 1px solid #E8E6E0; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>{{ $empresaNombre }}</h1>
      <p>Kore Ops Suite</p>
    </div>
    <div class="body">

      <p class="greeting">Hola, <strong>{{ $empleadoNombre }}</strong>:</p>

      <p class="text">
        Queremos darte la bienvenida a <strong>{{ $empresaNombre }}</strong>. 
        Nos da mucho gusto que formas parte de este proceso.
      </p>

      <p class="text">
        {{ $empresaNombre }} no es solo una tienda; es una historia que comenzó en 1986 
        gracias a la visión de una familia que apostó por el trabajo constante, la innovación 
        y, sobre todo, el trato cercano con las personas. A lo largo de los años, hemos crecido 
        adaptándonos a los cambios, incorporando nuevas ideas y fortaleciendo un compromiso claro: 
        ofrecer productos de calidad mientras construimos un equipo humano sólido.
      </p>

      <p class="text"><strong>Hoy, tú formas parte de ese siguiente paso.</strong></p>

      @if(count($documentos) > 0)
      <div class="docs-section">
        <p>Para que puedas integrarte de la mejor manera, hemos preparado documentos con información 
        clave que te ayudarán a conocer más sobre nosotros y sobre lo que esperamos como equipo:</p>
        @foreach($documentos as $doc)
        <div class="doc-item">🔹 {{ $doc['nombre'] }}</div>
        @endforeach
        <p style="margin-top:12px;margin-bottom:0;color:#6B7280;font-size:13px;">
          Los documentos se encuentran adjuntos a este correo.
        </p>
      </div>
      @else
      <p class="text">
        Para que puedas integrarte de la mejor manera, próximamente recibirás información 
        clave sobre la empresa, nuestros valores y lo que esperamos como equipo.
      </p>
      @endif

      <p class="text">
        Puedes acceder a la plataforma con las siguientes credenciales:
      </p>

      <div class="credentials">
        <div class="credentials-title">Tus credenciales de acceso</div>
        <div class="credential-row">
          <span class="credential-label">Correo electrónico</span>
          <span class="credential-value">{{ $email }}</span>
        </div>
        <div class="credential-row">
          <span class="credential-label">Contraseña temporal</span>
          <span class="credential-value">{{ $passwordTemporal }}</span>
        </div>
      </div>

      <a href="{{ $appUrl }}" class="btn">Acceder a la plataforma →</a>

      <div class="warning">
        ⚠️ Te recomendamos cambiar tu contraseña después de tu primer inicio de sesión 
        desde la sección <strong>Mi Perfil</strong>.
      </div>

      <p class="text">
        Este proceso nos permitirá avanzar contigo de manera ordenada y darte seguimiento 
        durante tu incorporación.
      </p>

      <p class="closing">
        Nos dará mucho gusto acompañarte en este proceso. Estamos seguros de que, 
        con tu actitud y compromiso, podrás crecer junto con nosotros.
      </p>

      <p class="closing">Bienvenido a esta nueva etapa.</p>

      <p class="closing">Atentamente,</p>
      <p class="signature">Equipo {{ $empresaNombre }}</p>

    </div>
    <div class="footer">
      Este correo fue enviado automáticamente por Kore Ops Suite.<br>
      Por favor no respondas a este mensaje.
    </div>
  </div>
</body>
</html>
