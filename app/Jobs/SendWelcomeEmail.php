<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Resend\Laravel\Facades\Resend;

class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $userId,
        public string $passwordTemporal,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $user = User::find($this->userId);

            if (! $user) {
                Log::warning('SendWelcomeEmail: user not found', ['user_id' => $this->userId]);
                return;
            }

            $empresa = Empresa::find($user->empresa_id);

            if (! $empresa) {
                Log::warning('SendWelcomeEmail: empresa not found', ['empresa_id' => $user->empresa_id]);
                return;
            }

            $documentos = is_array($empresa->documentos) ? $empresa->documentos : [];

            // Preparar adjuntos descargando desde S3
            $attachments = [];
            foreach ($documentos as $doc) {
                if (empty($doc['url']) || empty($doc['nombre']) || empty($doc['path'])) {
                    continue;
                }

                try {
                    $contenido = Storage::disk('s3')->get($doc['path']);
                    $attachments[] = [
                        'filename' => $doc['nombre'] . '.pdf',
                        'content'  => base64_encode($contenido),
                    ];
                } catch (\Exception $e) {
                    Log::warning("SendWelcomeEmail: no se pudo adjuntar documento {$doc['nombre']}: " . $e->getMessage());
                }
            }

            Resend::emails()->send([
                'from'    => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
                'to'      => [$user->email],
                'subject' => "¡Bienvenido a {$empresa->name}! Tus credenciales de acceso",
                'html'    => view('emails.bienvenida-empleado', [
                    'empleadoNombre'   => $user->name,
                    'empresaNombre'    => $empresa->name,
                    'email'            => $user->email,
                    'passwordTemporal' => $this->passwordTemporal,
                    'appUrl'           => config('app.frontend_url', 'https://kore-react-frontend.vercel.app'),
                    'documentos'       => $documentos,
                ])->render(),
                'attachments' => $attachments,
            ]);

            Log::info('SendWelcomeEmail: correo enviado exitosamente', ['user_id' => $this->userId]);
        } catch (\Throwable $e) {
            Log::error('SendWelcomeEmail job failed', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
