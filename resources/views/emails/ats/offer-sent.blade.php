<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Oferta laboral</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Hola, {{ $candidateName }}</h2>
    <p>Nos complace informarte que has sido seleccionado(a) para el puesto de <strong>{{ $jobTitle }}</strong> en <strong>{{ $empresaName }}</strong>.</p>
    <p>Te hemos enviado una oferta laboral a través de tu portal de candidato. Para revisarla y aceptarla, haz clic en el siguiente botón:</p>
    <p style="text-align: center; margin: 24px 0;">
        <a href="{{ $offerUrl }}" style="background-color: #312E74; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Ver mi oferta</a>
    </p>
    <p>Si no puedes hacer clic, copia y pega este enlace en tu navegador:</p>
    <p style="word-break: break-all;">{{ $offerUrl }}</p>
    <p>¡Te esperamos!</p>
</body>
</html>
