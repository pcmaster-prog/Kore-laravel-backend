<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPeriodResource extends JsonResource
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
            'empresa_id' => $this->empresa_id,
            'week_start' => $this->week_start?->format('Y-m-d'),
            'week_end' => $this->week_end?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,
            'total_entries' => $this->whenCounted('entries'),
            'total_amount' => $this->total_amount,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at,
        ];
    }
}
