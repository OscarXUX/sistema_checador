# Optimización: sin resolver

> Pendientes · actualizado 18/11/2026 · Solo lo abierto (sin resolver y parciales).

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 2 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Sincronización

No hay.

---

## 3. Consultas

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟢 Baja | **OP-08** · `raw_payload` sin plazo de conservación | Una fila JSON por checada. Depende de TE-08 | Retención y archivado; en el kardex, JSON solo al abrirlo | ❌ Sin resolver |

---

## 4. Front-end y despliegue

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟢 Baja | **OP-09** · Cachés y tarea programada de producción | No hay guía de despliegue. Depende de TE-06 | `config:cache`, `route:cache`, `view:cache`, OPcache y cron de `schedule:run` | ❌ Sin resolver |
