<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FcmTokenController extends Controller
{
    /**
     * POST /fcm/token — registrar o actualizar el token FCM del dispositivo.
     */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user();

        $data = $request->validate([
            'token'    => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'in:web,android,ios'],
        ]);

        FcmToken::updateOrCreate(
            ['user_id' => $u->id, 'token' => $data['token']],
            [
                'empresa_id'   => $u->empresa_id,
                'platform'     => $data['platform'] ?? 'web',
                'user_agent'   => substr((string) $request->userAgent(), 0, 300),
                'last_used_at' => now(),
            ]
        );

        return response()->json(['message' => 'Token registrado']);
    }

    /**
     * DELETE /fcm/token — eliminar token al cerrar sesión o revocar permisos.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        FcmToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Token eliminado']);
    }

    /**
     * POST /fcm/test — enviar una notificación de prueba al usuario actual.
     */
    public function test(Request $request): JsonResponse
    {
        $u = $request->user();

        try {
            app(\App\Services\NotificationService::class)->sendToUser(
                userId: $u->id,
                title: '🔔 Prueba de Notificación',
                body: 'Si lees esto, las notificaciones están funcionando correctamente en este dispositivo.',
                data: ['type' => 'test']
            );

            return response()->json(['message' => 'Notificación enviada con éxito']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al enviar: ' . $e->getMessage()], 500);
        }
    }
}
