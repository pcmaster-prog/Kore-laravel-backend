<?php

namespace App\Http\Resources;

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
            'created_at' => $this->created_at,
        ];
    }
}
