# Límites de peticiones, rango del kardex, huella sin plantilla y errores genéricos

> Parche · 18/11/2026 · Paquete 1 de pendientes que no dependen del cliente: cierra SE-07, OP-04, SE-10 y SE-12 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md.

---

## 1. Contexto / Causa

| Clave | Problema | Causa |
|---|---|---|
| SE-07 | Se podía sincronizar, capturar huellas o pedir reportes sin límite | Ninguna ruta tenía `throttle` |
| OP-04 | El kardex aceptaba cualquier rango (por ejemplo, años completos) | `ReportController::resolveFilters()` no tenía máximo |
| SE-10 | Se marcaba "Huella OK" aunque la terminal no regresara la plantilla | `triggerRemoteFingerprintCapture()` actualizaba `fingerprint_enrolled` siempre |
| SE-12 | El usuario veía errores técnicos (cURL, puertos, tiempos) | Los mensajes concatenaban `$e->getMessage()` |

---

## 2. Fix aplicado

### 2.1 SE-07 · Límites de peticiones

| Límite | Rutas | Valor |
|---|---|---|
| `device` | `POST /device/sync`, `POST /employees/{id}/sync-device`, `DELETE /employees/{id}/device`, `POST /employees/{id}/capture-fingerprint` | 10 por minuto |
| `reports` | `GET /reports/kardex`, `GET /reports/kardex/data` | 30 por minuto |

Se cuentan por usuario y, mientras no haya login, por IP. Al pasarse, responde 429 con el mensaje "Demasiadas solicitudes. Espera un momento e intenta de nuevo." (JSON para las peticiones de la pantalla y texto para el navegador). `GET /device/status` no se limita porque lo consulta la pantalla cada 30 s; eso se atiende en OP-05.

### 2.2 OP-04 · Rango máximo del kardex

| Qué | Cómo quedó |
|---|---|
| Máximo | `ReportController::MAX_RANGE_DAYS = 93` |
| Si se excede | Se recorta la fecha de inicio a 93 días antes de la fecha fin y la pantalla muestra un aviso amarillo |
| Datos nuevos en `filters` | `range_adjusted` y `max_range_days` |

Se recorta en lugar de rechazar porque el kardex es un formulario GET: un error de validación redirigiría a la misma URL.

### 2.3 SE-10 · Huella sin plantilla

Si la terminal responde OK pero sin `fingerData`, se registra un aviso en el log, se lanza el error "La terminal no devolvió la huella capturada. Coloca el dedo de nuevo en el lector e intenta otra vez." y el empleado **no** queda marcado con huella. Ya no se llama a `FingerPrint/SetUp` sin plantilla.

### 2.4 SE-12 · Errores genéricos

| Lugar | Antes | Ahora |
|---|---|---|
| `sendRequest()` sin conexión | "No se pudo conectar … : cURL error 7: …" | "No se pudo conectar con la terminal Hikvision en {IP}. Verifica que esté encendida y en la misma red." El detalle va al log |
| `testConnection()` error inesperado | "Error de comunicación ISAPI: {detalle}" | "Error inesperado al comunicarse con la terminal. Revisa el log del sistema." |

Los mensajes de la propia terminal (por ejemplo `employeeNoAlreadyExist`) se siguen mostrando porque ayudan a corregir el dato.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `tests/Feature/RateLimitAndReportRangeTest.php`, este documento |
| Modificados | `app/Providers/AppServiceProvider.php` (`configureRateLimiting()`), `routes/web.php`, `app/Http/Controllers/ReportController.php` (`resolveFilters()`), `resources/views/reports/kardex.blade.php` (aviso), `app/Services/HikvisionIsapiService.php` (`triggerRemoteFingerprintCapture()`, `sendRequest()`, `testConnection()`), `tests/Unit/HikvisionIsapiServiceTest.php`, `tests/Feature/EmployeeManagementTest.php` |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `php artisan optimize:clear`
4. `npm run build` (o `npm run dev` abierto) y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **28**. Nuevas: `test_device_sync_is_rate_limited`, `test_reports_are_rate_limited`, `test_kardex_range_is_limited_to_max_days`, `test_kardex_range_within_limit_is_not_adjusted`, `test_kardex_page_warns_when_range_is_adjusted`, `test_capture_without_template_fails_and_does_not_mark_fingerprint`, `test_connection_error_message_hides_technical_detail`. La prueba `test_employee_crud_and_isapi_actions` ahora simula una captura con plantilla.
2. Kardex → Fecha inicio 01/01/2025, Fecha fin hoy → Filtrar: aparece el aviso del rango máximo y la fecha de inicio cambia.
3. Sin la terminal conectada, Empleados → Sincronizar: el aviso dice "No se pudo conectar … Verifica que esté encendida…", sin texto de cURL.
4. Presionar "Sincronizar Ahora" más de 10 veces en un minuto: aparece "Demasiadas solicitudes…".

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | SE-15 · Fórmulas en la exportación CSV del kardex (nuevo) | `csvEscape()` en `resources/views/reports/kardex.blade.php` no antepone `'` a valores que empiezan con `=`, `+`, `-` o `@`; un nombre o departamento así se ejecutaría como fórmula al abrir el CSV en Excel (guía 4.5) |
| 🟢 Baja | Límites por IP | Mientras no haya login (SE-01), varios equipos detrás de la misma IP comparten el límite |
