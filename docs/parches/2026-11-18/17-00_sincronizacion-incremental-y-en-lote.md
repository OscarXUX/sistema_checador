# Sincronización incremental, en lote y con un solo candado

> Parche · 18/11/2026 · Paquete 3: cierra OP-01, OP-02, OP-03, OP-05 y SE-13 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md.

---

## 1. Contexto / Causa

| Clave | Problema | Causa |
|---|---|---|
| OP-01 | La tarea de cada minuto volvía a descargar todo el día desde las 00:00 | `HikvisionSyncCommand` siempre pedía desde `startOfDay()` |
| OP-02 | Muchas consultas por cada checada | `ingestEvent()` buscaba el empleado y el duplicado por evento, y recalculaba el resumen por cada inserción |
| OP-03 | Consultas que no usan índices | `whereDate()` en el dashboard y en el recálculo del resumen |
| SE-13 | El botón "Sincronizar" y la tarea programada podían correr a la vez | Solo la tarea tenía `withoutOverlapping()`; el resumen se buscaba y luego se creaba |
| OP-05 | Cada pestaña abierta consultaba la terminal cada 30 s | `/device/status` sin caché; cada consulta espera hasta 5 s si la terminal no responde |

---

## 2. Fix aplicado

### 2.1 OP-02 · Guardado en lote

`AttendanceCalculatorService::syncFromDevice()` ahora:

1. Carga todos los empleados (con su turno) una sola vez en un mapa por número de reloj.
2. Descarta las checadas repetidas dentro de la misma descarga.
3. Trae en **una** consulta las checadas que ya existen en el rango.
4. Inserta las nuevas en bloques de 500 con `insertOrIgnore` (el índice único sigue protegiendo).
5. Recalcula el resumen **una vez** por empleado y día, no por checada.

`ingestEvent()` se conserva para registrar una checada suelta.

### 2.2 OP-01 · Tarea programada incremental

| Qué | Cómo quedó |
|---|---|
| `php artisan hikvision:sync` sin opciones | Descarga desde el último corte menos 10 min de traslape (por checadas que la terminal registra tarde o por diferencias de reloj) hasta ahora. La primera vez, desde el inicio del día |
| Corte | Se guarda en la caché (`hikvision:sync:last_end`) solo si la descarga terminó bien |
| Servidor apagado mucho tiempo | Recupera como máximo 31 días hacia atrás |
| `--from` / `--to` y botón "Sincronizar" | Siguen descargando el rango completo pedido (el día en el botón); no mueven el corte |

### 2.3 SE-13 · Un solo candado

Nuevo `App\Services\AttendanceSyncCoordinator`: la tarea programada, el comando y el botón usan el mismo candado (`Cache::lock`, 5 min máximo). Si ya hay una sincronización en curso, el botón responde 409 "Ya hay una sincronización en curso. Intenta de nuevo en unos segundos." (aviso amarillo) y el comando lo informa sin marcar error. El resumen diario se guarda con `updateOrCreate` dentro de una transacción.

### 2.4 OP-03 · Índices

| Lugar | Antes | Ahora |
|---|---|---|
| Dashboard, resúmenes de hoy | `whereDate('work_date', …)` | `where('work_date', …)` |
| Dashboard, checadas de hoy | `whereDate('punched_at', …)` | `whereBetween('punched_at', [inicio, fin del día])` |
| Recálculo del resumen | `whereDate('work_date', …)` | `updateOrCreate` por `employee_id` y `work_date` (índice único) |

### 2.5 OP-05 · Estado de la terminal en caché

`GET /device/status` reutiliza el último resultado 30 s para todas las pestañas y usuarios. "Probar Conectividad" y el final de una sincronización piden `?fresh=1` para consultar de nuevo. Límite: 30 consultas por minuto por usuario.

---

## 3. Medición antes y después

Simulación con 200 empleados y 800 checadas en un día (4 por persona), terminal simulada que responde de 20 en 20 como el aparato real, base SQLite en archivo. El script de medición no se agregó al repositorio; los tiempos en MySQL serán distintos, pero la cantidad de consultas y peticiones es la misma.

| Escenario | Consultas antes | Consultas después | Peticiones a la terminal antes → después | Tiempo antes → después |
|---|---|---|---|---|
| Primera sincronización del día (800 nuevas) | 5,601 | 605 | 40 → 40 | 2,561 ms → 428 ms |
| Repetir sin cambios | 1,601 | 3 | 40 → 40 | 179 ms → 60 ms |
| Tarea programada, primer minuto (sin corte guardado) | 1,601 | 3 | 40 → 40 | 516 ms → 403 ms |
| Tarea programada, minutos siguientes | 1,601 | 2 | 40 → 1 | 520 ms → 12 ms |

Antes, el trabajo de cada minuto crecía con el número de checadas del día; ahora depende solo de lo que llegó en los últimos minutos.

---

## 4. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Services/AttendanceSyncCoordinator.php`, `app/Exceptions/SyncInProgressException.php`, `tests/Feature/SyncOptimizationTest.php`, este documento |
| Modificados | `app/Services/AttendanceCalculatorService.php` (`syncFromDevice()`, `existingPunchKeys()`, `recalculateForEmployeeDate()`), `app/Console/Commands/HikvisionSyncCommand.php`, `app/Http/Controllers/DeviceController.php`, `app/Http/Controllers/DashboardController.php`, `app/Providers/AppServiceProvider.php` (límite `device-status`), `routes/web.php`, `resources/js/app.js` |

---

## 5. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `php artisan optimize:clear`
4. `npm run build` (cambió el JavaScript) y Ctrl + F5.
5. Si tenías `php artisan schedule:work` abierto, ciérralo y vuelve a abrirlo.

---

## 6. Cómo verificar

1. `php artisan test`: deben pasar las **55** (8 nuevas en `SyncOptimizationTest`).
2. Con la terminal conectada, correr dos veces seguidas `php artisan hikvision:sync`: la primera dice "Sincronización incremental desde {inicio del día}"; la segunda, desde 10 minutos antes de la primera.
3. Presionar "Sincronizar" dos veces muy rápido: la segunda muestra el aviso amarillo de sincronización en curso (si la primera sigue corriendo).
4. Abrir el sistema en dos pestañas y revisar el log de la terminal o la pestaña Red: el estado se consulta al aparato como máximo una vez cada 30 s.

---

## 7. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Checadas de empleados no registrados | Si alguien checa antes de darse de alta en el sistema, la tarea incremental ya no las vuelve a pedir. Se recuperan con el botón "Sincronizar" (día completo) o `php artisan hikvision:sync --from=AAAA-MM-DD` |
| 🟡 Media | Medir con la terminal real | La simulación responde al instante; el aparato tarda por petición, así que la mejora del paso de 40 a 1 petición por minuto será mayor |
| 🟢 Baja | Caché del corte | Si se vacía la caché (`php artisan cache:clear`), la siguiente tarea vuelve a pedir desde el inicio del día; no se pierde nada |
