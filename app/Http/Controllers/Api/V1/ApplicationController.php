<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusLog;
use App\Models\Empleado;
use App\Models\EmpleadoModulo;
use App\Models\JobOpening;
use App\Services\SecureFileStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ApplicationController extends Controller
{
    /**
     * Estados válidos del pipeline ATS.
     */
    private const VALID_STATUSES = [
        'new',
        'screening',
        'interview-requested',
        'interviewing',
        'hired',
        'rejected',
    ];

    /**
     * Transiciones permitidas.
     * El estado destino 'rejected' se permite desde casi cualquier etapa activa.
     */
    private const ALLOWED_TRANSITIONS = [
        'new' => ['screening', 'rejected'],
        'screening' => ['interview-requested', 'rejected'],
        'interview-requested' => ['interviewing', 'rejected'],
        'interviewing' => ['hired', 'rejected'],
        'hired' => [],
        'rejected' => [],
    ];

    /**
     * Tipos de documentos permitidos.
     */
    private const DOCUMENT_TYPES = [
        'birth_certificate',
        'curp',
        'address_proof',
        'rfc',
        'nss',
        'cv',
    ];

    /**
     * Score mínimo para aprobar la autoevaluación.
     */
    private const MIN_SCREENING_SCORE = 7;

    /**
     * Auxiliar para enviar WhatsApp via CallMeBot
     */
    private function sendWhatsAppNotification($phone, $message)
    {
        $apiKey = config('services.whatsapp.api_key');
        $myPhone = config('services.whatsapp.phone');

        if (! $apiKey || ! $myPhone) {
            return;
        }

        // CallMeBot normalmente recibe el phone destino o lo asocia directo
        $url = 'https://api.callmebot.com/whatsapp.php?phone='.$phone.'&text='.urlencode($message).'&apikey='.$apiKey;

        try {
            file_get_contents($url);
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp via CallMeBot: '.$e->getMessage());
        }
    }

    // ==========================================
    // ENDPOINTS PORTAL (Aspirantes)
    // ==========================================

    public function apply(Request $request)
    {
        $validated = $request->validate([
            'job_opening_id' => 'required|exists:job_openings,id',
            'contact_info' => 'nullable|array',
            'education' => 'nullable|array',
            'experience' => 'nullable|array',
        ]);

        $job = JobOpening::where('id', $validated['job_opening_id'])
            ->where('status', 'open')
            ->first();

        if (! $job) {
            return response()->json(['message' => 'La vacante no está disponible.'], 422);
        }

        $existing = Application::where('user_id', $request->user()->id)
            ->where('job_opening_id', $job->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ya te has postulado a esta vacante.'], 409);
        }

        $app = Application::create([
            'empresa_id' => $job->empresa_id,
            'job_opening_id' => $job->id,
            'user_id' => $request->user()->id,
            'status' => 'new',
            'contact_info' => $validated['contact_info'] ?? [],
            'education' => $validated['education'] ?? [],
            'experience' => $validated['experience'] ?? [],
        ]);

        Log::info('Application created', [
            'application_id' => $app->id,
            'empresa_id' => $app->empresa_id,
            'job_opening_id' => $app->job_opening_id,
            'user_id' => $app->user_id,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'to_status' => 'new',
            'notes' => 'Postulación inicial recibida desde el portal.',
        ]);

        return response()->json(['data' => $app], 201);
    }

    public function myApplications(Request $request)
    {
        $apps = Application::with('jobOpening')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json(['data' => $apps]);
    }

    public function myCurrentApplication(Request $request)
    {
        $app = Application::with('jobOpening')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $app) {
            return response()->json(['message' => 'No application found'], 404);
        }

        return response()->json(['data' => $app]);
    }

    public function updateExpediente(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'phone' => 'required|string|regex:/^\d{10}$/',
            'rfc' => 'required|string|regex:/^[A-Z&Ñ]{3,4}\d{6}[A-Z\d]{2,3}$/i',
            'curp' => 'required|string|regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z\d]{2}$/i',
            'nss' => 'required|string|regex:/^\d{11}$/',
            'address' => 'required|string|min:10',
        ]);

        $app->update([
            'contact_info' => array_merge($app->contact_info ?? [], $validated),
        ]);

        return response()->json(['data' => $app]);
    }

    public function uploadDocument(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'document_type' => 'required|string|in:'.implode(',', self::DOCUMENT_TYPES),
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $folder = 'applications/'.$app->id;
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        $stored = SecureFileStorage::upload($request->file('file'), $folder, $allowedTypes, 5120);

        // Reemplazar documento previo del mismo tipo si existe.
        $existing = ApplicationDocument::where('application_id', $app->id)
            ->where('document_type', $validated['document_type'])
            ->first();

        if ($existing) {
            SecureFileStorage::delete($existing->disk ?? SecureFileStorage::disk(), $existing->file_path);
            $existing->update([
                'file_path' => $stored->path,
                'disk' => $stored->disk,
                'original_name' => $stored->original_name,
            ]);
            $doc = $existing;
        } else {
            $doc = ApplicationDocument::create([
                'application_id' => $app->id,
                'document_type' => $validated['document_type'],
                'file_path' => $stored->path,
                'disk' => $stored->disk,
                'original_name' => $stored->original_name,
            ]);
        }

        return response()->json([
            'data' => $doc,
            'url' => SecureFileStorage::temporaryUrl($doc->disk, $doc->file_path),
        ]);
    }

    public function deleteDocument(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'document_type' => 'required|string|in:'.implode(',', self::DOCUMENT_TYPES),
        ]);

        $existing = ApplicationDocument::where('application_id', $app->id)
            ->where('document_type', $validated['document_type'])
            ->first();

        if (! $existing) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        SecureFileStorage::delete($existing->disk ?? SecureFileStorage::disk(), $existing->file_path);
        $existing->delete();

        return response()->json(['message' => 'Documento eliminado.']);
    }

    /**
     * Genera una URL temporal segura para un documento.
     */
    private function documentUrl(ApplicationDocument $doc): ?string
    {
        return SecureFileStorage::temporaryUrl($doc->disk ?? SecureFileStorage::disk(), $doc->file_path);
    }

    public function markInductionWatched(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        if ($app->status !== 'new') {
            return response()->json(['message' => 'No puedes marcar la inducción en este estado.'], 422);
        }

        $app->update([
            'has_induction_video_watched' => true,
            'induction_video_watched_at' => now(),
        ]);

        return response()->json(['message' => 'Induction marked as watched.']);
    }

    public function submitScreening(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        if ($app->status !== 'new' || ! $app->has_induction_video_watched) {
            return response()->json(['message' => 'Debes ver el video de inducción antes de la autoevaluación.'], 422);
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'score' => 'required|integer|min:0|max:10',
        ]);

        $passed = $validated['score'] >= self::MIN_SCREENING_SCORE;
        $nextStatus = $passed ? 'screening' : 'screening';

        // Aprobó: avanza a screening (listo para solicitar entrevista).
        // No aprobó: permanece en screening pero con score bajo; el admin decide.
        $app->update([
            'screening_test_results' => [
                'answers' => $validated['answers'],
                'score' => $validated['score'],
                'passed' => $passed,
            ],
            'status' => $nextStatus,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => 'new',
            'to_status' => $nextStatus,
            'notes' => "Aspirante completó la autoevaluación. Score: {$validated['score']}/10. ".($passed ? 'Aprobado.' : 'No aprobado.'),
        ]);

        return response()->json([
            'message' => 'Screening submitted.',
            'data' => [
                'passed' => $passed,
                'score' => $validated['score'],
            ],
        ]);
    }

    public function requestInterview(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        if ($app->status !== 'screening') {
            return response()->json(['message' => 'No puedes solicitar entrevista en este estado.'], 422);
        }

        $results = $app->screening_test_results ?? [];
        if (empty($results['passed'])) {
            return response()->json(['message' => 'Debes aprobar la autoevaluación para solicitar entrevista.'], 422);
        }

        $oldStatus = $app->status;
        $app->update(['status' => 'interview-requested']);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'interview-requested',
            'notes' => 'Aspirante solicitó entrevista en vivo desde el portal.',
        ]);

        return response()->json(['message' => 'Entrevista solicitada.']);
    }

    // ==========================================
    // ENDPOINTS ADMIN (ERP Kore)
    // ==========================================

    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $empresaId = $request->user()->empresa_id;
        if (! $empresaId) {
            return response()->json([
                'message' => 'Tu usuario administrador no tiene una empresa asignada. Contacta soporte.',
            ], 400);
        }

        $apps = Application::with(['jobOpening', 'user'])
            ->where('empresa_id', $empresaId)
            ->orderBy('created_at', 'desc')
            ->get();

        Log::info('Admin listed applications', [
            'admin_id' => $request->user()->id,
            'empresa_id' => $empresaId,
            'count' => $apps->count(),
        ]);

        return response()->json(['data' => $apps]);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with(['jobOpening', 'user', 'documents', 'statusLogs.changedBy'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $app->documents->transform(function ($doc) {
            $doc->url = $this->documentUrl($doc);

            return $doc;
        });

        return response()->json(['data' => $app]);
    }

    public function changeStatus(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', self::VALID_STATUSES),
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $app->status;
        $newStatus = $validated['status'];

        if (! $this->canTransition($oldStatus, $newStatus)) {
            return response()->json([
                'message' => "No se puede cambiar el estado de '{$oldStatus}' a '{$newStatus}'.",
            ], 422);
        }

        $app->update(['status' => $newStatus]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'changed_by' => $request->user()->id,
            'notes' => $validated['notes'] ?? 'Cambio de estado manual.',
        ]);

        return response()->json(['data' => $app]);
    }

    /**
     * Valida si una transición de estado está permitida.
     */
    private function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        // Rechazo siempre permitido desde etapas activas, excepto estados finales.
        if ($to === 'rejected' && in_array($from, ['new', 'screening', 'interview-requested', 'interviewing'])) {
            return true;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? []);
    }

    public function scheduleInterview(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'interview_scheduled_at' => 'required|date',
            'notes' => 'nullable|string',
            'notify_whatsapp' => 'boolean',
        ]);

        $oldStatus = $app->status;
        if (! $this->canTransition($oldStatus, 'interviewing')) {
            return response()->json([
                'message' => "No se puede agendar entrevista desde el estado '{$oldStatus}'.",
            ], 422);
        }

        $app->update([
            'status' => 'interviewing',
            'interview_scheduled_at' => $validated['interview_scheduled_at'],
            'interview_notes' => $validated['notes'] ?? $app->interview_notes,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'interviewing',
            'changed_by' => $request->user()->id,
            'notes' => 'Entrevista agendada: '.$validated['interview_scheduled_at'].($validated['notes'] ? ' - '.$validated['notes'] : ''),
        ]);

        if ($request->boolean('notify_whatsapp') && isset($app->contact_info['phone'])) {
            $msg = "Hola {$app->user->name}, tienes una entrevista programada para la vacante de {$app->jobOpening->title} el día {$validated['interview_scheduled_at']}. ¡Te esperamos!";
            $phone = '52'.preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
            $this->sendWhatsAppNotification($phone, $msg);
        }

        return response()->json(['data' => $app]);
    }

    public function recordInterviewResult(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'result' => 'required|string|in:passed,failed,approved,rejected',
            'notes' => 'nullable|string',
        ]);

        if ($app->status !== 'interviewing') {
            return response()->json(['message' => 'La entrevista aún no ha sido agendada.'], 422);
        }

        // Normalizamos sinónimos comunes del frontend.
        $result = in_array($validated['result'], ['passed', 'approved']) ? 'passed' : 'failed';
        $oldStatus = $app->status;
        $nextStatus = $result === 'passed' ? 'interviewing' : 'rejected';

        $app->update([
            'status' => $nextStatus,
            'interview_result' => $result,
            'interview_notes' => $validated['notes'] ?? $app->interview_notes,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => $nextStatus,
            'changed_by' => $request->user()->id,
            'notes' => 'Resultado de entrevista: '.$result.($validated['notes'] ? ' - '.$validated['notes'] : ''),
        ]);

        return response()->json(['data' => $app]);
    }

    public function hireTrial(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with('user')->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'trial_period_months' => 'required|integer|in:1,2,3',
            'salary' => 'required|numeric|min:0',
            'modules' => 'nullable|array',
            'modules.*' => 'string|exists:modulos,slug',
            'position_id' => 'nullable|exists:positions,id',
        ]);

        $oldStatus = $app->status;
        if (! $this->canTransition($oldStatus, 'hired')) {
            return response()->json([
                'message' => "No se puede contratar desde el estado '{$oldStatus}'.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $app->update(['status' => 'hired']);

            ApplicationStatusLog::create([
                'application_id' => $app->id,
                'from_status' => $oldStatus,
                'to_status' => 'hired',
                'changed_by' => $request->user()->id,
                'notes' => "Contratado a prueba por {$validated['trial_period_months']} meses.",
            ]);

            $aspiranteUser = $app->user;
            $aspiranteUser->update([
                'role' => 'empleado_prueba',
                'empresa_id' => $request->user()->empresa_id,
            ]);

            $empleado = Empleado::create([
                'empresa_id' => $request->user()->empresa_id,
                'user_id' => $aspiranteUser->id,
                'full_name' => $aspiranteUser->name,
                'status' => 'active',
                'hired_at' => now(),
                'payment_type' => 'daily',
                'daily_rate' => $validated['salary'],
                'position_id' => $validated['position_id'] ?? null,
            ]);

            if (! empty($validated['modules'])) {
                foreach ($validated['modules'] as $moduleSlug) {
                    EmpleadoModulo::updateOrCreate([
                        'empleado_id' => $empleado->id,
                        'module_slug' => $moduleSlug,
                    ]);
                }
            }

            DB::commit();

            if (isset($app->contact_info['phone'])) {
                $msg = "¡Felicidades {$aspiranteUser->name}! Has sido aceptado(a) para el puesto de {$app->jobOpening->title}. Inicias tu periodo de prueba. ¡Bienvenido(a) a DecorArte!";
                $phone = '52'.preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
                $this->sendWhatsAppNotification($phone, $msg);
            }

            return response()->json(['message' => 'Candidato contratado a prueba exitosamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en hireTrial: '.$e->getMessage());

            return response()->json(['message' => 'Error al contratar al candidato.'], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with('user')->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string',
            'notify_whatsapp' => 'boolean',
        ]);

        $oldStatus = $app->status;
        if (! $this->canTransition($oldStatus, 'rejected')) {
            return response()->json([
                'message' => "No se puede rechazar una aplicación en estado '{$oldStatus}'.",
            ], 422);
        }

        $app->update(['status' => 'rejected']);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'rejected',
            'changed_by' => $request->user()->id,
            'notes' => 'Rechazado: '.$validated['reason'],
        ]);

        if ($request->boolean('notify_whatsapp') && isset($app->contact_info['phone'])) {
            $msg = "Hola {$app->user->name}, gracias por tu interés en la vacante de {$app->jobOpening->title}. Lamentablemente en esta ocasión no continuaremos con tu proceso. Te deseamos éxito.";
            $phone = '52'.preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
            $this->sendWhatsAppNotification($phone, $msg);
        }

        return response()->json(['message' => 'Candidato rechazado.']);
    }
}
