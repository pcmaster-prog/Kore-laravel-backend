<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_period_id' => $this->payroll_period_id,
            'empleado_id' => $this->empleado_id,
            'empleado_name' => $this->empleado?->full_name,
            'payment_type' => $this->payment_type,
            'rate' => $this->rate,
            'units' => $this->units,
            'rest_days_paid' => $this->rest_days_paid,
            'holidays_paid' => $this->holidays_paid,
            'tardiness_count' => $this->tardiness_count,
            'absences_count' => $this->absences_count,
            'subtotal' => $this->subtotal,
            'adjustment_amount' => $this->adjustment_amount,
            'adjustment_note' => $this->adjustment_note,
            'bonus_amount' => $this->bonus_amount,
            'bonus_note' => $this->bonus_note,
            'total' => $this->total,
            'status' => $this->status ?? 'draft',
            'locked_at' => $this->locked_at,
            'locked_by' => $this->locked_by,
            'created_at' => $this->created_at,
        ];
    }
}
