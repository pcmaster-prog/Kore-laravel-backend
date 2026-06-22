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
            'hired_at' => $this->hired_at?->toDateString(),
            'check_in_time' => $this->formatTime($this->check_in_time),
            'payment_type' => $this->payment_type,
            'hourly_rate' => $this->hourly_rate,
            'daily_rate' => $this->daily_rate,
            'rfc' => $this->rfc,
            'nss' => $this->nss,
            'curp' => $this->curp,
        ];
    }

    private function formatTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        $str = (string) $value;

        // datetime ISO: 2026-04-08T06:00:00.000000Z -> 06:00
        if (str_contains($str, 'T')) {
            return substr($str, 11, 5) ?: null;
        }

        // "Y-m-d H:i:s" o "H:i:s": tomar HH:mm de los ultimos 8 caracteres.
        if (strlen($str) > 5) {
            return substr($str, -8, 5) ?: null;
        }

        return substr($str, 0, 5) ?: null;
    }
}
