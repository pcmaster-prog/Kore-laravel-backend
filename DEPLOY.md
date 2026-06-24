# Deploy a Railway — Backend Kore

> ⚠️ **No subas tu `.env` real.** Usa las variables de entorno del dashboard de Railway.

## 1. Variables de entorno obligatorias en Railway

| Variable | Descripción | Ejemplo producción |
|---|---|---|
| `APP_ENV` | Entorno | `production` |
| `APP_KEY` | Generado con `php artisan key:generate --show` | `base64:...` |
| `APP_DEBUG` | Desactivar en prod | `false` |
| `APP_URL` | URL pública del backend | `https://kore-backend-production.up.railway.app` |
| `APP_FRONTEND_URL` | URL del ERP | `https://kore-erp.vercel.app` |
| `APP_FRONTEND_PORTAL_URL` | URL del portal de vacantes | `https://vacantes.decorartereposteria.mx` |
| `DB_CONNECTION` | Base de datos | `pgsql` |
| `DB_HOST` | Host de PostgreSQL | (Railway proporciona) |
| `DB_PORT` | Puerto | `5432` |
| `DB_DATABASE` | Nombre de la BD | (Railway proporciona) |
| `DB_USERNAME` | Usuario | (Railway proporciona) |
| `DB_PASSWORD` | Contraseña | (Railway proporciona) |
| `SESSION_DRIVER` | Driver de sesión | `database` o `cookie` |
| `SESSION_SECURE_COOKIE` | Solo HTTPS | `true` |
| `SESSION_SAME_SITE` | SameSite | `lax` |
| `CACHE_STORE` | Cache | `database` |
| `QUEUE_CONNECTION` | Colas | `database` |
| `BROADCAST_CONNECTION` | Broadcast | `log` |
| `MAIL_MAILER` | Mailer | `resend` |
| `RESEND_API_KEY` | API key de Resend | `re_...` |
| `MAIL_FROM_ADDRESS` | Remitente | `notificaciones@decorartereposteria.mx` |
| `SANCTUM_STATEFUL_DOMAINS` | Dominios SPA | `kore-erp.vercel.app,vacantes.decorartereposteria.mx` |
| `GOOGLE_CLIENT_ID` | OAuth Google | `...apps.googleusercontent.com` |
| `GOOGLE_CLIENT_SECRET` | OAuth Google | `GOCSPX-...` |
| `GOOGLE_REDIRECT_URL` | Callback OAuth | `https://vacantes.decorartereposteria.mx/auth/google/callback` |
| `AWS_ACCESS_KEY_ID` | S3 para documentos | `AKIA...` |
| `AWS_SECRET_ACCESS_KEY` | S3 | `...` |
| `AWS_DEFAULT_REGION` | S3 | `us-east-1` |
| `AWS_BUCKET` | Bucket S3 | `kore-documentos` |

## 2. Deploy automático

Railway detectará `railway.toml` y `Procfile`:

- **Build**: Nixpacks (PHP 8.2 + Node opcional).
- **Deploy**: corre migraciones con `--force` y levanta `php artisan serve` en el puerto asignado por Railway (`$PORT`).

## 3. Servicios adicionales en Railway

Para que los correos encolados y los recordatorios automáticos funcionen, crea dos servicios extra apuntando al mismo repositorio/imagen que el servicio web:

| Servicio | Comando de inicio | Propósito |
|---|---|---|
| `kore-worker` | `php artisan queue:work --sleep=3 --tries=3 --max-jobs=1000 --max-time=3600` | Procesa la cola de correos (`QUEUE_CONNECTION=database`). |
| `kore-scheduler` | `php artisan schedule:work` | Ejecuta el schedule de Laravel cada minuto, incluyendo `interviews:send-reminders` cada hora. |

Ambos servicios deben tener las **mismas variables de entorno** que el servicio web.

> `--max-time=3600` hace que el worker se reinicie cada hora para evitar fugas de memoria.

## 4. Post-deploy manual (solo la primera vez)

```bash
# Opcional: seed de módulos y criterios
php artisan db:seed --class=ModulesTableSeeder
php artisan db:seed --class=CriteriaSeeder
```

## 5. Notas

- El backend expone los endpoints del portal bajo `/api/v1/portal/*` y los públicos bajo `/api/v1/public/jobs*`.
- Para que el ERP muestre el link "Portal de Vacantes", el usuario debe tener `empresa_id` o rol `aspirante`.
