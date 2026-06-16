# Prompt Frontend: Reglas de Asignación Multitemplate (Kore)

> **Contexto:** El backend fue refactorizado. Las **Reglas de Asignación** ahora soportan múltiples templates ordenados (reemplazando la funcionalidad de Rutinas). Las **Rutinas** quedan deprecadas. La pestaña "Reglas de asignación" se mantiene tal como está en "Gestión por Áreas", solo se mejora para permitir seleccionar varias tareas por regla. Se elimina la pestaña "Programación".
>
> **Stack:** React 19 + TypeScript 5.9 + Vite 7 + TanStack Query v5 + Zustand + Tailwind CSS v4 + Axios

---

## 🚨 CAMBIOS CRÍTICOS

### 1. Nuevos / Modificados tipos (`src/features/tareas/types.ts`)

```typescript
export interface TaskAssignmentRule {
  id: string;
  assignee_type: 'empleado' | 'position' | 'section_supervisor';
  assignee_id: string | null;
  section_id: string | null;
  day_of_week: number[];
  trigger_time: string | null;
  trigger_event: 'time' | 'attendance_checkin' | 'both';
  is_active: boolean;
  // NUEVO: items en vez de un solo template
  items: TaskAssignmentRuleItem[];
  task_template_id?: string | null; // legacy
}

export interface TaskAssignmentRuleItem {
  id: string;
  rule_id: string;
  template_id: string;
  template: TaskTemplate;
  sort_order: number;
  is_active: boolean;
}

export interface EmpleadoSection {
  id: string;
  empleado_id: string;
  section_id: string;
  section?: Section;
  area?: Area;
  is_primary: boolean;
}

export interface UnassignedTask {
  id: string;
  title: string;
  description: string | null;
  priority: string;
  area: { id: string; name: string } | null;
  section: { id: string; name: string } | null;
  unassigned_reason: string;
  created_at: string;
}
```

---

### 2. Endpoints API (`src/features/tareas/api.ts`)

```typescript
// ========== REGLAS DE ASIGNACIÓN — AHORA MULTITEMPLATE ==========

export const fetchTaskAssignmentRules = (params?: { section_id?: string; empleado_id?: string; active?: boolean }) =>
  api.get('/task-assignment-rules', { params });

export const createTaskAssignmentRule = (data: {
  template_ids: string[]; // NUEVO: array en vez de task_template_id único
  assignee_type: 'empleado' | 'position' | 'section_supervisor';
  assignee_id?: string | null;
  section_id?: string | null;
  day_of_week: number[];
  trigger_time?: string;
  trigger_event?: 'time' | 'attendance_checkin' | 'both';
}) => api.post('/task-assignment-rules', data);

export const updateTaskAssignmentRule = (id: string, data: Partial<{
  template_ids: string[];
  assignee_type: 'empleado' | 'position' | 'section_supervisor';
  assignee_id: string | null;
  section_id: string | null;
  day_of_week: number[];
  trigger_time: string | null;
  trigger_event: 'time' | 'attendance_checkin' | 'both';
  is_active: boolean;
}>) => api.patch(`/task-assignment-rules/${id}`, data);

export const deleteTaskAssignmentRule = (id: string) =>
  api.delete(`/task-assignment-rules/${id}`);

// ========== EMPLEADO-SECCIONES ==========

export const fetchEmpleadoSections = (empleadoId: string) =>
  api.get(`/empleados/${empleadoId}/sections`);

export const assignSectionToEmpleado = (empleadoId: string, sectionId: string, isPrimary = false) =>
  api.post(`/empleados/${empleadoId}/sections`, { section_id: sectionId, is_primary: isPrimary });

export const removeSectionFromEmpleado = (empleadoId: string, sectionId: string) =>
  api.delete(`/empleados/${empleadoId}/sections/${sectionId}`);

// ========== TAREAS HUÉRFANAS ==========

export const fetchUnassignedTasks = () =>
  api.get('/tareas/huerfanas');

export const reasignarTarea = (taskId: string, empleadoIds: string[]) =>
  api.post(`/tareas/${taskId}/reasignar`, { empleado_ids: empleadoIds });
```

---

### 3. Cambios en pestañas de "Gestión por Áreas"

**ANTES:**
- Árbol de tareas
- Áreas y secciones
- Reglas de asignación
- Programación

**AHORA:**
- Árbol de tareas
- Áreas y secciones
- **Reglas de asignación** ✅ (se mantiene, se mejora)
- ❌ Programación (eliminar)

**También en "Gestión de Tareas" (nivel superior):**
- ❌ Eliminar pestaña "Rutinas"
- Mantener: Tareas, Plantillas, Góndolas, Áreas

---

### 4. `ReglasDeAsignacionPage.tsx` — MEJORAR (no eliminar)

Mantener el **calendario semanal** tal como está. Solo cambia el formulario de crear/editar regla.

**Tarjetas del calendario semanal:**
```
LUN                          MAR
┌────────────────────────┐  ┌────────────────────────┐
│ 👤 PUESTO              │  │ 👤 PUESTO              │
│ Limpieza de caja       │  │ Limpieza de caja       │
│ 3 tareas               │  │ 3 tareas               │
│ 🕐 08:00               │  │ 🕐 08:00               │
│ Cajero                 │  │ Cajero                 │
└────────────────────────┘  └────────────────────────┘
```

**Ahora muestra:**
- Tipo de asignado (Empleado / Puesto / Sección)
- **Cantidad de tareas** en la regla (ej: "3 tareas")
- Hora
- Nombre del asignado
- Al hacer clic: abre modal de edición

---

### 5. `ReglaFormModal.tsx` — CAMBIOS EN EL FORMULARIO

Mantener el layout actual de 2 columnas, pero reemplazar el campo de plantilla único por multi-select.

**ANTES:**
```
PLANTILLA DE TAREA          TIPO DE ASIGNADO
[ID de plantilla ▼]         [Posición ▼]
```

**AHORA:**
```
TAREAS DE LA REGLA          TIPO DE ASIGNADO
┌───────────────────────┐   [Empleado ▼]
│ ☰ Revisar jabón    🗑️ │   
│ ☰ Lavar piso       🗑️ │   ASIGNADO
│ ☰ Revisar basura   🗑️ │   [Juan Pérez ▼]
│                       │
│ [+ Agregar tarea]     │   TRIGGER
└───────────────────────┘   [Por hora ▼]
                            
                            HORA
                            [08:00 a.m. ⏰]
                            
                            DÍAS DE LA SEMANA
                            [Lun] [Mar] [Mié] [Jue] [Vie] [Sáb] [Dom]
```

**Campo "Tareas de la regla":**
- Multi-select de templates (`GET /task-templates`)
- Chips ordenados verticalmente con **drag & drop** (handle ☰ a la izquierda)
- Cada chip muestra nombre de la tarea + botón 🗑️ para quitar
- Botón "+ Agregar tarea" abre dropdown de templates disponibles
- Guardar como `template_ids: string[]` en el orden visual

**Tipo de asignado:**
- `Empleado` → select de empleados activos
- `Puesto` → select de puestos
- `Sección` → select de secciones (filtra empleados de esa sección)

**Trigger:**
- `Por hora` → muestra time picker
- `Por asistencia` → oculta hora
- `Ambos` → muestra hora + badge explicativo

**Días de la semana:**
- Mantener pills toggles actuales

**Al guardar:**
```json
{
  "template_ids": ["tpl-1", "tpl-2", "tpl-3"],
  "assignee_type": "empleado",
  "assignee_id": "uuid-juan",
  "day_of_week": [1, 2, 3, 4, 5],
  "trigger_time": "08:00",
  "trigger_event": "time"
}
```

---

### 6. `EmpleadoForm.tsx` / `EmpleadoDetailModal.tsx` — Tab "Secciones"

**NUEVO:** Tab "Secciones" en el modal de editar empleado.

```
┌─ Editar Usuario ──────────────────────┐
│  [GENERAL]  [SECCIONES]               │
│                                        │
│  📍 SECCIONES ASIGNADAS                │
│  ┌─────────────────────────────────┐  │
│  │ 🏠 Patio > Limpieza exterior    │  │
│  │    [Principal]              [🗑️] │  │
│  │ 🏠 Mostrador > Atención         │  │
│  │                         [🗑️]    │  │
│  └─────────────────────────────────┘  │
│                                        │
│  Agregar sección                       │
│  [Área: Patio ▼] [Sección: Limpieza ▼] │
│  [ ] Sección principal                 │
│  [ ⊕ Agregar ]                         │
└────────────────────────────────────────┘
```

- Lista secciones vinculadas (`GET /empleados/{id}/sections`)
- Badge "Principal" si `is_primary`
- Formulario para agregar: select Área → select Sección (filtrado) + checkbox principal

---

### 7. `TareasHuerfanasPage.tsx` — NUEVA PANTALLA

Accesible desde menú del supervisor: "⚠️ Tareas sin asignar"

- Cards con título, área/sección, fecha
- Botón "Reasignar" → modal con select múltiple de empleados
- Badge/contador en sidebar

---

### 8. Hooks TanStack Query

```typescript
export const useTaskAssignmentRules = (params?: { section_id?: string; empleado_id?: string; active?: boolean }) =>
  useQuery({ queryKey: ['task-assignment-rules', params], queryFn: () => fetchTaskAssignmentRules(params) });

export const useCreateTaskAssignmentRule = () =>
  useMutation({ mutationFn: createTaskAssignmentRule, onSuccess: invalidateRules });

export const useUpdateTaskAssignmentRule = () =>
  useMutation({ mutationFn: ({ id, data }) => updateTaskAssignmentRule(id, data), onSuccess: invalidateRules });

export const useDeleteTaskAssignmentRule = () =>
  useMutation({ mutationFn: deleteTaskAssignmentRule, onSuccess: invalidateRules });

export const useEmpleadoSections = (empleadoId: string) =>
  useQuery({ queryKey: ['empleado-sections', empleadoId], queryFn: () => fetchEmpleadoSections(empleadoId), enabled: !!empleadoId });

export const useUnassignedTasks = () =>
  useQuery({ queryKey: ['unassigned-tasks'], queryFn: fetchUnassignedTasks, refetchInterval: 30000 });

export const useReasignarTarea = () =>
  useMutation({
    mutationFn: ({ taskId, empleadoIds }) => reasignarTarea(taskId, empleadoIds),
    onSuccess: () => { invalidateUnassignedTasks(); invalidateTasks(); }
  });
```

---

### 9. Checklist Frontend

- [ ] Actualizar `types.ts` con `TaskAssignmentRuleItem`, `EmpleadoSection`, `UnassignedTask`
- [ ] Actualizar `api.ts` con endpoints multitemplate y empleado-secciones
- [ ] ❌ **Eliminar pestaña "Programación"** de "Gestión por Áreas"
- [ ] ❌ **Eliminar pestaña "Rutinas"** de "Gestión de Tareas"
- [ ] ✅ **Mantener pestaña "Reglas de asignación"** en "Gestión por Áreas"
- [ ] Modificar tarjetas del calendario semanal para mostrar "N tareas" en vez de 1 solo template
- [ ] Crear/modificar `ReglaFormModal` — multi-select de templates con drag & drop para ordenar
- [ ] Modificar select de asignado para soportar Empleado / Puesto / Sección
- [ ] Agregar tab "Secciones" en `EmpleadoForm` con CRUD de secciones asignadas
- [ ] Crear `TareasHuerfanasPage` con reasignación
- [ ] Crear hooks TanStack Query nuevos
- [ ] Probar: crear regla con 3 templates → verificar calendario semanal → verificar auto-asignación

---

### 10. Notas Importantes

1. **Rutinas son legacy:** Los endpoints `/task-routines` y `/routine-schedules` siguen existiendo por compatibilidad, pero **no se usan para nueva funcionalidad**. No crear nuevas pantallas para rutinas.

2. **Template principal:** El campo `task_template_id` en la regla es legacy. Usar siempre `template_ids` array al crear/actualizar.

3. **Orden de templates:** El backend respeta `sort_order`. Enviar `template_ids` en el orden que el usuario definió con drag & drop.
