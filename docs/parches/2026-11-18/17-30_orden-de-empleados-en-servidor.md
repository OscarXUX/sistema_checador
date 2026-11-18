# Ordenar empleados por columna sobre todo el padrón

> Parche · 18/11/2026 · Sigue a docs/parches/2026-11-18/17-15_wijmo-por-pagina-y-empleados-paginados.md. Pedido de Danny: "que se apliquen a todos los datos de todos los empleados y que te muestre los 50 pero correspondientes al filtro aplicado a todos".

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| Al hacer clic en el encabezado de una columna, solo se ordenaban los 50 empleados de la página visible | Con la paginación en el servidor (OP-07), Wijmo seguía ordenando en el navegador solo lo que tenía cargado |

La búsqueda ya se hacía en el servidor sobre todos los empleados; lo que faltaba era el orden.

---

## 2. Fix aplicado

| Qué | Cómo quedó |
|---|---|
| `GET /employees/list` | Acepta `sort` y `direction` (`asc` o `desc`). Ordena **todo** el padrón (o todo el resultado de la búsqueda) y después pagina. `meta` regresa el orden aplicado |
| Columnas que se pueden ordenar | ID Reloj, Nombre, RFC, Departamento, Turno (por nombre del turno), Estado (ascendente = "Activo" primero) y Reloj / Huella (fecha de sincronización). Acciones no se ordena |
| Columnas no permitidas | Responde 422; no se puede ordenar por columnas que no están en `EmployeeController::SORTABLE_COLUMNS` |
| Desempate | Por `id`, para que ningún empleado se repita ni se salte entre páginas |
| Pantalla | Al hacer clic en un encabezado se pide la página 1 con ese orden; "Anterior / Siguiente" y la búsqueda conservan el orden elegido. La tabla ya no reordena por su cuenta: solo muestra la flecha de la columna |

Se probó en un navegador (Chrome sin ventana) con 120 empleados: orden ascendente y descendente por nombre, paso a la página 2 manteniendo el orden y búsqueda combinada con el orden, sin errores de JavaScript.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | Este documento |
| Modificados | `app/Http/Controllers/EmployeeController.php` (`SORTABLE_COLUMNS`, `list()`, `paginateEmployees()`), `resources/views/employees/index.blade.php`, `tests/Feature/EmployeeListPaginationTest.php` |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `npm run build` (o `npm run dev` abierto) y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **65** (4 nuevas: `test_sorting_applies_to_all_employees_before_paginating`, `test_sorting_combines_with_search`, `test_sorting_by_shift_and_status`, `test_sorting_rejects_unknown_columns`).
2. Empleados con más de 50 registros → clic en "Nombre Completo" dos veces (descendente): el primero de la página 1 es el último alfabéticamente de **todo** el padrón.
3. "Siguiente": la página 2 continúa el mismo orden.
4. Escribir un departamento en la búsqueda: el resultado sigue ordenado por la columna elegida.
