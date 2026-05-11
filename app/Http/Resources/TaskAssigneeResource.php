<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskAssigneeResource extends JsonResource
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
            'empleado_id' => $this->empleado_id,
            'task_id' => $this->task_id,
            'status' => $this->status,
            'notes' => $this->note,
            'completed_at' => $this->done_at,
            'empleado' => $this->whenLoaded('empleado', fn () => new EmpleadoResource($this->empleado)),
        ];
    }
}
