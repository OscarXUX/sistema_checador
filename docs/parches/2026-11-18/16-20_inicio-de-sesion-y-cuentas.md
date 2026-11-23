# Inicio de sesión y administración de cuentas

> Parche · 18/11/2026 · Cierra SE-01 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md. Opción A (login propio) acordada con Danny.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| Cualquiera que abriera la URL podía ver RFC, CURP y NSS, cambiar empleados y turnos, borrar usuarios de la terminal o capturar huellas | Ninguna de las 17 rutas de `routes/web.php` tenía el middleware `auth`; la tabla `users` no se usaba |

---

## 2. Fix aplicado

### 2.1 Pantalla y flujo de inicio de sesión

| Qué | Cómo quedó |
|---|---|
| Rutas | `GET/POST /login` (solo para quien no tiene sesión) y `POST /logout`. Todo lo demás está dentro de `Route::middleware('auth')` |
| Vista | `resources/views/auth/login.blade.php`, mismo estilo Bootstrap oscuro; solo carga CSS. Sin enlace de registro |
| Credenciales | Correo (sin importar mayúsculas ni espacios) y contraseña; solo entran cuentas con `is_active = true` |
| Mensaje de error | Siempre "Correo o contraseña incorrectos.", exista o no la cuenta, esté o no activa |
| Intentos | 5 fallidos por minuto por correo + IP (`LoginRequest::MAX_ATTEMPTS`) y 20 envíos por minuto por IP (`throttle:login`) |
| Sesión | Se regenera el identificador al entrar; al salir se invalida en el servidor y se renueva el token CSRF. Inactividad: 30 min (`SESSION_LIFETIME`) |
| Usuario en pantalla | Nombre y botón "Cerrar sesión" en la barra superior |
| Sesión vencida | `apiFetch()` en `resources/js/app.js` manda al login con el aviso "Tu sesión expiró" cuando recibe 401 o 419 |

### 2.2 Cuentas sin registro público

| Comando | Qué hace |
|---|---|
| `php artisan usuarios:crear` | Pide nombre, correo y contraseña (con confirmación) y crea la cuenta |
| `php artisan usuarios:restablecer {email}` | Cambia la contraseña y cierra todas las sesiones abiertas de esa cuenta |
| `php artisan usuarios:estado {email} activo\|inactivo` | Activa o desactiva la cuenta; al desactivarla cierra sus sesiones |

No existe ninguna cuenta de fábrica. Las contraseñas siguen `App\Support\PasswordPolicy` (NIST 800-63B rev. 4, guía 4.2): mínimo 15 caracteres, máximo 255, sin exigir mayúsculas ni símbolos, se aceptan frases con espacios; se rechazan las que contienen "checador", "hikvision", "password", "contraseña", "123456789" o el usuario del correo. Se guardan con bcrypt (lo que ya trae Laravel).

### 2.3 Prueba guardiana de rutas

`tests/Feature/RouteProtectionTest.php` recorre todas las rutas y falla si alguna no tiene `auth`. Solo se permiten abiertas `login`, `up` (salud del servidor) y `storage/{path}` (archivos de Laravel con URL firmada).

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Http/Controllers/Auth/AuthController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Support/PasswordPolicy.php`, `app/Support/UserSessions.php`, `app/Console/Commands/CreateUserCommand.php`, `app/Console/Commands/ResetUserPasswordCommand.php`, `app/Console/Commands/SetUserActiveCommand.php`, `database/migrations/2026_11_18_000001_add_is_active_to_users_table.php`, `resources/views/auth/login.blade.php`, `tests/Feature/AuthTest.php`, `tests/Feature/RouteProtectionTest.php`, `tests/Feature/UserCommandsTest.php`, este documento |
| Modificados | `routes/web.php`, `app/Providers/AppServiceProvider.php` (límite `login`), `app/Models/User.php`, `database/factories/UserFactory.php`, `config/session.php` y `.env.example` (`SESSION_LIFETIME=30`), `resources/views/layouts/app.blade.php`, `resources/js/app.js`, `tests/Feature/EmployeeManagementTest.php`, `tests/Feature/ExampleTest.php`, `tests/Feature/RateLimitAndReportRangeTest.php` (inician sesión con `actingAs`) |

---

## 4. Pasos manuales

1. `git pull`
2. `php artisan migrate` (dos migraciones: `is_active` en `users` y la tabla `audit_logs` del parche de bitácora).
3. En tu `.env`, cambiar `SESSION_LIFETIME=120` por `SESSION_LIFETIME=30` y confirmar `SESSION_DRIVER=database` (lo necesitan los comandos para cerrar sesiones).
4. `php artisan usuarios:crear` para crear tu cuenta.
5. `php artisan optimize:clear`
6. `npm run build` (cambió el JavaScript) y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **47** (incluye las del parche de bitácora). Nuevas de este parche: 8 de `AuthTest` (la novena es de la bitácora), `RouteProtectionTest` (1) y `UserCommandsTest` (4).
2. Abrir `http://127.0.0.1:8000` sin sesión → manda a `/login`.
3. Correo correcto y contraseña equivocada → "Correo o contraseña incorrectos.". Con un correo que no existe, el mismo mensaje.
4. Seis intentos fallidos seguidos → "Demasiados intentos. Intenta de nuevo en N segundos.".
5. Entrar → aparece el nombre arriba a la derecha → botón de salir → regresa al login.
6. Con sesión abierta, borrar en DBeaver la fila de la tabla `sessions` y presionar "Sincronizar" → manda al login con "Tu sesión expiró".

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🔴 Alta | Roles | Toda cuenta puede hacer todo hasta que se resuelva SE-02 (espera TE-05) |
| 🟡 Media | MFA para administradores | Recomendado por la guía (4.2); se puede agregar después |
| 🟡 Media | Restablecer por correo | Hoy solo por consola, porque el correo no está configurado (`MAIL_MAILER=log`) |
| 🟡 Media | Tiempo absoluto de sesión | Laravel solo vence por inactividad; la guía sugiere también un máximo de 4 a 8 h |
| 🟢 Baja | Contraseñas filtradas | `Password::uncompromised()` consulta un servicio externo; se puede activar si el servidor tiene internet |
