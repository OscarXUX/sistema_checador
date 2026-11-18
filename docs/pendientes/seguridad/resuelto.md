# Seguridad: resuelto

> Pendientes · actualizado 18/11/2026 · Resueltos y parciales, con cómo se resolvieron.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 11 |
| ⚠️ Parcial | 1 |
| ❌ Sin resolver | 0 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-01** · No hay inicio de sesión | Todas las rutas de `routes/web.php` están abiertas a cualquiera en la red | Login de Laravel sin registro público, límite de intentos y todas las rutas con `auth` | ✅ Resuelto 18/11/2026 |

### 2.1 SE-01 · Cómo se resolvió

Login propio sin registro público: todas las rutas piden sesión salvo `/login`; 5 intentos fallidos por minuto; mensaje genérico; cuentas por consola con contraseña de 15 caracteres o más.

| Qué | Cómo quedó |
|---|---|
| Rutas | `Route::middleware('auth')` en `routes/web.php`; `RouteProtectionTest` falla si una ruta queda abierta |
| Login | `AuthController`, `LoginRequest`, `resources/views/auth/login.blade.php` |
| Cuentas | `usuarios:crear`, `usuarios:restablecer`, `usuarios:estado`; columna `users.is_active` |
| Pruebas | `AuthTest`, `RouteProtectionTest`, `UserCommandsTest` |
| Detalle | `docs/parches/2026-11-18/16-20_inicio-de-sesion-y-cuentas.md` |

---

## 3. Datos y privacidad

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-03** · Borrar un empleado borra sus checadas | `cascadeOnDelete()` en `attendance_logs` y `daily_summaries`; `EmployeeController::destroy()` borra físicamente | Baja lógica y `restrictOnDelete()`; las checadas se conservan | ✅ Resuelto 17/11/2026 |
| 🔴 Alta | **SE-04** · La plantilla de huella llega al navegador y al log | `device_response` con `fingerData` en la respuesta de captura; `raw_body` completo en el log | Quitar ambos; registrar solo códigos de error | ✅ Resuelto 17/11/2026 |
| 🟡 Media | **SE-05** · RFC, CURP y NSS completos y sin cifrar | Van completos en el JSON del listado y del kardex | Enmascarar en listados y *cast* `encrypted` en la base | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **SE-09** · No hay bitácora de acciones de usuarios | No queda registro de quién creó, editó, borró o sincronizó. Depende de SE-01 | Tabla de auditoría con usuario, acción, antes y después, IP y fecha | ✅ Resuelto 18/11/2026 |

### 3.1 SE-03 · Cómo se resolvió

Un empleado con checadas se da de baja de forma lógica (`is_active = false`) y conserva su historial; sin historial se elimina. La base ya no borra en cascada.

| Qué | Cómo quedó |
|---|---|
| Aplicación | `EmployeeController::destroy()` revisa si hay historial antes de borrar |
| Base de datos | Migración `2026_11_17_000001_restrict_employee_delete_on_attendance_tables.php`: `restrictOnDelete()` |
| Pruebas | `test_employee_with_attendance_is_deactivated_and_history_is_kept`, `test_database_blocks_deleting_employee_with_attendance` |
| Detalle | `docs/parches/2026-11-17/11-30_baja-logica-conserva-checadas.md` |

### 3.2 SE-04 · Cómo se resolvió

La captura ya no devuelve la respuesta cruda de la terminal y el log de errores cambia los datos biométricos por `[REDACTADO]`.

| Qué | Cómo quedó |
|---|---|
| Respuesta | `captureFingerprint()` manda solo `finger_no` y `template_captured` |
| Log | `redactSensitive()` en `HikvisionIsapiService`; sin `raw_body` |
| Pruebas | `test_capture_result_does_not_include_fingerprint_template`, `test_error_log_redacts_fingerprint_template`, `test_fingerprint_capture_response_does_not_expose_template` |
| Detalle | `docs/parches/2026-11-17/11-32_huella-fuera-de-respuestas-y-logs.md` |

### 3.3 SE-05 · Cómo se resolvió

RFC, CURP y NSS cifrados en la base con el cast `encrypted` (APP_KEY) e índice ciego para buscarlos completos; enmascarados en Empleados y en el kardex (pantalla y exportaciones). El formulario de edición pide los completos y la consulta queda en la bitácora.

| Qué | Cómo quedó |
|---|---|
| Base | Migración `2026_11_18_000003_encrypt_employee_personal_ids.php` |
| Código | `App\Support\PersonalData`, `EmployeeController::show()` |
| Pruebas | `PersonalDataProtectionTest` (6) |
| Detalle | `docs/parches/2026-11-18/17-35_rfc-curp-nss-cifrados-y-enmascarados.md` |

### 3.4 SE-09 · Cómo se resolvió

Tabla `audit_logs` con quién, qué, cuándo, desde dónde y antes/después; registra login, empleados, turnos, acciones en la terminal y comandos de cuentas. RFC, CURP y NSS se guardan como `[protegido]`.

| Qué | Cómo quedó |
|---|---|
| Servicio | `App\Services\AuditLogger` |
| Modelo | `AuditLog`, no se modifica ni se borra desde la aplicación |
| Consulta | En DBeaver por ahora; la pantalla espera a los roles (SE-02) |
| Pruebas | `AuditLogTest`, `test_login_logout_and_failures_are_audited` |
| Detalle | `docs/parches/2026-11-18/16-25_bitacora-de-acciones.md` |

---

## 4. Infraestructura

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-07** · Sin límites de peticiones | Sincronización, captura de huella y kardex sin `throttle` | `throttle` por usuario en esas rutas | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **SE-08** · Configuración de producción y cabeceras | `APP_DEBUG=true`, sin cookie `Secure` ni cabeceras de seguridad | `.env` de producción y middleware de cabeceras (HSTS, nosniff, X-Frame-Options, CSP) | ⚠️ Parcial 18/11/2026 |
| 🟢 Baja | **SE-14** · `composer audit` sin ejecutar | No se pudo correr en la auditoría; `npm audit` dio 0 | Correrlo y agregarlo antes de cada entrega | ✅ Resuelto 18/11/2026 |

### 4.1 SE-07 · Cómo se resolvió

Límites de 10 peticiones por minuto en acciones que llaman a la terminal y 30 en reportes, por usuario o IP; responde 429 con mensaje en español.

| Qué | Cómo quedó |
|---|---|
| Límites | `RateLimiter::for('device')` y `RateLimiter::for('reports')` en `AppServiceProvider` |
| Rutas | `throttle:device` y `throttle:reports` en `routes/web.php` |
| Pruebas | `test_device_sync_is_rate_limited`, `test_reports_are_rate_limited` |
| Detalle | `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` |

### 4.2 SE-08 · Qué se resolvió y qué falta

Hecho: cabeceras de seguridad y CSP con nonce en todas las respuestas. Falta: `.env` de producción (`APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`), que va con la guía de despliegue (TE-06).

| Qué | Cómo quedó |
|---|---|
| Middleware | `App\Http\Middleware\SecurityHeaders` |
| Pruebas | `SecurityHeadersTest` (4) |
| Detalle | `docs/parches/2026-11-18/17-36_cabeceras-de-seguridad.md` |

### 4.3 SE-14 · Cómo se resolvió

Danny corrió `composer audit` el 18/11/2026: sin vulnerabilidades reportadas. `npm audit` también da 0.

| Qué | Cómo quedó |
|---|---|
| Resultado | "No security vulnerability advisories found." |
| Detalle | `docs/parches/2026-11-18/17-15_wijmo-por-pagina-y-empleados-paginados.md` |

---

## 5. Lógica y errores

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🟡 Media | **SE-10** · "Huella OK" aunque no llegue la plantilla | `fingerprint_enrolled = true` siempre tras la captura | Marcarla solo si se recibió y vinculó la plantilla | ✅ Resuelto 18/11/2026 |
| 🟡 Media | **SE-15** · Fórmulas en la exportación CSV del kardex | `csvEscape()` no neutraliza valores que empiezan con `=`, `+`, `-` o `@`. Detectado en docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md | Anteponer `'` a esos valores al exportar | ✅ Resuelto 18/11/2026 |
| 🟢 Baja | **SE-12** · Errores con detalle interno | Mensajes de cURL y URL de la terminal llegan al usuario | Mensaje genérico y detalle solo en el log | ✅ Resuelto 18/11/2026 |
| 🟢 Baja | **SE-13** · Sincronizaciones simultáneas | La manual no comparte el candado de la programada | `Cache::lock()` y `updateOrCreate` en transacción | ✅ Resuelto 18/11/2026 |

### 5.1 SE-10 · Cómo se resolvió

Si la terminal no regresa la plantilla, la captura falla con un mensaje claro y el empleado no queda marcado con huella.

| Qué | Cómo quedó |
|---|---|
| Servicio | `triggerRemoteFingerprintCapture()` exige `fingerData` |
| Pruebas | `test_capture_without_template_fails_and_does_not_mark_fingerprint` |
| Detalle | `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` |

### 5.2 SE-15 · Cómo se resolvió

La exportación CSV antepone un apóstrofo a los valores que empiezan con `=`, `+`, `-`, `@`, tabulador o retorno de carro, salvo números y `--:--`.

| Qué | Cómo quedó |
|---|---|
| Módulo | `resources/js/csv.js` (`neutralizeFormula()`, `csvEscape()`) |
| Uso | `window.AppUI.csvEscape` en `resources/views/reports/kardex.blade.php` |
| Pruebas | Sin ejecutor de pruebas JS en el proyecto; se verificó con 12 casos aparte |
| Detalle | `docs/parches/2026-11-18/16-10_csv-sin-formulas.md` |

### 5.3 SE-12 · Cómo se resolvió

Los errores de conexión y los inesperados muestran un mensaje genérico; el detalle técnico queda en el log.

| Qué | Cómo quedó |
|---|---|
| Servicio | `sendRequest()` y `testConnection()` en `HikvisionIsapiService` |
| Pruebas | `test_connection_error_message_hides_technical_detail` |
| Detalle | `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` |

### 5.4 SE-13 · Cómo se resolvió

Un solo candado para la tarea, el comando y el botón; el botón responde 409 si ya hay una en curso. Resumen con `updateOrCreate` en transacción.

| Qué | Cómo quedó |
|---|---|
| Servicio | `AttendanceSyncCoordinator::run()` |
| Pruebas | `test_manual_sync_returns_409_while_another_sync_is_running` |
| Detalle | `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` |
