# Horarios de comida, recálculo de turnos y diseño responsivo

> Parche · 22/11/2026 · Corrige cálculo en `0` de comidas, retardos y horas trabajadas en registros del DS-K1T804AEF y adapta la interfaz para tablets y celulares (`320px–1024px`).

---

## 1. Contexto / Causas detectadas

| Problema | Causa raíz en datos reales | Solución aplicada |
|---|---|---|
| `meal_minutes = 0` e `in_meal = 0` aunque había checadas de comida | El DS-K1T804AEF reportó `statusValue: 3` (`breakIn`) al salir a comer y `statusValue: 4` (`breakOut`) al regresar, invirtiendo `break_out`/`break_in` (`break_in < break_out` o `break_out = NULL` con `break_in` huérfano) | `HikvisionIsapiService::mapAttendanceStatus()` prioriza `statusValue` (`3 => break_out`, `4 => break_in`); `AttendanceCalculatorService::resolveDailyPunchSlots()` normaliza comidas invertidas o de una sola marca |
| `late_minutes = 0` y horas desplazadas | La terminal envió timestamps con offset `+08:00` de fábrica sobre la hora local real (`14:30:08+08:00`), y `setTimezone('America/Mexico_City')` restaba 14 horas (`00:30:08`) | Se agregó `HIKVISION_USE_DEVICE_WALL_TIME=true` (`config/services.php`, `parseDeviceTimestamp()`) para respetar la hora de pared del reloj sin desfase |
| 2 checadas sin clasificar quedaban con `worked_minutes = 0` | El respaldo cronológico asignaba la 2.ª checada `unclassified` a `break_out` dejando `last_check_out = NULL` | `resolveDailyPunchSlots()` asigna la 2.ª checada a `check_out` si la jornada ya cerró o si ocurre dentro de los 90 min previos a `exit_time` |
| Editar un turno no actualizaba resúmenes | `WorkShiftController::update()` solo guardaba el turno sin recalcular `daily_summaries` | Llama a `AttendanceCalculatorService::recalculateAllForShift($shift)` cuando cambian horarios o tolerancias; nuevo comando `php artisan attendance:recalculate` |
| KPIs nocturnos y monitor en 0 tras medianoche / días previos | `DashboardController` solo consultaba `work_date = hoy` y no tenía fallback cuando hoy no había checadas | Incluye turnos nocturnos activos iniciados ayer (`SE-11`) y expone `live_logs_scope` (`today` o `recent` hasta 50 checadas) |
| Modales recortados y controles ocultos en móvil/tablet | `<dialog>` sin cuerpo scrollable, tabla de turnos sin `@container`, botón de sincronizar oculto en móvil y alturas fijas en Wijmo | `viewport-fit=cover`, `safe-area-inset-*`, `.app-dialog-body` scrollable, `.shifts-table` apilable vía `@container`, `--target-size` (`44px` táctil), alturas fluidas `dvh`/`clamp()` |

---

## 2. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Console/Commands/RecalculateAttendanceCommand.php`, este documento |
| Modificados | `.env.example`, `.env.production.example`, `config/services.php`, `app/Services/HikvisionIsapiService.php`, `app/Services/AttendanceCalculatorService.php`, `app/Http/Controllers/WorkShiftController.php`, `app/Http/Controllers/DashboardController.php`, `resources/css/app.css`, `resources/css/wijmo.css`, `resources/views/layouts/app.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/employees/index.blade.php`, `resources/views/reports/kardex.blade.php`, `resources/views/shifts/index.blade.php`, `tests/Unit/HikvisionIsapiServiceTest.php`, `tests/Feature/HikvisionSyncAndCalculatorTest.php`, `tests/Feature/ExampleTest.php`, `docs/README.md`, `docs/CONTEXTO_SISTEMA_CHECADOR.md` |

---

## 3. Pasos manuales

1. `git pull`
2. Agregar `HIKVISION_USE_DEVICE_WALL_TIME=true` en `.env` (y ajustar el huso horario en la terminal física a `UTC-06:00`).
3. `npm run build`
4. `php artisan optimize:clear`
5. `php artisan attendance:recalculate` (recalcula los `daily_summaries` existentes a partir de `attendance_logs` sin modificar la bitácora inmutable).

---

## 4. Cómo verificar

1. `./vendor/bin/phpunit`: pasan las **84** pruebas (`463` aserciones).
2. `php artisan attendance:recalculate`: verifica que `meal_minutes` y los slots de comida se recalculan en base de datos.
3. Probar la interfaz en ancho móvil (`320px–576px`) y tablet (`768px–1024px`) confirmando tabla apilada en `/shifts`, modales con scroll interno y barra superior sin desborde.
