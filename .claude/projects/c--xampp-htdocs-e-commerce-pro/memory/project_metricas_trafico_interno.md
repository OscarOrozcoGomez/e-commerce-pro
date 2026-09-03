---
name: project_metricas_trafico_interno
description: es_interno flag excludes staff navigation from marketing reports; branch fix/metricas-trafico-interno, PR #102, migration pending in prod.
metadata:
  type: project
---

Feature "no contar la navegacion interna del personal como trafico real": bandera `es_interno` en `logs_actividad`, sellada en el INSERT de `api/log_activity.php` segun rol de sesion via `sessionRoleIsInternal()` en `core/site_behavior.php`. Regla: interno = cualquier rol distinto de `cliente`; anonimo y cliente logueado SI cuentan como trafico real. Reportes (`views/trafico_visitas.php`, `views/comportamiento_sitio.php`) filtran `es_interno = 0`; `views/activity_logs.php` tiene filtro Interno/Externo y el log de auditoria sigue mostrando todo.

Estado (2026-08-31): rama `fix/metricas-trafico-interno` rebaseada sobre main, pusheada, **PR #102 abierto** contra main. Suite Unit 556/556 OK. Test nuevo: `tests/Unit/SessionRoleIsInternalTest.php` (8 casos).

**Pendiente de deploy:** migracion `20260830_000001_add_es_interno_a_logs_actividad.sql` debe correr ANTES o junto con el codigo en prod; si el codigo sube primero el INSERT falla por columna desconocida, el error se traga en silencio y el tracking se detiene hasta migrar. Ver [[project_e2e_automation_workflow]].
