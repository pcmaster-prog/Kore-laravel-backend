<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationToManagers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $empresaId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $service): void
    {
        try {
            $service->sendToManagers($this->empresaId, $this->title, $this->body, $this->data);
        } catch (\Throwable $e) {
            Log::error('SendPushNotificationToManagers job failed', [
                'empresa_id' => $this->empresaId,
                'title'      => $this->title,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
