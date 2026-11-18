# Pendientes del sistema checador

> Índice · actualizado 18/11/2026 · Todo lo que falta, por apartado.

---

## 1. Estructura

```text
docs/pendientes/
├── README.md
├── seguridad/       SE-NN
├── optimizacion/    OP-NN
└── teracrom/        TE-NN: lo que solo puede resolver el cliente
```

Cada carpeta tiene `pendientes.md` (todo), `resuelto.md` (resueltos y parciales con cómo se resolvieron) y `sinresolver.md` (solo lo abierto).

---

## 2. Resumen

| Apartado | Resueltos | Parciales | Sin resolver | 🔴 Alta abiertos |
|---|---|---|---|---|
| `seguridad/` | 9 | 0 | 6 | 1 |
| `optimizacion/` | 5 | 0 | 4 | 0 |
| `teracrom/` | 0 | 0 | 8 | 2 |

Origen: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 3. Reglas

| Regla | Detalle |
|---|---|
| Claves | Fijas y sin reutilizar: `SE-` seguridad, `OP-` optimización, `TE-` cliente |
| Orden | Por prioridad (🔴, 🟡, 🟢) y luego por clave |
| Cliente | Lo de `teracrom/` también se menciona en el apartado técnico que desbloquea ("Depende de TE-04") |
| Estados | ❌ Sin resolver, ⚠️ Parcial, ✅ Resuelto; en este proyecto (desde cero) también "En revisión" y "Sujeto a cambios" |
| Al cerrar uno | Cambiar el estado en los tres archivos, agregar "Cómo se resolvió" en `resuelto.md` con la ruta del parche, quitarlo de `sinresolver.md` y actualizar este resumen |
| Extensión | Una línea por pendiente; el detalle vive en la auditoría o el parche |
