# Prompt Frontend: Migraci\u00f3n a Productos Maestros en G\u00f3ndolas (Kore)

> **Contexto:** El backend del m\u00f3dulo de g\u00f3ndolas fue refactorizado para soportar un **cat\u00e1logo maestro de productos**. Un mismo producto puede estar en m\u00faltiples g\u00f3ndolas. Este prompt detalla TODOS los cambios que el frontend debe aplicar para cuadrar con el nuevo backend.
>
> **Stack:** React 19 + TypeScript 5.9 + Vite 7 + TanStack Query v5 + Zustand + Tailwind CSS v4 + Axios

---

## \ud83d\udea8 CAMBIOS CR\u00cdTICOS (Rompen el flujo actual si no se aplican)

### 1. Nuevo tipo de dato: `Product` (cat\u00e1logo maestro)

Agregar a `src/features/gondolas/types.ts`:

```typescript
export interface Product {
  id: string;           // UUID del producto maestro
  sku: string | null;   // antes "clave"
  name: string;         // antes "nombre"
  description: string | null;
  default_unit: string; // "pz" | "kg" | "caja" | "media_caja" | etc.
  photo_url: string | null;
  is_active: boolean;
  locations_count?: number; // cu\u00e1ntas g\u00f3ndolas lo tienen
}

export interface ProductLocation {
  id: string;           // ID de la ubicaci\u00f3n (gondola_productos)
  gondola_id: string;
  product_id: string;
  product?: Product;    // datos del producto maestro
  orden: number;
  activo: boolean;
  // Campos legacy (para productos a\u00fan no migrados):
  clave?: string | null;
  nombre?: string | null;
  unidad?: string | null;
  foto_url?: string | null;
}

export interface GondolaOrdenItem {
  id: string;
  product_id?: string | null;  // NUEVO: FK al producto maestro
  product?: Product | null;    // NUEVO: datos del producto maestro
  // Snapshot legacy (siempre presente para historial):
  clave: string | null;
  nombre: string;
  unidad: string;
  cantidad: number | null;
  unit?: string | null;        // NUEVO: unidad din\u00e1mica registrada por empleado
}
```

**Modificar `GondolaProducto` (el tipo viejo):**
```typescript
// ANTIGUO — se usa solo para productos legacy sin product_id
export interface GondolaProductoLegacy {
  id: string;
  gondola_id: string;
  clave: string | null;
  nombre: string;
  descripcion: string | null;
  unidad: string;
  foto_url: string | null;
  orden: number;
  activo: boolean;
}

// NUEVO — unificador que el backend devuelve
export interface GondolaProducto {
  id: string;              // Este ES el location_id (gondola_productos.id)
  product_id?: string | null;
  product?: Product;
  // Fallback legacy:
  clave?: string | null;
  nombre?: string | null;
  unidad?: string | null;
  foto_url?: string | null;
  orden: number;
  activo: boolean;
}
```

---

### 2. Nuevos Endpoints API (`src/features/gondolas/api.ts`)

```typescript
// ========== CAT\u00c1LOGO MAESTRO DE PRODUCTOS ==========

export const fetchProducts = (params?: { active?: boolean; search?: string; unit?: string }) =>
  api.get('/products', { params });

export const createProduct = (data: {
  sku?: string;
  name: string;
  description?: string;
  default_unit: string;
  photo?: File;
}) => {
  const form = new FormData();
  form.append('name', data.name);
  if (data.sku) form.append('sku', data.sku);
  if (data.description) form.append('description', data.description);
  form.append('default_unit', data.default_unit);
  if (data.photo) form.append('photo', data.photo);
  return api.post('/products', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const updateProduct = (id: string, data: Partial<Product> & { photo?: File }) => {
  const form = new FormData();
  Object.entries(data).forEach(([key, value]) => {
    if (value !== undefined && key !== 'photo') form.append(key, String(value));
  });
  if (data.photo) form.append('photo', data.photo);
  return api.post(`/products/${id}`, form, { // Laravel acepta POST con _method=PATCH
    headers: { 'Content-Type': 'multipart/form-data' },
    params: { _method: 'PATCH' },
  });
};

export const deleteProduct = (id: string) =>
  api.delete(`/products/${id}`);

export const fetchProductLocations = (productId: string) =>
  api.get(`/products/${productId}/locations`);

// ========== G\u00d3NDOLAS — CAMBIOS ==========

// POST /gondolas/:id/productos — AHORA requiere product_id
export const addProductoToGondola = (gondolaId: string, productId: string, orden?: number) =>
  api.post(`/gondolas/${gondolaId}/productos`, { product_id: productId, orden });

// PATCH /gondolas/:gId/productos/:pId — pId AHORA es location_id
export const updateProductoEnGondola = (gondolaId: string, locationId: string, data: { orden?: number; activo?: boolean }) =>
  api.patch(`/gondolas/${gondolaId}/productos/${locationId}`, data);

// DELETE /gondolas/:gId/productos/:pId — pId AHORA es location_id
export const removeProductoDeGondola = (gondolaId: string, locationId: string) =>
  api.delete(`/gondolas/${gondolaId}/productos/${locationId}`);

// POST /gondolas/:gId/productos/:pId/foto — pId AHORA es product_id (del maestro)
export const uploadFotoProducto = (gondolaId: string, productId: string, file: File) => {
  const form = new FormData();
  form.append('file', file);
  return api.post(`/gondolas/${gondolaId}/productos/${productId}/foto`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

// ========== NUEVO: GENERAR TAREA DE RELLENO ==========

export const generarTareaDeRelleno = (gondolaId: string, data: {
  empleado_ids: string[];
  due_at?: string;
  notas?: string;
}) => api.post(`/gondolas/${gondolaId}/generar-tarea`, data);

// ========== \u00d3RDENES — CAMBIOS ==========

// completar ahora soporta unit por item
export const completarOrden = (ordenId: string, data: {
  items: { id: string; cantidad: number; unit?: string }[];
  notas_empleado?: string;
  evidencia_url?: string;
  evidencia?: File;
}) => {
  const form = new FormData();
  form.append('items', JSON.stringify(data.items));
  if (data.notas_empleado) form.append('notas_empleado', data.notas_empleado);
  if (data.evidencia_url) form.append('evidencia_url', data.evidencia_url);
  if (data.evidencia) form.append('evidencia', data.evidencia);
  return api.post(`/gondola-ordenes/${ordenId}/completar`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};
```

**Eliminar del `api.ts` el endpoint viejo `addProductoToGondola` que creaba productos inline.**

---

### 3. Cambios en Componentes

#### `GondolaDetailModal.tsx` — Tab "Productos"

**ANTES:**
- Bot\u00f3n "Agregar producto" abre un form inline con campos: nombre, clave, unidad, foto
- El producto se creaba directamente en la gondola

**AHORA:**
- Bot\u00f3n "Agregar producto" abre un **selector de productos maestros** (`ProductPickerModal`)
- El picker muestra el cat\u00e1logo de `/products` con b\u00fasqueda
- El admin selecciona un producto del cat\u00e1logo y lo vincula a la g\u00f3ndola
- Si el producto no existe en el cat\u00e1logo, hay un bot\u00f3n "+ Crear nuevo producto" que abre `ProductFormModal`
- La lista de productos en la g\u00f3ndola muestra:
  - Foto del producto maestro (`product.photo_url`)
  - SKU (`product.sku`)
  - Nombre (`product.name`)
  - Unidad default (`product.default_unit`)
  - Toggle activo
  - Bot\u00f3n eliminar (desvincula de la g\u00f3ndola, NO borra el producto)

**Manejo de legacy:** Si un producto NO tiene `product_id`, mostrar los campos legacy (`clave`, `nombre`, `unidad`, `foto_url`) con un badge gris "Legacy".

```tsx
// Helper para renderizar un producto de g\u00f3ndola
const renderProducto = (p: GondolaProducto) => {
  if (p.product) {
    return (
      <>
        <img src={p.product.photo_url} />
        <span>{p.product.sku}</span>
        <span>{p.product.name}</span>
        <span>{p.product.default_unit}</span>
      </>
    );
  }
  // Fallback legacy
  return (
    <>
      <img src={p.foto_url} />
      <span>{p.clave}</span>
      <span>{p.nombre}</span>
      <span>{p.unidad}</span>
      <Badge variant="secondary">Legacy</Badge>
    </>
  );
};
```

**IMPORTANTE:** El `id` de cada fila en la lista es `p.id` (que es el `location_id`). Este `id` se usa para:
- `PATCH /gondolas/:gId/productos/:locationId` (cambiar orden o activo)
- `DELETE /gondolas/:gId/productos/:locationId` (desvincular)

#### `GondolaDetailModal.tsx` — Upload de foto

**ANTES:** `uploadFotoProducto(gondolaId, productoIdDeGondola, file)`
**AHORA:** `uploadFotoProducto(gondolaId, productoMaestroId, file)`

Usar `product.id` (el del maestro), no `location.id`.

---

#### `CrearOrdenModal.tsx` — Bot\u00f3n "Generar como Tarea"

**NUEVO:** Agregar un toggle/checkbox "Generar como tarea para el empleado".

- Si est\u00e1 activado: llamar `generarTareaDeRelleno(gondolaId, { empleado_ids: [id], notas })` en vez de `POST /gondola-ordenes`
- Esto crea tanto la orden como la tarea vinculada
- El empleado la ver\u00e1 en su panel de tareas (`/mis-tareas`) en vez de solo en `/mis-ordenes-gondola`

- Si est\u00e1 desactivado: seguir usando el flujo actual de `POST /gondola-ordenes`

---

#### `GondolaRellenoPage.tsx` — Formulario de cantidades

**CAMBIO 1: Mostrar producto maestro**

```tsx
// ANTES
item.nombre, item.clave, item.unidad

// AHORA
item.product?.name ?? item.nombre
item.product?.sku ?? item.clave
item.product?.photo_url ?? null
```

**CAMBIO 2: Unidad din\u00e1mica**

Cada producto ahora puede tener una **unidad diferente** a la del snapshot:

```tsx
// En cada fila de producto, agregar un select de unidad
<Select 
  value={item.unit || item.unidad} 
  onChange={(u) => updateItemUnit(item.id, u)}
>
  <option value="pz">pieza</option>
  <option value="caja">caja</option>
  <option value="media_caja">media caja</option>
  <option value="kg">kg</option>
</Select>
```

Esta `unit` se env\u00eda en `completarOrden` como `items: [{ id, cantidad, unit }]`. Si no se env\u00eda, el backend usa el snapshot.

---

#### `GondolasManagerTab.tsx` — Card de g\u00f3ndola

**NUEVO:** Bot\u00f3n "Generar Tarea" en cada card de g\u00f3ndola (junto a "Crear orden").

Al hacer clic:
1. Abrir modal r\u00e1pido: seleccionar empleado + nota opcional
2. Llamar `generarTareaDeRelleno(gondolaId, { empleado_ids, notas })`
3. Mostrar toast de \u00e9xito: "Tarea de relleno generada para [Empleado]"

---

### 4. Nuevos Componentes a Crear

#### `ProductPickerModal.tsx`
Modal reutilizable para seleccionar productos del cat\u00e1logo maestro.

Props:
```typescript
interface ProductPickerModalProps {
  open: boolean;
  onClose: () => void;
  onSelect: (product: Product) => void;
  excludeIds?: string[]; // productos ya vinculados (para no mostrarlos)
}
```

Features:
- Lista de `/products` paginada
- B\u00fasqueda en tiempo real (`search` param)
- Foto, SKU, nombre, unidad
- Bot\u00f3n "Crear nuevo producto" que abre `ProductFormModal`

#### `ProductFormModal.tsx`
Formulario para crear/editar productos maestros.

Campos:
- SKU (input, opcional)
- Nombre (input, requerido)
- Descripci\u00f3n (textarea, opcional)
- Unidad default (select: pz, kg, caja, media_caja)
- Foto (upload, opcional)

#### `ProductCatalogPage.tsx` o Tab (Admin)
Nueva pantalla/tab "Cat\u00e1logo de Productos" en el m\u00f3dulo de g\u00f3ndolas.

Grid/table de productos maestros con:
- Foto
- SKU + Nombre
- Unidad
- Ubicaciones (cu\u00e1ntas g\u00f3ndolas lo tienen)
- Acciones: editar, ver ubicaciones, desactivar

---

### 5. Cambios en Hooks de TanStack Query

```typescript
// Nuevos hooks en src/features/gondolas/hooks/

export const useProducts = (params?: { active?: boolean; search?: string }) =>
  useQuery({ queryKey: ['products', params], queryFn: () => fetchProducts(params) });

export const useCreateProduct = () =>
  useMutation({ mutationFn: createProduct, onSuccess: invalidateProducts });

export const useAddProductToGondola = () =>
  useMutation({ 
    mutationFn: ({ gondolaId, productId, orden }: { gondolaId: string; productId: string; orden?: number }) =>
      addProductoToGondola(gondolaId, productId, orden),
    onSuccess: invalidateGondolaProductos 
  });

export const useGenerateRefillTask = () =>
  useMutation({ 
    mutationFn: ({ gondolaId, data }: { gondolaId: string; data: { empleado_ids: string[]; notas?: string } }) =>
      generarTareaDeRelleno(gondolaId, data),
    onSuccess: invalidateTasks // invalida /mis-tareas
  });
```

---

### 6. Retrocompatibilidad en el Frontend

El backend mantiene datos legacy. El frontend debe manejar ambos casos:

```typescript
// Helper obligatorio
export const getProductDisplayName = (item: GondolaProducto | GondolaOrdenItem): string => {
  if ('product' in item && item.product) return item.product.name;
  return item.nombre ?? 'Sin nombre';
};

export const getProductPhoto = (item: GondolaProducto | GondolaOrdenItem): string | null => {
  if ('product' in item && item.product) return item.product.photo_url;
  return item.foto_url ?? null;
};

export const getProductSku = (item: GondolaProducto | GondolaOrdenItem): string | null => {
  if ('product' in item && item.product) return item.product.sku;
  return item.clave ?? null;
};

export const getProductUnit = (item: GondolaProducto | GondolaOrdenItem): string => {
  if ('product' in item && item.product) return item.product.default_unit;
  return item.unidad ?? 'pz';
};
```

---

### 7. Flujo de Datos Actualizado

```
ADMIN
  |
  ├─► Crea Producto Maestro → POST /products
  |     (sku, name, default_unit, photo)
  |
  ├─► Abre Góndola → GET /gondolas/:id
  |
  ├─► Agrega producto → POST /gondolas/:id/productos
  |     { product_id: "uuid-del-maestro", orden: 1 }
  |
  ├─► Genera tarea → POST /gondolas/:id/generar-tarea
  |     { empleado_ids: ["emp-1"], notas: "Rellenar hoy" }
  |
  └─► Backend crea: GondolaOrden + Task vinculadas

EMPLEADO
  |
  ├─► Ve tarea en /mis-tareas → "Rellenar: Gondola A"
  |     (task.task_source === 'gondola_refill')
  |
  ├─► Abre tarea → muestra GondolaOrden vinculada
  |
  ├─► Selecciona productos + cantidades + unidades
  |
  ├─► Completar → POST /gondola-ordenes/:id/completar
  |     { items: [{ id, cantidad, unit }] }
  |
  └─► Backend: Orden → completado, Task → done_pending
```

---

### 8. Checklist de Implementaci\u00f3n Frontend

- [ ] Actualizar `types.ts` con `Product`, `ProductLocation`, campos nuevos en `GondolaProducto` y `GondolaOrdenItem`
- [ ] Actualizar `api.ts` con nuevos endpoints (`/products`, `/gondolas/:id/generar-tarea`, cambios en POST/PATCH/DELETE productos)
- [ ] Crear `ProductPickerModal.tsx`
- [ ] Crear `ProductFormModal.tsx`
- [ ] Crear `ProductCatalogPage.tsx` (o tab)
- [ ] Modificar `GondolaDetailModal` — Tab Productos: usar picker, manejar `product_id`, mostrar badge Legacy
- [ ] Modificar `GondolaDetailModal` — Upload foto: usar `product.id` (maestro)
- [ ] Modificar `CrearOrdenModal`: agregar toggle "Generar como tarea"
- [ ] Modificar `GondolasManagerTab`: agregar bot\u00f3n "Generar Tarea" en cards
- [ ] Modificar `GondolaRellenoPage`: usar `item.product?.name ?? item.nombre`, agregar select de unidad
- [ ] Crear hooks TanStack Query para productos y generar tarea
- [ ] Crear helpers `getProductDisplayName`, `getProductPhoto`, etc.
- [ ] Probar flujo completo: crear producto → vincular → generar tarea → completar → aprobar

---

### 9. Preguntas para Validar

1. \u00bfEl admin quiere ver el cat\u00e1logo de productos como **tab dentro de G\u00f3ndolas** o como **pantalla separada**?
2. \u00bfAl completar una orden, el empleado puede usar **cualquier unidad** o solo las predefinidas (`pz`, `caja`, `media_caja`, `kg`)?
3. \u00bfEl bot\u00f3n "Generar Tarea" reemplaza al "Crear Orden" o **coexiste** con \u00e9l?
