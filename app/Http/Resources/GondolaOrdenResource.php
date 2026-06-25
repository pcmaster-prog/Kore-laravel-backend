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
            'gondola' => $this->whenLoaded('gondola'),
            'empleado_id' => $this->empleado_id,
            'empleado' => $this->whenLoaded('empleado'),
            'status' => $this->status,
            'notas_empleado' => $this->notas_empleado,
            'notas_rechazo' => $this->notas_rechazo,
            'evidencia_url' => $this->evidencia_url,
            'completed_at' => $this->completed_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'approvedBy' => $this->whenLoaded('approvedBy'),
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'gondola_producto_id' => $item->gondola_producto_id,
                        'product_id' => $item->product_id,
                        'clave' => $item->clave,
                        'nombre' => $item->nombre,
                        'unidad' => $item->unidad,
                        'unit' => $item->unit,
                        'cantidad' => $item->cantidad,
                        'producto' => $item->relationLoaded('producto') ? $item->producto : null,
                        'product' => $item->relationLoaded('product') ? $item->product : null,
                    ];
                });
            }),
            // Campos legacy para compatibilidad con frontend anterior
            'tipo' => null,
            'prioridad' => null,
            'notas' => $this->notas_empleado,
            'created_at' => $this->created_at,
        ];
    }
}
