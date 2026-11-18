# Exportación CSV del kardex sin fórmulas

> Parche · 18/11/2026 · Cierra SE-15, detectado en docs/parches/2026-11-18/15-55_limites-rango-kardex-y-errores.md.

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| Un nombre o departamento que empezara con `=`, `+`, `-` o `@` se ejecutaba como fórmula al abrir el CSV en Excel (por ejemplo `=HYPERLINK(...)` mostraba un enlace a un sitio externo) | `csvEscape()` en `resources/views/reports/kardex.blade.php` solo duplicaba comillas |

Es la inyección de fórmulas de la guía 4.5. El riesgo depende de quién pueda capturar empleados; hoy cualquiera en la red (SE-01).

---

## 2. Fix aplicado

| Qué | Cómo quedó |
|---|---|
| Nuevo módulo | `resources/js/csv.js` con `neutralizeFormula()` y `csvEscape()` |
| Regla | Si el valor empieza con `=`, `+`, `-`, `@`, tabulador o retorno de carro, se le antepone `'` para que la hoja lo abra como texto |
| Excepciones | Números (incluidos negativos, como `-5`) y el marcador `--:--` de checadas vacías, que genera el sistema |
| Uso | `app.js` lo expone en `window.AppUI.csvEscape`; el kardex usa ese en lugar de su función local |

La exportación a Excel (`.xlsx`) de Wijmo no tenía el problema: guarda los valores como texto, no como fórmulas.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `resources/js/csv.js`, este documento |
| Modificados | `resources/js/app.js` (importa y expone `csvEscape`), `resources/views/reports/kardex.blade.php` (usa `csvEscape` de `AppUI`) |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones.
3. `npm run build` (obligatorio: cambió el JavaScript) y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **28** (no hay pruebas de JavaScript en el proyecto; la función se probó aparte con 12 casos: fórmulas, tabulador, negativos, `--:--`, nulos y comillas).
2. Empleados → crear uno con departamento `=1+1` → registrar o simular una checada → Kardex → Exportar CSV → abrir en Excel: la celda debe mostrar `'=1+1` como texto, no `2`.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟢 Baja | Pruebas de JavaScript | El proyecto no tiene un ejecutor de pruebas para JS (por ejemplo Vitest); `csv.js` quedó como módulo separado para poder probarlo cuando se agregue |
