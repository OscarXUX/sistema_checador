# Sistema checador: control de entradas y salidas con terminal Hikvision

> Referencia · actualizado 17/11/2026 · Para quien se integra al proyecto (persona o sesión de Claude).

---

## 1. Resumen

| Dato | Valor |
|---|---|
| Qué es | Sistema web que descarga las checadas de una terminal biométrica Hikvision y las muestra en un dashboard: quién entra, quién sale, a qué hora, retardos, comida y horas extra |
| Pedido original | Oscar: "un dashboard para registrar cada vez que alguien entra y sale, con un checador conectado a una página web. Que muestre quién sale, a qué hora sale, por qué sale y todo eso" |
| Estado | Base funcional hecha por Oscar (commit del 12/11/2026). Sin login ni roles; sin pruebas contra la terminal real |
| Participantes | Oscar (base del proyecto), Danny |
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
├── Console/Commands/HikvisionSyncCommand.php   Comando hikvision:sync
├── Http/Controllers/                           Dashboard, Device, Employee, Report, WorkShift
├── Http/Requests/                              Validación de empleados (RFC, CURP, NSS)
├── Models/                                     Employee, WorkShift, AttendanceLog, DailySummary
└── Services/
    ├── HikvisionIsapiService.php               Cliente HTTP de la terminal
    └── AttendanceCalculatorService.php         Guarda checadas y calcula el resumen diario
resources/views/                                dashboard, employees, reports/kardex, shifts
resources/js/app.js                             Wijmo, estado de la terminal, peticiones con CSRF
routes/web.php                                  Todas las rutas (sin login)
routes/console.php                              hikvision:sync cada minuto
tests/                                          21 pruebas (Feature y Unit)
```

---

## 4. Convenciones

| Tema | Convención |
|---|---|
| Código | En inglés (`work_shifts`, `punched_at`); textos de pantalla y mensajes en español |
| Respuestas | Los controladores responden JSON cuando la petición lo pide (`expectsJson()`) y redirección en formularios |
| Validación | Form Requests (`StoreEmployeeRequest`, `UpdateEmployeeRequest`) y `$request->validated()` |
| Zona horaria | `APP_TIMEZONE=America/Mexico_City` |
| Documentación | `docs/` según el estándar de Danny; el commit se propone en el chat, no en los .md |

---

## 5. Base de datos

| Tabla | Para qué | Notas |
|---|---|---|
| `work_shifts` | Turnos: entrada, salida, minutos de comida, tolerancia, horas por semana | Requerido para crear empleados |
| `employees` | Empleados con su número en el reloj (`hikvision_employee_no`), RFC, CURP, NSS, departamento, puesto | `fingerprint_enrolled` y `device_synced_at` reflejan el estado en la terminal |
| `attendance_logs` | Cada checada tal como llegó del reloj, con `event_type` y `raw_payload` (JSON) | Único `(employee_id, punched_at)`; no se puede editar desde Eloquent. La base impide borrar a un empleado que tenga checadas (`restrictOnDelete`) |
| `daily_summaries` | Resumen por empleado y día: entrada, comida, salida, minutos trabajados, retardo, horas extra, estado | Se recalcula al llegar cada checada nueva |
| `users`, `sessions`, `cache`, `jobs` | Tablas base de Laravel | `users` existe pero no se usa (no hay login) |

`event_type` admite: `check_in` (Entrada), `break_out` (Salida a comida), `break_in` (Regreso de comida), `check_out` (Salida) y `unclassified`. Si el reloj no manda el tipo, se asigna por orden: 1.ª entrada, 2.ª salida a comida, 3.ª regreso, 4.ª salida.

---

## 6. Módulos

| Módulo | Qué hace | Pantallas / rutas | Permisos |
|---|---|---|---|
| Dashboard | Indicadores del día (en planta, en comida, retardos, horas extra de la semana), dos gráficas y el monitor de checadas | `GET /`, `GET /dashboard/data` | Ninguno |
| Terminal | Estado de conexión y sincronización manual | `GET /device/status`, `POST /device/sync` | Ninguno |
| Empleados y huellas | Alta, edición, baja (lógica si ya tiene checadas); enviar al reloj, borrar del reloj y capturar huella remota | `/employees`, `/employees/{id}/sync-device`, `/employees/{id}/capture-fingerprint` | Ninguno |
| Kardex | Resumen diario por rango de fechas, departamento o empleado; bitácora de checadas con su JSON original; exportación a Excel | `GET /reports/kardex`, `GET /reports/kardex/data` | Ninguno |
| Turnos | Alta y edición de turnos | `/shifts` | Ninguno |
| Sincronización automática | Descarga las checadas del día cada minuto | `php artisan hikvision:sync` (scheduler) | — |

---

## 7. Funcionalidades

| Funcionalidad | Estado |
|---|---|
| Descargar checadas de la terminal por ISAPI (paginado) | ✅ Implementado, probado solo con respuestas simuladas |
| Evitar checadas duplicadas | ✅ Implementado |
| Calcular retardos, minutos de comida, trabajados y horas extra | ✅ Implementado |
| Dashboard con indicadores y gráficas | ✅ Implementado |
| Kardex con filtros y exportación a Excel | ✅ Implementado |
| Alta de empleados en la terminal y captura remota de huella | ✅ Implementado, sin probar con el aparato |
| Mostrar **por qué** sale una persona | ❌ No existe; falta definirlo (TE-01) |
| Inicio de sesión y roles | ❌ No existe (SE-01, SE-02) |
| Monitor que se actualice solo | ❌ Solo se actualiza con el botón "Refrescar"; lo automático es el estado de la terminal (cada 30 s) |
| Turnos que cruzan la medianoche | ❌ El cálculo es por día calendario (SE-11) |

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

---

## 9. Detalles que hay que saber

| Detalle | Explicación |
|---|---|
| Las pruebas de páginas fallan sin assets compilados | Las vistas usan `@vite`; sin `public/build/manifest.json` responden 500. Correr `npm run build` (o tener `npm run dev` abierto) antes de `php artisan test` |
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
| 2 | Login y roles (SE-01, SE-02); bajas lógicas y huella ya resueltas (SE-03, SE-04) | Ninguna ruta abierta sin sesión; pruebas de permisos por ruta |
| 3 | Probar con la terminal real y ajustar la sincronización (OP-01, OP-02) | Checadas reales visibles en el dashboard |
| 4 | Motivo de salida (según TE-01) | Dashboard muestra quién, a qué hora y por qué |
| 5 | Preparar producción (SE-06, SE-08, OP-09) | Lista 8.3 de la guía de seguridad cumplida |

El detalle está en `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 12. Historial de errores importantes

| Fecha | Error | Causa | Parche |
|---|---|---|---|
| 17/11/2026 | Borrar un empleado borraba sus checadas | `cascadeOnDelete()` y borrado físico | `docs/parches/2026-11-17/11-30_baja-logica-conserva-checadas.md` |
| 17/11/2026 | La plantilla de huella llegaba al navegador y al log | Se devolvía y registraba la respuesta cruda de la terminal | `docs/parches/2026-11-17/11-32_huella-fuera-de-respuestas-y-logs.md` |

---

## 13. Entorno

**Requisitos:** PHP ≥ 8.2, Composer, Node y MySQL (o MariaDB de XAMPP). Base de datos con DBeaver sobre la conexión `localhost:3306`.

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
# Crear en DBeaver: CREATE DATABASE sistema_checador CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
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

No hay usuarios del sistema porque no hay login.
