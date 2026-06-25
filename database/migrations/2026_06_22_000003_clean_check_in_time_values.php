<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Algunos registros en produccion quedaron con datetime completo
        // en lugar de solo HH:mm. Esta migracion los corrige.
        DB::table('empleados')
            ->whereNotNull('check_in_time')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $clean = $this->extractTime($row->check_in_time);

                    if ($clean !== null && $clean !== $row->check_in_time) {
                        DB::table('empleados')
                            ->where('id', $row->id)
                            ->update(['check_in_time' => $clean]);
                    }
                }
            });
    }

    public function down(): void
    {
        // No es reversible de forma segura.
    }

    private function extractTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $str = (string) $value;

        // datetime ISO: 2026-04-08T06:00:00.000000Z -> 06:00
        if (str_contains($str, 'T')) {
            return $this->validate(substr($str, 11, 5));
        }

        // "YYYY-MM-DD HH:mm:ss" -> 06:00
        if (strlen($str) > 5) {
            return $this->validate(substr($str, -8, 5));
        }

        return $this->validate(substr($str, 0, 5));
    }

    private function validate(?string $value): ?string
    {
        if ($value && preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }
};
