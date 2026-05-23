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
use App\Http\Controllers\Api\V1\AreasController;
use App\Http\Controllers\Api\V1\SectionsController;
use App\Http\Controllers\Api\V1\EmpleadoSectionsController;
use App\Http\Controllers\Api\V1\PositionsController;
use App\Http\Controllers\Api\V1\SupervisorSectionsController;
use App\Http\Controllers\Api\V1\TaskAssignmentRulesController;
use App\Http\Controllers\Api\V1\RoutineSchedulesController;
use App\Http\Controllers\Api\V1\IncidentsController;

// Controlador del dashboard (nuevo)
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FcmTokenController;


//Controlador de nómina
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\EmployeeReceiptController;
use App\Http\Controllers\Api\V1\AdminGratificationController;

//Perfil
use App\Http\Controllers\Api\V1\ProfileController;

// Módulo Góndolas
use App\Http\Controllers\Api\V1\GondolasController;
use App\Http\Controllers\Api\V1\GondolaOrdenesController;
use App\Http\Controllers\Api\V1\ProductsController;

// Módulo Semáforo de Desempeño
use App\Http\Controllers\Api\V1\SemaforoController;

// Bitácora
use App\Http\Controllers\Api\V1\BitacoraController;

// Documentos de empresa
use App\Http\Controllers\Api\V1\EmpresaDocumentosController;

// Solicitudes de ausencia
use App\Http\Controllers\Api\V1\AbsenceRequestController;

// Sistema de retardos
use App\Http\Controllers\Api\V1\TardinessConfigController;
use App\Http\Controllers\Api\V1\TardinessReportController;

// Horarios de comida
use App\Http\Controllers\Api\V1\MealScheduleController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\AttendanceReportController;

// 🔥 Nuevo controlador para revisiones
//use App\Http\Controllers\Api\V1\TaskReviewsController;

Route::prefix('v1')->group(function () {

    // Registro empresa (público) - README_BACKEND implementation
    Route::post('/register', [\App\Http\Controllers\Api\V1\RegisterController::class, 'register'])
        ->middleware('throttle:5,60');

    // Auth (público)
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // Rutas autenticadas
    Route::middleware(['auth:sanctum','throttle:api'])->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Rutas con tenant (empresa activa)
        Route::middleware('tenant')->group(function () {

            // Bitácora — catálogo de criterios (requiere tenant)
            Route::middleware('role:admin,supervisor')->group(function () {
                Route::get('/bitacora/criterios',  [BitacoraController::class, 'getCriterios']);
                Route::post('/bitacora/criterios', [BitacoraController::class, 'saveCriterios']);
            });

            // NUEVAS RUTAS DEL DASHBOARD (dentro de auth + tenant)
            Route::get('/dashboard/manager', [DashboardController::class, 'manager']);
            Route::get('/dashboard/supervisor', [DashboardController::class, 'supervisor']);
            Route::get('/dashboard/employee', [DashboardController::class, 'employee']);

            // FCM Tokens (Notificaciones Push)
            Route::post('/fcm/token',   [FcmTokenController::class, 'store']);
            Route::delete('/fcm/token', [FcmTokenController::class, 'destroy']);
            Route::post('/fcm/test',    [FcmTokenController::class, 'test']);


            // Rutas protegidas por el módulo "configuracion"
            Route::middleware('module:configuracion')->group(function () {
                // Admin + Supervisor
                Route::get('/empleados', [EmployeesController::class, 'index']);
                Route::get('/empleados/{id}', [EmployeesController::class, 'show'])->whereUuid('id');

                // Solo Admin
                Route::post('/empleados', [EmployeesController::class, 'store']);
                Route::put('/empleados/{id}', [EmployeesController::class, 'update'])->whereUuid('id');

                // Calendar & overrides
                Route::patch('/empleados/{id}/calendar', [EmployeesController::class, 'updateCalendar'])->whereUuid('id');
                Route::post('/empleados/{id}/calendar/override', [EmployeesController::class, 'upsertCalendarOverride'])->whereUuid('id');

                // Solo Empleado (su propio perfil)
                Route::get('/empleados-me', [EmployeesController::class, 'me']);

                Route::post('/empleados/{id}/link-user', [EmployeesController::class, 'linkUser'])->whereUuid('id');

                // Gestión de usuarios (admin)
                Route::get('/usuarios', [UsersController::class, 'index']);
                Route::get('/usuarios/{id}', [UsersController::class, 'show'])->whereUuid('id');
                Route::post('/usuarios', [UsersController::class, 'store']);
                Route::put('/usuarios/{id}', [UsersController::class, 'update'])->whereUuid('id');
                Route::patch('/usuarios/{id}/toggle-status', [UsersController::class, 'toggleStatus'])->whereUuid('id');
                Route::delete('/usuarios/{id}', [UsersController::class, 'destroy'])->whereUuid('id');

                // Documentos de empresa
                Route::get('/empresa/documentos',            [EmpresaDocumentosController::class, 'index']);
                Route::post('/empresa/documentos',           [EmpresaDocumentosController::class, 'upload']);
                Route::delete('/empresa/documentos/{index}', [EmpresaDocumentosController::class, 'destroy']);
            });

            // Rutas del módulo "tasks"
            Route::middleware('module:tareas')->group(function () {
                // Tareas existentes (asignaciones, etc.)
                Route::get('/tareas', [TasksController::class, 'index']);
                Route::post('/tareas', [TasksController::class, 'store']);
                Route::get('/tareas/{id}', [TasksController::class, 'show'])->whereUuid('id');
                Route::delete('/tareas/{id}', [TasksController::class, 'destroy'])->whereUuid('id');
                Route::post('/tareas/{id}/asignar', [TasksController::class, 'assign'])->whereUuid('id');

                // Empleado
                Route::get('/mis-tareas', [TasksController::class, 'myTasks']);
                
                Route::get('/mis-tareas/asignaciones', [TasksController::class, 'myAssignments']);
                Route::patch('/mis-tareas/asignacion/{assignmentId}', [TasksController::class, 'updateMyAssignment']);

                // Empleados asignables (respeta jerarquía)
                Route::get('/tareas/empleados-asignables', [TasksController::class, 'empleadosAsignables']);

                // Panel combinado tareas + góndolas
                Route::get('/mi-panel', [TasksController::class, 'miPanel']);
                // Áreas y Secciones
                Route::get('/areas', [AreasController::class, 'index']);
                Route::get('/areas/with-sections', [AreasController::class, 'withSections']);
                Route::post('/areas', [AreasController::class, 'store']);
                Route::patch('/areas/{id}', [AreasController::class, 'update'])->whereUuid('id');
                Route::delete('/areas/{id}', [AreasController::class, 'destroy'])->whereUuid('id');

                Route::get('/task-sections', [SectionsController::class, 'index']);
                Route::get('/task-sections/by-area/{areaId}', [SectionsController::class, 'byArea'])->whereUuid('areaId');
                Route::post('/task-sections', [SectionsController::class, 'store']);
                Route::patch('/task-sections/{id}', [SectionsController::class, 'update'])->whereUuid('id');
                Route::delete('/task-sections/{id}', [SectionsController::class, 'destroy'])->whereUuid('id');

                // Puestos
                Route::get('/positions', [PositionsController::class, 'index']);
                Route::post('/positions', [PositionsController::class, 'store']);
                Route::get('/positions/{id}', [PositionsController::class, 'show'])->whereUuid('id');
                Route::patch('/positions/{id}', [PositionsController::class, 'update'])->whereUuid('id');
                Route::delete('/positions/{id}', [PositionsController::class, 'destroy'])->whereUuid('id');
                Route::get('/positions/{id}/base-tasks', [PositionsController::class, 'baseTasks'])->whereUuid('id');
                Route::post('/positions/{id}/base-tasks', [PositionsController::class, 'syncBaseTasks'])->whereUuid('id');

                // Supervisor - Secciones
                Route::get('/my-sections', [SupervisorSectionsController::class, 'mySections']);
                Route::post('/supervisor-sections/assign', [SupervisorSectionsController::class, 'assign']);
                Route::delete('/supervisor-sections/{id}', [SupervisorSectionsController::class, 'destroy'])->whereUuid('id');

                // Templates
                Route::get('/task-templates', [TaskTemplatesController::class, 'index']);
                Route::post('/task-templates', [TaskTemplatesController::class, 'store']);
                Route::get('/task-templates/{id}', [TaskTemplatesController::class, 'show'])->whereUuid('id');
                Route::patch('/task-templates/{id}', [TaskTemplatesController::class, 'update'])->whereUuid('id');
                Route::delete('/task-templates/{id}', [TaskTemplatesController::class, 'destroy'])->whereUuid('id');
                Route::get('/sections', [TaskTemplatesController::class, 'sections']);

                // Routines
                Route::get('/task-routines', [TaskRoutinesController::class, 'index']);
                Route::post('/task-routines', [TaskRoutinesController::class, 'store']);
                Route::get('/task-routines/{id}', [TaskRoutinesController::class, 'show'])->whereUuid('id');
                Route::patch('/task-routines/{id}', [TaskRoutinesController::class, 'update'])->whereUuid('id');
                Route::delete('/task-routines/{id}', [TaskRoutinesController::class, 'destroy'])->whereUuid('id');

                Route::post('/task-routines/{id}/items', [TaskRoutinesController::class, 'addItems'])->whereUuid('id');
                Route::delete('/task-routines/{id}/items/{itemId}', [TaskRoutinesController::class, 'removeItem'])->whereUuid(['id', 'itemId']);

                // Catálogo del día + crear tareas desde template
                Route::get('/tareas/catalogo', [TaskCatalogController::class, 'catalog']);
                Route::post('/tareas/crear-desde-template', [TaskCatalogController::class, 'createFromTemplate']);
                Route::post('/tareas/crear-desde-catalogo-bulk', [TaskCatalogController::class, 'createBulkFromTemplates']);
                Route::post('/task-routines/{id}/assign', [TaskRoutinesController::class, 'assignRoutine'])->whereUuid('id');

                // Reglas de asignación automática
                Route::get('/task-assignment-rules', [TaskAssignmentRulesController::class, 'index']);
                Route::post('/task-assignment-rules', [TaskAssignmentRulesController::class, 'store']);
                Route::post('/task-assignment-rules/bulk', [TaskAssignmentRulesController::class, 'bulkStore']);
                Route::get('/task-assignment-rules/{id}', [TaskAssignmentRulesController::class, 'show'])->whereUuid('id');
                Route::patch('/task-assignment-rules/{id}', [TaskAssignmentRulesController::class, 'update'])->whereUuid('id');
                Route::delete('/task-assignment-rules/{id}', [TaskAssignmentRulesController::class, 'destroy'])->whereUuid('id');

                // Rutinas automáticas
                Route::get('/routine-schedules', [RoutineSchedulesController::class, 'index']);
                Route::post('/routine-schedules', [RoutineSchedulesController::class, 'store']);
                Route::get('/routine-schedules/{id}', [RoutineSchedulesController::class, 'show'])->whereUuid('id');
                Route::patch('/routine-schedules/{id}', [RoutineSchedulesController::class, 'update'])->whereUuid('id');
                Route::delete('/routine-schedules/{id}', [RoutineSchedulesController::class, 'destroy'])->whereUuid('id');

                // Empleado-Secciones
                Route::get('/empleados/{id}/sections', [EmpleadoSectionsController::class, 'index'])->whereUuid('id');
                Route::post('/empleados/{id}/sections', [EmpleadoSectionsController::class, 'store'])->whereUuid('id');
                Route::delete('/empleados/{id}/sections/{sectionId}', [EmpleadoSectionsController::class, 'destroy'])->whereUuid(['id', 'sectionId']);
                Route::get('/sections/{id}/empleados', [EmpleadoSectionsController::class, 'sectionEmpleados'])->whereUuid('id');

                // Tareas huérfanas
                Route::get('/tareas/huerfanas', [TasksController::class, 'huerfanas']);
                Route::post('/tareas/{id}/reasignar', [TasksController::class, 'reasignar'])->whereUuid('id');

                // Nuevos endpoints de tareas
                Route::get('/tareas/tree', [TasksController::class, 'tree']);
                Route::get('/tareas/by-section/{sectionId}', [TasksController::class, 'bySection'])->whereUuid('sectionId');
                Route::post('/tareas/{id}/iniciar', [TasksController::class, 'iniciar'])->whereUuid('id');
                Route::post('/tareas/{id}/finalizar', [TasksController::class, 'finalizar'])->whereUuid('id');

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

                // Incidencias
                Route::get('/incidents', [IncidentsController::class, 'index']);
                Route::post('/incidents', [IncidentsController::class, 'store']);
                Route::patch('/incidents/{id}/resolve', [IncidentsController::class, 'resolve'])->whereUuid('id');
                Route::patch('/incidents/{id}/dismiss', [IncidentsController::class, 'dismiss'])->whereUuid('id');

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
                Route::post('/asistencia/dia-descanso', [AttendanceControllerV2::class, 'marcarDiaDescansoAdmin']);
                Route::delete('/asistencia/dia-descanso', [AttendanceControllerV2::class, 'quitarDiaDescansoAdmin']);

                Route::get('/asistencia/mis-hoy', [AttendanceControllerV2::class, 'myToday']);

                // Cronómetro de comida
                Route::post('/asistencia/comida/iniciar',  [AttendanceControllerV2::class, 'iniciarComida']);
                Route::post('/asistencia/comida/terminar', [AttendanceControllerV2::class, 'terminarComida']);

                // Ajuste de asistencia (Admin/Supervisor)
                Route::patch('/asistencia/ajustar/{empleadoId}/{fecha}', [AttendanceControllerV2::class, 'ajustar']);
                Route::delete('/asistencia/eliminar/{empleadoId}/{fecha}', [AttendanceControllerV2::class, 'eliminarDia']);

                // Retardos — empleado
                Route::get('/asistencia/mis-retardos', [AttendanceControllerV2::class, 'myLateInfo']);

                // Cierre masivo + Reportes (admin/supervisor)
                Route::middleware('role:admin,supervisor')->group(function () {
                    Route::post('/asistencia/cerrar-masivo', [AttendanceReportController::class, 'cerrarMasivo']);
                    Route::get('/reportes/asistencia-semanal', [AttendanceReportController::class, 'asistenciaSemanal']);
                    Route::get('/reportes/empleado/{empleado_id}', [AttendanceReportController::class, 'reporteEmpleado'])->whereUuid('empleado_id');
                });

                // Solicitudes de ausencia justificada
                Route::post('/asistencia/ausencias',              [AbsenceRequestController::class, 'store']);
                Route::get('/asistencia/ausencias',               [AbsenceRequestController::class, 'myRequests']);
                Route::get('/asistencia/ausencias/pendientes',    [AbsenceRequestController::class, 'pending']);
                Route::patch('/asistencia/ausencias/{id}',        [AbsenceRequestController::class, 'review']);
            });

            // ── Módulo Retardos ──────────────────────────────────────────────────────
            // Configuración de retardos (solo admin)
            Route::middleware(['module:asistencia','role:admin'])->group(function () {
                Route::get('/config/retardos',   [TardinessConfigController::class, 'show']);
                Route::patch('/config/retardos', [TardinessConfigController::class, 'update']);
            });

            // Reportes de retardos (admin + supervisor)
            Route::middleware(['module:asistencia','role:admin,supervisor'])->group(function () {
                Route::get('/retardos/resumen-mes',            [TardinessReportController::class, 'monthlySummary']);
                Route::get('/retardos/empleado/{empleado}',    [TardinessReportController::class, 'employeeDetail']);
            });

            // ── Módulo Nómina ────────────────────────────────────────────────────────
            Route::middleware(['module:nomina','role:admin'])->group(function () {
                Route::get('/nomina/periodos',                       [PayrollController::class, 'index']);
                Route::get('/nomina/periodos/semana',                [PayrollController::class, 'showByWeekDate']);
                Route::post('/nomina/periodos/generar',              [PayrollController::class, 'generate']);
                Route::get('/nomina/periodos/{id}',                  [PayrollController::class, 'show']);
                Route::patch('/nomina/periodos/{id}',                [PayrollController::class, 'updateNotes']);
                Route::patch('/nomina/periodos/{id}/entradas/{entryId}', [PayrollController::class, 'updateEntry']);
                Route::post('/nomina/periodos/{id}/aprobar',         [PayrollController::class, 'approve']);
                Route::patch('/nomina/periodos/{id}/reabrir',        [PayrollController::class, 'reabrir']);
                Route::get('/nomina/periodos/{id}/exportar',         [PayrollController::class, 'export']);
                Route::post('/nomina/periodos/{periodoId}/excluir',  [PayrollController::class, 'excluirEmpleado']);
            });

            // ── Recibos de Nómina y Gratificaciones (Empleado) ───────────────────────
            Route::middleware(['module:nomina','role:admin,empleado'])->group(function () {
                Route::get('/mis-recibos/nomina', [EmployeeReceiptController::class, 'indexNomina']);
                Route::get('/mis-recibos/nomina/{id}', [EmployeeReceiptController::class, 'showNomina']);
                Route::post('/mis-recibos/nomina/{id}/firmar', [EmployeeReceiptController::class, 'firmarNomina']);

                Route::get('/mis-recibos/gratificaciones', [EmployeeReceiptController::class, 'indexGratificaciones']);
                Route::get('/mis-recibos/gratificaciones/{id}', [EmployeeReceiptController::class, 'showGratificacion']);
                Route::post('/mis-recibos/gratificaciones/{id}/firmar', [EmployeeReceiptController::class, 'firmarGratificacion']);
            });

            // ── Gratificaciones (Admin) ──────────────────────────────────────────────
            Route::middleware(['module:nomina','role:admin'])->group(function () {
                Route::get('/admin/tipos-gratificacion', [AdminGratificationController::class, 'indexTipos']);
                Route::post('/admin/tipos-gratificacion', [AdminGratificationController::class, 'storeTipo']);
                Route::put('/admin/tipos-gratificacion/{id}', [AdminGratificationController::class, 'updateTipo']);
                Route::delete('/admin/tipos-gratificacion/{id}', [AdminGratificationController::class, 'destroyTipo']);

                Route::get('/admin/gratificaciones', [AdminGratificationController::class, 'indexGratificaciones']);
                Route::post('/admin/gratificaciones/generar', [AdminGratificationController::class, 'generar']);
                Route::post('/admin/gratificaciones/{id}/aprobar', [AdminGratificationController::class, 'aprobar']);
            });

            // Horarios de comida
            Route::middleware('module:asistencia')->group(function () {
                Route::get('/meal-schedules',       [MealScheduleController::class, 'index']);
                Route::post('/meal-schedules/bulk', [MealScheduleController::class, 'bulkStore']);
            });
            // Festivos
            Route::get('/empresa/festivos', [HolidayController::class, 'index']);
            Route::post('/empresa/festivos', [HolidayController::class, 'store']);
            Route::delete('/empresa/festivos/{id}', [HolidayController::class, 'destroy']);
            Route::post('/empresa/festivos/cargar-mexico', [HolidayController::class, 'loadMexicoHolidays']);

            //perfil
            Route::get('/mi-perfil',   [ProfileController::class, 'show']);
            Route::patch('/mi-perfil', [ProfileController::class, 'update']);
            Route::post('/mi-perfil/avatar', [ProfileController::class, 'uploadAvatar']);
            Route::post('/mi-perfil/password', [ProfileController::class, 'changePassword']);


            // Rutas de módulos empresa (solo admin)
            Route::middleware(['module:configuracion','role:admin'])->group(function () {
                Route::get('/empresa/modulos', [EmpresaController::class, 'modulos']);
                Route::post('/empresa/modulos', [EmpresaController::class, 'toggleModulo']);

                // Config IP empresa
                Route::patch('/empresa/config', [EmpresaController::class, 'config']);
                Route::get('/empresa/red',  [EmpresaController::class, 'getRed']);
                Route::post('/empresa/red', [EmpresaController::class, 'updateRed']);

                // Configuración de calendario a nivel empresa
                Route::patch('/empresa/settings/calendar', [EmpresaSettingsController::class, 'updateCalendar']);
                Route::get('/empresa/settings/operativo', [EmpresaSettingsController::class, 'getOperativo']);
                Route::patch('/empresa/settings/operativo', [EmpresaSettingsController::class, 'updateOperativo']);
            });

            // ── Módulo Góndolas ───────────────────────────────────────────────────────
            Route::middleware(['module:gondolas'])->group(function () {
                // Productos maestros
                Route::get('/products',                              [ProductsController::class, 'index']);
                Route::post('/products',                             [ProductsController::class, 'store']);
                Route::get('/products/{id}',                         [ProductsController::class, 'show'])->whereUuid('id');
                Route::patch('/products/{id}',                       [ProductsController::class, 'update'])->whereUuid('id');
                Route::delete('/products/{id}',                      [ProductsController::class, 'destroy'])->whereUuid('id');
                Route::get('/products/{id}/locations',               [ProductsController::class, 'locations'])->whereUuid('id');

                // Góndolas CRUD
                Route::get('/gondolas',                              [GondolasController::class, 'index']);
                Route::post('/gondolas',                             [GondolasController::class, 'store']);
                Route::get('/gondolas/{id}',                         [GondolasController::class, 'show']);
                Route::patch('/gondolas/{id}',                       [GondolasController::class, 'update']);
                Route::delete('/gondolas/{id}',                      [GondolasController::class, 'destroy']);
                Route::get('/gondolas/{id}/productos',               [GondolasController::class, 'productos']);
                Route::post('/gondolas/{id}/productos',              [GondolasController::class, 'addProducto']);
                Route::patch('/gondolas/{gId}/productos/{pId}',      [GondolasController::class, 'updateProducto']);
                Route::delete('/gondolas/{gId}/productos/{pId}',     [GondolasController::class, 'removeProducto']);
                Route::post('/gondolas/{gId}/productos/{pId}/foto',  [GondolasController::class, 'uploadFoto']);

                // Órdenes — gestión (admin/supervisor)
                Route::get('/gondola-ordenes',                       [GondolaOrdenesController::class, 'index']);
                Route::post('/gondola-ordenes',                      [GondolaOrdenesController::class, 'store']);
                Route::get('/gondola-ordenes/{id}',                  [GondolaOrdenesController::class, 'show']);
                Route::post('/gondola-ordenes/{id}/aprobar',         [GondolaOrdenesController::class, 'aprobar']);
                Route::post('/gondola-ordenes/{id}/rechazar',        [GondolaOrdenesController::class, 'rechazar']);

                // Órdenes — empleado
                Route::get('/mis-ordenes-gondola',                   [GondolaOrdenesController::class, 'misOrdenes']);
                Route::post('/gondola-ordenes/{id}/iniciar',         [GondolaOrdenesController::class, 'iniciar']);
                Route::post('/gondola-ordenes/{id}/completar',       [GondolaOrdenesController::class, 'completar']);

                // Auto-relleno por iniciativa propia
                Route::post('/gondolas/{gondolaId}/auto-rellenar',   [GondolaOrdenesController::class, 'autoRellenar']);

                // Generar tarea de relleno
                Route::post('/gondolas/{id}/generar-tarea',          [GondolaOrdenesController::class, 'generarTarea']);
            });

            // ── Módulo Semáforo de Desempeño ─────────────────────────────────────────
            Route::middleware(['module:semaforo'])->group(function () {
                // Admin
                Route::get('/semaforo/empleados',                            [SemaforoController::class, 'index']);
                Route::post('/semaforo/empleados/{empleadoId}/activar',      [SemaforoController::class, 'activar']);
                Route::post('/semaforo/empleados/{empleadoId}/desactivar',   [SemaforoController::class, 'desactivar']);
                Route::get('/semaforo/empleados/{empleadoId}/resultado',     [SemaforoController::class, 'resultado']);

                // Admin + Supervisor
                Route::post('/semaforo/evaluaciones',                        [SemaforoController::class, 'evaluarAdmin']);
                Route::get('/semaforo/mis-evaluaciones-pendientes',          [SemaforoController::class, 'pendientesSupervisor']);

                // Empleado
                Route::get('/semaforo/companeros',                           [SemaforoController::class, 'companeros']);
                Route::post('/semaforo/peer-evaluaciones',                   [SemaforoController::class, 'peerEvaluar']);

                // Configuración del semáforo (lectura pública dentro del módulo, escritura solo admin)
                Route::get('/semaforo/config',                               [SemaforoController::class, 'configShow']);
                Route::post('/semaforo/config',                              [SemaforoController::class, 'configStore']);
            });

            // Ruta de prueba 
            Route::get('/demo/employees-module-check', function () {
                return response()->json(['ok' => true, 'message' => 'Employees module enabled']);
            })->middleware('module:configuracion');
        });
    });
});
