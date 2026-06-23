<?php

namespace App\Services;

use App\Mail\ApplicationReceivedMail;
use App\Mail\HiredMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\RejectedMail;
use App\Models\Application;
use App\Models\Interview;
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

    public static function interviewScheduled(Interview $interview): void
    {
        $candidate = $interview->application?->user;
        if (! $candidate?->email) {
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
    }

    public static function hired(Application $application): void
    {
        $candidate = $application->user;
        if (! $candidate?->email) {
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
}
