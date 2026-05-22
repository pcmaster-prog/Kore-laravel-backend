<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Procesar reglas de asignación automática de tareas cada 5 minutos
Schedule::command('tasks:process-assignment-rules')->everyFiveMinutes();

// Procesar rutinas automáticas cada 5 minutos
Schedule::command('tasks:process-routine-schedules')->everyFiveMinutes();
