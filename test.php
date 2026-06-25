<?php

use App\Models\GondolaOrden;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$o = GondolaOrden::latest('id')->with('items.producto')->first();
echo "== ORDEN ==\n";
print_r($o?->toArray());
