# Turnos nocturnos (SE-11), payload bajo demanda en kardex (OP-08) y guía de despliegue (OP-09 / SE-08)

> Parche · 22/11/2026 · Integra las correcciones locales pendientes con `origin/main` y resuelve lo accionable en código y documentación sin depender de respuestas del cliente.

---

## 1. Contexto / Causa

| Clave | Situación previa | Corrección |
|---|---|---|
| Captura remota y turnos | En `main` local había correcciones sin subir para `0x50000001` en `CaptureFingerPrint` y aviso cuando no hay turnos registrados; faltaba integrarlas con `origin/main` (`SE-04`, `SE-10`) | Merge limpio preservando la validación estricta de `fingerData`, la no devolución de `device_response` y la redacción de logs (`redactSensitive`) |
| `SE-11` | `recalculateForEmployeeDate()` filtraba solo por día calendario (`00:00:00` a `23:59:59`), partiendo en dos las checadas de turnos que cruzan medianoche aunque `WorkShift::ordinaryDailyMinutes()` ya los contemplaba | `WorkShift` expone `crossesMidnight()`, `shiftWindowForDate()` y `resolveWorkDateForPunch()`; `AttendanceCalculatorService` y `AttendanceLog` agrupan y recalculan por ventana de jornada |
| `OP-08` (kardex) | `/reports/kardex` y `/reports/kardex/data` serializaban hasta 250 `raw_payload` completos en cada carga aunque el usuario no abriera ningún modal | `queryAuditLogs()` omite `raw_payload` y solo se consulta bajo demanda vía `GET /reports/kardex/logs/{attendanceLog}/payload` (protegido con `auth` y `throttle:reports`) |
| `OP-09` / `SE-08` | Faltaba plantilla `.env.production.example` y guía operativa con comandos de caché (`optimize`) y `schedule:run` en producción | Se agregaron `.env.production.example` y `docs/despliegue/2026-11-22/20-00_GUIA_DESPLIEGUE_PRODUCCION.md` |

---

## 2. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `.env.production.example`, `docs/despliegue/2026-11-22/20-00_GUIA_DESPLIEGUE_PRODUCCION.md`, este documento |
| Modificados | `app/Models/WorkShift.php`, `app/Models/AttendanceLog.php`, `app/Services/AttendanceCalculatorService.php`, `app/Http/Controllers/ReportController.php`, `routes/web.php`, `resources/views/reports/kardex.blade.php`, `tests/Feature/HikvisionSyncAndCalculatorTest.php`, `tests/Feature/RateLimitAndReportRangeTest.php`, `docs/README.md`, `docs/CONTEXTO_SISTEMA_CHECADOR.md`, `docs/pendientes/` |

---

## 3. Cómo verificar

1. `./vendor/bin/phpunit`: pasan todas las pruebas (incluyendo `test_overnight_shift_groups_punches_into_shift_start_date` y `test_kardex_audit_logs_load_raw_payload_on_demand`).
2. `php artisan optimize` y luego `php artisan optimize:clear` en local para comprobar que `config:cache`, `route:cache` y `view:cache` compilan sin errores.
3. En Kardex, verificar con F12 → Red que la vista inicial no trae `raw_payload` en el JSON embebido y que al pulsar **JSON** en un evento consulta `/reports/kardex/logs/{id}/payload`.
