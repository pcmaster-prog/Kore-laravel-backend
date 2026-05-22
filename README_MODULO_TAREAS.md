1. Estructura Jerárquica: Áreas → Secciones → Tareas
El sistema organiza el trabajo en tres niveles anidados, replicando la distribución física de la tienda:
Nivel 1 — Áreas (Lugares Físicos)
Son las zonas de la tienda. En los datos demo existen 5 áreas:
Table
Área	Icono	Secciones
Patio	Sol	General
Mostrador	Tienda	Atención, Exhibición
Almacén	Bodega	Inventario, Recepción
Caja	Tarjeta	Cobro
Producción	Fábrica	Preparación
Nivel 2 — Secciones (Sub-zonas)
Dentro de cada área hay secciones específicas. Ejemplo: Mostrador se divide en Atención (interacción con clientes) y Exhibición (presentación de productos).
Nivel 3 — Tareas (Acciones Concretas)
Cada sección contiene tareas ejecutables. Ejemplo: la sección General del Patio tiene 3 tareas: Lavar piso, Limpiar ventanas, Revisar basura.
2. Modelo de Datos de una Tarea
Cada tarea en el sistema tiene estas propiedades definidas en types/index.ts:
TypeScript
Copy
interface Task {
  id: string;           // Identificador único
  name: string;         // Nombre de la tarea
  description: string;  // Instrucciones detalladas
  areaId: string;       // Área a la que pertenece
  sectionId: string;    // Sección a la que pertenece
  priority: 'baja' | 'media' | 'alta';
  assignedTo?: string[];  // IDs de empleados asignados
  dueDate?: string;     // Fecha límite
  estimatedTime?: number;  // Tiempo estimado en minutos
  actualTime?: number;  // Tiempo real transcurrido
  completed: boolean;   // Estado de completitud
  attachments: Attachment[];  // Fotos, audios, videos, notas
  checklist: ChecklistItem[]; // Sub-tareas verificables
  notes: string;        // Observaciones libres
  incidents: Incident[]; // Reportes de incidencias
}
3. Panel de Tareas (Vista del Administrador/Supervisor)
El módulo de Tareas tiene un layout de dos columnas:
Columna Izquierda — Árbol de Navegación
Acordeones expandibles por Área → Sección → Tarea
Cada tarea muestra: círculo de prioridad (verde=alta, naranja=media, mauve=baja) + nombre + tachado si está completada
Barra de búsqueda que filtra tareas por nombre en tiempo real
Contadores de tareas totales, completadas, pendientes y de alta prioridad en la parte superior
Columna Derecha — Detalle de la Tarea (se abre al hacer clic)
Cuando seleccionas una tarea, aparece un panel con:
Table
Campo	Descripción
Nombre editable	Input inline para cambiar el nombre
Breadcrumbs	Muestra Área / Sección
Descripción	Textarea con instrucciones detalladas
Asignación	Select para elegir empleado(s)
Prioridad	Botones toggle: Baja / Media / Alta
Tiempo estimado	En minutos
Fecha límite	Date picker
Checklist	Sub-tareas con checkboxes
Herramientas	Foto, nota de voz, nota de texto, incidencia
Observaciones	Textarea libre
Acciones	Guardar (verde), Completar (naranja), Eliminar (wine)
4. Herramientas Auxiliares por Tarea
Cada tarea puede incluir herramientas específicas que ayudan al empleado a ejecutarla correctamente:
Ejemplo real del demo — Tarea: "Lavar piso" (Patio > General)
Tiene un checklist con 3 items verificables:
Verificar jabón
Verificar trapeador
Verificar bolsas de basura
Ejemplo real — Tarea: "Recibir mercancía" (Almacén > Recepción)
Checklist de 3 pasos:
Verificar orden de compra ✅
Contar piezas ✅
Revisar daños ✅
Herramientas disponibles:
Table
Herramienta	Uso
Fotografía	Evidencia visual de antes/después
Nota de voz	Reporte rápido sin escribir
Nota de texto	Observaciones estructuradas
Incidencia	Reportar falta de material, equipo dañado, etc.
5. Módulo de Rutinas (Agrupación de Tareas)
Las Rutinas son conjuntos de tareas que se ejecutan en secuencia, pensadas para momentos específicos del día.
Tipos de rutina disponibles:
Apertura (08:00) — 7 tareas: encender luces, revisar cajas, limpieza, uniformes, pasillos, aire acondicionado, incidencias
Cierre (19:00) — 5 tareas: cuadrar cajas, limpiar, apagar equipos, revisar alarma, cerrar puertas
Limpieza — 4 tareas: lavar pisos, baños, desinfectar, sacudir
Inventario — 4 tareas: contar stock, caducidades, mermas, faltantes
Características de cada rutina:
Orden numérico de ejecución (drag & drop para reordenar)
Tareas obligatorias vs. opcionales (checkbox)
Asignación automática por: puesto, horario, área, día o empleado específico
Vista previa del empleado — simula cómo ve el trabajador la rutina en su celular
6. Flujo Completo: Quién hace qué, dónde y cómo
plain
Copy
1. ADMIN crea un PUESTO (ej. "Ayudante Integral")
   ↓
2. ADMIN define las TAREAS BASE del puesto en el Constructor de Puestos
   ↓
3. ADMIN crea ÁREAS y SECCIONES (Patio, Mostrador, Almacén...)
   ↓
4. ADMIN crea TAREAS específicas dentro de cada sección
   ↓
5. ADMIN agrupa tareas en RUTINAS (Apertura, Cierre, Limpieza...)
   ↓
6. ADMIN asigna rutinas automáticamente por: puesto / horario / área / día
   ↓
7. EMPLEADO ve sus tareas asignadas en su dashboard
   ↓
8. EMPLEADO ejecuta: marca checklist, sube fotos, registra tiempo
   ↓
9. SUPERVISOR revisa tareas completadas, evidencia e incidencias
   ↓
10. SISTEMA genera métricas de productividad para nómina y evaluaciones
7. Conexión con otros módulos
Table
Módulo	Conexión con Tareas
Puestos	Cada puesto tiene baseTasks[] — tareas predeterminadas
Empleados	Las tareas se asignan por employeeId
Asistencia	Solo empleados con registro de entrada pueden ver tareas
Evaluaciones	El % de tareas completadas es un KPI de desempeño
Nómina	Productividad por tareas puede influir en bonos
Reportes	Métricas de tareas completadas por área, empleado, período
Academia	Cursos de capacitación se asignan según las tareas del puesto
8. Ejemplo Práctico en los Datos Demo
Empleado: Fernando del Río (Auxiliar de Limpieza, medio tiempo 06:00-10:00)
Área asignada: Patio > General
Sus tareas del día:
Table
Tarea	Prioridad	Tiempo est.	Estado
Revisar basura	Alta	15 min	✅ Completada
Lavar piso	Media	30 min	⏳ Pendiente (checklist: jabón ❌, trapeador ❌, bolsas ❌)
Limpiar ventanas	Baja	20 min	⏳ Pendiente
Rutina matutina asignada: Limpieza (automática por horario 06:00)
Herramientas que puede usar: Cámara para foto del patio limpio, nota de voz si falta material, incidencia si el trapeador está dañado.
En resumen, el sistema de tareas responde a las 7 preguntas clave del diseño: quién (empleado asignado) hace qué (tarea con checklist), dónde (área/sección), cómo (descripción + herramientas), con qué herramientas (foto, voz, notas), en cuánto tiempo (estimado vs. real), y supervisado por quién (supervisor del puesto).



Puntos a considerar,  los supervisores pueden tener unas secciones predeterminadas, por ejemplo el supervisor de cajas va atener la responsabilidad de asignar las tareas que ya estan predefinidas en su seccion, el si puede crear tareas, pero solo para su seccion, por ejemplo no va poder crear tareas para el area de pesaje, las rutinas mi idea es que se asignen automaticamente, es decir si el admin ya definio la rutina de apertura, a aprtir de las 8:30 se le asigna automaticamente al empleado, y ya al empleado se le marca, le llega una notificacion, todo esto considerando que debe ser configurable por el admin, desde las secciones, asignar a supervisores secciones, modificar quienes pueden asignar rutinas, lo de que por ejemplo se abra diario, se puede modificar, que de lunes a sabado la responsabilida o la asignacion sea solamente a un empleado y el domingo cambie a otro empleado, configurable,  tambien hay tareas como lavar el baño que es diario, que ya estan predefinidas quien la realiza cada dia, porque cada dia la realiza alguien diferente, entonces esta por ejemplo a juan ya esta definido que debe lavar el baño los martes, se llega el martes y hasta que juan marque asistencia esa tarea se le va asignar, el va a elegir en que momento de la jornada realizar dicha tarea, y si por alguna razon la persona que le tocaba lavar el baño no fue a trabajar dicho dia, el sistema avisa que por el momento no hay quien realice dicha tarea, y que me de la opcion de asignarsela a otra persona.