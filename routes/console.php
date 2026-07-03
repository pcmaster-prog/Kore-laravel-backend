<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Procesar reglas de asignación automática de tareas cada 5 minutos
Schedule::command('tasks:process-assignment-rules')->everyFiveMinutes();

// Enviar recordatorios de entrevista por WhatsApp cada hora
Schedule::command('ats:send-interview-reminders')->hourly();

// DEPRECATED: Rutinas ahora se manejan via TaskAssignmentRule con items
// Schedule::command('tasks:process-routine-schedules')->everyFiveMinutes();
