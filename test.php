<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$o = App\Models\GondolaOrden::latest('id')->with('items.producto')->first();
echo "== ORDEN ==\n";
print_r($o?->toArray());
