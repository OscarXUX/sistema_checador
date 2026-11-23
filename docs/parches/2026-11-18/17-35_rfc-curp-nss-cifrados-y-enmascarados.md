# RFC, CURP y NSS cifrados en la base y enmascarados en pantalla

> Parche · 18/11/2026 · Cierra SE-05 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| RFC, CURP y NSS completos en el JSON del listado, en el HTML de Empleados y en el kardex (pantalla y exportaciones) | `formatEmployeeRow()` y `queryKardexRows()` mandaban el valor completo |
| Los mismos datos en texto plano en la base | Columnas `string` sin cifrar; cualquiera con acceso a la base o a un respaldo los lee |

Son datos personales según la LFPDPPP; la guía pide minimizar su exposición (4.21) y cifrar las columnas sensibles (4.8).

---

## 2. Fix aplicado

### 2.1 Enmascarado

| Dato | Ejemplo en pantalla |
|---|---|
| RFC | `HELS••••••MDF` (4 primeros y 3 últimos) |
| CURP | `HELS••••••••••••02` (4 primeros y 2 últimos) |
| NSS | `•••••••8901` (4 últimos) |

| Dónde | Cómo quedó |
|---|---|
| Tabla de Empleados, `/employees/list` y respuestas de alta/edición | Enmascarados, con `personal_ids_masked: true` |
| Formulario de edición | Pide los datos completos a la ruta nueva `GET /employees/{id}` al abrirse; esa consulta queda en la bitácora como `employee.personal_data_viewed` |
| Kardex (pantalla, CSV y Excel) | Enmascarados |
| Columna RFC | Ya no se ordena (el valor cifrado no tiene un orden útil) |

### 2.2 Cifrado en la base

| Qué | Cómo quedó |
|---|---|
| Columnas `rfc`, `curp`, `nss` | Tipo `text`, cifradas con el *cast* `encrypted` de Laravel (AES-256 con `APP_KEY`). El modelo `Employee` las entrega descifradas al código |
| Búsqueda | Nuevas columnas `rfc_hash` y `curp_hash` con un HMAC-SHA256 del valor (índice ciego). La búsqueda encuentra un RFC o CURP **completo y exacto**, sin importar mayúsculas; ya no encuentra fragmentos (por ejemplo, la fecha de nacimiento) |
| Datos existentes | La migración cifra lo que ya había y calcula los índices; el `down()` los descifra y regresa las columnas a como estaban |
| Clases nuevas | `App\Support\PersonalData` (enmascarado e índice ciego) |

> ⚠️ El cifrado depende de `APP_KEY`. Si se cambia la llave sin volver a cifrar, RFC, CURP y NSS ya no se pueden leer. Un respaldo de la base sirve solo junto con su `APP_KEY`, que se guarda aparte y nunca en el repositorio.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Support/PersonalData.php`, `database/migrations/2026_11_18_000003_encrypt_employee_personal_ids.php`, `tests/Feature/PersonalDataProtectionTest.php`, este documento |
| Modificados | `app/Models/Employee.php` (casts `encrypted`, índice ciego al guardar), `app/Http/Controllers/EmployeeController.php` (`show()`, `formatEmployeeRow()`, búsqueda, `SORTABLE_COLUMNS`), `app/Http/Controllers/ReportController.php`, `routes/web.php` (`employees.show`), `resources/views/employees/index.blade.php` (formulario de edición, columna RFC, texto de búsqueda) |

---

## 4. Pasos manuales

1. `git pull`
2. **Respaldar la base** antes de migrar (en DBeaver: clic derecho en `sistema_checador` → Herramientas → Volcar base de datos).
3. `php artisan migrate` (cifra los RFC, CURP y NSS existentes).
4. `php artisan optimize:clear`, `npm run build` y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **75** (6 nuevas en `PersonalDataProtectionTest`; las 4 de `SecurityHeadersTest` son del parche de cabeceras).
2. En DBeaver, tabla `employees`: las columnas `rfc`, `curp` y `nss` muestran texto que empieza con `eyJpdiI6…`, y `rfc_hash` / `curp_hash` tienen 64 caracteres.
3. Empleados: la columna RFC aparece como `HELS••••••MDF`; al abrir "Editar", el formulario muestra los datos completos.
4. Buscar un RFC completo lo encuentra; buscar solo `940312` no.
5. Kardex → Exportar CSV: las columnas RFC, CURP y NSS salen enmascaradas.

La migración se probó en SQLite con un empleado con datos y otro sin ellos: cifra, el modelo lee el valor original, y el `rollback` regresa todo a texto plano. En MySQL no se probó.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Exportar el kardex con datos completos | Si nómina los necesita, conviene una exportación aparte con permiso propio cuando haya roles (SE-02) |
| 🟢 Baja | Rotación de `APP_KEY` | Laravel permite llaves anteriores (`APP_PREVIOUS_KEYS`) para leer y volver a cifrar; documentarlo en la guía de despliegue |
