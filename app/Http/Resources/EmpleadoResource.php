<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpleadoResource extends JsonResource
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
            'full_name' => $this->full_name,
            'employee_code' => $this->employee_code,
            'position_title' => $this->position_title,
            'status' => $this->status,
            'hired_at' => $this->hired_at,
            'check_in_time' => $this->check_in_time ? substr($this->check_in_time, 0, 5) : null,
            'payment_type' => $this->payment_type,
            'hourly_rate' => $this->hourly_rate,
            'daily_rate' => $this->daily_rate,
            'rfc' => $this->rfc,
            'nss' => $this->nss,
            'curp' => $this->curp,
        ];
    }
}
