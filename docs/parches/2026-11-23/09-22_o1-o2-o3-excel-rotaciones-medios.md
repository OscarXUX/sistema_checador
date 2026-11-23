# Parche O1, O2 y O3: Excel mensual, turnos rotativos y medios de marcaje

> Parche · 23/11/2026 09:22 · Implementación en orden de las tres tareas asignadas a Oscar (O1, O2 y O3).

---

## 1. O1 · Excel del kardex por mes (notas 7 y 9)

- **Generación en servidor:** Se instaló `phpoffice/phpspreadsheet` (`^5.12`) con `composer audit` limpio (0 avisos de seguridad) y se creó `App\Services\KardexExcelExportService`.
- **Alcance de exportación vs pantalla:** `ReportController::MAX_RANGE_DAYS` conserva el límite de **93 días** en pantalla (`/reports/kardex` y `/reports/kardex/data`), mientras `GET /reports/kardex/export/xlsx` (`ReportController::exportXlsx`) genera un archivo `.xlsx` con **una pestaña por cada mes calendario** (`Y-m`) hasta un máximo de **12 meses**. Rangos de 13 o más meses son rechazados con HTTP `422`.
- **Formato y seguridad:** Encabezados en negritas, primera fila congelada (`A2`), autofiltro (`A1:R{ultimaFila}`), anchos de columna explícitos, fechas con formato `dd/mm/yyyy` (`dd/mm/aaaa`), horas con `0.00` y fila de totales por empleado por mes. Todas las celdas de texto se escriben con `DataType::TYPE_STRING` (`@`) para evitar inyección/evaluación de fórmulas (`=`, `+`, `-`, `@`), `RFC`/`CURP`/`NSS` van enmascarados mediante `PersonalData`, y cada descarga queda registrada en `audit_logs` (`kardex.exported_xlsx`).
- **Front-end:** En `resources/js/wijmo/kardex.js` y `resources/views/reports/kardex.blade.php` se retiró `FlexGridXlsxConverter` del cliente y el botón **Exportar Excel (.xlsx)** abre un diálogo que solicita rango hasta 12 meses y descarga directo desde el servidor.

---

## 2. O2 · Turnos rotativos y excepciones (notas 11 a 13)

- **Esquema y modelos (`2026_11_23_000001_create_shift_schedules_tables`):**
  - `shift_rotations` y `shift_rotation_steps` (`App\Models\ShiftRotation`, `App\Models\ShiftRotationStep`): pasos ordenados (`1→2→3`, `3→2→1`, `2→1→3`, etc.) y columna `cycle_days` ("días por ciclo").
  - `employee_groups` y `employee_group_employee` (`App\Models\EmployeeGroup`): cuadrillas/grupos de empleados.
  - `shift_rotation_assignments` (`App\Models\ShiftRotationAssignment`): asignaciones con vigencia (`starts_on`, `ends_on`) hacia empleado, grupo o departamento.
  - `shift_exceptions` (`App\Models\ShiftException`): excepciones puntuales por empleado y rango de fechas.
- **Resolución y cálculo:** `App\Services\ShiftScheduleResolver` determina el turno vigente por fecha respetando la prioridad estricta **`excepción > empleado > grupo > departamento > turno actual del empleado`**, incluyendo precarga en lote (`loadBatchContext()` / `previewForRange()`). `AttendanceCalculatorService` usa este resolver tanto en la evaluación de checadas/retardos/horas extra como en ventanas de turnos nocturnos, y `attendance:recalculate` incorpora `--from`, `--to`, `--employee`, `--group`, `--department` y `--rotation`.
- **Pantalla Turnos (`/shifts`):** `WorkShiftController` y `ShiftScheduleController` gestionan Turnos, Rotaciones, Asignaciones (incluyendo grupos) y Excepciones recalculando automáticamente las jornadas afectadas con `attendance:recalculate`. La vista `resources/views/shifts/index.blade.php` integra pestañas accesibles (`Turnos`, `Rotaciones`, `Asignaciones`, `Excepciones`) y una **Vista Previa de 14 Días** responsiva.

---

## 3. O3 · Medios de marcaje (nota 5)

- **Catálogo y esquema:**
  - Migración `2026_11_23_000002_create_marking_methods_table` (`App\Models\MarkingMethod`) sembrada con `fingerprint`, `face`, `card` y `pin`.
  - Migración `2026_11_23_000003_add_marking_credentials_status_to_employees_table`: agrega `card_no`, `card_enrolled` y `pin_configured` en `employees`. **No existe columna para almacenar el PIN ni su hash en BD.**
- **Detección ISAPI y altas en terminal:** `HikvisionIsapiService::detectVerificationCapabilities()` consulta `/ISAPI/AccessControl/capabilities` y sub-recursos (`FingerPrint`, `CardInfo`, `UserInfo`, `FDLib`) para detectar qué medios soporta el hardware. Desde Empleados se permite el alta remota de **Tarjeta RFID** (`CardInfo/Record`) y **PIN** (`UserInfo/Modify`); el PIN se excluye explícitamente en `AuditLogger::EXCLUDED_FIELDS` y `HikvisionIsapiService::SENSITIVE_KEYS`, nunca persiste en BD ni logs. El medio **Rostro** permanece bloqueado tanto para activación en catálogo como para enrolamiento por ser biométrico en espera del aviso de privacidad **`TE-02`**.
- **Inspección de `currentVerifyMode`:**
  - `HikvisionIsapiService::mapVerificationMode()` pasa a método público y combina `currentVerifyMode` (modos simples y reglas compuestas `*Or*` como `cardOrFp` o `faceOrFpOrCardOrPw`) junto con códigos `minor` reales de `AcsEvent`.
  - Se ejecutó `php artisan hikvision:inspect-verify-modes` sobre los 5 registros existentes en la base local (`MariaDB`): los 5 eventos presentan `currentVerifyMode = (ausente)` y `minor = 38`, mapeados a `fingerprint`.
  - La terminal física en `192.168.0.69` no estuvo alcanzable desde esta red al momento de la ejecución (`ping`/TCP timeout); cuando el equipo esté conectado a la LAN del reloj se debe ejecutar `php artisan hikvision:inspect-verify-modes --live` o pulsar **Detectar en Terminal (ISAPI)** en `/marking-methods`.

---

## 4. Validación y pruebas

- `./vendor/bin/pint --test` (archivos modificados/nuevos): aprobado (`passed`).
- `./vendor/bin/phpunit --no-progress`: `OK (95 tests, 567 assertions)` (84 previas + `KardexExcelExportTest`, `ShiftScheduleTest` y `MarkingMethodsTest`).
- `php artisan migrate --force` y `npm run build` ejecutados sin errores.
