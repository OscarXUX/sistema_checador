# Documentación del sistema checador

> Índice · actualizado 23/11/2026 · Cómo está organizada la carpeta.

---

## 1. Organización

Los documentos vivos (contexto, arquitectura) van sueltos en `docs/` con nombre en MAYÚSCULAS. Los documentos de un momento (auditorías, parches, propuestas) van en una carpeta por día `AAAA-MM-DD/` y el nombre del archivo empieza con la hora `HH-MM_` (24 h, hora del centro de México), para que se ordenen solos.

```text
docs/
├── README.md                          Este índice
├── CONTEXTO_SISTEMA_CHECADOR.md       Qué es el sistema, stack, base de datos y cómo levantarlo
├── auditorias/
│   └── 2026-11-17/
│       └── 11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md
├── parches/
│   ├── 2026-11-17/
│   │   ├── 11-30_baja-logica-conserva-checadas.md
│   │   └── 11-32_huella-fuera-de-respuestas-y-logs.md
│   ├── 2026-11-18/
│   │   ├── 15-55_limites-rango-kardex-y-errores.md
│   │   ├── 16-10_csv-sin-formulas.md
│   │   ├── 16-20_inicio-de-sesion-y-cuentas.md
│   │   ├── 16-25_bitacora-de-acciones.md
│   │   ├── 17-00_sincronizacion-incremental-y-en-lote.md
│   │   ├── 17-15_wijmo-por-pagina-y-empleados-paginados.md
│   │   ├── 17-30_orden-de-empleados-en-servidor.md
│   │   ├── 17-35_rfc-curp-nss-cifrados-y-enmascarados.md
│   │   └── 17-36_cabeceras-de-seguridad.md
│   ├── 2026-11-22/
│   │   ├── 20-00_turnos-nocturnos-kardex-json-y-despliegue.md
│   │   ├── 20-40_candado-pruebas-config-en-cache.md
│   │   └── 21-50_horarios-comida-recalculo-y-responsividad.md
│   └── 2026-11-23/
│       ├── 09-10_perfiles-y-permisos.md
│       ├── 09-22_o1-o2-o3-excel-rotaciones-medios.md
│       ├── 09-50_merge-permisos-con-o1-o2-o3.md
│       ├── 10-25_ventanas-tipos-evento-y-validaciones.md
│       └── 10-50_correccion-de-checadas-e-incidencias-en-dashboard.md
├── despliegue/
│   └── 2026-11-22/
│       └── 20-00_GUIA_DESPLIEGUE_PRODUCCION.md
├── propuestas/
│   └── 2026-11-22/
│       └── 23-15_propuesta-notas-oscar-perfiles-catalogos-turnos.md
└── pendientes/
    ├── README.md                      Resumen y reglas de los pendientes
    ├── seguridad/                     SE-NN
    ├── optimizacion/                  OP-NN
    └── teracrom/                      TE-NN: lo que solo puede resolver el cliente
```

Ejemplo de ruta: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

Las carpetas nuevas (por ejemplo `handoffs/`) se crean cuando haga falta, con el mismo esquema, y se agregan a este árbol.
