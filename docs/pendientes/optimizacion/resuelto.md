# Optimización: resuelto

> Pendientes · actualizado 18/11/2026 · Resueltos y parciales, con cómo se resolvieron.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 5 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 0 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Sincronización

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **OP-01** · Se descarga todo el día cada minuto | El rango siempre empieza a las 00:00; el trabajo crece durante el día | Sincronización incremental desde la última checada guardada menos un margen | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **OP-02** · Consultas por cada checada | Busca empleado y duplicado por evento y recalcula por cada inserción | Mapa de empleados, consulta única de existentes, inserción en lote y un recálculo por empleado y día | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **OP-05** · Estado de la terminal cada 30 s por pestaña | Cada consulta espera hasta 5 s si la terminal no responde | Caché del estado en el servidor de 30 a 60 s | ✅ Resuelto 18/11/2026 |

### 2.1 OP-01 · Cómo se resolvió

La tarea programada descarga solo desde el último corte menos 10 min de traslape (máximo 31 días atrás); el corte se guarda solo si la descarga terminó bien. Minutos siguientes: de 40 peticiones a 1.

| Qué | Cómo quedó |
|---|---|
| Servicio | `AttendanceSyncCoordinator::runIncremental()` |
| Comando | `hikvision:sync` sin opciones |
| Detalle | `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` |

### 2.2 OP-02 · Cómo se resolvió

Empleados en un mapa, checadas existentes en una consulta, inserción en lote con `insertOrIgnore` y un recálculo por empleado y día. 800 checadas: de 5,601 a 605 consultas; sin cambios: de 1,601 a 3.

| Qué | Cómo quedó |
|---|---|
| Servicio | `AttendanceCalculatorService::syncFromDevice()` |
| Pruebas | `test_bulk_sync_inserts_new_punches_and_skips_existing_with_few_queries` |
| Detalle | `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` |

### 2.3 OP-05 · Cómo se resolvió

`/device/status` reutiliza el último estado 30 s; "Probar Conectividad" pide `?fresh=1`.

| Qué | Cómo quedó |
|---|---|
| Controlador | `DeviceController::status()` |
| Pruebas | `test_device_status_is_cached_and_fresh_queries_again` |
| Detalle | `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` |

---

## 3. Consultas

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **OP-03** · `whereDate()` impide usar índices | Dashboard y cálculo del resumen | `whereBetween` sobre `punched_at` y `where` directo sobre `work_date` | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **OP-04** · Kardex sin rango máximo | Acepta cualquier rango de fechas. Cubre también SE-07 | Rango máximo (por ejemplo 93 días) y exportaciones grandes en cola | ✅ Resuelto 18/11/2026 |

### 3.1 OP-03 · Cómo se resolvió

Comparaciones directas y rangos en lugar de `whereDate()` en el dashboard y en el resumen diario.

| Qué | Cómo quedó |
|---|---|
| Archivos | `DashboardController`, `AttendanceCalculatorService::recalculateForEmployeeDate()` |
| Detalle | `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` |

### 3.2 OP-04 · Cómo se resolvió

El kardex consulta como máximo 93 días; si se pide más, recorta la fecha de inicio y muestra un aviso.

| Qué | Cómo quedó |
|---|---|
| Controlador | `ReportController::MAX_RANGE_DAYS` y `resolveFilters()` |
| Vista | Aviso en `resources/views/reports/kardex.blade.php` |
| Pruebas | `test_kardex_range_is_limited_to_max_days`, `test_kardex_range_within_limit_is_not_adjusted`, `test_kardex_page_warns_when_range_is_adjusted` |
| Detalle | `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` |

---

## 4. Front-end y despliegue

No hay.
