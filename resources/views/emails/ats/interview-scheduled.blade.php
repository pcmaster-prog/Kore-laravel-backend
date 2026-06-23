<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Entrevista programada</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Hola, {{ $candidateName }}</h2>
    <p>Tu entrevista para el puesto de <strong>{{ $jobTitle }}</strong> ha sido programada.</p>
    <ul>
        <li><strong>Fecha y hora:</strong> {{ $scheduledAt }}</li>
        <li><strong>Modalidad:</strong> {{ $method === 'video' ? 'Videollamada' : ($method === 'phone' ? 'Teléfono' : 'Presencial') }}</li>
        @if($location)
            <li><strong>Lugar:</strong> {{ $location }}</li>
        @endif
        @if($meetingUrl)
            <li><strong>Enlace:</strong> <a href="{{ $meetingUrl }}">{{ $meetingUrl }}</a></li>
        @endif
    </ul>
    <p>Te esperamos.</p>
</body>
</html>
