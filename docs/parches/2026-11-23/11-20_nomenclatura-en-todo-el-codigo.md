# Nomenclatura de componentes y estructuras en todo el código

> Parche · 23/11/2026 · Respuesta de Oscar a P4: "a todo el código". Oscar dejó de tocar pantallas mientras se hacía (acordado con Danny).

---

## 1. Contexto / Causa

| Antes | Problema |
|---|---|
| Ids en inglés con guiones (`employee-form-dialog`, `emp-rfc`, `kpi-in-plant`) y, en lo nuevo de hoy, prefijo con guion (`txt-nombre`) | No seguían la guía del sistema de tickets (`txtNombre`) |
| Variables de JS y PHP sin prefijo (`payload`, `employees`, `$validated`, `$filters`) | No se distinguían listas, objetos y componentes |

La regla quedó escrita en `docs/NOMENCLATURA_COMPONENTES_Y_ESTRUCTURAS.md`.

---

## 2. Fix aplicado

### 2.1 Pantallas (ids y JavaScript)

| Pantalla | Ejemplos de cambio |
|---|---|
| Login | `email` → `txtCorreo`, `toggle-password` → `btnVerContrasena` |
| Layout y menú | `mobile-nav-drawer` → `mdMenuMovil`, `app-toast-container` → `contenedorAvisos` |
| Dashboard | `kpi-in-plant` → `lblEnPlanta`, `punctuality-chart` → `chartPuntualidad`, `live-monitor-grid` → `gridMonitor` |
| Empleados | `employee-form-dialog` → `mdEmpleado`, `emp-shift` → `cbTurno`, `employees-grid` → `gridEmpleados`, `cb-tipo-sangre` → `cbTipoSangre` |
| Kardex | `kardex-grid` → `gridKardex`, `filter-start-date` → `dtFechaInicio`, `export-xlsx-dialog` → `mdExcel`, `md-checadas` → `mdChecadas` |
| Turnos | `panel-rotaciones` → `tabRotaciones` (panel), `tab-rotaciones` → `btnTabRotaciones` (botón), `shift-entry` → `dtTurnoEntrada` |
| Medios, catálogos y administración | `grid-ventanas` → `gridVentanas`, `rb-grant-12` → `rbDar12`, `txt-desde-5` → `txtDesde5` |

En el JavaScript de cada vista y en `resources/js/app.js`: componentes con su prefijo (`mdEmpleado`, `btnPaginaSiguiente`), listas con `lst` (`lstEmpleados`, `lstVistaKardex`), objetos con `data` (`dataRespuesta`, `dataRutas`, `dataKpis`). Los valores simples quedaron sin prefijo (`strFoto` → `htmlFoto`, `intResumen` → `idResumen`).

### 2.2 PHP

| Dónde | Ejemplos |
|---|---|
| Datos a las vistas | `'shifts'` → `'lstTurnos'`, `'employeesPage'` → `'dataPaginaEmpleados'`, `'filters'` → `'dataFiltros'`, `'dashboardData'` → `'dataDashboard'` |
| Controladores | `$validated` → `$dataValidado`, `$before` → `$dataAntes`, `$summaries` → `$lstResumenes` |
| Servicios y comandos | `AttendanceCalculatorService` (`$lstChecadas`, `$dataResumen`, `$dataContextoTurnos`), `ShiftScheduleResolver`, `PunchClassifier`, `AuditLogger`, `KardexExcelExportService`, `HikvisionIsapiService` |

### 2.3 Lo que no cambió (a propósito)

Base de datos, llaves JSON de las respuestas, `name` de los campos de formulario, clases CSS, nombres de rutas, URLs, funciones, clases y constantes. Por eso no hay migraciones y el API responde igual.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `docs/NOMENCLATURA_COMPONENTES_Y_ESTRUCTURAS.md`, este documento |
| Modificados | Todas las vistas de `resources/views/` con ids o scripts, `resources/js/app.js`, 27 archivos de `app/` (controladores, servicios, comandos, modelos `WorkShift` y `ShiftRotation`, `LoginRequest`), `tests/Feature/AuthTest.php` y `tests/Feature/ShiftScheduleTest.php` (ids que revisan), `docs/CONTEXTO_SISTEMA_CHECADOR.md`, `docs/README.md`, la propuesta del 22/11/2026 |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `php artisan optimize:clear` y `npm run build`; Ctrl + F5 (el JS cambió).

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **143**.
2. Se recorrió cada pantalla en Chrome sin errores en la consola: login (ver contraseña), dashboard (refrescar, buscar, aviso de incidencias), empleados (alta, edición, medios, huella, baja, búsqueda, paginación), kardex (agrupar, Excel, CSV, checadas y corrección, JSON), turnos (pestañas, turno, rotación con pasos, grupo, asignación, excepción), medios, ventanas, tipos de evento, usuarios, perfiles, permisos y el menú móvil.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Código nuevo de Oscar | Debe usar la guía desde ya; las pantallas que tenga en una rama sin subir chocarán con estos ids: hacer `git pull` antes de seguir |
| 🟢 Baja | Pruebas automáticas | Las variables dentro de `tests/` no se renombraron (solo los ids que revisan); se pueden migrar cuando se toque cada prueba |
