# Seguridad: sin resolver

> Pendientes · actualizado 17/11/2026 · Solo lo abierto (sin resolver y parciales).

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 12 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-01** · No hay inicio de sesión | Todas las rutas de `routes/web.php` están abiertas a cualquiera en la red | Login de Laravel sin registro público, límite de intentos y todas las rutas con `auth` | ❌ Sin resolver |
| 🔴 Alta | **SE-02** · No hay roles ni permisos | `EmployeeFormRequest::authorize()` regresa `true`. Depende de SE-01 y TE-05 | Roles de administración y consulta con Policies; prueba de permisos por ruta | ❌ Sin resolver |

---

## 3. Datos y privacidad

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-05** · RFC, CURP y NSS completos y sin cifrar | Van completos en el JSON del listado y del kardex | Enmascarar en listados y *cast* `encrypted` en la base | ❌ Sin resolver |
| 🟡 Media | **SE-09** · No hay bitácora de acciones de usuarios | No queda registro de quién creó, editó, borró o sincronizó. Depende de SE-01 | Tabla de auditoría con usuario, acción, antes y después, IP y fecha | ❌ Sin resolver |

---

## 4. Infraestructura

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-06** · Comunicación con la terminal sin cifrar | ISAPI por `http://` con Digest Auth. Depende de TE-04 | HTTPS en la terminal o red aislada entre servidor y terminal | ❌ Sin resolver |
| 🟡 Media | **SE-07** · Sin límites de peticiones | Sincronización, captura de huella y kardex sin `throttle` | `throttle` por usuario en esas rutas | ❌ Sin resolver |
| 🟡 Media | **SE-08** · Configuración de producción y cabeceras | `APP_DEBUG=true`, sin cookie `Secure` ni cabeceras de seguridad | `.env` de producción y middleware de cabeceras (HSTS, nosniff, X-Frame-Options, CSP) | ❌ Sin resolver |
| 🟢 Baja | **SE-14** · `composer audit` sin ejecutar | No se pudo correr en la auditoría; `npm audit` dio 0 | Correrlo y agregarlo antes de cada entrega | ❌ Sin resolver |

---

## 5. Lógica y errores

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-10** · "Huella OK" aunque no llegue la plantilla | `fingerprint_enrolled = true` siempre tras la captura | Marcarla solo si se recibió y vinculó la plantilla | ❌ Sin resolver |
| 🟡 Media | **SE-11** · Turnos que cruzan la medianoche | El resumen agrupa por día calendario. Depende de TE-07 | Asignar cada checada a su jornada según el turno | ❌ Sin resolver |
| 🟢 Baja | **SE-12** · Errores con detalle interno | Mensajes de cURL y URL de la terminal llegan al usuario | Mensaje genérico y detalle solo en el log | ❌ Sin resolver |
| 🟢 Baja | **SE-13** · Sincronizaciones simultáneas | La manual no comparte el candado de la programada | `Cache::lock()` y `updateOrCreate` en transacción | ❌ Sin resolver |
