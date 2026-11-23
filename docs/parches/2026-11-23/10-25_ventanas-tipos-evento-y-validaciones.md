# Ventanas de horario, tipos de evento y validación de checadas

> Parche · 23/11/2026 · Paquete 3 de la propuesta `docs/propuestas/2026-11-22/23-15_propuesta-notas-oscar-perfiles-catalogos-turnos.md` (notas 3, 4 y 6 de Oscar; respuestas P6 y P7).

---

## 1. Contexto / Causa

| Nota | Pedido de Oscar | Antes |
|---|---|---|
| 6 | "Que sea por hora y no por orden: de 7 a 10 entrada, de 5 a 7 salida… y que se pueda modificar en los catálogos". P6: "por turnos con un catálogo" | La checada tomaba la tecla del reloj o el orden (1.ª entrada, 2.ª salida a comer…) y una regla fija de 90 minutos |
| 3 | "Detectar y/o checar repetidas o fuera de orden" | Solo se evitaban duplicados exactos (mismo segundo) |
| 4 | Catálogo de tipo de evento: "sí entró, sí 5 minutos tarde, etc.". P7: "esos los creamos nosotros" | Solo había el estatus fijo (A tiempo, Retardo, Comida excedida…) |

---

## 2. Fix aplicado

### 2.1 Ventanas de horario por turno (nota 6)

| Qué | Cómo quedó |
|---|---|
| Dónde | Catálogos → Ventanas de horario (`/catalogos/ventanas`) |
| Datos | Por turno: ventana de entrada (desde–hasta), ventana de salida y minutos para considerar una checada repetida (3 por omisión) |
| Por omisión | Entrada de 60 min antes a 120 min después de la hora del turno; salida de 60 min antes a 180 min después |
| Reglas al guardar | La ventana de entrada debe incluir la hora de entrada, la de salida la de salida, y no pueden encimarse. Se recalculan los resúmenes del turno y queda en la bitácora |
| Turnos nocturnos | Las ventanas se anclan a la hora de entrada y a la de salida, también si cruzan la medianoche |

### 2.2 Clasificación y validaciones (notas 3 y 6)

`App\Services\PunchClassifier` decide qué es cada checada. La bitácora de checadas no se modifica: lo que no cuenta queda como **incidencia** en el resumen del día.

| Caso | Resultado | Incidencia |
|---|---|---|
| Checada a menos de N minutos de la anterior | Se ignora | Checada repetida |
| Dentro de la ventana de entrada | Entrada (la primera; las demás se ignoran) | Entrada repetida |
| Dentro de la ventana de salida | Salida (la última; las anteriores se ignoran) | Salida repetida |
| Entre las dos ventanas | Comida; si aún no hay entrada, es una llegada tarde; con la tecla de salida del reloj, es salida | — |
| Teclas de comida del reloj | Comida (la primera es salida a comer y la última el regreso) | Checada de comida de más (las de en medio) |
| Antes de la entrada o después de la salida | Se usa la tecla o, sin tecla, entrada (antes) o salida (después) | Fuera de ventana de horario |
| Día cerrado, sin salida y comida impar | La última checada es la salida (salida anticipada) | — |
| Salida sin entrada | Jornada incompleta | Salida sin entrada |

Los empleados sin turno siguen con la clasificación anterior.

### 2.3 Catálogo de tipos de evento (nota 4)

| Qué | Cómo quedó |
|---|---|
| Dónde | Catálogos → Tipos de evento (`/catalogos/tipos-evento`) |
| Se mide | Entrada: minutos de retardo. Salida: minutos antes de la hora de salida. Comida: minutos por encima de la permitida |
| Iniciales | A tiempo (0), Retardo (1–15), Retardo mayor (16+), Salida normal (0), Salida anticipada (1+), Comida en tiempo (0), Comida excedida (1+) |
| Edición | Nombre, rango, color, orden y activo; se pueden crear y eliminar tipos nuevos. Los iniciales se desactivan pero no se borran. Dos tipos activos del mismo grupo no pueden cubrir los mismos minutos |
| Al guardar | Se vuelven a asignar los tipos a todos los resúmenes sin recalcular checadas (`EventTypeClassifier::reclassifyAll()`) |

### 2.4 Kardex y permisos

| Qué | Cómo quedó |
|---|---|
| Kardex | Columnas nuevas "Eventos" (etiquetas con el color del catálogo) e "Incidencias" (cuántas, con el detalle al pasar el cursor); también en el CSV |
| Resumen diario | Columnas `early_exit_minutes`, `meal_exceeded_minutes`, `entry_event_type_id`, `exit_event_type_id`, `meal_event_type_id` e `incidents` (JSON) |
| Permisos | Módulo nuevo "Catálogos": `catalogos.consultar` y `catalogos.administrar`; el menú tiene la sección "Catálogos" |

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `database/migrations/2026_11_23_000005_create_attendance_rules_catalogs.php`, `app/Models/AttendanceEventType.php`, `app/Services/PunchClassifier.php`, `app/Services/EventTypeClassifier.php`, `app/Http/Controllers/Catalog/{TimeWindowController,EventTypeController}.php`, `resources/views/catalogs/{time-windows,event-types}.blade.php`, `tests/Feature/AttendanceRulesTest.php`, este documento |
| Modificados | `app/Models/WorkShift.php` (`windowTimes()`, `classificationWindows()`), `app/Models/DailySummary.php`, `app/Services/AttendanceCalculatorService.php` (`recalculateForEmployeeDate()`), `app/Http/Controllers/ReportController.php`, `resources/views/reports/kardex.blade.php`, `resources/views/partials/sidebar-content.blade.php`, `routes/web.php`, `app/Support/Permissions.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, documentación |

---

## 4. Pasos manuales

1. `git pull`
2. `php artisan migrate`
3. `php artisan permisos:sincronizar` (agrega `catalogos.consultar` y `catalogos.administrar`).
4. `php artisan attendance:recalculate` (vuelve a clasificar las checadas que ya existen con las ventanas y llena eventos e incidencias).
5. `php artisan optimize:clear`, `npm run build` y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **128** (nuevas: las 17 de `AttendanceRulesTest`).
2. Catálogos → Ventanas de horario: cada turno muestra sus ventanas por omisión. Quitar "Usar valores por omisión", poner entrada 07:00–10:00 y guardar: aviso con cuántos resúmenes se recalcularon.
3. Poner una ventana de entrada que no incluya la hora del turno: mensaje de error y no se guarda.
4. Catálogos → Tipos de evento: cambiar "Retardo" a 1–30 sin tocar "Retardo mayor": error de rango cruzado.
5. Kardex: un día con dos checadas a 1 minuto de distancia muestra "1 incidencia" y, al pasar el cursor, "Checada repetida (hh:mm:ss)".

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| ✅ Resuelto | Corrección manual de una checada | `docs/parches/2026-11-23/10-50_correccion-de-checadas-e-incidencias-en-dashboard.md` |
| ✅ Resuelto | Excel del servidor (O1) | Columnas `Eventos` (`S`) e `Incidencias` (`T`) ya incluidas en `KardexExcelExportService` |
| ✅ Resuelto | Dashboard | Mismo parche: aviso de incidencias de hoy |
