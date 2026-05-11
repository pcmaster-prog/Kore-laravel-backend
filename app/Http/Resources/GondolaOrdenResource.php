<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GondolaOrdenResource extends JsonResource
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
            'gondola_id' => $this->gondola_id,
            'empleado_id' => $this->empleado_id,
            'tipo' => $this->tipo,
            'prioridad' => $this->prioridad,
            'status' => $this->status,
            'notas' => $this->notas,
            'created_at' => $this->created_at,
        ];
    }
}
