# Pendientes de seguridad

> Pendientes · actualizado 18/11/2026 · Hallazgos de seguridad de la auditoría del 17/11/2026. Todos los renglones.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 10 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 5 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-01** · No hay inicio de sesión | Todas las rutas de `routes/web.php` están abiertas a cualquiera en la red | Login de Laravel sin registro público, límite de intentos y todas las rutas con `auth` | ✅ Resuelto 18/11/2026 |
| 🔴 Alta | **SE-02** · No hay roles ni permisos | `EmployeeFormRequest::authorize()` regresa `true`; con login (SE-01) toda cuenta puede hacer todo. Depende de TE-05 | Roles de administración y consulta con Policies; prueba de permisos por ruta | ❌ Sin resolver |

---

## 3. Datos y privacidad

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-03** · Borrar un empleado borra sus checadas | `cascadeOnDelete()` en `attendance_logs` y `daily_summaries`; `EmployeeController::destroy()` borra físicamente | Baja lógica y `restrictOnDelete()`; las checadas se conservan | ✅ Resuelto 17/11/2026 |
| 🔴 Alta | **SE-04** · La plantilla de huella llega al navegador y al log | `device_response` con `fingerData` en la respuesta de captura; `raw_body` completo en el log | Quitar ambos; registrar solo códigos de error | ✅ Resuelto 17/11/2026 |
| 🟡 Media | **SE-05** · RFC, CURP y NSS completos y sin cifrar | Van completos en el JSON del listado y del kardex | Enmascarar en listados y *cast* `encrypted` en la base | ❌ Sin resolver |
| 🟡 Media | **SE-09** · No hay bitácora de acciones de usuarios | No queda registro de quién creó, editó, borró o sincronizó. Depende de SE-01 | Tabla de auditoría con usuario, acción, antes y después, IP y fecha | ✅ Resuelto 18/11/2026 |

---

## 4. Infraestructura

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-06** · Comunicación con la terminal sin cifrar | ISAPI por `http://` con Digest Auth. Depende de TE-04 | HTTPS en la terminal o red aislada entre servidor y terminal | ❌ Sin resolver |
| 🟡 Media | **SE-07** · Sin límites de peticiones | Sincronización, captura de huella y kardex sin `throttle` | `throttle` por usuario en esas rutas | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **SE-08** · Configuración de producción y cabeceras | `APP_DEBUG=true`, sin cookie `Secure` ni cabeceras de seguridad | `.env` de producción y middleware de cabeceras (HSTS, nosniff, X-Frame-Options, CSP) | ❌ Sin resolver |
| 🟢 Baja | **SE-14** · `composer audit` sin ejecutar | No se pudo correr en la auditoría; `npm audit` dio 0 | Correrlo y agregarlo antes de cada entrega | ✅ Resuelto 18/11/2026 |

---

## 5. Lógica y errores

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-10** · "Huella OK" aunque no llegue la plantilla | `fingerprint_enrolled = true` siempre tras la captura | Marcarla solo si se recibió y vinculó la plantilla | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **SE-11** · Turnos que cruzan la medianoche | El resumen agrupa por día calendario. Depende de TE-07 | Asignar cada checada a su jornada según el turno | ❌ Sin resolver |
| 🟡 Media | **SE-15** · Fórmulas en la exportación CSV del kardex | `csvEscape()` no neutraliza valores que empiezan con `=`, `+`, `-` o `@`. Detectado en docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md | Anteponer `'` a esos valores al exportar | ✅ Resuelto 18/11/2026 |
| 🟢 Baja | **SE-12** · Errores con detalle interno | Mensajes de cURL y URL de la terminal llegan al usuario | Mensaje genérico y detalle solo en el log | ✅ Resuelto 18/11/2026 |
| 🟢 Baja | **SE-13** · Sincronizaciones simultáneas | La manual no comparte el candado de la programada | `Cache::lock()` y `updateOrCreate` en transacción | ✅ Resuelto 18/11/2026 |
