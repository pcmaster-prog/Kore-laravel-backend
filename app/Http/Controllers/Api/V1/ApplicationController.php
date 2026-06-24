<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusLog;
use App\Models\Empleado;
use App\Models\EmpleadoModulo;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Services\AtsNotificationService;
use App\Services\EmployeeOnboardingService;
use App\Services\SecureFileStorage;
use App\Services\WhatsAppNotificationService;
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
        'offer-sent',
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
        'interviewing' => ['offer-sent', 'rejected'],
        'offer-sent' => ['hired', 'rejected'],
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
        'ine',
        'proof_of_studies',
        'imss_proof',
        'bank_account_card',
        'beneficiary_ine',
    ];

    /**
     * Score mínimo por defecto para aprobar la autoevaluación.
     */
    private const MIN_SCREENING_SCORE = 7;

    /**
     * Formato esperado de cada pregunta de screening.
     */
    private const SCREENING_QUESTION_SCHEMA = ['question', 'options', 'correctIndex'];

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

        $app->load(['user', 'jobOpening', 'empresa']);
        AtsNotificationService::applicationReceived($app);

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
        $app = Application::with(['jobOpening', 'interviews'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $app) {
            return response()->json(['message' => 'No application found'], 404);
        }

        // Load offer relationship safely – the table may not exist yet in some environments.
        try {
            $app->load('offer');
        } catch (\Exception $e) {
            Log::warning('Could not load offer relationship: ' . $e->getMessage());
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
        $app = Application::where('user_id', $request->user()->id)
            ->with('jobOpening')
            ->findOrFail($id);

        if ($app->status !== 'new' || ! $app->has_induction_video_watched) {
            return response()->json(['message' => 'Debes ver el video de inducción antes de la autoevaluación.'], 422);
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'integer|min:0',
        ]);

        $questions = $app->jobOpening->screening_questions ?? [];

        if (count($questions) === 0) {
            return response()->json(['message' => 'Esta vacante no tiene autoevaluación configurada.'], 422);
        }

        if (count($validated['answers']) !== count($questions)) {
            return response()->json(['message' => 'Debes responder todas las preguntas.'], 422);
        }

        $correct = 0;
        $gradeable = 0; // Questions that actually have a correct answer defined
        foreach ($questions as $index => $question) {
            if (! is_array($question)) {
                continue;
            }

            // If no correctIndex is set, the question is informational – always counts as correct.
            if (! array_key_exists('correctIndex', $question) || $question['correctIndex'] === null || $question['correctIndex'] === '') {
                $correct++;
                continue;
            }

            $gradeable++;
            if (($validated['answers'][$index] ?? null) === $question['correctIndex']) {
                $correct++;
            }
        }

        $total = count($questions);
        // If no gradeable questions exist, the whole screening is informational – auto-pass.
        if ($gradeable === 0) {
            $score = 10;
        } else {
            $score = (int) round(($correct / $total) * 10);
        }
        $threshold = $app->jobOpening->screening_pass_score ?? self::MIN_SCREENING_SCORE;
        $passed = $score >= $threshold;
        $nextStatus = $passed ? 'screening' : 'rejected';

        $app->update([
            'screening_test_results' => [
                'answers' => $validated['answers'],
                'score' => $score,
                'passed' => $passed,
            ],
            'status' => $nextStatus,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => 'new',
            'to_status' => $nextStatus,
            'notes' => "Aspirante completó la autoevaluación. Score: {$score}/10. ".($passed ? 'Aprobado.' : 'Rechazado automáticamente por no alcanzar el puntaje mínimo.'),
        ]);

        if (! $passed) {
            AtsNotificationService::rejected($app, 'No alcanzaste el puntaje mínimo en la autoevaluación.');
        }

        return response()->json([
            'message' => 'Screening submitted.',
            'data' => [
                'passed' => $passed,
                'score' => $score,
            ],
        ]);
    }

    public function toggleManualReview(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'manual_review_required' => 'required|boolean',
            'manual_review_reason' => 'nullable|string|max:500',
        ]);

        $app->update([
            'manual_review_required' => $validated['manual_review_required'],
            'manual_review_reason' => $validated['manual_review_reason'],
        ]);

        return response()->json(['data' => $app]);
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
        $app = Application::with(['user', 'jobOpening', 'empresa'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'interview_scheduled_at' => 'required|date',
            'notes' => 'nullable|string',
            'notify_whatsapp' => 'boolean',
            'method' => 'nullable|in:in-person,video,phone',
            'location' => 'nullable|string',
            'meeting_url' => 'nullable|string|url',
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

        $interview = Interview::create([
            'application_id' => $app->id,
            'interviewer_id' => $request->user()->id,
            'scheduled_at' => $validated['interview_scheduled_at'],
            'method' => $validated['method'] ?? 'in-person',
            'location' => $validated['location'] ?? null,
            'meeting_url' => $validated['meeting_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'interviewing',
            'changed_by' => $request->user()->id,
            'notes' => 'Entrevista agendada: '.$validated['interview_scheduled_at'].(($validated['notes'] ?? null) ? ' - '.$validated['notes'] : ''),
        ]);

        AtsNotificationService::interviewScheduled($interview);

        if ($request->boolean('notify_whatsapp') && isset($app->contact_info['phone'])) {
            $msg = "Hola {$app->user->name}, tienes una entrevista programada para la vacante de {$app->jobOpening->title} el día {$validated['interview_scheduled_at']}. ¡Te esperamos!";
            $phone = '52'.preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
            WhatsAppNotificationService::send($phone, $msg);
        }

        return response()->json(['data' => $app->load('interviews')]);
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
        $app = Application::with(['user', 'jobOpening', 'empresa'])->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

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

        try {
            $service = new EmployeeOnboardingService();
            $service->create($app, $request->user(), [
                'salary' => $validated['salary'],
                'trial_months' => $validated['trial_period_months'],
                'modules' => $validated['modules'] ?? [],
                'position_id' => $validated['position_id'] ?? null,
                'notes' => "Contratado a prueba por {$validated['trial_period_months']} meses.",
            ]);

            if (isset($app->contact_info['phone'])) {
                $msg = "¡Felicidades {$app->user->name}! Has sido aceptado(a) para el puesto de {$app->jobOpening->title}. Inicias tu periodo de prueba. ¡Bienvenido(a) a DecorArte!";
                $phone = '52'.preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
                WhatsAppNotificationService::send($phone, $msg);
            }

            AtsNotificationService::hired($app);

            return response()->json(['message' => 'Candidato contratado a prueba exitosamente.']);
        } catch (\Exception $e) {
            Log::error('Error en hireTrial: '.$e->getMessage());

            return response()->json(['message' => 'Error al contratar al candidato.'], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with(['user', 'jobOpening', 'empresa'])->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

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
            WhatsAppNotificationService::send($phone, $msg);
        }

        AtsNotificationService::rejected($app, $validated['reason']);

        return response()->json(['message' => 'Candidato rechazado.']);
    }

    public function checkRehire(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::with(['user', 'jobOpening'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $previousEmpleado = null;
        if ($app->user?->email) {
            $previousEmpleado = Empleado::withTrashed()
                ->where('empresa_id', $app->empresa_id)
                ->whereHas('user', fn ($q) => $q->where('email', $app->user->email))
                ->latest()
                ->first();
        }

        return response()->json([
            'data' => [
                'is_rehire' => ! is_null($previousEmpleado),
                'previous_empleado_id' => $previousEmpleado?->id,
                'previous_full_name' => $previousEmpleado?->full_name,
                'previous_hired_at' => $previousEmpleado?->hired_at?->toDateTimeString(),
            ],
        ]);
    }

    public function rehire(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::with(['user', 'jobOpening', 'empresa'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'salary' => 'required|numeric|min:0',
            'modules' => 'nullable|array',
            'modules.*' => 'string|exists:modulos,slug',
            'position_id' => 'nullable|exists:positions,id',
        ]);

        if (! $app->user?->email) {
            return response()->json(['message' => 'La aplicación no tiene un usuario asociado.'], 422);
        }

        $previousEmpleado = Empleado::withTrashed()
            ->where('empresa_id', $app->empresa_id)
            ->whereHas('user', fn ($q) => $q->where('email', $app->user->email))
            ->latest()
            ->first();

        if (! $previousEmpleado) {
            return response()->json(['message' => 'No se encontró un empleado anterior para recontratar.'], 422);
        }

        $oldStatus = $app->status;
        if (in_array($oldStatus, ['hired', 'rejected'])) {
            return response()->json([
                'message' => "No se puede recontratar una aplicación en estado '{$oldStatus}'.",
            ], 422);
        }

        try {
            $service = new EmployeeOnboardingService();
            $service->rehire($app, $request->user(), $previousEmpleado, [
                'salary' => $validated['salary'],
                'modules' => $validated['modules'] ?? [],
                'position_id' => $validated['position_id'] ?? null,
                'notes' => 'Recontratación rápida de ex-empleado.',
            ]);

            AtsNotificationService::hired($app);

            return response()->json(['message' => 'Ex-empleado recontratado exitosamente.']);
        } catch (\Exception $e) {
            Log::error('Error en rehire: '.$e->getMessage());

            return response()->json(['message' => 'Error al recontratar al ex-empleado.'], 500);
        }
    }
}
