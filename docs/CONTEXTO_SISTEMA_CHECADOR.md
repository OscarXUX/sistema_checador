# Sistema checador: control de entradas y salidas con terminal Hikvision

> Referencia · actualizado 22/11/2026 · Para quien se integra al proyecto.

---

## 1. Resumen

| Dato | Valor |
|---|---|
| Qué es | Sistema web que descarga las checadas de una terminal biométrica Hikvision y las muestra en un dashboard: quién entra, quién sale, a qué hora, retardos, comida y horas extra |
| Pedido original | Oscar: "un dashboard para registrar cada vez que alguien entra y sale, con un checador conectado a una página web. Que muestre quién sale, a qué hora sale, por qué sale y todo eso" |
| Estado | Base funcional de Oscar (12/11/2026) con correcciones de seguridad de Danny: login, bitácora de acciones, límites de peticiones. Sin roles; sin pruebas contra la terminal real |
| Participantes | Oscar, Danny |
| Tipo de proyecto | Desde cero: los estados "En revisión" y "Sujeto a cambios" sí aplican |

---

## 2. Stack

| Capa | Tecnología | Versión | Notas |
|---|---|---|---|
| Lenguaje | PHP | ≥ 8.2 | El de XAMPP sirve en local |
| Framework | Laravel | 12 (lock: 12.72.0) | Blade, Eloquent, scheduler |
| Base de datos | MySQL | 8 (o MariaDB de XAMPP) | Base `sistema_checador`, `utf8mb4` |
| Front-end | Blade + Bootstrap 5 + Bootstrap Icons | 5.3 | Tema oscuro |
| Tablas y gráficas | Wijmo (MESCIUS) | 5.20262 | Licencia comercial; hoy en modo evaluación (marca de agua) |
| Empaquetado | Vite + Tailwind 4 | 6 | Tailwind solo lo usa `welcome.blade.php`, que no tiene ruta |
| Terminal | Hikvision DS-K1T804AEF | — | Protocolo ISAPI por HTTP con Digest Auth |
| Pruebas | PHPUnit | 11 | SQLite en memoria (`phpunit.xml`) |

---

## 3. Estructura del repositorio

```text
app/
├── Console/Commands/                           hikvision:sync, attendance:recalculate y usuarios:crear, usuarios:restablecer, usuarios:estado
├── Http/Controllers/                           Dashboard, Device, Employee, Report, WorkShift; Auth/AuthController (login)
├── Http/Requests/Auth/LoginRequest.php         Credenciales, límite de intentos y mensaje genérico
├── Http/Requests/                              Validación de empleados (RFC, CURP, NSS)
├── Models/                                     Employee, WorkShift, AttendanceLog, DailySummary, User, AuditLog
├── Support/                                    PasswordPolicy (reglas de contraseña), UserSessions, PersonalData (enmascarado e índice ciego)
├── Http/Middleware/SecurityHeaders.php         Cabeceras de seguridad y CSP con nonce
└── Services/
    ├── HikvisionIsapiService.php               Cliente HTTP de la terminal
    ├── AttendanceCalculatorService.php         Guarda checadas en lote y calcula el resumen diario
    ├── AttendanceSyncCoordinator.php           Candado único y sincronización incremental
    └── AuditLogger.php                         Escribe la bitácora de acciones
resources/views/                                dashboard, employees, reports/kardex, shifts
resources/js/app.js                             Lo común: avisos, peticiones con CSRF, estado de la terminal
resources/js/wijmo/                             Wijmo por página: grid.js (tablas), chart.js (gráficas), kardex.js (filtros y Excel)
resources/js/csv.js                             Exportación CSV sin fórmulas
routes/web.php                                  /login abierto; todo lo demás dentro de middleware auth
routes/console.php                              hikvision:sync cada minuto
tests/                                          84 pruebas (Feature y Unit)
```

---

## 4. Convenciones

| Tema | Convención |
|---|---|
| Código | En inglés (`work_shifts`, `punched_at`); textos de pantalla y mensajes en español |
| Respuestas | Los controladores responden JSON cuando la petición lo pide (`expectsJson()`) y redirección en formularios |
| Validación | Form Requests (`StoreEmployeeRequest`, `UpdateEmployeeRequest`) y `$request->validated()` |
| Zona horaria | `APP_TIMEZONE=America/Mexico_City` |
| Documentación | `docs/` según el estándar de Danny|

---

## 5. Base de datos

| Tabla | Para qué | Notas |
|---|---|---|
| `work_shifts` | Turnos: entrada, salida, minutos de comida, tolerancia, horas por semana | Requerido para crear empleados |
| `employees` | Empleados con su número en el reloj (`hikvision_employee_no`), RFC, CURP, NSS, departamento, puesto | RFC, CURP y NSS **cifrados** con `APP_KEY`; `rfc_hash` y `curp_hash` permiten buscarlos completos | `fingerprint_enrolled` y `device_synced_at` reflejan el estado en la terminal |
| `attendance_logs` | Cada checada tal como llegó del reloj, con `event_type` y `raw_payload` (JSON) | Único `(employee_id, punched_at)`; no se puede editar desde Eloquent. La base impide borrar a un empleado que tenga checadas (`restrictOnDelete`) |
| `daily_summaries` | Resumen por empleado y día: entrada, comida, salida, minutos trabajados, retardo, horas extra, estado | Se recalcula al llegar cada checada nueva |
| `users` | Cuentas del sistema | `is_active` para desactivar sin borrar; se crean por consola (`usuarios:crear`) |
| `audit_logs` | Bitácora: quién, qué, cuándo y desde dónde | Antes y después de los campos que cambiaron; RFC, CURP y NSS como `[protegido]`; no se modifica desde la aplicación |
| `sessions`, `cache`, `jobs` | Tablas base de Laravel | `sessions` permite cerrar las sesiones de una cuenta |

`event_type` admite: `check_in` (Entrada), `break_out` (Salida a comida), `break_in` (Regreso de comida), `check_out` (Salida) y `unclassified`. Si el reloj no manda el tipo, se asigna por orden: 1.ª entrada, 2.ª salida a comida, 3.ª regreso, 4.ª salida.

---

## 6. Módulos

| Módulo | Qué hace | Pantallas / rutas | Permisos |
|---|---|---|---|
| Dashboard | Indicadores del día (en planta, en comida, retardos, horas extra de la semana), dos gráficas y el monitor de checadas | `GET /`, `GET /dashboard/data` | Sesión iniciada (sin roles todavía) |
| Terminal | Estado de conexión y sincronización manual | `GET /device/status`, `POST /device/sync` | Sesión iniciada (sin roles todavía) |
| Empleados y huellas | Listado paginado de 50 con búsqueda y orden por columna en el servidor; RFC, CURP y NSS enmascarados (completos solo al editar); alta, edición, baja (lógica si ya tiene checadas); enviar al reloj, borrar del reloj y capturar huella remota | `/employees`, `/employees/{id}/sync-device`, `/employees/{id}/capture-fingerprint` | Sesión iniciada (sin roles todavía) |
| Kardex | Resumen diario por rango de fechas (máximo 93 días), departamento o empleado; bitácora de checadas con carga bajo demanda del JSON original; exportación a Excel | `GET /reports/kardex`, `GET /reports/kardex/data`, `GET /reports/kardex/logs/{id}/payload` | Sesión iniciada (sin roles todavía) |
| Turnos | Alta y edición de turnos | `/shifts` | Sesión iniciada (sin roles todavía) |
| Sesión | Inicio y cierre de sesión | `GET/POST /login`, `POST /logout` | Abierta solo `/login` |
| Sincronización automática | Cada minuto descarga solo lo nuevo desde el último corte (con 10 min de traslape); comparte candado con el botón "Sincronizar" | `php artisan hikvision:sync` (scheduler) | — |

---

## 7. Funcionalidades

| Funcionalidad | Estado |
|---|---|
| Inicio de sesión sin registro público | ✅ Implementado (SE-01) |
| Bitácora de acciones de usuarios | ✅ Implementado; se consulta en DBeaver (SE-09) |
| Descargar checadas de la terminal por ISAPI (paginado) | ✅ Implementado, probado solo con respuestas simuladas |
| Evitar checadas duplicadas | ✅ Implementado |
| Calcular retardos, minutos de comida, trabajados y horas extra | ✅ Implementado |
| Dashboard con indicadores y gráficas | ✅ Implementado |
| Kardex con filtros y exportación a Excel | ✅ Implementado |
| Alta de empleados en la terminal y captura remota de huella | ✅ Implementado, sin probar con el aparato |
| Mostrar **por qué** sale una persona | ❌ No existe; falta definirlo (TE-01) |
| Roles y permisos | ❌ No existe (SE-02) |
| Monitor que se actualice solo | ❌ Solo se actualiza con el botón "Refrescar"; lo automático es el estado de la terminal (cada 30 s) |
| Turnos que cruzan la medianoche | ✅ Implementado en `WorkShift` y `AttendanceCalculatorService` (SE-11); pendiente confirmar con cliente si aplica (TE-07) |

---

## 8. Decisiones tomadas

| Fecha | Decisión | Quién | Por qué |
|---|---|---|---|
| 12/11/2026 | Laravel 12 + MySQL + Blade | Oscar | Base inicial del proyecto |
| 12/11/2026 | Terminal Hikvision DS-K1T804AEF por ISAPI | Oscar | Es el aparato con el que se trabajará |
| 12/11/2026 | Wijmo para tablas y gráficas | Oscar | Pendiente confirmar la licencia (TE-03) |
| 12/11/2026 | Bitácora de checadas inmutable y resumen diario recalculado | Oscar | Auditoría de las checadas |
| 17/11/2026 | Un empleado con checadas se da de baja de forma lógica, no se borra | Danny | Conservar el historial (SE-03) |
| 17/11/2026 | La plantilla de huella no sale del servidor ni se guarda en logs | Danny | Es un dato biométrico (SE-04) |
| 18/11/2026 | Límites de 10 peticiones por minuto a la terminal y 30 a reportes | Danny | Evitar abuso y saturar el aparato (SE-07) |
| 18/11/2026 | El kardex consulta máximo 93 días; se recorta en lugar de rechazar | Danny | Consultas acotadas sin errores de redirección en el formulario GET (OP-04) |
| 18/11/2026 | Login propio sin registro público; cuentas por consola; contraseña mínima de 15 | Danny | Menos dependencias; NIST 800-63B rev. 4 (SE-01) |
| 18/11/2026 | Bitácora en tabla propia sin copiar RFC, CURP ni NSS | Danny | Saber quién hizo qué sin duplicar datos personales (SE-09) |
| 18/11/2026 | Tarea programada incremental; el botón "Sincronizar" sigue pidiendo el día completo | Danny | Menos carga cada minuto; el botón sirve para recuperar checadas atrasadas (OP-01) |
| 18/11/2026 | Estado de la terminal en caché 30 s | Danny | Varias pestañas no saturan el aparato (OP-05) |
| 18/11/2026 | Wijmo se carga solo en Dashboard, Empleados y Kardex | Danny | Turnos pasó de 440 a 48 kB de JS (OP-06) |
| 18/11/2026 | Empleados paginados de 50 en el servidor | Danny | El padrón completo ya no viaja en cada carga (OP-07) |
| 18/11/2026 | RFC, CURP y NSS cifrados y enmascarados, también en el kardex | Danny | Minimizar la exposición de datos personales (SE-05) |
| 18/11/2026 | CSP con nonce y cabeceras de seguridad en todas las respuestas | Danny | Guía 4.10 (SE-08) |

---

## 9. Detalles que hay que saber

| Detalle | Explicación |
|---|---|
| Las pruebas de páginas fallan sin assets compilados | Las vistas usan `@vite`; sin `public/build/manifest.json` responden 500. Correr `npm run build` (o tener `npm run dev` abierto) antes de `php artisan test`. Si se agrega una entrada nueva en `vite.config.js`, hay que volver a compilar |
| `APP_KEY` | RFC, CURP y NSS están cifrados con ella: no se cambia sin volver a cifrar, y un respaldo de la base no sirve sin su `APP_KEY` |
| Un `<script>` nuevo en una vista | Debe llevar `nonce="{{ Vite::cspNonce() }}"`; sin nonce la CSP lo bloquea |
| Una página nueva con tablas o gráficas | Debe agregar `@push('styles') @vite(['resources/js/wijmo/grid.js', …]) @endpush`; si no, `window.AppUI.wijmo` estará vacío |
| Checadas de alguien que aún no está registrado | La tarea incremental no las vuelve a pedir; se recuperan con el botón "Sincronizar" o `php artisan hikvision:sync --from=AAAA-MM-DD` |
| `npm run dev` no es la aplicación | Su URL (puerto 5173) solo sirve CSS y JS; la aplicación se abre en la URL de `php artisan serve` |
| La terminal solo responde en su red | `192.168.0.69` es una IP local; fuera de esa red el dashboard muestra "offline" |
| `HIKVISION_PASSWORD` vacía | Sin la contraseña del aparato, la conexión falla por autenticación |
| Seeder vacío | Hay que crear al menos un turno antes de registrar empleados |
| Marca de agua de Wijmo | Tapa la esquina superior derecha (estado de la terminal) hasta que haya licencia |
| El checkbox "Activo" | En la edición, si no se manda `is_active`, el empleado queda inactivo (comportamiento del checkbox) |

---

## 10. Forma de trabajo

| Tema | Regla |
|---|---|
| Ramas | Por definir con Oscar |
| Commits | `tipo(área): resumen` (`feat`, `fix`, `docs`, `test`, `chore`…), con `-m` en PowerShell |
| Documentación | Cada cambio aplicado lleva su parche en `docs/parches/AAAA-MM-DD/HH-MM_slug.md` en el mismo commit |
| Pendientes | `docs/pendientes/`; al cerrar uno se actualizan sus tres archivos y el índice |

---

## 11. Roadmap

| Fase | Qué | Criterio de terminado |
|---|---|---|
| 1 | Definir con el cliente el "por qué sale", roles y aviso de privacidad (TE-01, TE-02, TE-05) | Respuestas registradas en `docs/pendientes/teracrom/` |
| 2 | Roles (SE-02); login, bitácora, bajas lógicas y huella ya resueltos (SE-01, SE-09, SE-03, SE-04) | Ninguna ruta abierta sin sesión; pruebas de permisos por ruta |
| 3 | Probar con la terminal real (la sincronización ya está optimizada: OP-01, OP-02) | Checadas reales visibles en el dashboard y medición con el aparato |
| 4 | Motivo de salida (según TE-01) | Dashboard muestra quién, a qué hora y por qué |
| 5 | Preparar producción (SE-06, SE-08, OP-09) | Lista 8.3 de la guía de seguridad cumplida |

El detalle está en `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 12. Historial de errores importantes

| Fecha | Error | Causa | Parche |
|---|---|---|---|
| 17/11/2026 | Borrar un empleado borraba sus checadas | `cascadeOnDelete()` y borrado físico | `docs/parches/2026-11-17/11-30_baja-logica-conserva-checadas.md` |
| 17/11/2026 | La plantilla de huella llegaba al navegador y al log | Se devolvía y registraba la respuesta cruda de la terminal | `docs/parches/2026-11-17/11-32_huella-fuera-de-respuestas-y-logs.md` |
| 18/11/2026 | Se marcaba "Huella OK" sin plantilla | La captura no exigía `fingerData` | `docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md` |
| 18/11/2026 | El CSV del kardex podía ejecutar fórmulas en Excel | `csvEscape()` no neutralizaba `=`, `+`, `-`, `@` | `docs/parches/2026-11-18/16-10_csv-sin-formulas.md` |
| 22/11/2026 | Horarios de comida y retardos en `0` aunque hubiera checadas | Teclado DS-K1T804AEF invierte `statusValue` 3/4 (`breakIn`/`breakOut`) y el reloj reportó offset `+08:00` | `docs/parches/2026-11-22/21-50_horarios-comida-recalculo-y-responsividad.md` |

---

## 13. Entorno

La guía completa de instalación, arranque y problemas comunes está en `README.md`, en la raíz del proyecto. Aquí va el resumen.

**Requisitos:** PHP ≥ 8.2, Composer, Node y MySQL (o MariaDB de XAMPP). Base de datos con DBeaver sobre la conexión `localhost:3306`.

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
# Crear en DBeaver: CREATE DATABASE sistema_checador CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
php artisan usuarios:crear     # tu cuenta para entrar
npm run build
php artisan test
```

Para trabajar se abren dos terminales, y una tercera si se quiere la sincronización automática:

```powershell
php artisan serve        # http://127.0.0.1:8000
npm run dev              # assets con recarga
php artisan schedule:work
```

Variables de `.env` que hay que revisar (sin valores reales en este documento):

```env
APP_URL=http://127.0.0.1:8000
DB_DATABASE=sistema_checador
DB_USERNAME=root
DB_PASSWORD=            # la de tu MySQL local
HIKVISION_URL=http://192.168.0.69
HIKVISION_USER=admin
HIKVISION_PASSWORD=     # la del aparato; nunca en el repositorio
```

No hay cuentas de fábrica: cada quien crea la suya con `php artisan usuarios:crear` (contraseña de 15 caracteres o más; puede ser una frase). `SESSION_DRIVER=database` y `SESSION_LIFETIME=30`.
