<?php

namespace App\Console\Commands;

use App\Models\Interview;
use App\Services\AtsNotificationService;
use Illuminate\Console\Command;

class SendInterviewReminders extends Command
{
    protected $signature = 'interviews:send-reminders';

    protected $description = 'Envía recordatorios de entrevista 24h antes al candidato y al entrevistador';

    public function handle(): int
    {
        $from = now()->addHours(23);
        $to = now()->addHours(25);

        $interviews = Interview::with(['application.user', 'application.jobOpening', 'interviewer'])
            ->where('result', 'pending')
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereNull('reminder_sent_at')
            ->get();

        foreach ($interviews as $interview) {
            $this->sendReminder($interview);
            $interview->update(['reminder_sent_at' => now()]);
        }

        $this->info("{$interviews->count()} recordatorio(s) enviado(s).");

        return self::SUCCESS;
    }

    private function sendReminder(Interview $interview): void
    {
        $candidate = $interview->application?->user;
        $interviewer = $interview->interviewer;

        if ($candidate?->email) {
            AtsNotificationService::interviewReminder($interview, $candidate->email, $candidate->name, 'candidate');
        }

        if ($interviewer?->email) {
            AtsNotificationService::interviewReminder($interview, $interviewer->email, $interviewer->name, 'interviewer');
        }
    }
}
