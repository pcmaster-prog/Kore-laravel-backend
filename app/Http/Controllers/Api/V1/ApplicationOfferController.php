<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOffer;
use App\Models\UserActivationToken;
use App\Services\AtsNotificationService;
use App\Services\EmployeeOnboardingService;
use App\Services\SecureFileStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApplicationOfferController extends Controller
{
    private const ONBOARDING_DOCUMENT_TYPES = [
        'ine' => 'INE',
        'proof_of_studies' => 'Comprobante de estudios',
        'imss_proof' => 'Alta IMSS',
        'bank_account_card' => 'Tarjeta de cuenta bancaria',
        'beneficiary_ine' => 'INE del beneficiario',
    ];

    public function store(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::with(['user', 'jobOpening', 'empresa'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'salary' => 'required|numeric|min:0',
            'trial_months' => 'required|integer|in:1,2,3',
            'position_id' => 'nullable|exists:positions,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! in_array($app->status, ['interviewing', 'offer-sent'])) {
            return response()->json([
                'message' => "No se puede enviar una oferta desde el estado '{$app->status}'.",
            ], 422);
        }

        $oldStatus = $app->status;

        $offer = ApplicationOffer::updateOrCreate(
            ['application_id' => $app->id],
            [
                'salary' => $validated['salary'],
                'trial_months' => $validated['trial_months'],
                'position_id' => $validated['position_id'] ?? null,
                'status' => 'sent',
                'sent_at' => now(),
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]
        );

        $app->update(['status' => 'offer-sent']);

        \App\Models\ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'offer-sent',
            'changed_by' => $request->user()->id,
            'notes' => 'Oferta laboral enviada al candidato.',
        ]);

        if ($app->user?->email) {
            AtsNotificationService::offerSent($app, config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx').'/oferta');
        }

        return response()->json(['data' => $offer->load('position')], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $offer = ApplicationOffer::whereHas('application', fn ($q) =>
            $q->where('empresa_id', $request->user()->empresa_id)
        )->where('application_id', $id)->first();

        if (! $offer) {
            return response()->json(['message' => 'No hay oferta para esta aplicación.'], 404);
        }

        return response()->json(['data' => $offer->load('position', 'creator')]);
    }

    public function resend(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::with(['user', 'jobOpening', 'empresa'])
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $offer = ApplicationOffer::where('application_id', $app->id)
            ->where('status', 'sent')
            ->first();

        if (! $offer) {
            return response()->json(['message' => 'No hay una oferta enviada para reenviar.'], 404);
        }

        $offer->update(['sent_at' => now()]);

        if ($app->user?->email) {
            AtsNotificationService::offerSent($app, config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx').'/oferta');
        }

        return response()->json(['data' => $offer->load('position')]);
    }

    public function accept(Request $request)
    {
        $app = Application::with(['user', 'jobOpening', 'empresa', 'offer'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $app || $app->status !== 'offer-sent' || ! $app->offer) {
            return response()->json(['message' => 'No tienes una oferta pendiente.'], 422);
        }

        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'accept_terms' => 'required|boolean|accepted',
        ]);

        if (strtolower(trim($validated['full_name'])) !== strtolower(trim($app->user->name))) {
            return response()->json(['message' => 'El nombre no coincide con el registrado.'], 422);
        }

        DB::beginTransaction();
        try {
            $offer = $app->offer;
            $offer->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            $service = new EmployeeOnboardingService();
            $service->create($app, $offer->creator ?? $request->user(), [
                'salary' => $offer->salary,
                'trial_months' => $offer->trial_months,
                'position_id' => $offer->position_id,
                'modules' => [],
                'notes' => 'Oferta aceptada por el candidato desde el portal.',
            ]);

            $token = UserActivationToken::createForUser($app->user);
            $app->user->update(['is_active' => false]);

            DB::commit();

            AtsNotificationService::hired($app);

            return response()->json([
                'message' => 'Oferta aceptada. Bienvenido a Decorarte.',
                'data' => [
                    'activation_token' => $token->token,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Error al procesar la aceptación.'], 500);
        }
    }

    public function myOnboardingDocuments(Request $request)
    {
        $app = Application::with('onboardingDocuments')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $app) {
            return response()->json(['message' => 'No application found'], 404);
        }

        $uploaded = $app->onboardingDocuments->keyBy('document_type');

        $checklist = collect(self::ONBOARDING_DOCUMENT_TYPES)->map(function ($label, $type) use ($uploaded) {
            $doc = $uploaded->get($type);

            return [
                'type' => $type,
                'label' => $label,
                'uploaded' => ! is_null($doc),
                'verified' => $doc?->verified_at !== null,
                'url' => $doc ? SecureFileStorage::temporaryUrl($doc->disk ?? SecureFileStorage::disk(), $doc->file_path) : null,
                'uploaded_at' => $doc?->created_at?->toDateTimeString(),
            ];
        })->values();

        return response()->json(['data' => $checklist]);
    }

    public function reject(Request $request)
    {
        $app = Application::with('offer')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $app || $app->status !== 'offer-sent' || ! $app->offer) {
            return response()->json(['message' => 'No tienes una oferta pendiente.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $app->offer->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        $oldStatus = $app->status;
        $app->update(['status' => 'rejected']);

        \App\Models\ApplicationStatusLog::create([
            'application_id' => $app->id,
            'from_status' => $oldStatus,
            'to_status' => 'rejected',
            'notes' => 'Candidato rechazó la oferta.'.($validated['reason'] ? ' '.$validated['reason'] : ''),
        ]);

        return response()->json(['message' => 'Oferta rechazada.']);
    }

    public function onboardingDocuments(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $app = Application::with('onboardingDocuments')
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        $uploaded = $app->onboardingDocuments->keyBy('document_type');

        $checklist = collect(self::ONBOARDING_DOCUMENT_TYPES)->map(function ($label, $type) use ($uploaded) {
            $doc = $uploaded->get($type);

            return [
                'type' => $type,
                'label' => $label,
                'uploaded' => ! is_null($doc),
                'verified' => $doc?->verified_at !== null,
                'url' => $doc ? SecureFileStorage::temporaryUrl($doc->disk ?? SecureFileStorage::disk(), $doc->file_path) : null,
                'uploaded_at' => $doc?->created_at?->toDateTimeString(),
            ];
        })->values();

        return response()->json(['data' => $checklist]);
    }

    public function verifyDocument(Request $request, $id, $type)
    {
        Gate::authorize('manage-users');

        if (! array_key_exists($type, self::ONBOARDING_DOCUMENT_TYPES)) {
            return response()->json(['message' => 'Tipo de documento inválido.'], 422);
        }

        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $doc = ApplicationDocument::where('application_id', $app->id)
            ->where('document_type', $type)
            ->first();

        if (! $doc) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $doc->update(['verified_at' => now()]);

        return response()->json(['data' => $doc]);
    }

    public function unverifyDocument(Request $request, $id, $type)
    {
        Gate::authorize('manage-users');

        if (! array_key_exists($type, self::ONBOARDING_DOCUMENT_TYPES)) {
            return response()->json(['message' => 'Tipo de documento inválido.'], 422);
        }

        $app = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $doc = ApplicationDocument::where('application_id', $app->id)
            ->where('document_type', $type)
            ->first();

        if (! $doc) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $doc->update(['verified_at' => null]);

        return response()->json(['data' => $doc]);
    }
}
