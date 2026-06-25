<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $userId,
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
            $service->sendToUser($this->userId, $this->title, $this->body, $this->data);
        } catch (\Throwable $e) {
            Log::error('SendPushNotification job failed', [
                'user_id' => $this->userId,
                'title' => $this->title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
