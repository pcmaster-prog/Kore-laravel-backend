<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InterviewReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $candidateName,
        public string $jobTitle,
        public string $scheduledAt,
        public ?string $method = null,
        public ?string $location = null,
        public ?string $meetingUrl = null,
        public string $role = 'candidate', // candidate | interviewer
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->role === 'interviewer'
            ? "Recordatorio: entrevista con {$this->candidateName}"
            : 'Recordatorio: tienes una entrevista mañana';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ats.interview-reminder',
        );
    }
}
