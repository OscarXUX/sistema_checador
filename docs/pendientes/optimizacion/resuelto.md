# Optimización: resuelto

> Pendientes · actualizado 18/11/2026 · Resueltos y parciales, con cómo se resolvieron.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 1 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 0 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Sincronización

No hay.

---

## 3. Consultas

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **OP-04** · Kardex sin rango máximo | Acepta cualquier rango de fechas. Cubre también SE-07 | Rango máximo (por ejemplo 93 días) y exportaciones grandes en cola | ✅ Resuelto 18/11/2026 |

### 3.1 OP-04 · Cómo se resolvió

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
