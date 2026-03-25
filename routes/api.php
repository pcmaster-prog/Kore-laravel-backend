<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmpresaController;
use App\Http\Controllers\Api\V1\ModulesController;
use App\Http\Controllers\Api\V1\EmployeesController;
use App\Http\Controllers\Api\V1\TasksController;
use App\Http\Controllers\Api\V1\EvidencesController;
use App\Http\Controllers\Api\V1\AttendanceControllerV2;
use App\Http\Controllers\Api\V1\EmpresaSettingsController;
use App\Http\Controllers\Api\V1\UsersController;

// Nuevos controladores para templates, rutinas y catálogo
use App\Http\Controllers\Api\V1\TaskTemplatesController;
use App\Http\Controllers\Api\V1\TaskRoutinesController;
use App\Http\Controllers\Api\V1\TaskCatalogController;
use App\Http\Controllers\Api\V1\ActivityLogsController;

// Controlador del dashboard (nuevo)
use App\Http\Controllers\Api\V1\DashboardController;

//Controlador de nómina
use App\Http\Controllers\Api\V1\PayrollController;

//Perfil
use App\Http\Controllers\Api\V1\ProfileController;

// 🔥 Nuevo controlador para revisiones
//use App\Http\Controllers\Api\V1\TaskReviewsController;

Route::prefix('v1')->group(function () {

    // Registro empresa (público) - README_BACKEND implementation
    Route::post('/register', [\App\Http\Controllers\Api\V1\RegisterController::class, 'register']);

    // Auth (público)
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Rutas autenticadas
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Rutas con tenant (empresa activa)
        Route::middleware('tenant')->group(function () {

            // NUEVAS RUTAS DEL DASHBOARD (dentro de auth + tenant)
            Route::get('/dashboard/manager', [DashboardController::class, 'manager']);
            Route::get('/dashboard/employee', [DashboardController::class, 'employee']);

            // Rutas protegidas por el módulo "configuracion"
            Route::middleware('module:configuracion')->group(function () {
                // Admin + Supervisor
                Route::get('/empleados', [EmployeesController::class, 'index']);
                Route::get('/empleados/{id}', [EmployeesController::class, 'show']);

                // Solo Admin 
                Route::post('/empleados', [EmployeesController::class, 'store']);
                Route::put('/empleados/{id}', [EmployeesController::class, 'update']);

                // Calendar & overrides
                Route::patch('/empleados/{id}/calendar', [EmployeesController::class, 'updateCalendar']);
                Route::post('/empleados/{id}/calendar/override', [EmployeesController::class, 'upsertCalendarOverride']);

                // Solo Empleado (su propio perfil)
                Route::get('/empleados-me', [EmployeesController::class, 'me']);

                Route::post('/empleados/{id}/link-user', [EmployeesController::class, 'linkUser']);

                // Gestión de usuarios (admin)
                Route::get('/usuarios', [UsersController::class, 'index']);
                Route::post('/usuarios', [UsersController::class, 'store']);
                Route::put('/usuarios/{id}', [UsersController::class, 'update']);
                Route::patch('/usuarios/{id}/toggle-status', [UsersController::class, 'toggleStatus']);
                Route::delete('/usuarios/{id}', [UsersController::class, 'destroy']);
            });

            // Rutas del módulo "tasks"
            Route::middleware('module:tareas')->group(function () {
                // Tareas existentes (asignaciones, etc.)
                Route::get('/tareas', [TasksController::class, 'index']);
                Route::post('/tareas', [TasksController::class, 'store']);
                Route::get('/tareas/{id}', [TasksController::class, 'show'])->whereUuid('id');
                Route::post('/tareas/{id}/asignar', [TasksController::class, 'assign'])->whereUuid('id');

                // Empleado
                Route::get('/mis-tareas', [TasksController::class, 'myTasks']);
                
                Route::get('/mis-tareas/asignaciones', [TasksController::class, 'myAssignments']);
                Route::patch('/mis-tareas/asignacion/{assignmentId}', [TasksController::class, 'updateMyAssignment']);

                // Templates
                Route::get('/task-templates', [TaskTemplatesController::class, 'index']);
                Route::post('/task-templates', [TaskTemplatesController::class, 'store']);
                Route::get('/task-templates/{id}', [TaskTemplatesController::class, 'show']);
                Route::patch('/task-templates/{id}', [TaskTemplatesController::class, 'update']);
                Route::delete('/task-templates/{id}', [TaskTemplatesController::class, 'destroy']);

                // Routines
                Route::get('/task-routines', [TaskRoutinesController::class, 'index']);
                Route::post('/task-routines', [TaskRoutinesController::class, 'store']);
                Route::get('/task-routines/{id}', [TaskRoutinesController::class, 'show']);
                Route::patch('/task-routines/{id}', [TaskRoutinesController::class, 'update']);
                Route::delete('/task-routines/{id}', [TaskRoutinesController::class, 'destroy']);

                Route::post('/task-routines/{id}/items', [TaskRoutinesController::class, 'addItems']);
                Route::delete('/task-routines/{id}/items/{itemId}', [TaskRoutinesController::class, 'removeItem']);

                // Catálogo del día + crear tareas desde template
                Route::get('/tareas/catalogo', [TaskCatalogController::class, 'catalog']);
                Route::post('/tareas/crear-desde-template', [TaskCatalogController::class, 'createFromTemplate']);
                Route::post('/tareas/crear-desde-catalogo-bulk', [TaskCatalogController::class, 'createBulkFromTemplates']);
                Route::post('/task-routines/{id}/assign', [TaskRoutinesController::class, 'assignRoutine']);

                Route::get('/activity-logs', [ActivityLogsController::class, 'index']);
                Route::patch('/tareas/{id}/status', [TasksController::class, 'updateStatus'])->whereUuid('id');

                // Rutas de revisión (usando TasksController, alineado a tu DB real)
                Route::middleware('role:admin,supervisor')->group(function () {
                    Route::get('/tareas/revision', [TasksController::class, 'reviewQueue']);
                    Route::post('/tareas/asignaciones/{assignmentId}/approve', [TasksController::class, 'approveAssignment']);
                    Route::post('/tareas/asignaciones/{assignmentId}/reject', [TasksController::class, 'rejectAssignment']);
                    Route::get('/tareas/{id}/evidencias', [TasksController::class, 'taskEvidences'])->whereUuid('id');
                });

                //checklist
                Route::patch('/mis-tareas/asignacion/{assignmentId}/checklist', [TasksController::class, 'updateMyChecklistItem']);

                // Evidencias (ahora parte de tareas)
                Route::post('/evidencias/upload', [EvidencesController::class, 'upload']);
                Route::get('/evidencias/{id}', [EvidencesController::class, 'show']);

                // Empleado: ligar evidencia a SU asignación
                Route::post('/mis-tareas/asignacion/{assignmentId}/evidencia', [EvidencesController::class, 'attachToMyAssignment']);
            });


            // Rutas del módulo "attendance"
            Route::middleware('module:asistencia')->group(function () {
                // Empleado
                Route::post('/asistencia/entrada', [AttendanceControllerV2::class, 'checkIn']);
                Route::post('/asistencia/pausa/iniciar', [AttendanceControllerV2::class, 'breakStart']);
                Route::post('/asistencia/pausa/terminar', [AttendanceControllerV2::class, 'breakEnd']);
                Route::post('/asistencia/salida', [AttendanceControllerV2::class, 'checkOut']);
                Route::get('/asistencia/mis-dias', [AttendanceControllerV2::class, 'myDays']);

                Route::post('/asistencia/descanso', [AttendanceControllerV2::class, 'markRestDay']);
                Route::delete('/asistencia/descanso/{date}', [AttendanceControllerV2::class, 'cancelRestDay']);

                // Admin/Supervisor
                Route::get('/asistencia/por-fecha', [AttendanceControllerV2::class, 'byDate']);
                Route::get('/asistencia/semanal', [AttendanceControllerV2::class, 'weeklySummary']);

                Route::get('/asistencia/mis-hoy', [AttendanceControllerV2::class, 'myToday']);
            });

            //Modulo de Nomina
                Route::get('/nomina/periodos',                       [PayrollController::class, 'index']);
                Route::post('/nomina/periodos/generar',              [PayrollController::class, 'generate']);
                Route::get('/nomina/periodos/{id}',                  [PayrollController::class, 'show']);
                Route::patch('/nomina/periodos/{id}/entradas/{entryId}', [PayrollController::class, 'updateEntry']);
                Route::post('/nomina/periodos/{id}/aprobar',         [PayrollController::class, 'approve']);
                Route::get('/nomina/periodos/{id}/exportar',         [PayrollController::class, 'export']);
            //perfil
            Route::get('/mi-perfil',   [ProfileController::class, 'show']);
            Route::patch('/mi-perfil', [ProfileController::class, 'update']);
            Route::post('/mi-perfil/avatar', [ProfileController::class, 'uploadAvatar']);


            // Rutas de módulos empresa (Nuevas)
            Route::get('/empresa/modulos', [EmpresaController::class, 'modulos']);
            Route::post('/empresa/modulos', [EmpresaController::class, 'toggleModulo']);

            // Config IP empresa
            Route::patch('/empresa/config', [EmpresaController::class, 'config']);
            Route::get('/empresa/red',  [EmpresaController::class, 'getRed']);
            Route::post('/empresa/red', [EmpresaController::class, 'updateRed']);

            // Configuración de calendario a nivel empresa
            Route::patch('/empresa/settings/calendar', [EmpresaSettingsController::class, 'updateCalendar']);

            // Ruta de prueba 
            Route::get('/demo/employees-module-check', function () {
                return response()->json(['ok' => true, 'message' => 'Employees module enabled']);
            })->middleware('module:configuracion');
        });
    });
});
