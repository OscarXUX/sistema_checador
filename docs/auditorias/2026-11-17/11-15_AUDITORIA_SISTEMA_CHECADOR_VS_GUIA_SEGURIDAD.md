# Auditoría de seguridad y optimización del sistema checador

> Auditoría · 17/11/2026 · Compara la base de Oscar (commit del 12/11/2026) contra la guía de seguridad y optimización de Danny (OWASP Top 10:2025, ASVS 5.0, NIST 800-63B rev. 4, LFPDPPP 2025).

---

## 1. Resumen

La base está bien hecha en lo técnico: valida con Form Requests, escapa la salida, usa CSRF, no concatena SQL y evita checadas duplicadas. **No se puede publicar todavía.** Hay cuatro hallazgos 🔴 Alta:

- No hay inicio de sesión (SE-01).
- No hay roles (SE-02).
- Al borrar un empleado se borra su bitácora de checadas (SE-03).
- La plantilla de huella llega al navegador (SE-04).

A eso se suma que el sistema guarda RFC, CURP, NSS y datos biométricos sin aviso de privacidad ni consentimiento (TE-02). En rendimiento, el problema principal es que la sincronización vuelve a descargar todo el día cada minuto (OP-01) y hace varias consultas por cada checada (OP-02). No afecta con pocos empleados, pero crece a lo largo del día.

| Prioridad | Seguridad | Optimización | Cliente |
|---|---|---|---|
| 🔴 Alta | 4 | 0 | 2 |
| 🟡 Media | 7 | 5 | 4 |
| 🟢 Baja | 3 | 4 | 2 |

Pruebas: `npm audit` sin vulnerabilidades; `composer audit` quedó pendiente (SE-14). La revisión es de código, no de un servidor en producción.

---

## 2. Tabla comparativa por apartado

| Apartado de la guía | Lo esperado | Lo que hay | Estado |
|---|---|---|---|
| 4.2 Autenticación | Login con hash, límite de intentos | No hay login; `routes/web.php` sin middleware `auth` | ❌ |
| 4.4 Autorización | Permiso por ruta y por registro | `EmployeeFormRequest::authorize()` regresa `true`; no hay roles | ❌ |
| 4.3 Sesiones y CSRF | Token en todo POST/PUT/DELETE | `@csrf` en formularios y `X-CSRF-TOKEN` en `resources/js/app.js` | ✅ |
| 4.5 Validación y salida | Validar en servidor, escapar al mostrar | Form Requests con regex de RFC/CURP/NSS; Blade y `escapeHtml()` en celdas de Wijmo; JSON crudo con `textContent` | ✅ |
| 4.4 Asignación masiva | Lista blanca de campos | `$fillable` y `$request->validated()` | ✅ |
| 4.5 Inyección SQL | Consultas parametrizadas | Eloquent; `DB::raw` solo con texto fijo | ✅ |
| 4.8 Criptografía | Transporte cifrado; cifrado de datos sensibles | Terminal por `http://`; RFC/CURP/NSS en texto plano | ⚠️ Parcial |
| 4.9 Configuración | Depuración apagada, secretos fuera del repositorio | `.env` en `.gitignore`; `.env.example` sin contraseñas; `APP_DEBUG=true` (normal en local) | ⚠️ Parcial |
| 4.10 Cabeceras HTTP | HSTS, nosniff, X-Frame-Options, CSP | No configuradas | ❌ |
| 4.12 Recursos | Límites de peticiones y rangos | Tiempos de espera en el cliente HTTP ✅; sin `throttle`; kardex sin rango máximo | ⚠️ Parcial |
| 4.13 Errores | Fallar cerrado, sin detalle interno | Excepción propia de la terminal ✅; mensajes con error de cURL al cliente; huella se marca aunque falle | ⚠️ Parcial |
| 4.14 Lógica del negocio | Reglas en el servidor, registros protegidos | Cálculos en el servidor ✅; borrado en cascada de checadas; turnos nocturnos | ⚠️ Parcial |
| 4.15 Registro | Bitácora de acciones, sin datos sensibles en logs | Log de errores de la terminal con cuerpo completo; sin bitácora de usuarios | ⚠️ Parcial |
| 4.16 Dependencias | Lockfiles y auditoría | `composer.lock` y `package-lock.json` ✅; `npm audit` 0 ✅; `composer audit` pendiente | ⚠️ Parcial |
| 4.18 Base de datos | Integridad, baja lógica en registros de auditoría | Único `(employee_id, punched_at)` ✅; `cascadeOnDelete` en checadas | ⚠️ Parcial |
| 4.21 LFPDPPP | Aviso, consentimiento, minimización | No existe aviso; biométricos sin consentimiento expreso | ❌ |
| 5.2 Código del servidor | Sin N+1, trabajo pesado fuera de la petición | N+1 en la sincronización | ⚠️ Parcial |
| 6.2 Índices | Consultas que usan índices | `whereDate()` impide usarlos | ⚠️ Parcial |
| 6.4 / 6.10 Paginación y reportes | Rango y página máximos | Monitor con `limit(150)` ✅, bitácora con `limit(250)` ✅; kardex sin límite | ⚠️ Parcial |

---

## 3. ✅ Ya implementado

| Control | Evidencia |
|---|---|
| CSRF en formularios y en `fetch` | `resources/views/layouts/app.blade.php` (meta `csrf-token`), `resources/js/app.js` (`X-CSRF-TOKEN`) |
| Escape de salida en tablas Wijmo | `escapeHtml()` en `resources/js/app.js` y en cada `innerHTML` de las vistas; JSON original con `textContent` en `reports/kardex.blade.php` |
| Validación estricta de empleados | `app/Http/Requests/EmployeeFormRequest.php`: número de reloj con lista de caracteres, RFC, CURP y NSS con formato oficial |
| Sin asignación masiva | `Employee::$fillable` y `create($request->validated())` |
| Checadas sin duplicados | Índice único `attendance_logs_employee_punched_unique` y captura de `UniqueConstraintViolationException` |
| Bitácora no editable desde Eloquent | `AttendanceLog::booted()` lanza excepción al actualizar |
| Tiempos de espera hacia la terminal | `HikvisionIsapiService::client()`: `timeout` y `connectTimeout` |
| Sin solapamiento en la tarea programada | `routes/console.php`: `withoutOverlapping()` |
| Lectura de XML sin entidades externas | `parseResponseBody()` sin `LIBXML_NOENT` |
| Secretos fuera del repositorio | `.gitignore` incluye `.env`, `.env.backup`, `.env.production` |
| Pruebas automáticas | 16 pruebas, incluida la inmutabilidad de la bitácora y la validación de RFC/CURP/NSS |

---

## 4. Valor agregado

| Qué | Por qué suma |
|---|---|
| Se guarda el JSON original de cada checada (`raw_payload`) | Permite auditar o recalcular si cambia una regla |
| Respaldo cronológico cuando el reloj no clasifica la checada | El sistema funciona aunque el aparato no esté configurado con teclas de estado |
| Compatibilidad con respuestas JSON y XML de distintos firmwares | Menos fallas al cambiar de modelo o versión |

---

## 5. ⚠️ Parcial

Los apartados parciales de la tabla 2 se detallan como faltantes en la sección 6 con su clave.

---

## 6. ❌ Faltante

### 🔴 Alta (bloquea producción)

**SE-01 · No hay inicio de sesión** (4.2, OWASP A01/A07) · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/16-20_inicio-de-sesion-y-cuentas.md`
- Evidencia: `routes/web.php` no tiene middleware `auth`; `bootstrap/app.php` no registra ninguno. La tabla `users` existe pero no se usa.
- Consecuencia: cualquier equipo en la red puede ver RFC, CURP y NSS, borrar empleados, mandar usuarios al reloj o activar el lector de huellas.
- Qué hacer: login con el sistema de Laravel (por ejemplo Breeze sin registro público), `bcrypt` o Argon2id, límite de intentos (`throttle`), todas las rutas dentro de `Route::middleware('auth')`, y una prueba que falle si una ruta queda sin `auth`.

**SE-02 · No hay roles ni permisos** (4.4)
- Evidencia: `app/Http/Requests/EmployeeFormRequest.php:10-12` regresa `true`; los controladores no revisan permisos.
- Consecuencia: aunque haya login, cualquier usuario podría borrar empleados o dar de baja en el reloj.
- Qué hacer: al menos dos roles (administración y solo consulta), con *Gates* o *Policies* en cada ruta que cambia datos o toca la terminal. Depende de TE-05.

**SE-03 · Borrar un empleado borra su bitácora de checadas** (4.14, 4.18) · ✅ Resuelto 17/11/2026: `docs/parches/2026-11-17/11-30_baja-logica-conserva-checadas.md`
- Evidencia: `EmployeeController.php:103` hace `$employee->delete()`; las migraciones `..._create_attendance_logs_table.php:18` y `..._create_daily_summaries_table.php:18` tienen `cascadeOnDelete()`.
- Consecuencia: se pierden las checadas y el kardex de esa persona, aunque el modelo dice que la bitácora es inmutable. Los registros de asistencia pueden hacer falta ante una revisión laboral.
- Qué hacer: baja lógica (`is_active = false`, y opcionalmente `SoftDeletes`), cambiar a `restrictOnDelete()` y una prueba que confirme que las checadas sobreviven a la baja.

**SE-04 · La plantilla de huella llega al navegador y al log** (4.21, 4.15) · ✅ Resuelto 17/11/2026: `docs/parches/2026-11-17/11-32_huella-fuera-de-respuestas-y-logs.md`
- Evidencia: `HikvisionIsapiService.php:285` incluye `device_response` (con `fingerData`) en el resultado; `EmployeeController.php:179` lo regresa como `detail`. `HikvisionIsapiService.php:518` guarda `raw_body` completo en el log cuando la terminal falla.
- Consecuencia: un dato biométrico (sensible según la LFPDPPP) queda en el navegador, en las herramientas de desarrollo y en `storage/logs`.
- Qué hacer: quitar `device_response` y `detail` de las respuestas; en el log, solo `statusCode`, `subStatusCode` y `errorMsg`. Prueba que confirme que la respuesta no contiene `fingerData`.

### 🟡 Media

| Clave | Hallazgo | Evidencia | Qué hacer |
|---|---|---|---|
| SE-05 | RFC, CURP y NSS completos en el JSON del listado y del kardex, y en texto plano en la base · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-35_rfc-curp-nss-cifrados-y-enmascarados.md` | `EmployeeController.php:211-213`, `ReportController.php:108-110` | Enmascarar en listados (`VELC********`), mostrar completo solo en el formulario a quien tenga permiso; *cast* `encrypted` en la base (4.8, 4.21) |
| SE-06 | Comunicación con la terminal sin cifrar | `.env.example:32` `HIKVISION_URL=http://…`; Digest Auth sobre HTTP | Habilitar HTTPS en la terminal si el firmware lo permite; si no, red o VLAN aislada entre servidor y terminal (4.8, 4.19). Depende de TE-04 |
| SE-07 | Sin límites de peticiones en acciones costosas · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` | `POST /device/sync`, `/employees/{id}/capture-fingerprint`, `/reports/kardex/data` sin `throttle` | `throttle` por usuario en estas rutas (4.12) |
| SE-08 | Configuración de producción y cabeceras · ⚠️ Parcial 18/11/2026: cabeceras en `docs/parches/2026-11-18/17-36_cabeceras-de-seguridad.md`; falta el `.env` de producción | `APP_DEBUG=true` en `.env.example`; sin `SESSION_SECURE_COOKIE`; sin cabeceras de seguridad | En producción: `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true` y un middleware con `nosniff`, `X-Frame-Options`, `Referrer-Policy`, HSTS y CSP básica (4.9, 4.10) |
| SE-09 | No hay bitácora de quién hizo qué · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/16-25_bitacora-de-acciones.md` | Altas, cambios, bajas, sincronizaciones y capturas de huella no registran usuario | Tabla de auditoría (usuario, acción, registro, antes y después, IP, fecha) (4.15). Depende de SE-01 |
| SE-10 | La captura marca "Huella OK" aunque no llegue la plantilla · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` | `HikvisionIsapiService.php:276` actualiza `fingerprint_enrolled = true` siempre | Marcarla solo si se recibió y se vinculó la plantilla; si no, mensaje de error (4.13) |
| SE-11 | Turnos que cruzan la medianoche se calculan mal | `recalculateForEmployeeDate()` agrupa por día calendario; `WorkShift::ordinaryDailyMinutes()` sí contempla el cruce | Asignar la checada a la jornada según el turno, no a la fecha (4.14). Depende de TE-07 |

### 🟢 Baja

| Clave | Hallazgo | Evidencia | Qué hacer |
|---|---|---|---|
| SE-12 | Mensajes de error con detalle interno · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` | `HikvisionIsapiService.php:141` y `:427` concatenan `$e->getMessage()` (cURL, URL) en la respuesta | Mensaje genérico al usuario y detalle solo en el log (4.13) |
| SE-13 | Sincronización manual y programada pueden correr a la vez · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` | `DeviceController::sync()` no usa el candado de `withoutOverlapping()`; el resumen diario se busca y luego se crea | `Cache::lock()` compartido y `updateOrCreate` dentro de una transacción (4.13, 6.7) |
| SE-14 | `composer audit` sin ejecutar · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-15_wijmo-por-pagina-y-empleados-paginados.md` | No se pudo correr en esta revisión | Correr `composer audit` y agregarlo al flujo antes de cada entrega (4.16) |

### Optimización

| Prioridad | Clave | Hallazgo | Evidencia | Qué hacer |
|---|---|---|---|---|
| 🟡 Media | OP-01 | Cada minuto se descarga todo el día desde las 00:00 · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` | `HikvisionSyncCommand.php:28`, `DeviceController.php:22` | Sincronización incremental: desde la última `punched_at` guardada menos un margen (por ejemplo 10 min) |
| 🟡 Media | OP-02 | Varias consultas por cada checada y recálculo repetido · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` | `AttendanceCalculatorService.php:76` (busca el empleado aunque ya hay mapa), `:86` (busca duplicado), `:108` (recalcula por cada checada) | Usar el mapa `knownEmployees`, traer las checadas existentes del rango en una consulta, insertar en lote y recalcular una vez por empleado y día |
| 🟡 Media | OP-03 | `whereDate()` impide usar los índices · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` | `DashboardController.php:41` y `:155`, `AttendanceCalculatorService.php:190` | `whereBetween('punched_at', [inicio, fin])` y `where('work_date', $fecha)` (6.2) |
| 🟡 Media | OP-04 | Kardex sin rango máximo ni paginación · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` | `ReportController::resolveFilters()` acepta cualquier rango | Validar fechas con `date` y un máximo (por ejemplo 93 días); exportaciones grandes en cola (6.10). También cubre SE-07 |
| 🟡 Media | OP-05 | Estado de la terminal consultado cada 30 s por cada pestaña · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-00_sincronizacion-incremental-y-en-lote.md` | `resources/js/app.js:234`; cada llamada espera hasta 5 s si la terminal no responde | Guardar el estado en caché del servidor de 30 a 60 s |
| 🟢 Baja | OP-06 | Todas las librerías de Wijmo y JSZip en todas las páginas · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-15_wijmo-por-pagina-y-empleados-paginados.md` | `resources/js/app.js:1-11` | Importar por página (`import()` dinámico) |
| 🟢 Baja | OP-07 | Listado de empleados completo embebido en la página · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-15_wijmo-por-pagina-y-empleados-paginados.md` | `EmployeeController::serializeEmployees()` | Suficiente para cientos de empleados; paginar si se pasa de ~1,000 |
| 🟢 Baja | OP-08 | `raw_payload` crece sin plazo de conservación | Una fila JSON por checada; el kardex manda hasta 250 | Definir retención y archivado (depende de TE-08); en el kardex, mandar el JSON solo al abrirlo |
| 🟢 Baja | OP-09 | Cachés y tarea programada de producción | Sin guía de despliegue | `config:cache`, `route:cache`, `view:cache`, OPcache y cron de `schedule:run` en la guía de despliegue |

---

## 7. Resumen priorizado

| Prioridad | Pendiente | Esfuerzo estimado |
|---|---|---|
| 🔴 Alta | SE-01 Login | ✅ Resuelto 18/11/2026 |
| 🔴 Alta | SE-02 Roles | 1 día (después de TE-05) |
| 🔴 Alta | SE-03 Baja lógica sin borrar checadas | ✅ Resuelto 17/11/2026 |
| 🔴 Alta | SE-04 Quitar la huella de respuestas y logs | ✅ Resuelto 17/11/2026 |
| 🟡 Media | SE-05 a SE-11 | 2 a 3 días en total |
| 🟡 Media | OP-01 a OP-05 | 1 a 2 días en total |
| 🟢 Baja | SE-12 a SE-14, OP-06 a OP-09 | 1 día en total |

---

## 8. Recomendación

1. Corregir primero SE-03 y SE-04: son cambios chicos y evitan pérdida de datos y exposición de biométricos.
2. Agregar login y roles (SE-01, SE-02) antes de conectar la terminal real o cargar empleados reales.
3. Llevar al cliente las preguntas de `docs/pendientes/teracrom/`, sobre todo el "por qué sale" (TE-01) y el aviso de privacidad (TE-02), porque cambian el diseño.
4. Al probar con la terminal real, aplicar OP-01 a OP-03 y medir el antes y el después.
5. Antes de publicar, recorrer la lista 8.3 de la guía (SE-06, SE-08, OP-09) y verificar contra ASVS nivel 2, porque el sistema maneja datos personales sensibles.
