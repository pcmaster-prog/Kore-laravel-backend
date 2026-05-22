<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('gondola_productos')->get();

            foreach ($rows as $row) {
                $productId = (string) Str::uuid();

                DB::table('products')->insert([
                    'id'            => $productId,
                    'empresa_id'    => $row->empresa_id,
                    'sku'           => $row->clave,
                    'name'          => $row->nombre,
                    'description'   => $row->descripcion,
                    'default_unit'  => $row->unidad ?? 'pz',
                    'photo_url'     => $row->foto_url,
                    'is_active'     => $row->activo ?? true,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table('gondola_productos')
                    ->where('id', $row->id)
                    ->update(['product_id' => $productId]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('gondola_productos')->update(['product_id' => null]);
            DB::table('products')->delete();
        });
    }
};
