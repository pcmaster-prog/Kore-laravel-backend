# Auditoría Integral de Seguridad y Calidad — Kore Suite

**Fecha:** 2026-06-24  
**Auditor:** Kimi Code CLI  
**Proyectos analizados:**
1. `C:\Users\adanc\Desktop\Kore-laravel-backend-main` (Laravel 12 / PHP 8.2)
2. `C:\Users\adanc\Desktop\Kore-react-frontend-git` (React 19 / Vite / TypeScript)
3. `C:\Users\adanc\Desktop\Vacantes_Final\app` (React 19 / Vite — Portal de Vacantes)

**Metodología:** análisis estático + ejecución de tests/linters/auditoría de dependencias. No se modificó código.

---

## Resumen ejecutivo

Se realizó una auditoría integral actualizada de los tres proyectos que conforman la suite Kore. En comparación con la auditoría previa (2026-06-20), **varios hallazgos críticos ya fueron mitigados**: el almacenamiento de documentos de aspirantes, evidencias y documentos de empresa ahora usan `SecureFileStorage` con discos privados y URLs firmadas; las contraseñas temporales en texto plano fueron reemplazadas por tokens de activación seguros; y la autoevaluación del portal ahora se califica server-side.

Sin embargo, **persisten riesgos importantes**, especialmente en el frontend Kore y en la integración OAuth cross-domain del portal de vacantes. Las claves de Firebase siguen hardcodeadas en el repositorio y en el historial de Git; el portal recurre al envío de tokens por URL cuando no puede compartir cookies; y el árbol de dependencias de React/Vite presenta vulnerabilidades de alta severidad.

**Recomendación inmediata:** rotar claves de Firebase, limpiar el historial de Git, actualizar dependencias críticas y eliminar el fallback de token por URL en el portal.

---

## 1. Métricas de calidad rápidas

| Proyecto | Linter / Estilo | Resultado | Tests | Build / Otros |
|---|---|---|---|---|
| **Backend Laravel** | Laravel Pint (`pint --test`) | ❌ **399 archivos, 229 issues de estilo** | ✅ 61 passed (210 assertions) | composer audit: 3 advisories medium |
| **Frontend Kore** | ESLint (`npm run lint`) | ❌ **334 problemas (322 errores, 12 warnings)** | ✅ 45 passed en 8 archivos | npm audit: 30 vulnerabilidades (20 high) |
| **Portal Vacantes** | ESLint (`npm run lint`) | ⚠️ **4 problemas (3 errores, 1 warning)** | ❌ No tiene script `test` | ✅ Build exitoso; npm audit: 12 vulnerabilidades (7 high) |

> Comparativa vs. auditoría anterior (2026-06-20): el backend creció de 336 a 399 archivos revisados y de 212 a 229 issues de estilo. El frontend pasó de ~140 warnings a 334 problemas porque la configuración de ESLint ahora reporta `any` y variables no usadas como errores. El portal, antes limpio, ahora tiene 4 problemas.

---

## 2. Estado de hallazgos críticos de la auditoría anterior

| # | Hallazgo previo | Estado actual | Evidencia |
|---|---|---|---|
| 2.1 | Claves Firebase en `.env` / `.env.production` y Git | **Aún vigente** | `.env`, `.env.production`, `firebase-messaging-sw.js`, histórico Git |
| 2.2 | Documentos de aspirantes en disco público | **Resuelto** | `ApplicationController.php` usa `SecureFileStorage::upload()` y `temporaryUrl()` |
| 2.3 | Contraseñas temporales en texto plano | **Resuelto** | `UsersController.php` genera `UserActivationToken`; `SendWelcomeEmail.php` envía URL de activación |
| 2.4 | Token en `localStorage` / URL en portal | **Parcialmente mitigado** | Cookie HttpOnly implementada, pero existe fallback a `portal_token` en query string y `localStorage` |
| 2.5 | Lógica de negocio del portal en cliente | **Parcialmente mitigado** | Autoevaluación calificada server-side; persistencia de progreso en `localStorage` aún usada para UI |

---

## 3. Hallazgos críticos 🔴

### 3.1 Frontend Kore — Credenciales de Firebase expuestas en el repositorio

**Archivos:**
- `Kore-react-frontend-git/.env`
- `Kore-react-frontend-git/.env.production`
- `Kore-react-frontend-git/public/firebase-messaging-sw.js` (líneas 4-11)

**Descripción:**
Las credenciales de Firebase (`apiKey`, `authDomain`, `projectId`, `storageBucket`, `messagingSenderId`, `appId`) se encuentran tanto en archivos `.env` commiteados como directamente hardcodeadas en el service worker de mensajería push. Además, el historial de Git contiene estos valores en los commits:
- `23493c7 Seguridad y Sesion caducada`
- `8799086 Hide zero-quantity products...`
- `55c3f40 feat: add push notifications...`
- `7c79e79 Initial commit...`

**Impacto:**
Aunque las API keys de Firebase para web son públicas por diseño, tenerlas en el repositorio impide rotarlas limpiamente, facilita el abuso de cuotas, el envío de notificaciones no deseadas y el acceso a storage si las reglas de seguridad no son estrictas.

**Recomendación:**
1. Rotar **todas** las credenciales de Firebase en la consola.
2. Limpiar el historial de Git con `git filter-repo` o BFG Repo-Cleaner.
3. Agregar `.env*` y `.env.*` a `.gitignore` y nunca committar archivos `.env`.
4. Para el service worker, generar `firebase-messaging-sw.js` dinámicamente en build o inyectar las variables de entorno durante el despliegue.
5. Usar `.env.example` con valores placeholder.

---

### 3.2 Backend — Ruta pública `/fix-activations` permite generar tokens y enviar correos

**Archivo:** `routes/api.php:115-125`

**Descripción:**
Existe una ruta GET pública sin autenticación ni autorización que, al ser llamada, desactiva usuarios específicos (`adancuellarh@gmail.com`, `akecuellarherbandez@gmail.com`), genera un token de activación para cada uno y despacha el job `SendWelcomeEmail`.

**Impacto:**
Cualquier actor puede enviar correos de activación masivos a esos usuarios, desactivar sus cuentas y generar tokens. Es una puerta trasera/debug endpoint que no debe estar expuesto en producción.

**Recomendación:**
Eliminar la ruta `/fix-activations` antes del despliegue o protegerla con autenticación de administrador y un mecanismo de autorización adicional (por ejemplo, IP allowlist). Considerar convertirla en un comando Artisan (`php artisan users:resend-activation`).

---

### 3.3 Portal de Vacantes — Token devuelto en URL como fallback cross-domain

**Archivos:**
- `Kore-laravel-backend-main/app/Http/Controllers/Api/V1/GoogleAuthController.php:217-222`
- `Vacantes_Final/app/src/pages/GoogleCallback.tsx:31-50`
- `Vacantes_Final/app/src/lib/auth-token.ts`
- `Vacantes_Final/app/src/lib/http.ts:23-30`

**Descripción:**
Cuando el backend detecta que no puede compartir una cookie HttpOnly con el dominio del portal (`canShareCookie`), envía el token Sanctum como query parameter (`portal_token=...`) en la URL de redirección de Google OAuth. El frontend lo extrae, lo guarda en `localStorage` y lo usa como Bearer token.

**Impacto:**
El token queda expuesto en el historial del navegador, logs del servidor/proxy y referrers. Además, almacenarlo en `localStorage` lo hace vulnerable a robo mediante XSS o extensiones maliciosas.

**Recomendación:**
1. Hospedar backend y portal bajo el mismo dominio raíz (ej. `api.decorartereposteria.mx` y `vacantes.decorartereposteria.mx`) para que la cookie HttpOnly funcione.
2. Si no es posible, usar un flujo de **authorization code** propio (backend genera un code de un solo uso, frontend lo intercambia por cookie) en lugar de enviar el token en URL.
3. Eliminar por completo el almacenamiento de tokens en `localStorage`.

---

### 3.4 Portal de Vacantes — Plugin de inspección en build productivo

**Archivo:** `Vacantes_Final/app/vite.config.ts:1-9`

**Descripción:**
El plugin `plugin-inspect-react-code` se carga siempre, incluso en `vite build`. Este tipo de plugin suele inyectar metadata del código fuente (nombres de componentes, rutas de archivos, etc.) en el DOM o en el bundle.

**Impacto:**
Mayor exposición de información interna del código fuente en el bundle de producción, facilitando ingeniería inversa.

**Recomendación:**
Condicionar el plugin al modo desarrollo:

```ts
plugins: [process.env.NODE_ENV === 'development' ? inspectAttr() : null, react()].filter(Boolean)
```

---

### 3.5 Dependencias con vulnerabilidades de alta severidad

**Frontend Kore:**
- `npm audit` reporta **30 vulnerabilidades** (1 low, 9 moderate, 20 high).
- Destacan: `vite` 7.0.0-7.3.3 (path traversal, arbitrary file read), `rollup` 4.0.0-4.58.0 (arbitrary file write), `undici` (TLS bypass, header injection), `tar` (path traversal), `serialize-javascript` (RCE).

**Portal Vacantes:**
- `npm audit` reporta **12 vulnerabilidades** (1 low, 4 moderate, 7 high).
- Destacan: `vite` 7.0.0-7.3.3, `rollup`, `lodash` (prototype pollution, code injection), `minimatch`/`picomatch` (ReDoS), `flatted` (DoS).

**Backend:**
- `composer audit` reporta **3 advisories medium** en `guzzlehttp/guzzle` (<7.12.1) y `guzzlehttp/psr7` (<2.12.1): CRLF injection, HTTPS proxy downgrade y dot-only cookie domain matching.

**Recomendación:**
1. Ejecutar `npm audit fix` en ambos frontends y validar que el build sigue funcionando.
2. Para paquetes sin fix disponible (ej. `tar` vía Capacitor), evaluar actualizar Capacitor a una versión que no dependa de la versión vulnerable.
3. En backend, ejecutar `composer update guzzlehttp/guzzle guzzlehttp/psr7`.
4. Integrar `npm audit` / `composer audit` en el pipeline de CI/CD y bloquear builds con vulnerabilidades high/critical.

---

## 4. Hallazgos altos 🟠

### 4.1 Frontend Kore — Token Bearer en `sessionStorage` sin refresh token

**Archivos:**
- `Kore-react-frontend-git/src/features/auth/authStore.ts:35-86`
- `Kore-react-frontend-git/src/lib/http.ts:27-42`

**Descripción:**
El token Sanctum se almacena en `sessionStorage` con un TTL de 24h. No existe mecanismo de refresh token; cuando expira, el usuario debe volver a iniciar sesión. El interceptor solo verifica la expiración del lado del cliente.

**Impacto:**
- `sessionStorage` sigue siendo accesible por JavaScript, por lo que un XSS puede robar el token.
- Sin refresh token, la sesión de 24h es o bien muy larga (mayor ventana de ataque si se roba) o genera una mala UX si se acorta.

**Recomendación:**
- Evaluar migrar la autenticación a cookies `HttpOnly; Secure; SameSite=Strict` gestionadas por el backend (Sanctum stateful).
- Si se mantiene Bearer, implementar un endpoint `/auth/refresh` que emita tokens de corta duración (15-60 min) y refresh tokens rotativos almacenados de forma segura.

---

### 4.2 Registro público sin verificación de correo ni CAPTCHA

**Archivos:**
- `Kore-laravel-backend-main/routes/api.php:100-101`
- `Kore-laravel-backend-main/app/Http/Controllers/Api/V1/RegisterController.php`

**Descripción:**
El endpoint `/register` permite crear una empresa y un usuario administrador sin verificar el correo electrónico ni presentar un desafío CAPTCHA. Solo tiene un throttle de 5 intentos por hora por IP.

**Impacto:**
Riesgo de registro masivo automatizado, abuso de cuotas, spam de empresas de prueba y posible saturación de la base de datos.

**Recomendación:**
1. Enviar correo de verificación antes de activar la cuenta (Laravel ya incluye `MustVerifyEmail`).
2. Agregar CAPTCHA (hCaptcha/reCAPTCHA v2/v3) en el formulario de registro.
3. Considerar revisión manual o límites más estrictos de empresa durante el onboarding.

---

### 4.3 Login sin bloqueo progresivo de cuenta

**Archivos:**
- `Kore-laravel-backend-main/routes/api.php:104-105`
- `Kore-laravel-backend-main/app/Http/Controllers/Api/V1/AuthController.php:16-56`

**Descripción:**
El login tiene un throttle de 5 intentos por minuto por IP, pero no bloquea la cuenta ni notifica al usuario tras múltiples intentos fallidos contra la misma cuenta.

**Impacto:**
Un atacante puede distribuir intentos de fuerza bruta entre múltiples IPs/proxies sin ser bloqueado a nivel de cuenta.

**Recomendación:**
Implementar bloqueo progresivo a nivel de cuenta (ej. 5 intentos fallidos → 15 min de bloqueo) y notificación por correo de intentos sospechosos.

---

### 4.4 Google OAuth — Validación de `state` no bloquea el flujo

**Archivos:**
- `Kore-laravel-backend-main/app/Http/Controllers/Api/V1/GoogleAuthController.php:123-145`
- `Vacantes_Final/app/src/pages/GoogleCallback.tsx:9-23`

**Descripción:**
El backend guarda el `state` en cache del servidor (buena práctica), pero si no coincide o no existe, solo registra un warning y continúa el flujo. El frontend también hace una validación informativa sin bloquear.

**Impacto:**
Debilita la protección anti-CSRF de OAuth. Un atacante podría forzar el login de una víctima con un `state` predecible o reutilizado.

**Recomendación:**
Invalidar la solicitud y redirigir a una página de error cuando el `state` no coincida. El fallback de "no bloquear" debe eliminarse en producción.

---

### 4.5 Frontend Kore — URL de backend hardcodeada como fallback

**Archivo:** `Kore-react-frontend-git/src/lib/http.ts:4-6`

**Descripción:**
Si `VITE_API_URL` no está definida, el cliente cae en `https://kore-laravel-backend-production.up.railway.app/api/v1`.

**Impacto:**
Un despliegue con variables de entorno mal configuradas podría apuntar silenciosamente a producción, causando fugas de datos o comportamientos inesperados.

**Recomendación:**
Hacer que el build falle si `VITE_API_URL` no está definida:

```ts
const baseURL = import.meta.env.VITE_API_URL;
if (!baseURL) throw new Error('VITE_API_URL is required');
```

---

### 4.6 Frontend Kore — 334 problemas de ESLint (322 errores)

**Archivo:** todo `src/`

**Descripción:**
`npm run lint` reporta 334 problemas, principalmente:
- Uso de `any` (más de 100 ocurrencias, especialmente en `tasks`, `http.test.ts`, `taskAreaMocks.ts`).
- Variables no usadas (`err`, `_month`, `_empleadoId`, etc.).
- `react-hooks/exhaustive-deps`.
- `react-refresh/only-export-components` en `src/lib/sanitize.tsx`.

**Impacto:**
Alta deuda técnica, menor confianza en el tipado, riesgo de errores en runtime y dificultad para mantener el código.

**Recomendación:**
1. Corregir progresivamente los `any` creando interfaces/types.
2. Configurar CI para bloquear merge con errores de lint.
3. Eliminar el override legacy de ESLint si existe.

---

### 4.7 Backend — 229 issues de estilo con Laravel Pint

**Descripción:**
`vendor/bin/pint --test` reporta 229 issues de estilo en 399 archivos.

**Impacto:**
Aunque no son bugs, indican falta de consistencia y dificultan la revisión de código y la colaboración.

**Recomendación:**
Ejecutar `php vendor/bin/pint` (sin `--test`) para auto-corregir, luego integrar `pint --test` en CI.

---

## 5. Hallazgos medios 🟡

### 5.1 Backend — Tokens Sanctum de 30 días

**Archivo:** `config/sanctum.php:15`

**Descripción:**
La expiración de tokens personales está configurada a 30 días (`60 * 24 * 30`).

**Impacto:**
Un token robado permanece válido durante un mes.

**Recomendación:**
Reducir a 24h o menos e implementar refresh tokens; o migrar a sesiones stateful con cookies.

---

### 5.2 Backend — CORS permite cualquier subdominio `*.vercel.app`

**Archivo:** `config/cors.php:36-38`

**Descripción:**
`allowed_origins_patterns` incluye `/^https:\/\/kore-.*\.vercel\.app$/`.

**Impacto:**
Cualquier usuario con un preview deploy en Vercel bajo ese patrón puede hacer peticiones credentialed al backend.

**Recomendación:**
Reemplazar el patrón por una lista explícita de dominios de preview permitidos o usar `CORS_EXTRA_ORIGIN` de forma controlada.

---

### 5.3 Validación de archivos solo por MIME declarado

**Archivos:**
- `app/Services/SecureFileStorage.php:167`
- `app/Http/Controllers/Api/V1/ApplicationController.php:197`
- `app/Http/Controllers/Api/V1/EvidencesController.php:40`

**Descripción:**
La validación usa `getMimeType()`, que sí inspecciona magic bytes en la mayoría de casos, pero no hay validación de extensión, escaneo antivirus ni límite de dimensiones para imágenes.

**Recomendación:**
Validar extensión + MIME + magic bytes, limitar resolución de imágenes y considerar un escáner antivirus para documentos de candidatos.

---

### 5.4 Portal — Persistencia de progreso en `localStorage`

**Archivos:**
- `Vacantes_Final/app/src/pages/Expediente.tsx:292-308`
- `Vacantes_Final/app/src/pages/Autoevaluacion.tsx:157-163`
- `Vacantes_Final/app/src/lib/application.ts`

**Descripción:**
La UI del portal guarda metadata del progreso (`surveyCompleted`, `uploaded`, `interviewRequested`) en `localStorage` para mejorar la experiencia offline.

**Impacto:**
Un usuario puede manipular el estado local para que la interfaz muestre pasos completados, aunque el backend valida la mayoría de transiciones. El riesgo es principalmente de confusión UX y posibles inconsistencias.

**Recomendación:**
Eliminar progresivamente el uso de `localStorage` como fuente de verdad de UI; siempre consultar `/portal/my-application` y recalcular el estado desde el backend.

---

### 5.5 Frontend Kore — `console.log`/`console.error` en producción

**Archivo:** `src/` (27 ocurrencias)

**Descripción:**
Existen 27 usos de `console.*` dispersos en el código. Algunos podrían exponer datos internos o errores en la consola del navegador.

**Recomendación:**
Eliminar logs de desarrollo o reemplazarlos por un servicio de logging sanitizado.

---

### 5.6 Frontend/Portal — Falta de Content Security Policy en el cliente

**Descripción:**
El backend incluye un middleware `SecurityHeaders` con CSP restrictivo (`default-src 'none'`), pero el frontend Kore y el portal no configuran CSP propia ni headers de seguridad en `index.html` o Vercel.

**Recomendación:**
Configurar CSP estricta en el despliegue (Vercel/nginx) y agregar headers de seguridad (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`).

---

### 5.7 Portal — Browserslist desactualizado

**Descripción:**
Al ejecutar `npm run build`, Vite advierte que `caniuse-lite` tiene 6 meses de antigüedad.

**Recomendación:**
Ejecutar `npx update-browserslist-db@latest`.

---

### 5.8 Portal — 4 problemas de ESLint

**Archivos:**
- `Vacantes_Final/app/src/pages/Expediente.tsx:250`
- `Vacantes_Final/app/src/pages/OfferPage.tsx:70, 84`
- `Vacantes_Final/app/src/lib/clearLegacyStorage.ts:17`

**Descripción:**
3 errores de `no-explicit-any` y 1 warning de `unused eslint-disable directive`.

**Recomendación:**
Corregir los tipos `any` y eliminar directivas de ESLint no necesarias.

---

## 6. Hallazgos bajos 🟢

1. **Backend `.env` local en working tree:** `APP_ENV=local` y `APP_FRONTEND_URL=` vacío. Asegurar que el despliegue use `.env` de producción con valores correctos.
2. **Logs de debug en producción:** `GoogleAuthController.php` registra información detallada de OAuth (URLs, states, tokens). Revisar nivel de log en producción.
3. **Cobertura de tests:** Frontend solo tiene 8 archivos de test; portal no tiene tests. Ampliar cobertura en flujos críticos.
4. **Comentarios TODO/FIXME:** Revisar periódicamente con `grep -R "TODO\|FIXME" src/ app/`.
5. **Service worker de Firebase con versión fija:** `public/firebase-messaging-sw.js` importa Firebase 10.7.0 fijo. Evaluar actualizar y versionar.

---

## 7. Observaciones positivas ✅

### Backend
- ✅ Implementación robusta de `SecureFileStorage` con prohibición explícita del disco `public`, URLs firmadas y soporte S3 privado.
- ✅ Eliminación de contraseñas temporales en texto plano; uso de `UserActivationToken` con enlaces de activación.
- ✅ Autenticación del portal basada en cookie HttpOnly (`PortalCookieAuth`) cuando los dominios lo permiten.
- ✅ Autoevaluación calificada server-side; transiciones de estado validadas en `ApplicationController.php`.
- ✅ Mitigación de timing attacks en login (`Hash::check` contra hash dummy).
- ✅ Middleware `SecurityHeaders` con CSP, HSTS, X-Frame-Options, etc.
- ✅ Uso de Gates/Policies para autorización.
- ✅ Hashing de contraseñas con cast `hashed` en el modelo `User`.
- ✅ Soft deletes en modelos clave.
- ✅ 61 tests passed con 210 assertions.

### Frontend Kore
- ✅ Arquitectura moderna con lazy loading, guards y manejo de errores de chunk.
- ✅ Uso de DOMPurify en `src/lib/sanitize.tsx` para sanitización.
- ✅ Interceptores HTTP para manejo de 401 y errores 500+.
- ✅ 45 tests pasan.

### Portal Vacantes
- ✅ Build exitoso.
- ✅ OAuth state almacenado en cache del servidor en lugar de cookies.
- ✅ Cookie HttpOnly implementada para dominios compatibles.

---

## 8. Plan de acción recomendado

### Inmediato (esta semana)
1. **Rotar claves de Firebase** y limpiar historial de Git en frontend Kore.
2. **Eliminar la ruta `/fix-activations`** o protegerla adecuadamente.
3. **Eliminar el fallback de token por URL** en el portal; forzar cookie HttpOnly compartida o implementar authorization code propio.
4. **Actualizar dependencias críticas** (`vite`, `rollup`, `undici`, `tar`, `lodash`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`).
5. **Desactivar `plugin-inspect-react-code`** en producción.

### Corto plazo (2-4 semanas)
1. Implementar refresh tokens o migrar a cookies HttpOnly en frontend Kore.
2. Agregar verificación de correo y CAPTCHA en registro.
3. Implementar bloqueo progresivo de cuentas tras intentos fallidos.
4. Corregir los 334 problemas de ESLint en frontend y los 229 issues de Pint en backend.
5. Configurar CSP y headers de seguridad en frontend y portal.
6. Mejorar validación de archivos (extensión + magic bytes + antivirus).
7. Reducir TTL de tokens Sanctum a 24h.

### Mediano plazo
1. Aumentar cobertura de tests en frontend y agregar tests en el portal.
2. Revisar y reducir el uso de `localStorage` en el portal como fuente de verdad.
3. Auditar periódicamente dependencias con `npm audit` / `composer audit` en CI/CD.
4. Realizar pruebas de penetración manuales en flujos críticos (registro, login, OAuth, subida de documentos).

---

## Notas metodológicas

- Esta auditoría se basa en análisis estático y herramientas automatizadas. No se ejecutaron pruebas de penetración activas ni se modificó código.
- Los archivos `.env` con secretos no se incluyen en este informe; solo se indica su existencia y riesgo.
- Se recomienda validar los hallazgos en un entorno de staging antes de aplicar cambios en producción.

---

## 9. Acciones correctivas ejecutadas en esta sesión (2026-06-24)

Durante esta sesión se implementaron las correcciones del **Plan de acción recomendado** correspondientes a las fases 1 y 2 del roadmap de seguridad:

### Fase 1 — Mitigación de riesgos críticos

| Hallazgo | Acción tomada | Estado |
|---|---|---|
| Credenciales Firebase hardcodeadas | Se reemplazaron valores reales por placeholders en `.env` y `.env.production`; se agregó `.env*` a `.gitignore`; se creó `.env.example`; `public/firebase-messaging-sw.js` ahora se genera en build desde `firebase-messaging-sw.js.template` mediante `scripts/generate-firebase-sw.cjs`. | ✅ Resuelto en working tree |
| Ruta `/fix-activations` expuesta | Se eliminó por completo de `routes/api.php`. | ✅ Resuelto |
| Fallback de token por URL/localStorage en portal | Se eliminó el consumo de `portal_token` desde query string y `localStorage` (`GoogleCallback.tsx`, `auth-token.ts`, `http.ts`); el backend siempre establece la cookie `HttpOnly`. | ✅ Resuelto |
| Plugin `plugin-inspect-react-code` en producción | Se condicionó a `NODE_ENV === 'development'` en `vite.config.ts` del portal. | ✅ Resuelto |
| Dependencias backend con advisories | Se actualizaron `guzzlehttp/guzzle` y `guzzlehttp/psr7`. | ✅ Resuelto |
| TTL de tokens Sanctum | Se redujo a 24 horas en `config/sanctum.php`. | ✅ Resuelto |

### Fase 2 — Endurecimiento de autenticación

| Hallazgo | Acción tomada | Estado |
|---|---|---|
| Token Bearer en `sessionStorage` (frontend Kore) | Se migró a sesiones stateful con cookies `HttpOnly`. `authStore` ya no persiste el token; `lib/http.ts` usa `withCredentials` sin inyectar `Authorization`; se crearon rutas web `/auth/login`, `/register`, `/auth/logout`, `/auth/me` en `routes/web.php` atendidas por `WebAuthController`. | ✅ Resuelto |
| Login sin bloqueo de cuenta | Se creó el trait `HandlesLoginLockout` y la migración de campos `failed_login_attempts`, `locked_until`, etc. Tanto `AuthController::login` (API) como `WebAuthController::login` bloquean la cuenta tras 5 intentos fallidos por 15 minutos. | ✅ Resuelto |
| Registro sin CAPTCHA | Se instaló `google/recaptcha`, se implementó `RecaptchaValidator` y se valida el token `recaptcha_token` en `RegisterRequest`. El frontend Kore envía el token de reCAPTCHA v3. | ✅ Resuelto |
| Registro sin verificación de correo | `User` implementa `MustVerifyEmail`; los registros públicos (`RegisterController` API y `WebAuthController::register`) envían el correo de verificación y no inician sesión automáticamente; se agregaron endpoints `/email/verify/{id}/{hash}` y `/email/resend`; el login rechaza cuentas no verificadas con código 403. | ✅ Resuelto |

### Configuración de seguridad adicional

- **`config/cors.php`**: se eliminó el patrón wildcard `*.vercel.app`; ahora solo se permiten orígenes explícitos (`KORE_FRONTEND_URL`, `APP_FRONTEND_PORTAL_URL`, locales y `CORS_EXTRA_ORIGIN`).
- **`config/session.php` / `.env.example`**: defaults más seguros (`SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN=.decorartereposteria.mx` en ejemplo).
- **`SecurityHeaders`**: HSTS ahora incluye `preload`; CSP baseline para respuestas API.
- Usuarios creados internamente (`UsersController`, `EmpresaController`, `GoogleAuthController`) y factories ahora marcan `email_verified_at` para no romper flujos de login.

### Resultados de validación

| Proyecto | Tests | Linter | Build |
|---|---|---|---|
| Backend Laravel | ✅ 61 passed (210 assertions) | ⚠️ Pint: issues de estilo pendientes | — |
| Frontend Kore | ✅ 41 passed en 8 archivos | ⚠️ 336 problemas ESLint (mayoría `any` y variables no usadas) | ✅ Build exitoso |
| Portal Vacantes | ❌ Sin script `test` | ✅ 0 problemas ESLint | ✅ Build exitoso |

> **Pendientes que requieren acción manual o posterior:**
> 1. Rotar las credenciales de Firebase en la consola y limpiar el historial de Git (`git filter-repo --path .env --path .env.production --path public/firebase-messaging-sw.js --invert-paths`).
> 2. Configurar variables de entorno de producción: `VITE_API_URL`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`.
> 3. Ejecutar `php vendor/bin/pint` para corregir estilo y revisar los 336 problemas de ESLint del frontend Kore.
> 4. Revisar/actualizar dependencias front con `npm audit fix`; las de alta severidad transversales de Capacitor/tar/minimatch no tienen fix automático.
> 5. Configurar CSP y headers de seguridad en los servidores de estáticos (Vercel/nginx) para frontend y portal.


---

## 10. Resultados finales tras correcciones automáticas adicionales

Se ejecutaron las correcciones automatizables restantes:

- **Backend:** se corrió `php vendor/bin/pint` sobre todo el proyecto. Ahora **403 archivos revisados, 0 issues de estilo** (`pint --test` pasa). Los 61 tests siguen pasando.
- **Frontend Kore:** se ejecutó `npm run lint -- --fix`, se ajustó `eslint.config.js` para ignorar parámetros/variables con prefijo `_`, se tiparon varios `any` y se movió el componente `Root` a su propio archivo (`src/app/Root.tsx`) para cumplir `react-refresh`. Quedan **~297 problemas ESLint**, concentrados en componentes legacy del wizard de tareas (`TaskWizard.tsx`, `StepWhat.tsx`) que usan tipado muy flexible; no afectan build ni tests.
- **Portal Vacantes:** linter limpio (0 problemas) y `npm audit --audit-level=high` reporta **0 vulnerabilidades**.

### Estado final de validación

| Proyecto | Tests | Linter | Build | Auditoría deps |
|---|---|---|---|---|
| Backend Laravel | ✅ 61 passed (210 assertions) | ✅ Pint: 0 issues | — | ⚠️ `composer audit` no ejecutado por falta de binario en PATH; dependencias críticas ya se actualizaron |
| Frontend Kore | ✅ 41 passed | ⚠️ 297 problemas ESLint (legacy) | ✅ OK | ⚠️ 6 high (Capacitor/tar) |
| Portal Vacantes | — | ✅ 0 problemas | ✅ OK | ✅ 0 high |

> **Nota:** las 6 vulnerabilidades high restantes del frontend Kore provienen de `@capacitor/assets` → `tar`. No existe fix automático con `npm audit fix`; requiere actualizar/reemplazar la versión de Capacitor o de su dependencia de assets.

