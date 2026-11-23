# Foto y tipo de sangre en la ficha del empleado

> Parche · 23/11/2026 · Nota 16 de Oscar; P2: "para la nota 16 es solo la foto y el tipo de sangre".

---

## 1. Contexto / Causa

| Dato | Por qué se trata distinto |
|---|---|
| Tipo de sangre | Es un dato de salud: sensible para la LFPDPPP. Debe ir cifrado, con acceso restringido, en el aviso de privacidad y con consentimiento expreso (TE-02) |
| Foto | Es un dato personal que identifica a la persona. No debe quedar en una carpeta pública ni con metadatos (ubicación GPS, cámara) |

---

## 2. Fix aplicado

### 2.1 Tipo de sangre

| Qué | Cómo quedó |
|---|---|
| Campo | `employees.blood_type`, cifrado con `APP_KEY` (cast `encrypted`); valores O+, O-, A+, A-, B+, B-, AB+, AB- |
| Permiso | `empleados.datos_salud` (nuevo). Sin él, el campo no aparece en el formulario, no se envía y el servidor lo ignora; la ficha solo dice "Registrado (protegido)" o "Sin registrar" |
| Bitácora | Cada consulta con permiso queda como `employee.health_data_viewed`; los cambios se guardan como `[protegido]` |
| Listado y kardex | No llevan el tipo de sangre; el listado solo sabe si está registrado (`has_blood_type`) |

### 2.2 Foto

| Qué | Cómo quedó |
|---|---|
| Dónde se sube | Formulario de edición del empleado → "Subir foto" (para un empleado nuevo, después de guardarlo) |
| Archivo | JPG, PNG o WebP de hasta 4 MB; se vuelve a codificar como JPEG de máximo 600 px, sin metadatos, con nombre aleatorio |
| Almacenamiento | `storage/app/private/employee-photos/` (fuera de `public/`) |
| Cómo se ve | Ruta `GET /employees/{id}/photo` con `empleados.consultar`, `nosniff` y caché privada; miniatura junto al nombre en el listado |
| Cambiar o quitar | `POST` y `DELETE /employees/{id}/photo` con `empleados.actualizar`; la foto anterior se borra. Al eliminar un empleado sin historial, también su foto |
| Bitácora | `employee.photo_updated`, `employee.photo_removed` |

### 2.3 Arreglo de paso

El campo "ID Reloj Hikvision" tenía un `pattern` que Chrome ya no acepta (`[A-Za-z0-9_-]+` con la bandera `v`) y marcaba un error en la consola al validar. Se cambió a `[A-Za-z0-9_\-]+`.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `database/migrations/2026_11_23_000007_add_photo_and_blood_type_to_employees_table.php`, `app/Http/Controllers/EmployeePhotoController.php`, `tests/Feature/EmployeePhotoAndHealthTest.php`, este documento |
| Modificados | `app/Models/Employee.php`, `app/Http/Controllers/EmployeeController.php` (`show()`, `store()`, `update()`, `formatEmployeeRow()`), `app/Http/Requests/EmployeeFormRequest.php`, `app/Services/AuditLogger.php` (`blood_type` protegido), `app/Support/Permissions.php`, `routes/web.php`, `resources/views/employees/index.blade.php`, `README.md`, documentación |

---

## 4. Pasos manuales

1. `git pull`
2. Revisar que PHP tenga la extensión **gd** (`php -m`). En XAMPP: quitar el `;` de `extension=gd` en `C:\xampp\php\php.ini` y abrir una terminal nueva. Sin ella las fotos se rechazan con un mensaje.
3. `php artisan migrate`
4. `php artisan permisos:sincronizar` (agrega `empleados.datos_salud`).
5. `php artisan optimize:clear`, `npm run build` y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **143** (nuevas: las 7 de `EmployeePhotoAndHealthTest`).
2. Empleados → editar → elegir tipo de sangre "O+" y guardar. En DBeaver, `employees.blood_type` se ve cifrado.
3. En el mismo formulario, "Subir foto": aparece la foto y en el listado una miniatura junto al nombre.
4. Con un perfil sin "Ver y editar tipo de sangre": el formulario muestra "Registrado (protegido)" y no deja cambiarlo.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🔴 Alta | Aviso de privacidad y consentimiento (TE-02) | Antes de capturar tipos de sangre reales debe existir el aviso de privacidad que lo mencione y el consentimiento expreso por escrito |
| 🟢 Baja | Respaldo de fotos | Las fotos viven en `storage/app/private`; el respaldo del servidor debe incluir esa carpeta además de la base |
