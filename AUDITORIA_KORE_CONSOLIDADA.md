# Auditoría de Seguridad y Calidad de Código — Kore Suite

**Fecha:** 2026-06-20  
**Proyectos analizados:**
1. `C:\Users\adanc\Desktop\Kore-laravel-backend-main` (Laravel 12 / PHP 8.2)
2. `C:\Users\adanc\Desktop\Kore-react-frontend-git` (React 19 / Vite / TypeScript)
3. `C:\Users\adanc\Desktop\Vacantes_Final\app` (React 19 / Vite / portal de vacantes)

**Metodología:** análisis estático + ejecución de linters/tests. No se modificó código.

---

## 1. Métricas de calidad rápidas

| Proyecto | Linter / Estilo | Resultado | Tests | Build |
|---|---|---|---|---|
| **Backend Laravel** | Laravel Pint (`pint --test`) | ❌ **336 archivos, 212 issues de estilo** | ✅ 14 passed (43 assertions) | No evaluado |
| **Frontend Kore** | ESLint (`npm run lint`) | ⚠️ **0 errores, 140 warnings** | ✅ 45 passed en 8 archivos | No evaluado |
| **Portal Vacantes** | ESLint (`npm run lint`) | ✅ 0 errores, 0 warnings | No tiene script `test` | ✅ Build exitoso |

> Nota: en la conversación anterior se mencionaban "~130 errores de lint". En el frontend Kore actual hay **140 warnings** (no errores); en el backend hay **212 issues de estilo con Laravel Pint**. El portal de vacantes está limpio de lint.

---

## 2. Hallazgos críticos 🔴

### 2.1 Frontend Kore — Claves de Firebase en el repositorio

**Archivos:** `Kore-react-frontend-git/.env` y `Kore-react-frontend-git/.env.production`

Se encontraron claves reales de Firebase en el árbol de trabajo **y en el historial de Git**:

```
VITE_FIREBASE_API_KEY=AIzaSyBSuXppFbXeEPsSrvHmeipEKotlc4Q7jDg
VITE_FIREBASE_AUTH_DOMAIN=kore-ops.firebaseapp.com
VITE_FIREBASE_PROJECT_ID=kore-ops
VITE_FIREBASE_STORAGE_BUCKET=kore-ops.firebasestorage.app
VITE_FIREBASE_MESSAGING_SENDER_ID=387072867680
VITE_FIREBASE_APP_ID=1:387072867680:web:a6b4d462e649fced607205
```

Commits que las contienen:
- `23493c7 Seguridad y Sesion caducada`
- `8799086 Hide zero-quantity products...`
- `55c3f40 feat: add push notifications...`
- `7c79e79 Initial commit...`

**Riesgo:** cualquier persona con acceso al repositorio (actual o histórico) puede usar estas claves. Aunque las API keys de Firebase web son públicas por diseño, su presencia en Git impide rotarlas limpiamente y facilita abuso de cuota/storage/cloud messaging.

**Acción inmediata:**
1. Rotar **todas** las claves en Firebase Console.
2. Limpiar el historial con `git filter-repo` o BFG Repo-Cleaner.
3. Agregar `.env*` a `.gitignore` y nunca committar archivos `.env`.
4. Usar `.env.example` sin valores reales.

---

### 2.2 Backend — Documentos de aspirantes en disco público

**Archivo:** `app/Http/Controllers/Api/V1/ApplicationController.php` (~líneas 187-189, 240-260)

Cuando S3 no está configurado, los documentos de aspirantes (INE, CURP, RFC, NSS, acta de nacimiento, comprobante de domicilio) se almacenan en el disco `public` y se sirven con `Storage::disk('public')->url()`.

**Riesgo:** exposición masiva de PII a cualquiera que conozca o filtre la URL.

**Acción:** usar disco privado (`local` o S3 con ACL privado) y generar URLs firmadas (`temporaryUrl`) para descargas.

---

### 2.3 Backend — Contraseñas temporales en texto plano

**Archivos:**
- `app/Http/Controllers/Api/V1/UsersController.php:118`
- `app/Jobs/SendWelcomeEmail.php:23-25`
- `app/Mail/BienvenidaEmpleado.php:24`

La contraseña temporal de nuevos empleados se envía por correo en texto plano y se serializa en el payload del job.

**Riesgo:** fuga de credenciales si se registran logs de correo, colas o fallos.

**Acción:** enviar un enlace de activación/restablecimiento en lugar de la contraseña en claro; nunca persistirla en jobs.

---

### 2.4 Portal Vacantes — Token en `localStorage` y devuelto en URL

**Archivos:**
- `Vacantes_Final/app/src/hooks/useAuth.ts:47`
- `Vacantes_Final/app/src/lib/http.ts:16-18`
- `Vacantes_Final/app/src/pages/GoogleCallback.tsx:7-46`

El token de sesión (`portal_token`) se almacena en `localStorage` y el backend lo devuelve en el fragmento de URL (`#token=...&user=...`).

**Riesgo:** robo masivo de sesiones ante XSS/extensiones, y exposición en historial del navegador/logs.

**Acción:** mover autenticación a cookie `HttpOnly; Secure; SameSite=Lax/Strict` gestionada por el backend; nunca devolver tokens en URL.

---

### 2.5 Portal Vacantes — Lógica de negocio en el cliente

**Archivos:**
- `Vacantes_Final/app/src/pages/Expediente.tsx:62-89`, `160-226`, `377-379`
- `Vacantes_Final/app/src/pages/Dashboard.tsx:61-100`
- `Vacantes_Final/app/src/pages/Autoevaluacion.tsx:212-233`, `242-279`

El progreso del expediente y la metadata de documentos se guardan en `localStorage` y la UI confía en ellos para marcar pasos completados. La autoevaluación se califica localmente y luego se envía al backend.

**Riesgo:** un candidato puede manipular el navegador para saltarse etapas o enviar cualquier score.

**Acción:** el backend debe ser la única fuente de verdad para progreso, documentos y calificación de exámenes.

---

## 3. Hallazgos altos 🟠

### Backend

| # | Problema | Archivo(s) | Recomendación |
|---|---|---|---|
| 1 | Registro público sin verificación de correo ni CAPTCHA | `routes/api.php:93`, `RegisterController.php` | Agregar verificación de correo y CAPTCHA; endurecer throttle |
| 2 | Login sin bloqueo de cuenta tras intentos fallidos | `routes/api.php:98`, `AuthController.php:13-30` | Implementar bloqueo progresivo y notificación de intentos sospechosos |
| 3 | Evidencias pueden quedar en disco público | `EvidencesController.php:29, 147-173` | Forzar disco privado, URLs firmadas, validar mimetype real |
| 4 | Documentos de empresa con URL pública S3 | `EmpresaDocumentosController.php:47-52, 86-114` | Bucket privado + `temporaryUrl`; no guardar URLs públicas en BD |
| 5 | Restricción por IP usa `$request->ip()` sin proxies confiables | `AttendanceControllerV2.php:1141-1153` | Configurar `TrustProxies` antes de validar IPs |
| 6 | `hireTrial` puede sobreescribir datos de tenant | `ApplicationController.php:518-590` | Validar que el usuario no tenga empleado activo en otra empresa |
| 7 | Tokens Sanctum de 30 días | `config/sanctum.php:15` | Reducir TTL e implementar refresh tokens |

### Frontend Kore

| # | Problema | Archivo(s) | Recomendación |
|---|---|---|---|
| 1 | Token Bearer de 24h sin refresh token | `authStore.ts:35` | Implementar `/auth/refresh` y renovación silenciosa |
| 2 | Token en `sessionStorage` vulnerable a XSS | `authStore.ts:72-86` | Evaluar cookies `HttpOnly; Secure; SameSite=Strict` |
| 3 | URLs de backend hardcodeadas | `src/lib/http.ts:6`, `vite.config.ts:55` | Usar exclusivamente `import.meta.env.VITE_API_URL` |
| 4 | 117 usos de `any` | Múltiples, especialmente `attendance`, `bitacora`, `maderas` | Crear tipos; eliminar override legacy de ESLint |
| 5 | 13 archivos con `setState` en `useEffect` | `ManagerAttendancePage`, `EntryRow`, `AvailableTasksPanel`, etc. | Inicializar estado derivado en render o usar event handlers |
| 6 | 528/537 botones sin `type="button"` | Todo `src/` | Agregar `type="button"` a botones no-submit |
| 7 | `console.log`/`console.error` en producción | `EmployeeTaskExecution.tsx:82`, reclutamiento, etc. | Eliminar logs de desarrollo; usar logging sanitizado |
| 8 | Cobertura de tests muy baja | 8 archivos, 45 tests | Aumentar tests de integración en flujos críticos |

### Portal Vacantes

| # | Problema | Archivo(s) | Recomendación |
|---|---|---|---|
| 1 | Falta `state` en OAuth (CSRF) | `src/pages/Login.tsx:25-28` | Generar/validar `state` criptográfico; considerar PKCE |
| 2 | Validación de archivos solo por MIME del cliente | `src/pages/Expediente.tsx:250-289` | Revalidar en backend: extensión, magic bytes, tamaño, antivirus |
| 3 | Sin CSP ni headers de seguridad | `index.html`, `vite.config.ts` | Configurar CSP estricta y headers de seguridad en Vercel/nginx |
| 4 | Fallback productivo hardcodeado | `src/lib/http.ts:3`, `src/pages/Login.tsx:23` | Fallar en build si `VITE_API_URL` no está definida |
| 5 | Logout no invalida sesión en backend | `src/hooks/useAuth.ts:52-58` | Llamar a endpoint de logout/revoke |
| 6 | Plugin de inspección en build productivo | `vite.config.ts:1-15` (`plugin-inspect-react-code`) | Condicionar a modo desarrollo o remover |

---

## 4. Observaciones positivas ✅

### Backend
- Autorización centralizada con Gates y policies.
- Hashing de contraseñas con cast `hashed` en modelo `User`.
- Prevención de timing attacks en login (`Hash::check` contra hash dummy).
- Soft deletes en modelos clave.
- Headers de seguridad (`SecurityHeaders` middleware) con CSP, HSTS, X-Frame-Options.
- OAuth seguro con Socialite stateful bajo middleware `web`.
- No se detectaron inyecciones SQL explotables ni `env()` en controladores/modelos.

### Frontend Kore
- Arquitectura moderna con lazy loading, guards y manejo de errores de chunk.
- No se detectó `dangerouslySetInnerHTML`; existe `src/lib/sanitize.tsx` con DOMPurify.
- Login tiene buenas prácticas de a11y (`role="alert"`, focus, labels).
- 45 tests pasan.

### Portal Vacantes
- Build y lint limpios.
- No hay `console.log` de debug.
- No se generan source maps (menor exposición de código fuente).

---

## 5. Plan de acción recomendado

### Inmediato (esta semana)
1. **Rotar claves de Firebase** y limpiar historial de Git en frontend Kore.
2. **Mover documentos de aspirantes y evidencias** a almacenamiento privado con URLs firmadas en backend.
3. **Eliminar contraseñas temporales en texto plano**; usar enlaces de activación.
4. **Cambiar autenticación del portal de vacantes** a cookies `HttpOnly` y no devolver tokens en URL.
5. **Hacer que backend sea fuente de verdad** para progreso de expediente y calificación de autoevaluación.

### Corto plazo (2-4 semanas)
1. Implementar refresh tokens en frontend Kore.
2. Endurecer registro/login (verificación de correo, CAPTCHA, bloqueo progresivo).
3. Corregir 140 warnings de ESLint (especialmente `any`, `setState` en effects, `exhaustive-deps`).
4. Corregir 212 issues de Laravel Pint (correr `pint` sin `--test`).
5. Revalidar subida de archivos en backend.
6. Agregar CSP y headers de seguridad al portal de vacantes.

### Mediano plazo
1. Aumentar cobertura de tests en frontend y backend.
2. Migrar tokens a cookies `HttpOnly` en frontend Kore.
3. Auditoría de endpoints del portal (`/portal/apply`, `/portal/applications/*`, OAuth callback).
4. Implementar recuperación de contraseña para admins.

---

## 6. Notas sobre el conteo de errores

- **Frontend Kore:** `npm run lint` reporta **140 problems (0 errors, 140 warnings)**. No bloquean build pero son deuda técnica y algunos (React Hooks) pueden causar bugs sutiles.
- **Backend:** `vendor/bin/pint --test` reporta **336 archivos revisados, 212 style issues**. Son errores de estilo/ formato que Pint puede corregir automáticamente.
- **Portal Vacantes:** `npm run lint` reporta **0 errores, 0 warnings**.

Si el usuario desea, se puede ejecutar `pint` sin `--test` para autofix del backend, y corregir los warnings de ESLint del frontend de forma progresiva.
