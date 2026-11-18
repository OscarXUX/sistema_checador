# Seguridad: sin resolver

> Pendientes · actualizado 18/11/2026 · Solo lo abierto (sin resolver y parciales).

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 6 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-02** · No hay roles ni permisos | `EmployeeFormRequest::authorize()` regresa `true`; con login (SE-01) toda cuenta puede hacer todo. Depende de TE-05 | Roles de administración y consulta con Policies; prueba de permisos por ruta | ❌ Sin resolver |

---

## 3. Datos y privacidad

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-05** · RFC, CURP y NSS completos y sin cifrar | Van completos en el JSON del listado y del kardex | Enmascarar en listados y *cast* `encrypted` en la base | ❌ Sin resolver |

---

## 4. Infraestructura

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-06** · Comunicación con la terminal sin cifrar | ISAPI por `http://` con Digest Auth. Depende de TE-04 | HTTPS en la terminal o red aislada entre servidor y terminal | ❌ Sin resolver |
| 🟡 Media | **SE-08** · Configuración de producción y cabeceras | `APP_DEBUG=true`, sin cookie `Secure` ni cabeceras de seguridad | `.env` de producción y middleware de cabeceras (HSTS, nosniff, X-Frame-Options, CSP) | ❌ Sin resolver |
| 🟢 Baja | **SE-14** · `composer audit` sin ejecutar | No se pudo correr en la auditoría; `npm audit` dio 0 | Correrlo y agregarlo antes de cada entrega | ❌ Sin resolver |

---

## 5. Lógica y errores

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-11** · Turnos que cruzan la medianoche | El resumen agrupa por día calendario. Depende de TE-07 | Asignar cada checada a su jornada según el turno | ❌ Sin resolver |
