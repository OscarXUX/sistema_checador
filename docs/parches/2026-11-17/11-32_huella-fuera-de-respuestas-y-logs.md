# La plantilla de huella ya no sale del servidor

> Parche · 17/11/2026 · Cierra SE-04 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| La plantilla biométrica (`fingerData`) llegaba al navegador al capturar una huella | `triggerRemoteFingerprintCapture()` devolvía la respuesta completa de la terminal en `device_response`, y `EmployeeController::captureFingerprint()` la mandaba como `detail` |
| La plantilla podía quedar en `storage/logs` | Cuando la terminal respondía con error, `assertIsapiSuccess()` registraba `body` y `raw_body` completos |

La huella es un dato biométrico, sensible según la LFPDPPP.

---

## 2. Fix aplicado

| Qué | Cómo quedó |
|---|---|
| Resultado de la captura | Ya no incluye `device_response`; conserva `success`, `employee_no`, `finger_no`, `template_captured` y `message` |
| Respuesta al navegador | Ya no manda `detail`; manda `finger_no` y `template_captured` |
| Log de errores de la terminal | Ya no guarda `raw_body`. `body` pasa por `redactSensitive()`, que cambia por `[REDACTADO]` las llaves `fingerData`, `faceData`, `facePic`, `facePicData`, `picData`, `password` y `pwd` (sin importar mayúsculas, en cualquier nivel) y recorta a 500 caracteres el texto crudo que no sea JSON ni XML |

La prueba de Oscar que revisa que el cuerpo del error se registre sigue pasando: el detalle para depurar se conserva, sin los datos sensibles.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | Este documento |
| Modificados | `app/Services/HikvisionIsapiService.php` (`triggerRemoteFingerprintCapture()`, `assertIsapiSuccess()`, nuevo `redactSensitive()`), `app/Http/Controllers/EmployeeController.php` (`captureFingerprint()`), `tests/Unit/HikvisionIsapiServiceTest.php`, `tests/Feature/EmployeeManagementTest.php` |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones en este parche.
3. `php artisan optimize:clear`
4. Revisar si `storage/logs/laravel.log` tiene errores de la terminal anteriores a este parche; si los hay, vaciarlo, porque pudo guardar plantillas.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **21** (nuevas de este parche: `test_capture_result_does_not_include_fingerprint_template`, `test_error_log_redacts_fingerprint_template`, `test_fingerprint_capture_response_does_not_expose_template`).
2. Con la terminal conectada: Empleados → Huella → Activar lector. En las herramientas del navegador (F12 → Red), la respuesta de `capture-fingerprint` no debe tener `fingerData` ni `detail`.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | "Huella OK" sin plantilla | Sigue abierto SE-10: si la terminal no regresa la plantilla, el empleado se marca con huella igual |
| 🟡 Media | Transporte sin cifrar | La plantilla viaja entre servidor y terminal por HTTP (SE-06) |
