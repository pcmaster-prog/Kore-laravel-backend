<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public static function send(string $toPhone, string $message): bool
    {
        $apiUrl = config('services.whatsapp.api_url');
        $apiKey = config('services.whatsapp.global_api_key');
        $instanceName = config('services.whatsapp.instance_name');

        if (! $apiUrl || ! $apiKey || ! $instanceName || ! $toPhone) {
            return false;
        }

        $toPhone = self::sanitizePhone($toPhone);

        if (strlen($toPhone) < 10) {
            return false;
        }

        // Eliminar trailing slash si lo hay
        $apiUrl = rtrim($apiUrl, '/');
        $url = "{$apiUrl}/message/sendText/{$instanceName}";
        
        $payload = [
            'number' => $toPhone,
            'options' => [
                'delay' => 1200,
                'presence' => 'composing' // Muestra "escribiendo..." antes de enviar
            ],
            'text' => $message
        ];

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'apikey' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('Error enviando WhatsApp via Evolution API: '.$response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('Excepción enviando WhatsApp via Evolution API: '.$e->getMessage());
            return false;
        }
    }

    private static function sanitizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Si es a 10 dígitos (ej. México local), agregar el código de país.
        if (strlen($phone) === 10) {
            $phone = '52'.$phone;
        }

        return $phone;
    }
}
