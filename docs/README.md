# Documentación del sistema checador

> Índice · actualizado 17/11/2026 · Cómo está organizada la carpeta.

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
└── pendientes/
    ├── README.md                      Resumen y reglas de los pendientes
    ├── seguridad/                     SE-NN
    ├── optimizacion/                  OP-NN
    └── teracrom/                      TE-NN: lo que solo puede resolver el cliente
```

Ejemplo de ruta: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

Las carpetas `parches/`, `propuestas/` y `despliegue/` se crean cuando haga falta, con el mismo esquema, y se agregan a este árbol.
