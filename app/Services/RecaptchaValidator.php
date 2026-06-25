<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaValidator
{
    /**
     * Valida un token de reCAPTCHA v3 con la API de Google.
     *
     * @param  string  $action  Acción esperada (ej. 'register')
     * @param  float  $minScore  Score mínimo aceptable (0.0 - 1.0)
     */
    public static function validate(?string $token, string $action = 'submit', float $minScore = 0.5): bool
    {
        $secret = config('services.recaptcha.secret_key');

        if (empty($secret)) {
            Log::warning('RecaptchaValidator: secret_key no configurada. Permitiendo paso.');

            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

            if ($response->failed()) {
                Log::warning('RecaptchaValidator: fallo en petición a Google', ['status' => $response->status()]);

                return false;
            }

            $data = $response->json();

            if (empty($data['success'])) {
                Log::warning('RecaptchaValidator: token inválido', $data ?? []);

                return false;
            }

            if (! empty($data['action']) && $data['action'] !== $action) {
                Log::warning('RecaptchaValidator: acción no coincide', [
                    'expected' => $action,
                    'received' => $data['action'],
                ]);

                return false;
            }

            $score = $data['score'] ?? 0;
            if ($score < $minScore) {
                Log::warning('RecaptchaValidator: score bajo', ['score' => $score, 'min' => $minScore]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('RecaptchaValidator: excepción', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
