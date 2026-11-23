# Corrección manual de checadas e incidencias en el dashboard

> Parche · 23/11/2026 · Sigue a `docs/parches/2026-11-23/10-25_ventanas-tipos-evento-y-validaciones.md` (sección 6). Aprobado por Danny: permiso propio, motivo obligatorio y la checada original sin cambios.

---

## 1. Contexto / Causa

| Pendiente | Antes |
|---|---|
| Corrección manual (propuesta 3.3) | Si el reloj o las ventanas clasificaban mal una checada, no había forma de arreglarlo sin tocar la base |
| Incidencias en el dashboard | Las incidencias solo se veían en el kardex |

---

## 2. Fix aplicado

### 2.1 Corrección de checadas

| Qué | Cómo quedó |
|---|---|
| Dónde | Kardex → botón "Ver" de la columna "Checadas" (al lado de la fecha); el lápiz indica que el día tiene correcciones |
| Qué muestra | Cada checada del día: hora, tecla del reloj, cómo cuenta (Entrada, Salida a comer, Regreso de comer, Salida o No cuenta), incidencias y la corrección si la tiene (quién, cuándo y motivo) |
| Qué se puede hacer | Con `kardex.corregir`: elegir cómo debe contar o "No cuenta", con motivo obligatorio (5 a 255 caracteres); quitar la corrección para que vuelva a clasificarse sola |
| Regla | La corrección gana a las ventanas y a la detección de repetidas. La checada de `attendance_logs` no se modifica |
| Después de guardar | Se recalcula el día, se actualiza el kardex y queda en la bitácora (`attendance.corrected`, `attendance.correction_removed`) |
| Permiso | `kardex.corregir` (nuevo). Lo tiene el administrador; ver las checadas del día solo pide `kardex.consultar` |

### 2.2 Incidencias en el dashboard

Aviso amarillo debajo de los indicadores con el total de incidencias de las jornadas en curso y las 30 más recientes (empleado, incidencia y hora), con el botón "Revisar y corregir en Kardex" que abre el kardex del día. Se actualiza con el botón "Refrescar" y después de sincronizar.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `database/migrations/2026_11_23_000006_create_attendance_log_corrections_table.php`, `app/Models/AttendanceLogCorrection.php`, `app/Http/Controllers/PunchCorrectionController.php`, `tests/Feature/PunchCorrectionTest.php`, este documento |
| Modificados | `app/Services/PunchClassifier.php` (correcciones y qué fue cada checada), `app/Services/AttendanceCalculatorService.php` (`classifyDay()`), `app/Models/AttendanceLog.php` (`correction()`), `app/Models/DailySummary.php` (`corrected_punches`), `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/DashboardController.php`, `resources/views/reports/kardex.blade.php`, `resources/views/dashboard.blade.php`, `routes/web.php`, `app/Support/Permissions.php`, documentación |

---

## 4. Pasos manuales

1. `git pull`
2. `php artisan migrate`
3. `php artisan permisos:sincronizar` (agrega `kardex.corregir`).
4. `php artisan optimize:clear`, `npm run build` y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **136** (nuevas: las 8 de `PunchCorrectionTest`).
2. Kardex → "Ver" en un día con "Entrada repetida": cambiar esa checada a "Salida a comer" con un motivo y guardar. El día se recalcula, la incidencia desaparece y la fila muestra el lápiz.
3. Guardar sin motivo: aviso "Escribe el motivo de la corrección."
4. "Quitar" en la checada corregida: vuelve a su clasificación automática.
5. Con el perfil Consulta: se ven las checadas pero no la columna "Corregir".
6. Dashboard con checadas repetidas hoy: aparece el aviso "Incidencias de checadas hoy".
