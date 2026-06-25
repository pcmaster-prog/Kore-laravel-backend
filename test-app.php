<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$emails = [
    'adancuellarh@gmail.com',
    'akecuellarherbandez@gmail.com',
];

foreach ($emails as $email) {
    echo "=== Procesando: $email ===\n";

    $user = User::where('email', $email)->first();
    if (! $user) {
        echo "  ❌ Usuario no encontrado.\n\n";

        continue;
    }

    echo '  Usuario: '.$user->name.' (ID: '.$user->id.")\n";

    $applications = Application::where('user_id', $user->id)->latest()->get();
    echo '  Postulaciones: '.$applications->count()."\n";

    foreach ($applications as $application) {
        echo '  Postulación ID: '.$application->id.' | Status: '.$application->status."\n";

        $oldStatus = $application->status;
        $application->update([
            'status' => 'new',
            'screening_test_results' => null,
            'has_induction_video_watched' => true,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $application->id,
            'from_status' => $oldStatus,
            'to_status' => 'new',
            'notes' => 'Reseteo manual por admin: corrección en lógica de puntaje de autoevaluación.',
        ]);

        echo "  ✅ Reseteada a 'new'. Video de inducción marcado como visto.\n";
    }
    echo "\n";
}

echo "¡Listo! Ambas cuentas pueden retomar la autoevaluación directamente.\n";
