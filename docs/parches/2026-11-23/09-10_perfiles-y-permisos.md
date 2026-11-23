# Perfiles y permisos con la estructura del sistema de tickets

> Parche · 23/11/2026 · Retroalimentación de Oscar: los perfiles deben tener "la misma estructura que el sistema de tickets… y que el administrador tenga catálogos de igual modo". Cierra SE-02.

---

## 1. Contexto / Causa

| Pedido o problema | Antes / Causa |
|---|---|
| SE-02: no había roles ni permisos | Con el login (SE-01) cualquier cuenta podía hacer todo: dar de baja empleados, cambiar turnos, ver RFC, CURP y NSS completos |
| Estructura igual a tickets | En tickets hay un catálogo de permisos, puestos con permisos y excepciones por usuario (dar o negar) |
| Nombre (P3 de la propuesta) | Aquí "puesto" es el del empleado; para los usuarios del sistema se usa **perfil** |

---

## 2. Fix aplicado

### 2.1 Modelo de permisos

| Pieza | Tickets | Checador |
|---|---|---|
| Lista de permisos | `App\Support\Permisos::LISTA` | `App\Support\Permissions::LIST` (14 claves en 5 módulos) |
| Catálogo en base | `permisos` | `permissions` (descripción editable, activo) |
| Perfil | `usuariospuestos` + permisos por puesto | `profiles` + `profile_permission` |
| Excepciones por persona | `usuariospermisos` con `Negado` | `user_permissions` con `is_denied` |
| Administrador | Puesto 4 | Perfil con `is_admin`: todos los permisos activos y la sección Administración |
| Revisión | Gate por clave | Un Gate por clave (`can:empleados.agregar`) y `administrar` |
| Sincronizar claves | `Permisos::sincronizar()` | `php artisan permisos:sincronizar` |

Permisos efectivos = los del perfil + los dados − los negados, y solo los activos. Un perfil inactivo no da nada; un permiso desactivado no lo tiene nadie, ni el administrador.

### 2.2 Permisos

| Módulo | Claves |
|---|---|
| Dashboard | `dashboard.consultar` |
| Empleados | `empleados.consultar`, `agregar`, `actualizar`, `baja`, `datos_personales` |
| Terminal | `terminal.sincronizar`, `usuarios`, `huellas` |
| Turnos | `turnos.consultar`, `administrar`, `programar` (para la tarea O2 de Oscar) |
| Kardex | `kardex.consultar`, `exportar` |

La migración crea dos perfiles: **Administrador** (las cuentas que ya existían quedan aquí) y **Consulta** (dashboard, empleados enmascarados, turnos y kardex, sin cambios).

### 2.3 En el servidor y en pantalla

| Qué | Cómo quedó |
|---|---|
| Rutas | Cada ruta lleva su `can:`; `RouteProtectionTest` falla si una ruta nueva no lo declara (excepto `login`, `logout`, `inicio`, `device/status`, `up`) |
| Sin permiso | Página 403 en español; en peticiones JSON: `{"success": false, "message": "No tienes permiso para hacer esta acción."}` |
| Menú y botones | `@can` oculta opciones, "Nuevo empleado", "Sincronizar", exportaciones y edición de turnos; en Empleados la columna de acciones dice "Solo consulta" |
| RFC, CURP y NSS | Sin `empleados.datos_personales` se ven enmascarados al editar, los campos quedan bloqueados y el servidor ignora lo que llegue en ellos |
| Inicio | Al entrar se va a la primera pantalla permitida (`/inicio`); sin ninguna, un aviso de "Sin permisos" |

### 2.4 Pantallas de Administración (solo perfil administrador)

| Pantalla | Ruta | Qué hace |
|---|---|---|
| Usuarios | `/administracion/usuarios` | Alta con perfil y contraseña (15 caracteres o más); editar nombre, correo, perfil, estado y contraseña; excepciones por permiso: "Según perfil", "Dar" o "Negar", con la columna "Resultado" |
| Perfiles | `/administracion/perfiles` | Alta, editar nombre y descripción, activar, casillas de permisos agrupadas por módulo; eliminar solo si no tiene usuarios |
| Permisos | `/administracion/permisos` | Cambiar descripción y detalle, activar o desactivar |

| Protección | Regla |
|---|---|
| Último administrador | No se le puede quitar el perfil ni desactivar si no queda otro administrador activo (`App\Support\AdminGuard`) |
| Perfil administrador | No se desactiva, no se borra y su lista no se edita |
| Sesiones | Al desactivar una cuenta o cambiar su contraseña se cierran sus sesiones |
| Bitácora | `user.created`, `user.updated`, `user.permissions_updated`, `profile.created/updated/deleted`, `permission.updated` |

El código nuevo usa la nomenclatura de tickets (`txt-`, `cb-`, `ck-`, `rb-`, `btn-`, `grid-`, `lbl-`; `lst`, `data` en variables).

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Support/Permissions.php`, `app/Support/AdminGuard.php`, `app/Models/Profile.php`, `app/Models/Permission.php`, `app/Http/Controllers/Admin/{UserController,ProfileController,PermissionController}.php`, `app/Console/Commands/SynchronizePermissionsCommand.php`, `database/migrations/2026_11_23_000004_create_profiles_and_permissions_tables.php`, `resources/views/admin/**`, `resources/views/auth/no-permissions.blade.php`, `resources/views/errors/403.blade.php`, `tests/Feature/ProfilesAndPermissionsTest.php`, este documento |
| Modificados | `app/Models/User.php` (`profile()`, `hasPermission()`, `effectivePermissions()`, `homeRouteName()`), `app/Providers/AppServiceProvider.php` (Gates), `routes/web.php`, `bootstrap/app.php` (403 en JSON), `app/Http/Controllers/Auth/AuthController.php` (`home()`), `app/Http/Controllers/EmployeeController.php` y `app/Http/Requests/EmployeeFormRequest.php` (datos personales), `app/Console/Commands/CreateUserCommand.php` (`--perfil`), `database/factories/UserFactory.php`, vistas de layout, sidebar, empleados, kardex y turnos, `tests/Feature/RouteProtectionTest.php`, `tests/Feature/UserCommandsTest.php`, `README.md`, `docs/CONTEXTO_SISTEMA_CHECADOR.md`, `docs/README.md`, `docs/pendientes/**`, la propuesta del 22/11/2026 |

---

## 4. Pasos manuales

1. `git pull`
2. `php artisan migrate` (crea las tablas, los perfiles Administrador y Consulta, y pasa las cuentas existentes a Administrador).
3. `php artisan optimize:clear`
4. `npm run build` y Ctrl + F5.
5. Cuando alguien agregue un permiso nuevo en `App\Support\Permissions::LIST`: `php artisan permisos:sincronizar`.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **100** (nuevas: las 15 de `ProfilesAndPermissionsTest` y `test_every_authenticated_route_declares_its_permission`).
2. Entrar con una cuenta de administrador: en el menú aparece "Administración" con Usuarios, Perfiles y Permisos.
3. Usuarios → crear una cuenta con perfil Consulta → cerrar sesión y entrar con ella: solo ve las cuatro pantallas, sin "Nuevo empleado", "Sincronizar" ni exportaciones; en Empleados dice "Solo consulta".
4. Con esa cuenta, abrir `/administracion/usuarios`: página "No tienes permiso para entrar aquí".
5. Como administrador, en esa cuenta poner "Dar" en "Exportar el kardex" y guardar: la columna "Resultado" dice "Lo tiene" y la cuenta ya ve los botones de exportar.
6. Intentar quitarse a uno mismo el perfil Administrador siendo el único: "Debe quedar al menos un administrador activo".

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Rutas nuevas de Oscar (O1–O3) | El `RouteProtectionTest` ahora exige `can:`; usar `kardex.exportar`, `turnos.programar` y `terminal.usuarios` |
| 🟡 Media | Pantalla de bitácora | Sigue consultándose en DBeaver; puede ir en Administración con su propio permiso |
| 🟢 Baja | Usuarios por persona en Teracrom | TE-05 queda parcial: falta saber quién tendrá cuenta y con qué perfil |
