# Seguridad: resuelto

> Pendientes · actualizado 17/11/2026 · Resueltos y parciales, con cómo se resolvieron.

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 2 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 0 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Acceso

No hay.

---

## 3. Datos y privacidad

| Prioridad | Pendiente | Detalle | Recomendación / Resultado esperado | Estado |
|---|---|---|---|---|
| 🔴 Alta | **SE-03** · Borrar un empleado borra sus checadas | `cascadeOnDelete()` en `attendance_logs` y `daily_summaries`; `EmployeeController::destroy()` borra físicamente | Baja lógica y `restrictOnDelete()`; las checadas se conservan | ✅ Resuelto 17/11/2026 |
| 🔴 Alta | **SE-04** · La plantilla de huella llega al navegador y al log | `device_response` con `fingerData` en la respuesta de captura; `raw_body` completo en el log | Quitar ambos; registrar solo códigos de error | ✅ Resuelto 17/11/2026 |

### 3.1 SE-03 · Cómo se resolvió

Un empleado con checadas se da de baja de forma lógica (`is_active = false`) y conserva su historial; sin historial se elimina. La base ya no borra en cascada.

| Qué | Cómo quedó |
|---|---|
| Aplicación | `EmployeeController::destroy()` revisa si hay historial antes de borrar |
| Base de datos | Migración `2026_11_17_000001_restrict_employee_delete_on_attendance_tables.php`: `restrictOnDelete()` |
| Pruebas | `test_employee_with_attendance_is_deactivated_and_history_is_kept`, `test_database_blocks_deleting_employee_with_attendance` |
| Detalle | `docs/parches/2026-11-17/11-30_baja-logica-conserva-checadas.md` |

### 3.2 SE-04 · Cómo se resolvió

La captura ya no devuelve la respuesta cruda de la terminal y el log de errores cambia los datos biométricos por `[REDACTADO]`.

| Qué | Cómo quedó |
|---|---|
| Respuesta | `captureFingerprint()` manda solo `finger_no` y `template_captured` |
| Log | `redactSensitive()` en `HikvisionIsapiService`; sin `raw_body` |
| Pruebas | `test_capture_result_does_not_include_fingerprint_template`, `test_error_log_redacts_fingerprint_template`, `test_fingerprint_capture_response_does_not_expose_template` |
| Detalle | `docs/parches/2026-11-17/11-32_huella-fuera-de-respuestas-y-logs.md` |

---

## 4. Infraestructura

No hay.

---

## 5. Lógica y errores

No hay.
