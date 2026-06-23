<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Actualización de tu postulación</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Hola, {{ $candidateName }}</h2>
    <p>Agradecemos tu interés en el puesto de <strong>{{ $jobTitle }}</strong>.</p>
    <p>Después de revisar tu información, hemos decidido no continuar con tu proceso en esta ocasión.</p>
    @if($reason)
        <p><strong>Motivo:</strong> {{ $reason }}</p>
    @endif
    <p>Te deseamos mucho éxito en tu búsqueda.</p>
</body>
</html>
