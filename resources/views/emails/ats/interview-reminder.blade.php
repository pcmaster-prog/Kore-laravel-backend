@component('mail::message')
# ¡Hola, {{ $recipientName }}!

@if ($role === 'interviewer')
Te recordamos que mañana tienes una entrevista programada con **{{ $candidateName }}** para la vacante **{{ $jobTitle }}**.
@else
Te recordamos que mañana tienes una entrevista programada para la vacante **{{ $jobTitle }}**.
@endif

**Fecha y hora:** {{ $scheduledAt }}

@if ($method)
**Modalidad:** {{ $method }}
@endif

@if ($location)
**Ubicación:** {{ $location }}
@endif

@if ($meetingUrl)
**Enlace de la reunión:** [Unirme a la entrevista]({{ $meetingUrl }})
@endif

Si no puedes asistir, por favor avisa con anticipación.

Saludos,<br>
{{ config('app.name') }}
@endcomponent
