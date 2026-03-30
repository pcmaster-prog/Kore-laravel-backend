<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BienvenidaEmpleado extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $empleadoNombre,
        public string $empresaNombre,
        public string $email,
        public string $passwordTemporal,
        public string $appUrl,
        public array  $documentos = [], // [{ nombre, url }]
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "¡Bienvenido a {$this->empresaNombre}! Tus credenciales de acceso",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.bienvenida-empleado',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        Log::info('Documentos al enviar correo: ' . json_encode($this->documentos));
        
        if (empty($this->documentos)) return [];
        
        return collect($this->documentos)
            ->filter(fn($doc) => 
                is_array($doc) && 
                !empty($doc['url']) && 
                !empty($doc['nombre']) &&
                str_starts_with($doc['url'], 'http')
            )
            ->map(function ($doc) {
                try {
                    return Attachment::fromUrl($doc['url'])
                        ->as($doc['nombre'] . '.pdf')
                        ->withMime('application/pdf');
                } catch (\Exception $e) {
                    Log::error("Error adjuntando documento: " . $e->getMessage());
                    return null;
                }
            })
            ->filter()
            ->values()
            ->toArray();
    }
}
