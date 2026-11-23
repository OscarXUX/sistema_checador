# Candado para que las pruebas nunca usen la base real

> Parche · 22/11/2026 · Danny reportó 19 pruebas fallidas (419 y bitácora vacía) después de seguir docs/parches/2026-11-22/20-00_turnos-nocturnos-kardex-json-y-despliegue.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| 19 pruebas fallaban con 419 (token CSRF) y "la tabla `audit_logs` está vacía" | Se corrió `php artisan optimize` en local (paso 2 de "Cómo verificar" del parche del 22/11) sin el `optimize:clear` posterior. Con la configuración en caché (`bootstrap/cache/config.php`), Laravel ignora las variables de `phpunit.xml` |
| Las pruebas corrieron como `APP_ENV=local` | Por eso el CSRF seguía activo y respondía 419 |
| Las pruebas usaron la base MySQL `sistema_checador` del `.env` en lugar de SQLite en memoria | `RefreshDatabase` ejecutó `migrate:fresh` sobre la base local: **se borraron sus datos** (cuentas, empleados, turnos, checadas) |

---

## 2. Fix aplicado

| Qué | Cómo quedó |
|---|---|
| `tests/TestCase.php` | `createApplication()` revisa, antes de que corra `RefreshDatabase`, que la configuración no esté en caché, que el ambiente sea `testing` y que la conexión sea SQLite `:memory:`. Si algo no cuadra, detiene las pruebas con: "Pruebas detenidas para no borrar tu base de datos real (conexión: mysql, base: sistema_checador). Corre "php artisan optimize:clear" y vuelve a ejecutar "php artisan test"." |
| `README.md` | Fila nueva en problemas comunes |

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | Este documento |
| Modificados | `tests/TestCase.php`, `README.md` |

---

## 4. Pasos manuales

1. `php artisan optimize:clear` (quita la configuración en caché).
2. Si la base local quedó vacía: restaurar el respaldo de DBeaver o volver a `php artisan migrate` y `php artisan usuarios:crear`.
3. `php artisan test`.
4. En local **no** usar `php artisan optimize`; solo en producción, como indica la guía de despliegue.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **78**.
2. `php artisan config:cache` y luego `php artisan test`: todas se detienen con el mensaje del candado y la base no se toca. Después, `php artisan optimize:clear`.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟢 Baja | Paso de verificación del parche del 22/11 | Su paso 2 (`optimize` y luego `optimize:clear`) es correcto solo si se corren los dos; con el candado, olvidar el segundo ya no borra nada |
