<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $candidateName,
        public string $jobTitle,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Actualización sobre tu postulación a {$this->jobTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ats.rejected',
        );
    }
}
