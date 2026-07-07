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

// ─── REGLA ARQUITECTURAL (Gap 3 — Multi-Tenant Security) ──────────────────────
// Los comandos de backfill/migración de datos NUNCA deben usar runWithoutTenant().
// El patrón correcto es iterar por empresa y setear TenantContext en cada iteración:
//
//   Empresa::all()->each(function (Empresa $empresa) {
//       TenantContext::setId($empresa->id);
//       // ... operaciones con Global Scope activo ...
//       TenantContext::clear();
//   });
//
// runWithoutTenant() se reserva exclusivamente para operaciones genuinamente
// cross-tenant: reportes de super-admin, health checks, seeding inicial.
// ──────────────────────────────────────────────────────────────────────────────
