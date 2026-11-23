# Bitácora de acciones de usuarios

> Parche · 18/11/2026 · Cierra SE-09 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md. Sigue a docs/parches/2026-11-18/16-20_inicio-de-sesion-y-cuentas.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| No quedaba registro de quién creó, cambió o dio de baja empleados, cambió turnos o mandó acciones a la terminal | No había usuarios (SE-01) ni tabla de bitácora |

Sin esto el sistema no puede demostrar quién cambió un turno (lo que modifica retardos y horas extra) o quién dio de baja a alguien en el reloj.

---

## 2. Fix aplicado

### 2.1 Tabla `audit_logs`

| Columna | Contenido |
|---|---|
| `user_id` | Quién (nulo en intentos fallidos y en comandos de consola) |
| `action` | Clave de la acción, por ejemplo `employee.updated` |
| `auditable_type`, `auditable_id` | Sobre qué registro |
| `description` | Texto legible: "Cambio en el turno Matutino" |
| `old_values`, `new_values` | Antes y después, solo de los campos que cambiaron |
| `ip_address`, `user_agent` | Desde dónde (`consola` en comandos) |
| `created_at` | Cuándo |

El modelo `AuditLog` lanza excepción si algo intenta modificar o borrar un registro desde la aplicación.

### 2.2 Qué se registra

| Acción | Dónde |
|---|---|
| `auth.login`, `auth.logout`, `auth.login_failed` (con el correo intentado), `auth.lockout` | `AuthController`, `LoginRequest` |
| `employee.created`, `employee.updated`, `employee.deactivated`, `employee.deleted` | `EmployeeController` |
| `device.user_synced`, `device.user_deleted`, `device.fingerprint_captured`, `device.sync` (con conteos) | `EmployeeController`, `DeviceController` |
| `shift.created`, `shift.updated` | `WorkShiftController` |
| `user.created`, `user.password_reset`, `user.activated`, `user.deactivated` | Comandos `usuarios:*` |

La sincronización automática de cada minuto no se registra: no la hace un usuario.

### 2.3 Qué no se registra

| Dato | Cómo se trata |
|---|---|
| RFC, CURP y NSS | Se guarda `[protegido]`: queda constancia de que cambiaron, sin copiar el dato |
| Contraseñas (también las equivocadas), `remember_token`, plantillas de huella | Nunca se guardan |

Si escribir en la bitácora falla, la operación principal sigue y el error queda en `storage/logs`.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `database/migrations/2026_11_18_000002_create_audit_logs_table.php`, `app/Models/AuditLog.php`, `app/Services/AuditLogger.php`, `tests/Feature/AuditLogTest.php`, este documento |
| Modificados | `app/Http/Controllers/EmployeeController.php`, `app/Http/Controllers/DeviceController.php`, `app/Http/Controllers/WorkShiftController.php` (y los archivos de login del parche anterior) |

---

## 4. Pasos manuales

1. Los mismos del parche de inicio de sesión (`php artisan migrate` crea `audit_logs`).

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **47**. Nuevas de este parche: `AuditLogTest` (5) y `test_login_logout_and_failures_are_audited` en `AuthTest`.
2. Entrar, editar el puesto de un empleado y cambiar la tolerancia de un turno.
3. En DBeaver:

```sql
SELECT created_at, user_id, action, description, old_values, new_values, ip_address
FROM audit_logs
ORDER BY id DESC
LIMIT 20;
```

Deben aparecer `auth.login`, `employee.updated` (solo `position`) y `shift.updated` (solo `tolerance_minutes`), con tu `user_id` e IP.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Pantalla para consultar la bitácora | Hoy se consulta en DBeaver; la pantalla conviene hacerla junto con los roles (SE-02), para decidir quién puede verla |
| 🟡 Media | Retención | Definir cuánto tiempo se guarda (puede ir con TE-08) |
| 🟢 Baja | Inmutable en la base | La protección es de la aplicación; un usuario de MySQL con permisos aún puede borrar. Se cierra con un usuario de base sin `DELETE` sobre `audit_logs` al publicar |
