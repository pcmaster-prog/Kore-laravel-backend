<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table'");
foreach ($tables as $table) {
    $name = $table->name;
    if (str_starts_with($name, 'sqlite_')) continue;
    $count = Illuminate\Support\Facades\DB::table($name)->count();
    if ($count > 0) {
        echo "$name: $count\n";
    }
}
