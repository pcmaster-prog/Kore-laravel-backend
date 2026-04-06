<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NotificationService — Envía push notifications vía Firebase Cloud Messaging v1 HTTP API.
 *
 * No requiere SDK externo. Usa:
 *  - JWT firmado con la private key de la service account para obtener el OAuth2 access token.
 *  - Laravel HTTP client (Guzzle) para llamar a la FCM v1 API.
 *
 * Variables de entorno requeridas:
 *  FIREBASE_PROJECT_ID       → kore-ops
 *  FIREBASE_CLIENT_EMAIL     → firebase-adminsdk-fbsvc@kore-ops.iam.gserviceaccount.com
 *  FIREBASE_PRIVATE_KEY      → -----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n
 */
class NotificationService
{
    private string $projectId;
    private string $clientEmail;
    private string $privateKey;

    public function __construct()
    {
        $this->projectId   = config('services.firebase.project_id',   env('FIREBASE_PROJECT_ID', ''));
        $this->clientEmail = config('services.firebase.client_email',  env('FIREBASE_CLIENT_EMAIL', ''));
        $this->privateKey  = str_replace('\\n', "\n", config('services.firebase.private_key', env('FIREBASE_PRIVATE_KEY', '')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enviar notificación a un usuario específico (por user_id).
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): void
    {
        $tokens = FcmToken::where('user_id', $userId)->pluck('token')->toArray();
        if (empty($tokens)) return;

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Enviar notificación a múltiples usuarios.
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        $tokens = FcmToken::whereIn('user_id', $userIds)->pluck('token')->toArray();
        if (empty($tokens)) return;

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Enviar notificación a todos los admins/supervisores de una empresa.
     */
    public function sendToManagers(string $empresaId, string $title, string $body, array $data = []): void
    {
        $managerIds = \App\Models\User::where('empresa_id', $empresaId)
            ->whereIn('role', ['admin', 'supervisor'])
            ->pluck('id')
            ->toArray();

        if (empty($managerIds)) return;

        $this->sendToUsers($managerIds, $title, $body, $data);
    }

    /**
     * Eliminar un token inválido de la base de datos.
     */
    public function removeInvalidToken(string $token): void
    {
        FcmToken::where('token', $token)->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enviar a una lista de tokens FCM (máximo 500 por llamada a FCM v1).
     * FCM v1 API no soporta multicast directo; se envía uno a uno o en batch.
     * Para MVP, enviamos en loop (máx. ~100 tokens esperados).
     */
    private function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        if (empty($tokens) || empty($this->projectId)) return;

        // Convertir todos los valores de data a string (requerido por FCM)
        $stringData = collect($data)->map(fn($v) => (string) $v)->toArray();

        try {
            $accessToken = $this->getAccessToken();
            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $invalidTokens = [];

            foreach ($tokens as $token) {
                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],
                        'data' => $stringData,
                        'webpush' => [
                            'notification' => [
                                'title' => $title,
                                'body'  => $body,
                                'icon'  => '/icons/icon-192x192.png',
                            ],
                        ],
                    ],
                ];

                $response = Http::withToken($accessToken)
                    ->post($url, $payload);

                if ($response->failed()) {
                    $errorCode = $response->json('error.details.0.errorCode') ?? $response->json('error.status');
                    if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                        $invalidTokens[] = $token;
                    } else {
                        Log::warning('FCM notification failed', [
                            'token_preview' => substr($token, 0, 20) . '...',
                            'status'        => $response->status(),
                            'error'         => $response->json('error.message'),
                        ]);
                    }
                }
            }

            // Limpiar tokens inválidos
            if (!empty($invalidTokens)) {
                FcmToken::whereIn('token', $invalidTokens)->delete();
                Log::info('FCM: eliminados ' . count($invalidTokens) . ' tokens inválidos.');
            }

        } catch (\Throwable $e) {
            Log::warning('FCM NotificationService error: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene un OAuth2 access token para la FCM API.
     * Genera un JWT firmado con la private key de la service account,
     * lo intercambia por un access token en Google OAuth2, y lo cachea 55 min.
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'fcm_oauth_token_' . md5($this->clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(55), function () {
            $jwt = $this->buildJwt();

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('FCM OAuth2 token error: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Construye un JWT (RS256) firmado con la private key de la service account.
     */
    private function buildJwt(): string
    {
        $now = time();

        $header = $this->base64url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $claimSet = $this->base64url(json_encode([
            'iss'   => $this->clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claimSet}";

        $privateKey = openssl_pkey_get_private($this->privateKey);
        if (!$privateKey) {
            throw new \RuntimeException('FCM: No se pudo cargar la private key. Verifica FIREBASE_PRIVATE_KEY.');
        }

        $signature = '';
        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . $this->base64url($signature);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
