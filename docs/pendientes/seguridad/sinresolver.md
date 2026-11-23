# Seguridad: sin resolver

> Pendientes · actualizado 22/11/2026 · Solo lo abierto (sin resolver y parciales).

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 1 |
| ❌ Sin resolver | 2 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-02** · No hay roles ni permisos | `EmployeeFormRequest::authorize()` regresa `true`; con login (SE-01) toda cuenta puede hacer todo. Depende de TE-05 | Roles de administración y consulta con Policies; prueba de permisos por ruta | ❌ Sin resolver |

---

## 3. Datos y privacidad

No hay.

---

## 4. Infraestructura

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-06** · Comunicación con la terminal sin cifrar | ISAPI por `http://` con Digest Auth. Depende de TE-04 | HTTPS en la terminal o red aislada entre servidor y terminal | ❌ Sin resolver |
| 🟡 Media | **SE-08** · Configuración de producción y cabeceras | `APP_DEBUG=true`, sin cookie `Secure` ni cabeceras de seguridad | Plantilla `.env.production.example` y middleware listos; falta `.env` definitivo en servidor (TE-06) | ⚠️ Parcial 22/11/2026 |

---

## 5. Lógica y errores

No hay.
