<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedido Pino Gordo - {{ $pedido->codigo }}</title>
    <style>
        body { font-family: sans-serif; font-size: 14px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 24px; color: #333; }
        .details { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; }
        .section-title { background-color: #ddd; font-weight: bold; text-align: center; }
        .total { text-align: right; font-size: 18px; font-weight: bold; margin-top: 20px; }
        .right { text-align: right; }
    </style>
</head>
<body>

    <div class="header">
        <h1>PEDIDO A PINO GORDO</h1>
        <p>Fecha: {{ $pedido->fecha_pedido }} | Código: {{ $pedido->codigo }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Concepto / Descripción</th>
                <th class="right">Cantidad</th>
                <th class="right">Precio Unitario</th>
                <th class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @php
                $secciones = [
                    'hojas_mdf' => 'Hojas de MDF',
                    'tablas_pino' => 'Tablas de Pino',
                    'consumibles' => 'Consumibles',
                    'servicios_corte' => 'Servicios de Corte'
                ];
            @endphp

            @foreach ($secciones as $slug => $titulo)
                @php
                    $items = $detalles->where('seccion_pdf', $slug);
                @endphp
                
                @if ($items->count() > 0)
                    <tr>
                        <td colspan="4" class="section-title">{{ $titulo }}</td>
                    </tr>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->nombre_item }}</td>
                            <td class="right">{{ number_format($item->cantidad, 2) }}</td>
                            <td class="right">${{ number_format($item->precio_unitario, 2) }}</td>
                            <td class="right">${{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
        </tbody>
    </table>

    <div class="total">
        TOTAL DEL PEDIDO: ${{ number_format($pedido->total, 2) }}
    </div>

</body>
</html>
