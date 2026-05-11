<?php

namespace App\Helpers;

class NumeroALetras
{
    private static array $unidades = [
        '', 'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete', 'Ocho', 'Nueve',
        'Diez', 'Once', 'Doce', 'Trece', 'Catorce', 'Quince', 'Dieciséis', 'Diecisiete',
        'Dieciocho', 'Diecinueve', 'Veinte'
    ];

    private static array $decenas = [
        '', '', 'Veinti', 'Treinta', 'Cuarenta', 'Cincuenta', 'Sesenta', 'Setenta',
        'Ochenta', 'Noventa'
    ];

    private static array $centenas = [
        '', 'Ciento', 'Doscientos', 'Trescientos', 'Cuatrocientos', 'Quinientos',
        'Seiscientos', 'Setecientos', 'Ochocientos', 'Novecientos'
    ];

    public static function convertir(float $numero, string $moneda = 'pesos', string $fraccion = 'centavos'): string
    {
        $entero = (int) $numero;
        $decimales = (int) round(($numero - $entero) * 100);

        $letras = self::enteroALetras($entero);
        $letras = ucfirst(mb_strtolower($letras));
        $letrasDecimales = str_pad((string) $decimales, 2, '0', STR_PAD_LEFT);

        return sprintf(
            'Son: %s %s %s/100 M.N.',
            $letras,
            $moneda,
            $letrasDecimales
        );
    }

    private static function enteroALetras(int $numero): string
    {
        if ($numero === 0) {
            return 'Cero';
        }

        if ($numero < 0) {
            return 'Menos ' . self::enteroALetras(abs($numero));
        }

        $letras = '';

        if ($numero >= 1000000) {
            $millones = (int) ($numero / 1000000);
            $resto = $numero % 1000000;
            if ($millones === 1) {
                $letras .= 'Un Millón';
            } else {
                $letras .= self::enteroALetras($millones) . ' Millones';
            }
            if ($resto > 0) {
                $letras .= ' ' . self::enteroALetras($resto);
            }
            return $letras;
        }

        if ($numero >= 1000) {
            $miles = (int) ($numero / 1000);
            $resto = $numero % 1000;
            if ($miles === 1) {
                $letras .= 'Mil';
            } else {
                $letras .= self::enteroALetras($miles) . ' Mil';
            }
            if ($resto > 0) {
                $letras .= ' ' . self::enteroALetras($resto);
            }
            return $letras;
        }

        if ($numero >= 100) {
            $centena = (int) ($numero / 100);
            $resto = $numero % 100;
            if ($numero === 100) {
                return 'Cien';
            }
            $letras .= self::$centenas[$centena];
            if ($resto > 0) {
                $letras .= ' ' . self::enteroALetras($resto);
            }
            return $letras;
        }

        if ($numero <= 20) {
            return self::$unidades[$numero];
        }

        $decena = (int) ($numero / 10);
        $unidad = $numero % 10;

        if ($decena === 2 && $unidad > 0) {
            return self::$decenas[$decena] . self::unidadMinuscula($unidad);
        }

        $letras .= self::$decenas[$decena];
        if ($unidad > 0) {
            $letras .= ' y ' . self::$unidades[$unidad];
        }

        return $letras;
    }

    private static function unidadMinuscula(int $unidad): string
    {
        $map = [
            1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
            6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve'
        ];
        return $map[$unidad] ?? '';
    }
}
