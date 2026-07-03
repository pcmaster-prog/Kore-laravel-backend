<?php

namespace App\Console\Commands;

use App\Models\Interview;
use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInterviewReminders extends Command
{
    protected $signature = 'ats:send-interview-reminders';

    protected $description = 'Send WhatsApp reminder to candidates with interviews in the next 24 hours';

    public function handle(): int
    {
        $interviews = Interview::with(['application.user', 'application.jobOpening'])
            ->where('result', 'pending')
            ->whereBetween('scheduled_at', [now(), now()->addHours(24)])
            ->whereNull('reminder_sent_at')
            ->get();

        $sent = 0;

        foreach ($interviews as $interview) {
            $application = $interview->application;

            if (! $application) {
                continue;
            }

            $contactInfo = $application->contact_info ?? [];
            $rawPhone = $contactInfo['phone'] ?? null;

            if (! $rawPhone) {
                continue;
            }

            $phone = '52' . preg_replace('/[^0-9]/', '', $rawPhone);

            $name = $application->user?->name ?? 'Candidato';
            $jobTitle = $application->jobOpening?->title ?? 'la vacante';
            $scheduledAt = $interview->scheduled_at?->format('d/m/Y H:i') ?? '';

            $message = "Hola {$name}, te recordamos que tienes una entrevista programada mañana para la vacante de {$jobTitle}. Fecha: {$scheduledAt}. ¡Te esperamos! - Equipo DecorArte";

            $success = WhatsAppNotificationService::send($phone, $message);

            if ($success) {
                $sent++;
            }

            $interview->update(['reminder_sent_at' => now()]);
        }

        $total = $interviews->count();
        $this->info("Procesados: {$total} interview(s). Recordatorios enviados: {$sent}.");
        Log::info("ats:send-interview-reminders — procesados: {$total}, enviados: {$sent}.");

        return self::SUCCESS;
    }
}
