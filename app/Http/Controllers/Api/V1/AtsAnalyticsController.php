<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Interview;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AtsAnalyticsController extends Controller
{
    public function pipeline(Request $request)
    {
        Gate::authorize('manage-users');

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'job_opening_id' => 'nullable|exists:job_openings,id',
        ]);

        $empresaId = $request->user()->empresa_id;

        $query = Application::where('empresa_id', $empresaId);

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }
        if (! empty($validated['job_opening_id'])) {
            $query->where('job_opening_id', $validated['job_opening_id']);
        }

        $applications = $query->get();

        $statusCounts = $applications->groupBy('status')
            ->map(fn ($group) => $group->count())
            ->toArray();

        $statuses = ['new', 'screening', 'interview-requested', 'interviewing', 'offer-sent', 'hired', 'rejected'];
        $totals = [];
        foreach ($statuses as $status) {
            $totals[$status] = $statusCounts[$status] ?? 0;
        }

        $funnel = [
            ['stage' => 'Aplicaciones', 'count' => $applications->count()],
            ['stage' => 'Evaluación', 'count' => $applications->where('status', '!=', 'new')->count()],
            ['stage' => 'Entrevistas', 'count' => $applications->whereIn('status', ['interviewing', 'offer-sent', 'hired'])->count()],
            ['stage' => 'Ofertas', 'count' => $applications->whereIn('status', ['offer-sent', 'hired'])->count()],
            ['stage' => 'Contrataciones', 'count' => $applications->where('status', 'hired')->count()],
        ];

        $avgTimes = $this->averageTimesByStage($applications->pluck('id')->toArray());

        $rejectionReasons = ApplicationStatusLog::whereIn('application_id', $applications->pluck('id'))
            ->where('to_status', 'rejected')
            ->whereNotNull('notes')
            ->select('notes', DB::raw('COUNT(*) as count'))
            ->groupBy('notes')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $openJobs = JobOpening::where('empresa_id', $empresaId)
            ->where('status', 'open')
            ->withCount([
                'applications as total_applications',
                'applications as interviewing_count' => fn ($q) => $q->whereIn('status', ['interviewing', 'interview-requested']),
                'applications as offer_sent_count' => fn ($q) => $q->where('status', 'offer-sent'),
                'applications as hired_count' => fn ($q) => $q->where('status', 'hired'),
            ])
            ->get();

        $upcomingInterviews = Interview::whereHas('application', fn ($q) => $q->where('empresa_id', $empresaId))
            ->where('scheduled_at', '>=', now())
            ->where('scheduled_at', '<=', now()->addDays(7))
            ->with(['application.user', 'application.jobOpening'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'candidate_name' => $i->application?->user?->name,
                'job_title' => $i->application?->jobOpening?->title,
                'scheduled_at' => $i->scheduled_at->toDateTimeString(),
                'method' => $i->method,
                'location' => $i->location,
                'meeting_url' => $i->meeting_url,
            ]);

        return response()->json([
            'data' => [
                'totals' => $totals,
                'funnel' => $funnel,
                'average_times' => $avgTimes,
                'rejection_reasons' => $rejectionReasons,
                'open_jobs' => $openJobs,
                'upcoming_interviews' => $upcomingInterviews,
            ],
        ]);
    }

    private function averageTimesByStage(array $applicationIds): array
    {
        if (empty($applicationIds)) {
            return [];
        }

        $logs = ApplicationStatusLog::whereIn('application_id', $applicationIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('application_id');

        $deltas = [
            'new_to_screening' => [],
            'screening_to_interview_requested' => [],
            'interview_requested_to_interviewing' => [],
            'interviewing_to_offer_sent' => [],
            'offer_sent_to_hired' => [],
        ];

        foreach ($logs as $appLogs) {
            $prev = null;
            foreach ($appLogs as $log) {
                if ($prev) {
                    $key = $prev->to_status.'_to_'.$log->to_status;
                    if (array_key_exists($key, $deltas)) {
                        $deltas[$key][] = $log->created_at->diffInHours($prev->created_at);
                    }
                }
                $prev = $log;
            }
        }

        $result = [];
        foreach ($deltas as $key => $values) {
            $result[$key] = empty($values) ? null : round(array_sum($values) / count($values), 1);
        }

        return $result;
    }
}
