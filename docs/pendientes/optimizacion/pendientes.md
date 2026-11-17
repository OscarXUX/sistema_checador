# Pendientes de optimización

> Pendientes · actualizado 17/11/2026 · Hallazgos de rendimiento de la auditoría del 17/11/2026. Todos los renglones.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 9 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Sincronización

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **OP-01** · Se descarga todo el día cada minuto | El rango siempre empieza a las 00:00; el trabajo crece durante el día | Sincronización incremental desde la última checada guardada menos un margen | ❌ Sin resolver |
| 🟡 Media | **OP-02** · Consultas por cada checada | Busca empleado y duplicado por evento y recalcula por cada inserción | Mapa de empleados, consulta única de existentes, inserción en lote y un recálculo por empleado y día | ❌ Sin resolver |
| 🟡 Media | **OP-05** · Estado de la terminal cada 30 s por pestaña | Cada consulta espera hasta 5 s si la terminal no responde | Caché del estado en el servidor de 30 a 60 s | ❌ Sin resolver |

---

## 3. Consultas

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **OP-03** · `whereDate()` impide usar índices | Dashboard y cálculo del resumen | `whereBetween` sobre `punched_at` y `where` directo sobre `work_date` | ❌ Sin resolver |
| 🟡 Media | **OP-04** · Kardex sin rango máximo | Acepta cualquier rango de fechas. Cubre también SE-07 | Rango máximo (por ejemplo 93 días) y exportaciones grandes en cola | ❌ Sin resolver |
| 🟢 Baja | **OP-07** · Listado de empleados sin paginación | Se embebe completo en la página | Paginar si se pasa de ~1,000 empleados | ❌ Sin resolver |
| 🟢 Baja | **OP-08** · `raw_payload` sin plazo de conservación | Una fila JSON por checada. Depende de TE-08 | Retención y archivado; en el kardex, JSON solo al abrirlo | ❌ Sin resolver |

---

## 4. Front-end y despliegue

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟢 Baja | **OP-06** · Todo Wijmo en todas las páginas | Un solo paquete con grid, chart, xlsx y JSZip | Importar por página | ❌ Sin resolver |
| 🟢 Baja | **OP-09** · Cachés y tarea programada de producción | No hay guía de despliegue. Depende de TE-06 | `config:cache`, `route:cache`, `view:cache`, OPcache y cron de `schedule:run` | ❌ Sin resolver |
