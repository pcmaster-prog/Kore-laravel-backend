<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table'");
foreach ($tables as $table) {
    $name = $table->name;
    if (str_starts_with($name, 'sqlite_')) {
        continue;
    }
    $count = DB::table($name)->count();
    if ($count > 0) {
        echo "$name: $count\n";
    }
}
