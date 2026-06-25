<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public static function send(string $toPhone, string $message): bool
    {
        $apiKey = config('services.whatsapp.api_key');
        $sourcePhone = config('services.whatsapp.phone');

        if (! $apiKey || ! $sourcePhone || ! $toPhone) {
            return false;
        }

        $toPhone = self::sanitizePhone($toPhone);

        if (strlen($toPhone) < 10) {
            return false;
        }

        $url = 'https://api.callmebot.com/whatsapp.php'
            .'?phone='.urlencode($toPhone)
            .'&text='.urlencode($message)
            .'&apikey='.urlencode($apiKey);

        try {
            Http::timeout(15)->get($url);

            return true;
        } catch (\Exception $e) {
            Log::error('Error enviando WhatsApp via CallMeBot: '.$e->getMessage());

            return false;
        }
    }

    private static function sanitizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 10) {
            $phone = '52'.$phone;
        }

        return $phone;
    }
}
