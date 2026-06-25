<?php

namespace App\Http\Resources;

use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totals = AttendanceService::computeDayTotals($this->resource);
        $expectedExit = AttendanceService::calculateOfficialExitTime($this->resource);
        $requiredExit = AttendanceService::calculateRequiredExitTime($this->resource);

        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'empleado_id' => $this->empleado_id,
            'date' => $this->date,
            'status' => $this->status,
            'first_check_in_at' => $this->first_check_in_at,
            'last_check_out_at' => $this->last_check_out_at,
            'lunch_start_at' => $this->lunch_start_at,
            'lunch_end_at' => $this->lunch_end_at,
            'late_minutes' => $this->late_minutes,
            'early_departure_minutes' => $this->early_departure_minutes,
            'expected_exit_time' => $expectedExit?->toISOString(),
            'required_exit_time' => $requiredExit?->toISOString(),
            'totals' => $totals,
            'created_at' => $this->created_at,
        ];
    }
}
