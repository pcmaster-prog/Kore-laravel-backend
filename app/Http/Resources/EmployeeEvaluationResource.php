<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeEvaluationResource extends JsonResource
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
            'evaluador_id' => $this->evaluador_id,
            'periodo' => $this->periodo,
            'puntaje' => $this->puntaje,
            'comentario' => $this->comentario,
            'created_at' => $this->created_at,
        ];
    }
}
