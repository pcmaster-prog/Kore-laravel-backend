<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpresaResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'palette_key' => $this->palette_key,
            'plan_id' => $this->plan_id,
            'plan' => $this->plan,
            'logo_url' => $this->logo_url,
            'industry' => $this->industry,
            'employee_count_range' => $this->employee_count_range,
        ];
    }
}
