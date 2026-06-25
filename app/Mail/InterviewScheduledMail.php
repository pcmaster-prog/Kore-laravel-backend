<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $candidateName,
        public string $jobTitle,
        public string $scheduledAt,
        public string $method,
        public ?string $location,
        public ?string $meetingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Entrevista programada para {$this->jobTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ats.interview-scheduled',
        );
    }
}
