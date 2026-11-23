# Dar de baja a un empleado sin borrar sus checadas

> Parche · 17/11/2026 · Cierra SE-03 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| Al eliminar un empleado se borraban todas sus checadas y su kardex | `EmployeeController::destroy()` hacía `$employee->delete()` y las tablas `attendance_logs` y `daily_summaries` tenían `cascadeOnDelete()` |
| Contradecía el diseño | El modelo `AttendanceLog` dice que la bitácora es inmutable, pero se podía perder completa con un clic |

---

## 2. Fix aplicado

### 2.1 Baja lógica en la aplicación

| Qué | Cómo quedó |
|---|---|
| Empleado **con** checadas o resúmenes | Se marca `is_active = false` y se conserva todo su historial. Si además se pidió darlo de baja en la terminal, se limpian `device_synced_at` y `fingerprint_enrolled` |
| Empleado **sin** historial | Se elimina como antes (útil si se registró por error) |
| Respuesta JSON | Nuevo campo `deactivated` (`true` si fue baja lógica) y mensaje que lo explica |
| Diálogo de la pantalla Empleados | Título "Confirmar Baja", botón "Dar de Baja" y texto que explica cuándo se conserva el historial |

### 2.2 Restricción en la base de datos

Nueva migración que cambia la llave foránea `employee_id` de `attendance_logs` y `daily_summaries` de `cascadeOnDelete()` a `restrictOnDelete()`. Si algo intenta borrar físicamente a un empleado con historial, la base lo rechaza. El `down()` regresa a la cascada.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `database/migrations/2026_11_17_000001_restrict_employee_delete_on_attendance_tables.php`, este documento |
| Modificados | `app/Http/Controllers/EmployeeController.php` (`destroy()`), `resources/views/employees/index.blade.php` (diálogo de baja), `tests/Feature/EmployeeManagementTest.php` |

---

## 4. Pasos manuales

1. `git pull`
2. `php artisan migrate` (una migración nueva).
3. `php artisan optimize:clear`
4. `npm run build` (o tener `npm run dev` abierto) y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **21** (nuevas de este parche: `test_employee_with_attendance_is_deactivated_and_history_is_kept`, `test_database_blocks_deleting_employee_with_attendance`).
2. En DBeaver, en la base `sistema_checador`: tabla `attendance_logs` → pestaña Llaves foráneas → `attendance_logs_employee_id_foreign` debe tener la regla de borrado `RESTRICT`. Igual en `daily_summaries`.
3. Pantalla Empleados → botón de baja en un empleado **sin** checadas → desaparece de la lista.
4. Con un empleado que **sí** tenga checadas → queda en la lista como "Inactivo" y sus registros siguen en el Kardex.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Quién dio de baja | La baja no registra el usuario porque todavía no hay login (SE-01) ni bitácora de acciones (SE-09) |
| 🟢 Baja | Reactivar | Se reactiva editando al empleado y marcando "Activo"; no hay un botón dedicado |
