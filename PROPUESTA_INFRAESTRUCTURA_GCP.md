# Propuesta de Infraestructura: Migraci\u00f3n a Google Cloud Platform

**Proyecto:** Kore Ops Suite (Backend + Frontend)  
**Fecha:** Mayo 2026  
**Elabora:** Equipo de Desarrollo  
**Destinatario:** Direcci\u00f3n / Stakeholders  

---

## 1. Resumen Ejecutivo

Se propone migrar la infraestructura actual del sistema Kore desde Railway hacia **Google Cloud Platform (GCP)** bajo una arquitectura moderna basada en contenedores Docker y orquestaci\u00f3n profesional.

Esta migraci\u00f3n resuelve los problemas de estabilidad actuales, habilita la escalabilidad necesaria para el crecimiento inmediato de Kore, y establece la base t\u00e9cnica para el nuevo proyecto empresarial de mayor envergadura que est\u00e1 planeado.

**Inversi\u00f3n estimada:** $200 - $400 USD mensuales (infraestructura completa).  
**Tiempo de implementaci\u00f3n:** 3 semanas o mucho menos si se aprende rapido.  
**Reducci\u00f3n de ca\u00eddas esperada:** 99.9% de uptime SLA garantizado por Google.

---

## 2. Situaci\u00f3n Actual y Problemas

| Aspecto | Estado Actual (Railway) | Impacto de Negocio |
|---|---|---|
| **Estabilidad** | Ca\u00eddas recurrentes del servicio | Empleados sin poder registrar asistencia, supervisores sin acceso a tareas |
| **Escalabilidad** | Limitada, crece de forma vertical forzada | Lentitud en horas pico, imposible atender picos de demanda |
| **Observabilidad** | B\u00e1sica | Cuando falla, no sabemos por qu\u00e9 ni cu\u00e1ndo se recupera |
| **Multi-proyecto** | No est\u00e1 dise\u00f1ado para m\u00faltiples sistemas | Cada proyecto nuevo requiere reconstruir infraestructura desde cero |
| **Docker / Contenedores** | No soportado nativamente | Imposibilidad de estandarizar entornos (desarrollo, staging, producci\u00f3n) |
| **Respaldo de datos** | Limitado | Riesgo real de p\u00e9rdida de informaci\u00f3n cr\u00edtica de n\u00f3mina y asistencia |

---

## 3. Arquitectura Propuesta

### Visi\u00f3n General

Una infraestructura \u00fanica en Google Cloud que alberga **todos los sistemas actuales y futuros**, con separaci\u00f3n clara entre capa de aplicaci\u00f3n (contenedores), capa de datos (gestionada) y distribuci\u00f3n de contenido (CDN global).

```
                              USUARIOS (Web / M\u00f3vil / PWA)
                                   |
                                   v
                    +------------------------------------------+
                    |         Google Cloud CDN (Global)        |
                    |   (Distribuye contenido est\u00e1tico r\u00e1pido)  |
                    +------------------------------------------+
                          |                           |
                    APP.KORE.IO                 API.KORE.IO
                          |                           |
                          v                           v
            +-------------------------+    +---------------------------+
            |   Cloud Storage Bucket  |    |   Cloud Load Balancer     |
            |   (Frontend React+Vite) |    |   (Ingress / Enrutador)   |
            +-------------------------+    +-------------+-------------+
                                                         |
                                    +--------------------+--------------------+
                                    |                                         |
                                    v                                         v
                           +------------------+                    +------------------+
                           |   GKE Autopilot  |                    |   GKE Autopilot  |
                           |   (Web Backend)  |                    |  (Queue Workers) |
                           |     Laravel      |                    |  Procesos en     |
                           |   + Nginx        |                    |   segundo plano  |
                           +--------+---------+                    +---------+--------+
                                    |                                        |
                                    +----------------+-----------------------+
                                                     |
                                                     v
                                          +---------------------+
                                          |   Cloud SQL         |
                                          |   PostgreSQL        |
                                          |   (Alta disponib.)  |
                                          +----------+----------+
                                                     |
                                          +----------+----------+
                                          |   Redis (Memory)    |
                                          |   Cache + Sesiones  |
                                          +---------------------+
```

### Componentes Detallados

| Componente | Tecnolog\u00eda | Funci\u00f3n | Beneficio |
|---|---|---|---|
| **Frontend** | Cloud Storage + Cloud CDN | Almacena y distribuye la app React compilada | Carga instant\u00e1nea desde cualquier ciudad de M\u00e9xico; sin costo de servidor dedicado |
| **API / Backend** | GKE Autopilot (Kubernetes) | Corre los contenedores Docker de Laravel | Auto-escalado, auto-sanaci\u00f3n, 99.9% uptime |
| **Workers** | GKE Autopilot (Kubernetes) | Procesa colas: correos, notificaciones push, cierres de asistencia | Trabajos pesados no afectan la velocidad de la app web |
| **Scheduler (Cron)** | Kubernetes CronJobs | Ejecuta tareas programadas cada minuto (n\u00f3mina, recordatorios) | Fiable, nunca se omite una ejecuci\u00f3n |
| **Base de Datos** | Cloud SQL PostgreSQL | Almacena toda la informaci\u00f3n empresarial | Backups autom\u00e1ticos diarios, replicaci\u00f3n, encriptaci\u00f3n |
| **Cache** | Memorystore Redis | Sesiones de usuario, cache de queries | Respuestas en milisegundos, menos carga en la base de datos |
| **CI/CD** | GitHub + Cloud Build + ArgoCD | Cada cambio en c\u00f3digo se compila y despliega autom\u00e1ticamente | Despliegues en minutos, sin errores humanos |
| **Seguridad** | Secret Manager + SSL | Contrase\u00f1as, API keys, certificados encriptados | Cumplimiento b\u00e1sico de protecci\u00f3n de datos |

---

## 4. Flujo de Trabajo: De VS Code a Producci\u00f3n en Minutos

```
1. DESARROLLADOR trabaja en VS Code
         |
         v
2. git commit + git push (GitHub)
         |
         v
3. GitHub Actions detecta el cambio
   - Compila Docker image
   - Ejecuta pruebas autom\u00e1ticas
   - Sube imagen a Google Registry
         |
         v
4. ArgoCD detecta nueva imagen
   - Actualiza autom\u00e1ticamente el cluster
   - Realiza "rolling update" (sin p\u00e9rdida de servicio)
         |
         v
5. USUARIOS acceden a la nueva versi\u00f3n
```

**Tiempo total desde commit hasta producci\u00f3n:** 3-5 minutos.  
**Intervenci\u00f3n manual requerida:** Cero.

---

## 5. Inversi\u00f3n Mensual Estimada

| Concepto | Servicio GCP | Costo Aprox. USD/mes |
|---|---|---|
| Orquestaci\u00f3n de contenedores | GKE Autopilot (web + workers + cron) | $100 - $180 |
| Base de datos PostgreSQL | Cloud SQL (instancia db-n1-standard-1, HA) | $60 - $100 |
| Cache y sesiones | Memorystore Redis (1 GB) | $35 - $50 |
| Almacenamiento frontend | Cloud Storage + Cloud CDN | $10 - $25 |
| Registro de im\u00e1genes Docker | Artifact Registry | $5 - $10 |
| Balanceador de carga | Cloud Load Balancing | $15 - $25 |
| **TOTAL ESTIMADO** | | **$225 - $390 USD/mes** |

**Nota:** Este costo cubre tanto **Kore** como la capacidad para alojar el **proyecto nuevo** en el mismo cluster sin duplicar infraestructura base.

---

## 6. Roadmap de Implementaci\u00f3n (3 Semanas)

### Semana 1: Fundamentos y Preparaci\u00f3n
- Crear cuenta y proyecto en Google Cloud
- Configurar red, dominios y certificados SSL
- Crear cluster GKE Autopilot
- Dockerizar backend Laravel localmente (Docker Compose: web + worker + scheduler + postgres + redis)
- Pruebas locales exitosas

### Semana 2: Despliegue Inicial y Migraci\u00f3n
- Configurar Cloud SQL PostgreSQL y migrar datos desde Railway
- Desplegar backend en GKE (Deployment + Service + Ingress)
- Configurar workers y cronjobs en Kubernetes
- Configurar Cloud Storage bucket para frontend
- Configurar GitHub Actions para CI/CD autom\u00e1tico
- Pruebas de carga y funcionalidad

### Semana 3: Optimizaci\u00f3n y Go-Live
- Configurar Cloud CDN y cach\u00e9
- Configurar ArgoCD para GitOps
- Configurar monitoreo (Cloud Monitoring + Alertas)
- Documentaci\u00f3n t\u00e9cnica interna
- Cambio de DNS: apuntar dominios a nueva infraestructura
- Monitoreo post-migraci\u00f3n durante 48 horas

---

## 7. Beneficios Directos para el Negocio

| Beneficio | Descripci\u00f3n |
|---|---|
| **Cero ca\u00eddas por infraestructura** | Google garantiza 99.9% de disponibilidad. El sistema estar\u00e1 siempre accesible para empleados y supervisores. |
| **Escalabilidad autom\u00e1tica** | Si ma\u00f1ana contratas 1,000 empleados nuevos, la infraestructura crece sola sin que nadie toque nada. |
| **Base para el proyecto grande** | El nuevo proyecto empresarial se despliega en el mismo cluster en d\u00edas, no en meses. |
| **Estandarizaci\u00f3n total** | El c\u00f3digo corre exactamente igual en la laptop del desarrollador que en producci\u00f3n. Cero sorpresas. |
| **Recuperaci\u00f3n ante desastres** | Backups autom\u00e1ticos diarios de la base de datos. Si algo catastr\u00f3fico ocurre, se recupera en minutos. |
| **Velocidad de desarrollo** | Nuevas funcionalidades pasan del c\u00f3digo al usuario final en minutos, no en horas o d\u00edas. |
| **Seguridad empresarial** | Encriptaci\u00f3n en tr\u00e1nsito y en reposo. Contrase\u00f1as y API keys nunca expuestas en c\u00f3digo. |

---

## 8. Comparativa R\u00e1pida: Antes vs Despu\u00e9s

| Caracter\u00edstica | Antes (Railway) | Despu\u00e9s (GCP) |
|---|---|---|
| Uptime garantizado | No hay SLA | 99.9% SLA contractual |
| Tiempo de recuperaci\u00f3n | Horas o incierto | Minutos (auto-sanaci\u00f3n) |
| Escalabilidad | Manual y limitada | Autom\u00e1tica e ilimitada |
| Contenedores Docker | No soportado nativamente | Nativo, obligatorio |
| M\u00faltiples proyectos | Infraestructura separada | Mismo cluster, m\u00faltiples apps |
| Despliegue | Manual o semi-autom\u00e1tico | 100% autom\u00e1tico por git push |
| Backups de BD | B\u00e1sicos | Autom\u00e1ticos, encriptados, con punto en el tiempo |
| Costo predecible | Variable y opaco | Fijo y transparente |
| Monitoreo | M\u00ednimo | Dashboards en tiempo real, alertas autom\u00e1ticas |

---

## 9. Riesgos y Mitigaci\u00f3n

| Riesgo | Probabilidad | Mitigaci\u00f3n |
|---|---|---|
| Curva de aprendizaje del equipo | Media | El desarrollador principal tiene capacidad confirmada para dominar la tecnolog\u00eda en semanas. Documentaci\u00f3n exhaustiva de Google. |
| Migraci\u00f3n de datos incompleta | Baja | Migraci\u00f3n controlada con validaci\u00f3n de datos. Railway se mantiene activo como fallback durante 48 horas. |
| Costo mayor al esperado | Baja | Google Cloud tiene presupuestos y alertas configurables. Costo inicial se mantiene en rango medio-bajo para nuestra escala. |
| P\u00e9rdida de servicio durante migraci\u00f3n | Muy baja | La migraci\u00f3n se hace en paralelo. Solo se cambia el DNS cuando todo est\u00e1 validado. Rollback disponible en minutos. |

---

## 10. Decisi\u00f3n Requerida

Para proceder, se requiere autorizaci\u00f3n en los siguientes puntos:

1. [ ] **Aprobar presupuesto mensual** estimado de $225 - $390 USD para infraestructura cloud.
2. [ ] **Autorizar apertura de cuenta empresarial** en Google Cloud Platform.
3. [ ] **Asignar dominio/s subdominios** para la nueva arquitectura (ej. `app.kore.io`, `api.kore.io`).
4. [ ] **Confirmar prioridad:** \u00bfSe autoriza iniciar la migraci\u00f3n inmediatamente o se espera a una fecha espec\u00edfica?

---

## Anexos T\u00e9cnicos (Disponibles bajo solicitud)

- A1: Especificaci\u00f3n t\u00e9cnica del Dockerfile y docker-compose local
- A2: Manifiestos de Kubernetes (YAML) para despliegue
- A3: Configuraci\u00f3n de GitHub Actions para CI/CD
- A4: Plan detallado de migraci\u00f3n de base de datos desde Railway
- A5: Diagrama de red y seguridad (VPC, firewalls, reglas de acceso)

---

*Documento preparado por el equipo de desarrollo de Kore para consideraci\u00f3n de la direcci\u00f3n.*
