# Wijmo solo donde se usa y listado de empleados paginado

> Parche · 18/11/2026 · Cierra OP-06 y OP-07 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md. Registra también SE-14 (`composer audit` corrido por Danny).

---

## 1. Contexto / Causa

| Clave | Problema | Causa |
|---|---|---|
| OP-06 | Todas las páginas descargaban todo Wijmo (tablas, gráficas, filtros, Excel y JSZip), aunque no lo usaran | `resources/js/app.js` importaba todos los módulos y `app.css` todos los estilos de Wijmo |
| OP-07 | La pantalla de Empleados mandaba a todos los empleados en el HTML y en `/employees/list` | `serializeEmployees()` sin paginación; la búsqueda filtraba en el navegador |
| SE-14 | `composer audit` no se había corrido | En la auditoría no hubo acceso a Packagist |

---

## 2. Fix aplicado

### 2.1 OP-06 · Qué página usa Wijmo

| Página | Usa Wijmo | Módulos que carga |
|---|---|---|
| Login | ❌ No | Solo CSS |
| Turnos y Tolerancias | ❌ No | Solo `app.js` |
| Empleados y Huellas | ✅ Sí | Tabla (`wijmo/grid.js`) |
| Dashboard | ✅ Sí | Tabla del monitor y dos gráficas (`wijmo/grid.js`, `wijmo/chart.js`) |
| Kardex | ✅ Sí | Tablas, filtros por columna y exportación a Excel (`wijmo/grid.js`, `wijmo/kardex.js`) |

| Archivo | Qué contiene |
|---|---|
| `resources/js/app.js` | Solo lo común: Bootstrap, avisos, `apiFetch`, estado de la terminal, `csvEscape` |
| `resources/js/wijmo/grid.js` | Núcleo de Wijmo, idioma es-MX y FlexGrid |
| `resources/js/wijmo/chart.js` | FlexChart |
| `resources/js/wijmo/kardex.js` | Filtros por columna, exportación a Excel y JSZip |
| `resources/js/wijmo/register.js` | Junta los módulos en `window.AppUI.wijmo` sin importar el orden de carga |
| `resources/css/wijmo.css` | Estilos de Wijmo y su ajuste al tema oscuro (salieron de `app.css`) |

Cada vista que usa Wijmo agrega sus módulos con `@push('styles') @vite([...]) @endpush`. El código de las páginas no cambió: sigue usando `window.AppUI.wijmo`.

### 2.2 OP-07 · Empleados paginados en el servidor

| Qué | Cómo quedó |
|---|---|
| `GET /employees/list` | Acepta `page`, `per_page` (50 por omisión, máximo 100) y `q`; responde `employees` y `meta` (`total`, `page`, `per_page`, `last_page`, `q`) |
| Búsqueda | En el servidor, por número de reloj, nombre, RFC, CURP, departamento, puesto o turno; espera 300 ms después de teclear |
| Página que ya no existe | Responde la última (por ejemplo, después de dar de baja al único empleado de la última página) |
| Pantalla | Paginador "Anterior / Página X de Y / Siguiente" debajo de la tabla; el contador muestra el total |
| HTML inicial | Solo trae la primera página |

### 2.3 SE-14 · Dependencias de PHP

Danny corrió `composer audit` el 18/11/2026: "No security vulnerability advisories found.". `npm audit` ya daba 0 en la auditoría.

---

## 3. Medición antes y después

Compilado con `npm run build`, tamaño comprimido (gzip) que descarga el navegador la primera vez:

| Página | JS antes | JS después | CSS antes | CSS después |
|---|---|---|---|---|
| Login | — | — | 65.8 kB | 45.3 kB |
| Turnos | 440.4 kB | **47.7 kB** | 65.8 kB | 45.3 kB |
| Empleados | 440.4 kB | 255.8 kB | 65.8 kB | 65.6 kB |
| Dashboard | 440.4 kB | 332.1 kB | 65.8 kB | 65.6 kB |
| Kardex | 440.4 kB | 368.3 kB | 65.8 kB | 65.6 kB |

Los módulos compartidos (núcleo de Wijmo, tabla) se descargan una vez y el navegador los reutiliza entre páginas. Vite sigue avisando que un archivo pasa de 500 kB: es el núcleo de Wijmo (`es5-esm-…`), que ya solo se carga en las tres páginas que lo usan.

---

## 4. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `resources/js/wijmo/grid.js`, `resources/js/wijmo/chart.js`, `resources/js/wijmo/kardex.js`, `resources/js/wijmo/register.js`, `resources/css/wijmo.css`, `tests/Feature/EmployeeListPaginationTest.php`, este documento |
| Modificados | `resources/js/app.js`, `resources/css/app.css`, `vite.config.js` (entradas nuevas), `resources/views/dashboard.blade.php`, `resources/views/employees/index.blade.php`, `resources/views/reports/kardex.blade.php`, `app/Http/Controllers/EmployeeController.php` (`list()`, `paginateEmployees()`) |

---

## 5. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `npm run build` (**obligatorio**: hay archivos de entrada nuevos; sin esto las páginas con Wijmo dan error 500 "Unable to locate file in Vite manifest"). Si usas `npm run dev`, reinícialo.
4. `php artisan optimize:clear` y Ctrl + F5.

---

## 6. Cómo verificar

1. `php artisan test`: deben pasar las **61** (6 nuevas en `EmployeeListPaginationTest`).
2. Turnos → F12 → Red: no debe aparecer ningún archivo `chart-…`, `kardex-…` ni `es5-esm-…` (núcleo de Wijmo).
3. Dashboard, Empleados y Kardex: tablas, gráficas, filtros y "Exportar Excel" funcionan igual que antes.
4. Empleados con más de 50 registros: aparece "Página 1 de 2"; "Siguiente" carga los demás; buscar un nombre de la página 2 lo encuentra desde la página 1.

---

## 7. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟢 Baja | Ordenar por columna en Empleados · ✅ Resuelto 18/11/2026: `docs/parches/2026-11-18/17-30_orden-de-empleados-en-servidor.md` | Al hacer clic en un encabezado se ordena solo la página que se ve, no todo el padrón |
| 🟢 Baja | Búsqueda con `%` o `_` en SQLite | En MySQL se buscan como texto; en SQLite (pruebas) funcionan como comodín. No afecta en producción |
