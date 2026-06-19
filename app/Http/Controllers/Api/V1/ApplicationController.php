<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusLog;
use App\Models\JobOpening;
use App\Models\Empleado;
use App\Models\EmpleadoModulo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ApplicationController extends Controller
{
    /**
     * Auxiliar para enviar WhatsApp via CallMeBot
     */
    private function sendWhatsAppNotification($phone, $message)
    {
        $apiKey = config('services.whatsapp.api_key');
        $myPhone = config('services.whatsapp.phone');
        
        if (!$apiKey || !$myPhone) return;

        // CallMeBot normalmente recibe el phone destino o lo asocia directo
        $url = "https://api.callmebot.com/whatsapp.php?phone=" . $phone . "&text=" . urlencode($message) . "&apikey=" . $apiKey;
        
        try {
            file_get_contents($url);
        } catch (\Exception $e) {
            Log::error("Error sending WhatsApp via CallMeBot: " . $e->getMessage());
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

        $job = JobOpening::findOrFail($validated['job_opening_id']);

        $existing = Application::where('user_id', $request->user()->id)
            ->where('job_opening_id', $job->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ya te has postulado a esta vacante.'], 400);
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

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'to_status' => 'new',
            'notes' => 'Postulación inicial recibida desde el portal.'
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
            
        if (!$app) {
            return response()->json(['message' => 'No application found'], 404);
        }
        
        return response()->json(['data' => $app]);
    }

    public function updateExpediente(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);
        
        $validated = $request->validate([
            'phone' => 'nullable|string',
            'rfc' => 'nullable|string',
            'curp' => 'nullable|string',
            'nss' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        $app->update([
            'contact_info' => array_merge($app->contact_info ?? [], $validated)
        ]);

        return response()->json(['data' => $app]);
    }

    public function uploadDocument(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);

        $request->validate([
            'document_type' => 'required|string',
            'file' => 'required|file|mimes:pdf,jpg,png|max:5120'
        ]);

        $path = $request->file('file')->store('applications/'.$app->id, 's3');

        $doc = ApplicationDocument::create([
            'application_id' => $app->id,
            'document_type' => $request->document_type,
            'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);

        return response()->json(['data' => $doc]);
    }

    public function markInductionWatched(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);
        $app->update([
            'has_induction_video_watched' => true,
            'induction_video_watched_at' => now()
        ]);
        return response()->json(['message' => 'Induction marked as watched.']);
    }

    public function submitScreening(Request $request, $id)
    {
        $app = Application::where('user_id', $request->user()->id)->findOrFail($id);
        $request->validate(['results' => 'required|array']);
        
        $app->update([
            'screening_test_results' => $request->results,
            'status' => 'screening'
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => 'new',
            'to_status' => 'screening',
            'notes' => 'Aspirante completó la autoevaluación.'
        ]);

        return response()->json(['message' => 'Screening submitted.']);
    }

    // ==========================================
    // ENDPOINTS ADMIN (ERP Kore)
    // ==========================================

    public function index(Request $request)
    {
        Gate::authorize('manage-users');
        $apps = Application::with(['jobOpening', 'user'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['data' => $apps]);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with(['jobOpening', 'user', 'documents', 'statusLogs.changedBy'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $app->documents->transform(function ($doc) {
            $doc->url = Storage::disk('s3')->temporaryUrl($doc->file_path, now()->addMinutes(30));
            return $doc;
        });

        return response()->json(['data' => $app]);
    }

    public function changeStatus(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        
        $validated = $request->validate([
            'status' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        $oldStatus = $app->status;
        $app->update(['status' => $validated['status']]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => $validated['status'],
            'changed_by' => $request->user()->id,
            'notes' => $validated['notes']
        ]);

        return response()->json(['data' => $app]);
    }

    public function scheduleInterview(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'interview_scheduled_at' => 'required|date',
            'notes' => 'nullable|string',
            'notify_whatsapp' => 'boolean'
        ]);

        $app->update([
            'status' => 'interviewing',
            'interview_scheduled_at' => $validated['interview_scheduled_at'],
        ]);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $app->status,
            'to_status' => 'interviewing',
            'changed_by' => $request->user()->id,
            'notes' => 'Entrevista agendada: ' . $validated['interview_scheduled_at']
        ]);

        if ($request->notify_whatsapp && isset($app->contact_info['phone'])) {
            $msg = "Hola {$app->user->name}, tienes una entrevista programada para la vacante de {$app->jobOpening->title} el día {$validated['interview_scheduled_at']}. ¡Te esperamos!";
            $phone = "52" . preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
            $this->sendWhatsAppNotification($phone, $msg);
        }

        return response()->json(['data' => $app]);
    }

    public function recordInterviewResult(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'result' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        $app->update([
            'interview_result' => $validated['result'],
            'interview_notes' => $validated['notes']
        ]);

        return response()->json(['data' => $app]);
    }

    public function hireTrial(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with('user')->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'trial_period_months' => 'required|integer|in:1,2,3',
            'salary' => 'required|numeric',
            'modules' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            $app->update(['status' => 'hired']);
            
            ApplicationStatusLog::create([
                'application_id' => $app->id,
                'from_status' => 'interviewing',
                'to_status' => 'hired',
                'changed_by' => $request->user()->id,
                'notes' => "Contratado a prueba por {$validated['trial_period_months']} meses."
            ]);

            $aspiranteUser = $app->user;
            $aspiranteUser->update([
                'role' => 'empleado_prueba',
                'empresa_id' => $request->user()->empresa_id
            ]);

            $empleado = Empleado::create([
                'empresa_id' => $request->user()->empresa_id,
                'user_id' => $aspiranteUser->id,
                'full_name' => $aspiranteUser->name,
                'status' => 'active',
                'hired_at' => now(),
                'payment_type' => 'daily',
                'daily_rate' => $validated['salary'],
            ]);

            if (!empty($validated['modules'])) {
                foreach ($validated['modules'] as $moduleSlug) {
                    EmpleadoModulo::updateOrCreate([
                        'empleado_id' => $empleado->id,
                        'module_slug' => $moduleSlug
                    ]);
                }
            }

            DB::commit();

            if (isset($app->contact_info['phone'])) {
                $msg = "¡Felicidades {$aspiranteUser->name}! Has sido aceptado(a) para el puesto de {$app->jobOpening->title}. Inicias tu periodo de prueba. ¡Bienvenido(a) a DecorArte!";
                $phone = "52" . preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
                $this->sendWhatsAppNotification($phone, $msg);
            }

            return response()->json(['message' => 'Candidato contratado a prueba exitosamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al contratar: ' . $e->getMessage()], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $app = Application::with('user')->where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string',
            'notify_whatsapp' => 'boolean'
        ]);

        $app->update(['status' => 'rejected']);

        ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $app->status,
            'to_status' => 'rejected',
            'changed_by' => $request->user()->id,
            'notes' => 'Rechazado: ' . $validated['reason']
        ]);

        if ($request->notify_whatsapp && isset($app->contact_info['phone'])) {
            $msg = "Hola {$app->user->name}, gracias por tu interés en la vacante de {$app->jobOpening->title}. Lamentablemente en esta ocasión no continuaremos con tu proceso. Te deseamos éxito.";
            $phone = "52" . preg_replace('/[^0-9]/', '', $app->contact_info['phone']);
            $this->sendWhatsAppNotification($phone, $msg);
        }

        return response()->json(['message' => 'Candidato rechazado.']);
    }
}
