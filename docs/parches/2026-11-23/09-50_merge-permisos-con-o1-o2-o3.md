# Merge de perfiles y permisos con las tareas O1, O2 y O3 de Oscar

> Parche · 23/11/2026 · Sigue a `docs/parches/2026-11-23/09-10_perfiles-y-permisos.md` y `docs/parches/2026-11-23/09-22_o1-o2-o3-excel-rotaciones-medios.md`.

---

## 1. Contexto / Causa

| Quién | Qué cambió |
|---|---|
| Danny | Perfiles y permisos: `can:` en cada ruta y `@can` en las vistas |
| Oscar | O1 Excel por mes, O2 rotaciones y excepciones de turno, O3 medios de marcaje (rutas, vistas y migraciones nuevas sin permisos) |

`git pull` chocó en 7 archivos: `routes/web.php`, el menú lateral, las vistas de empleados, kardex y turnos, el contexto y el índice de `docs/`.

---

## 2. Fix aplicado

### 2.1 Conflictos

| Archivo | Cómo se resolvió |
|---|---|
| `routes/web.php` | Rutas de Oscar con su permiso (tabla 2.2) y la sección de Administración |
| `resources/views/partials/sidebar-content.blade.php` | "Medios de Marcaje" dentro de `@can('medios.consultar')`; se conserva Administración |
| `resources/views/employees/index.blade.php` | Botón "Medios" (tarjeta / PIN) solo con `terminal.usuarios` |
| `resources/views/reports/kardex.blade.php` | Se queda la exportación a Excel en el servidor de Oscar; el botón sigue dentro de `@can('kardex.exportar')` |
| `resources/views/shifts/index.blade.php` | Versión de Oscar (pestañas y vista previa) con `@can`: turnos base con `turnos.administrar`; rotaciones, grupos, asignaciones y excepciones con `turnos.programar`; sin permiso dice "Solo consulta" |
| `docs/CONTEXTO_SISTEMA_CHECADOR.md`, `docs/README.md` | Se unieron las dos versiones |

Además, `resources/views/marking-methods/index.blade.php` (no chocó) ahora oculta "Detectar en Terminal" y "Habilitar / Deshabilitar" sin `medios.administrar`.

### 2.2 Permisos de las rutas nuevas

| Ruta | Permiso |
|---|---|
| `GET /reports/kardex/export/xlsx` | `kardex.exportar` |
| `/shifts/rotations`, `/groups`, `/assignments`, `/exceptions` | `turnos.programar` |
| `POST /employees/{id}/enroll-card`, `enroll-pin` | `terminal.usuarios` |
| `POST /employees/{id}/enroll-face` | `terminal.huellas` (ahora "Capturar huellas y rostro": ambos son biométricos) |
| `GET /marking-methods` | `medios.consultar` (nuevo) |
| `POST /marking-methods/detect`, `PATCH /marking-methods/{id}` | `medios.administrar` (nuevo) |

### 2.3 Migración renombrada

Las dos ramas crearon una migración `2026_11_23_000001_…`. La de perfiles pasó a `2026_11_23_000004_create_profiles_and_permissions_tables.php` para que corra después de las de Oscar.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | Este documento |
| Modificados | Los 7 en conflicto, `app/Support/Permissions.php` (módulo `medios`), `app/Models/User.php` (`homeRouteName()` con Medios de marcaje), `resources/views/marking-methods/index.blade.php`, `docs/parches/2026-11-23/09-10_perfiles-y-permisos.md` y `docs/pendientes/seguridad/resuelto.md` (nombre de la migración) |
| Renombrados | `database/migrations/2026_11_23_000001_create_profiles_and_permissions_tables.php` → `2026_11_23_000004_…` |

---

## 4. Pasos manuales

1. `composer install` (Oscar agregó PhpSpreadsheet).
2. `php artisan migrate`
3. `php artisan optimize:clear`
4. `npm run build` y Ctrl + F5.

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **111**.
2. Con el perfil Consulta: en Turnos ve las cuatro pestañas sin botones de alta, edición ni borrado; "Medios de Marcaje" no aparece en el menú y `/marking-methods` da 403; en Kardex no hay botones de exportar.
3. Con el administrador: todo lo anterior aparece y funciona.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟢 Baja | Botón de rostro en el modal "Medios" | Lo ve quien tiene `terminal.usuarios`; si no tiene `terminal.huellas`, el servidor responde 403 con mensaje. Se puede ocultar cuando se active el rostro (TE-02) |
