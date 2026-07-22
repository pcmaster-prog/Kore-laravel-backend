<?php

namespace App\Services;

use App\Mail\ApplicationReceivedMail;
use App\Mail\HiredMail;
use App\Mail\InterviewReminderMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\OfferSentMail;
use App\Mail\RejectedMail;
use App\Mail\TemplatedEmail;
use App\Models\Application;
use App\Models\EmailTemplate;
use App\Models\Interview;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AtsNotificationService
{
    public static function applicationReceived(Application $application): void
    {
        $candidate = $application->user;
        if (! $candidate?->email) {
            return;
        }

        $variables = [
            'candidateName' => $candidate->name,
            'jobTitle' => $application->jobOpening?->title ?? 'la vacante',
            'empresaName' => $application->empresa?->name ?? 'nuestra empresa',
        ];

        if (self::sendTemplated('application_received', $candidate->email, $variables, $application->empresa_id)) {
            return;
        }

        try {
            Mail::to($candidate->email)->send(new ApplicationReceivedMail(
                candidateName: $candidate->name,
                jobTitle: $application->jobOpening?->title ?? 'la vacante',
                empresaName: $application->empresa?->name ?? 'nuestra empresa',
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando correo de postulación recibida: '.$e->getMessage());
        }
    }

    public static function interviewScheduled(Interview $interview, bool $notifyWhatsApp = true): void
    {
        $candidate = $interview->application?->user;
        if (! $candidate?->email) {
            return;
        }

        $variables = [
            'candidateName' => $candidate->name,
            'jobTitle' => $interview->application?->jobOpening?->title ?? 'la vacante',
            'scheduledAt' => $interview->scheduled_at?->format('d/m/Y H:i') ?? 'Por definir',
            'method' => $interview->method ?? '',
            'location' => $interview->location ?? '',
            'meetingUrl' => $interview->meeting_url ?? '',
            'empresaName' => $interview->application?->empresa?->name ?? 'nuestra empresa',
        ];

        if (self::sendTemplated('interview_scheduled', $candidate->email, $variables, $interview->application?->empresa_id)) {
            if ($notifyWhatsApp) {
                self::sendWhatsApp($interview->application, "Hola {$candidate->name}, tu entrevista para {$variables['jobTitle']} esta programada el {$variables['scheduledAt']}. Metodo: {$variables['method']}.");
            }

            return;
        }

        try {
            Mail::to($candidate->email)->send(new InterviewScheduledMail(
                candidateName: $candidate->name,
                jobTitle: $interview->application?->jobOpening?->title ?? 'la vacante',
                scheduledAt: $interview->scheduled_at?->format('d/m/Y H:i') ?? 'Por definir',
                method: $interview->method,
                location: $interview->location,
                meetingUrl: $interview->meeting_url,
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando correo de entrevista programada: '.$e->getMessage());
        }

        if ($notifyWhatsApp) {
            self::sendWhatsApp($interview->application, "Hola {$candidate->name}, tu entrevista para {$variables['jobTitle']} esta programada el {$variables['scheduledAt']}. Metodo: {$variables['method']}.");
        }
    }

    public static function interviewReminder(Interview $interview, string $recipientEmail, string $recipientName, string $role = 'candidate'): void
    {
        $candidate = $interview->application?->user;
        $jobTitle = $interview->application?->jobOpening?->title ?? 'la vacante';
        $scheduledAt = $interview->scheduled_at?->format('d/m/Y H:i') ?? 'Por definir';

        $variables = [
            'recipientName' => $recipientName,
            'candidateName' => $candidate?->name ?? 'Candidato',
            'jobTitle' => $jobTitle,
            'scheduledAt' => $scheduledAt,
            'method' => $interview->method ?? '',
            'location' => $interview->location ?? '',
            'meetingUrl' => $interview->meeting_url ?? '',
            'role' => $role,
            'empresaName' => $interview->application?->empresa?->name ?? 'nuestra empresa',
        ];

        if (self::sendTemplated('interview_reminder', $recipientEmail, $variables, $interview->application?->empresa_id)) {
            return;
        }

        try {
            Mail::to($recipientEmail)->queue(new InterviewReminderMail(
                recipientName: $recipientName,
                candidateName: $candidate?->name ?? 'Candidato',
                jobTitle: $jobTitle,
                scheduledAt: $scheduledAt,
                method: $interview->method,
                location: $interview->location,
                meetingUrl: $interview->meeting_url,
                role: $role,
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando recordatorio de entrevista: '.$e->getMessage());
        }

        if ($role === 'candidate') {
            $method = $interview->method ?? 'Por definir';
            self::sendWhatsApp($interview->application, "Hola {$recipientName}, recuerda tu entrevista para {$jobTitle} el {$scheduledAt}. Metodo: {$method}.");
        }
    }

    public static function offerSent(Application $application, string $offerUrl): void
    {
        $candidate = $application->user;
        if (! $candidate?->email) {
            return;
        }

        $variables = [
            'candidateName' => $candidate->name,
            'jobTitle' => $application->jobOpening?->title ?? 'la vacante',
            'empresaName' => $application->empresa?->name ?? 'nuestra empresa',
            'offerUrl' => $offerUrl,
        ];

        if (self::sendTemplated('offer_sent', $candidate->email, $variables, $application->empresa_id)) {
            return;
        }

        try {
            Mail::to($candidate->email)->queue(new OfferSentMail(
                candidateName: $candidate->name,
                jobTitle: $application->jobOpening?->title ?? 'la vacante',
                empresaName: $application->empresa?->name ?? 'nuestra empresa',
                offerUrl: $offerUrl,
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando correo de oferta laboral: '.$e->getMessage());
        }

        $offerJobTitle = $application->jobOpening?->title ?? 'la vacante';
        self::sendWhatsApp($application, "Hola {$candidate->name}, te enviamos una oferta para {$offerJobTitle}. Revisala aqui: {$offerUrl}");
    }

    public static function hired(Application $application): void
    {
        $candidate = $application->user;
        if (! $candidate?->email) {
            return;
        }

        $variables = [
            'candidateName' => $candidate->name,
            'jobTitle' => $application->jobOpening?->title ?? 'la vacante',
            'empresaName' => $application->empresa?->name ?? 'nuestra empresa',
        ];

        if (self::sendTemplated('hired', $candidate->email, $variables, $application->empresa_id)) {
            return;
        }

        try {
            Mail::to($candidate->email)->send(new HiredMail(
                candidateName: $candidate->name,
                jobTitle: $application->jobOpening?->title ?? 'la vacante',
                empresaName: $application->empresa?->name ?? 'nuestra empresa',
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando correo de contratación: '.$e->getMessage());
        }
    }

    public static function rejected(Application $application, string $reason): void
    {
        $candidate = $application->user;
        if (! $candidate?->email) {
            return;
        }

        $variables = [
            'candidateName' => $candidate->name,
            'jobTitle' => $application->jobOpening?->title ?? 'la vacante',
            'reason' => $reason,
            'empresaName' => $application->empresa?->name ?? 'nuestra empresa',
        ];

        if (self::sendTemplated('rejected', $candidate->email, $variables, $application->empresa_id)) {
            return;
        }

        try {
            Mail::to($candidate->email)->send(new RejectedMail(
                candidateName: $candidate->name,
                jobTitle: $application->jobOpening?->title ?? 'la vacante',
                reason: $reason,
            ));
        } catch (\Exception $e) {
            Log::error('Error enviando correo de rechazo: '.$e->getMessage());
        }
    }

    private static function sendWhatsApp(?Application $application, string $message): void
    {
        if (! $application) {
            return;
        }

        $phone = $application->contact_info['phone'] ?? null;
        if (! $phone) {
            return;
        }

        try {
            WhatsAppNotificationService::send($phone, $message);
        } catch (\Exception $e) {
            Log::error('Error enviando WhatsApp ATS: '.$e->getMessage());
        }
    }

    private static function sendTemplated(string $type, string $to, array $variables, ?string $empresaId): bool
    {
        if (! $empresaId) {
            return false;
        }

        $template = EmailTemplate::where('empresa_id', $empresaId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return false;
        }

        try {
            $subject = Blade::render($template->subject, $variables);
            $body = Blade::render($template->body, $variables);

            Mail::to($to)->queue(new TemplatedEmail($subject, $body));
        } catch (\Exception $e) {
            Log::error("Error enviando email templado {$type}: ".$e->getMessage());
        }

        return true;
    }
}
