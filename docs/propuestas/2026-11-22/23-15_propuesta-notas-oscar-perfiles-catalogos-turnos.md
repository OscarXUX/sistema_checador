# Notas de Oscar: perfiles, catálogos, validaciones y turnos

> Propuesta · 22/11/2026 · Notas en papel de Oscar y su retroalimentación por audio del 22/11/2026. Para revisar en la reunión antes de programar.

---

## 1. Contexto

Oscar compartió notas de lo que debe tener el sistema y después confirmó por audio cómo entenderlas. Este documento junta ambas cosas, propone cómo hacer cada punto y deja las dudas para la reunión. Oscar pidió quedarse con algunas tareas (sección 6).

| # | Nota | Respuesta de Oscar | Estado hoy |
|---|---|---|---|
| 1, 2 | Login y perfiles con la estructura de tickets | "Los perfiles tienen que tener la misma estructura que el sistema de tickets… y que el administrador tenga catálogos de igual modo" | ⚠️ Hay login (SE-01); faltan perfiles y catálogos |
| 3 | Validaciones: 2 entradas, 2 salidas, salida sin entrada | "Detectar y/o checar repetidas o fuera de orden… eso sí está bien" | ❌ Solo se evitan duplicados exactos |
| 4 | Catálogo de tipo de evento | "Sí entró, sí 5 minutos tarde, etc." | ⚠️ El retardo se calcula, sin catálogo |
| 5 | Medios de marcaje | "Que se conecte automáticamente si es de huella, rostro, tarjeta o PIN" | ⚠️ Se reconocen al sincronizar; el alta solo hace huella |
| 6 | Clasificar por horario | "Que sea por hora y no por orden: de 7 a 10 entrada, de 5 a 7 salida… y que se pueda modificar en los catálogos" | ❌ Usa la tecla del reloj o el orden |
| 7 | Excel con hoja por mes, límite de 1 año | Confirmado | ❌ Una hoja; kardex limitado a 93 días (OP-04) |
| 8 | Bitácora | Confirmado | ✅ SE-09 |
| 9 | Formato del Excel | Confirmado | ❌ |
| 10 | Check a la autenticación | "Es solo revisar" | ✅ Revisión hecha en la auditoría (SE-01) |
| 11 – 13 | Turnos rotativos por plantilla, departamento o grupo; sentido y días; excepciones | Confirmado | ❌ Un turno fijo por empleado |
| 14 | Validación de turnos por usuario con check obligatorio | "Quién sabe… pregunto mañana en la reunión" | ❓ P1 |
| 15 | Departamento y puesto como catálogo | Confirmado | ❌ Texto libre |
| 16 | Foto, tipo de sangre | "Lo que le falta de la foto al tipo de sangre" | ❓ P2 |
| 17 – 24 | Campos opcionales, nomenclatura, comida en 0, responsivo, ubicación, PWA, remotos | "Lo demás está bien" | Ver sección 4 y 5 |

---

## 2. Preguntas para la reunión

| # | Pregunta | Opciones | Recomendación |
|---|---|---|---|
| P1 | Nota 14: ¿qué es la "validación de turnos por usuarios" con check obligatorio? | A) Un supervisor aprueba la jornada antes de que cuente para nómina; B) Un check por empleado de "debe checar" (algunos están exentos); C) Otra | Preguntar a quien escribió la nota |
| P2 | Nota 16: ¿qué campos de ficha de personal faltan "de la foto al tipo de sangre"? | Lista exacta (foto, teléfono, contacto de emergencia, tipo de sangre…) | Pedir la lista. **Tipo de sangre es dato de salud (sensible, LFPDPPP)**: consentimiento expreso y cifrado como RFC/CURP/NSS |
| P3 | En tickets, "puesto" es el perfil del usuario; aquí "puesto" es el del empleado (nota 15). ¿Cómo se llaman? | A) "Perfiles" para usuarios del sistema y "Puestos" para empleados; B) Igual que tickets | A, para no confundir |
| P4 | Nota 19: ¿la nomenclatura (`txt`, `cb`, `grid`, `lst`, `data`) se aplica al código existente o solo al nuevo? | A) Solo código nuevo, como acordaron en tickets; B) Migrar todo | A; migrar por pantalla cuando se toque |
| P5 | Nota 7 contra OP-04: ¿el límite de 1 año aplica a la pantalla o solo a la exportación? | A) Pantalla 93 días y exportación hasta 12 meses; B) Todo a 1 año | A: la pantalla sigue ágil y el Excel cubre el año |
| P6 | Nota 6: ¿las ventanas de horario son por turno o generales? | A) Por turno (el turno de 7:00 tiene sus ventanas); B) Generales | A, con valores por omisión |
| P7 | Nota 4: ¿qué eventos lleva el catálogo y cómo se califican? | Ejemplo: A tiempo, Retardo (tolerancia–15 min), Retardo mayor, Falta, Salida anticipada, Comida excedida | Catálogo editable con minutos desde/hasta |
| P8 | Notas 22 – 24 (ubicación, PWA, remotos): ¿entran en esta etapa? | A) Etapa posterior (valor agregado); B) Ahora | A; requieren aviso de privacidad por geolocalización |
| P9 | ¿Qué tareas toma Oscar? | Ver sección 6 | — |

---

## 3. Propuesta mínima

### 3.1 Perfiles y permisos (notas 1 y 2), igual que tickets

Mismo esquema que `sistema_tickets_php` (`App\Support\Permisos`, `Usuario::tienePermiso`, `PermisoController`):

| Pieza | Tickets | Checador |
|---|---|---|
| Lista de permisos en código | `App\Support\Permisos::LISTA` (clave => módulo, descripción, detalles) | Igual, con claves del checador |
| Catálogo en base | Tabla `permisos` (descripción editable, activar/desactivar) | Igual |
| Perfil | `usuariospuestos` + `usuariospuestospermisos` | Perfiles + permisos por perfil (nombre según P3) |
| Excepciones por persona | `usuariospermisos` con `Negado` | Igual: dar o negar un permiso a una persona |
| Regla | Administrador = todos; los demás: perfil + dados − negados; desactivado = nadie | Igual |
| Uso | `Gate::define` por clave; `can:` en rutas; `@can` en Blade | Igual |
| Sincronizar | `php artisan permisos:sincronizar` | Igual |

Permisos iniciales propuestos:

| Módulo | Claves |
|---|---|
| Dashboard | `dashboard.consultar` |
| Empleados | `empleados.consultar`, `empleados.agregar`, `empleados.actualizar`, `empleados.baja`, `empleados.datos_personales` (ver RFC, CURP y NSS completos) |
| Terminal | `terminal.sincronizar`, `terminal.usuarios` (alta/baja en el reloj), `terminal.huellas` |
| Turnos | `turnos.consultar`, `turnos.administrar`, `turnos.programar` (rotaciones y excepciones) |
| Kardex | `kardex.consultar`, `kardex.exportar`, `kardex.exportar_completo` (con datos personales) |
| Bitácora | `bitacora.consultar` |

Cierra SE-02. Las cuentas se siguen creando por consola o desde un catálogo de usuarios (como tickets) si se decide en la reunión.

### 3.2 Catálogos del administrador

| Catálogo | Uso | Notas |
|---|---|---|
| Perfiles y permisos | 3.1 | — |
| Usuarios del sistema | Alta, perfil, activo, excepciones | Hoy solo por consola |
| Departamentos | Nota 15 | Migrar los textos actuales sin perder datos |
| Puestos de empleados | Nota 15 | Igual |
| Tipos de evento | Nota 4 | Nombre, color, regla en minutos |
| Ventanas de horario | Nota 6 | Por turno: rango de hora → entrada, salida a comer, regreso, salida |
| Medios de marcaje | Nota 5 | Huella, rostro, tarjeta, PIN; activar cuáles se aceptan |
| Grupos y plantillas de turno | Notas 11 – 13 | Ver 3.4 |

Mismo patrón que `CatalogosBasicos` de tickets: una definición por catálogo y un controlador genérico para los de "descripción + detalles".

### 3.3 Validaciones y clasificación (notas 3, 4, 6 y 20)

| Regla | Comportamiento propuesto |
|---|---|
| Clasificación | La checada toma el tipo según la ventana de horario de su turno; la tecla del reloj solo se usa si no cae en ninguna ventana |
| Dos entradas o dos salidas seguidas | Se guardan (la bitácora es inmutable) pero se marcan como **incidencia** y no cuentan dos veces |
| Salida sin entrada | Incidencia "Falta entrada"; la jornada queda incompleta |
| Repetida en pocos minutos | Si llega otra del mismo tipo en menos de N minutos (configurable, 3 por omisión), se marca como repetida |
| Corrección | Un usuario con permiso puede reclasificar una checada; queda en la bitácora con quién y por qué (la checada original no se modifica) |
| Comida en 0 (nota 20) | Se corrige con la clasificación por horario: hoy, con dos checadas sin tecla, la segunda se toma como "salida a comer" |

### 3.4 Turnos rotativos (notas 11 – 13)

| Pieza | Propuesta |
|---|---|
| Plantilla de rotación | Lista ordenada de turnos (1 → 2 → 3, 3 → 2 → 1, 2 → 1 → 3) y cada cuántos días cambia |
| Asignación | A un empleado, a un departamento o a un grupo, con fecha de inicio |
| Excepción | Un empleado en otro turno entre dos fechas; tiene prioridad sobre la plantilla |
| Turno del día | El cálculo usa el turno vigente de esa fecha, no un turno fijo |

### 3.5 Exportación a Excel (notas 7 y 9)

Una hoja por mes del rango (máximo 12), encabezados con formato, anchos de columna, totales por empleado y RFC/CURP/NSS enmascarados salvo con `kardex.exportar_completo`. Generada en el servidor para no depender del navegador con un año de datos.

---

## 4. Lo que queda fuera de la primera versión

| Nota | Motivo |
|---|---|
| 22 – 24 Ubicación, PWA y usuarios remotos | Valor agregado; es otro canal de checado y requiere aviso de privacidad por geolocalización (P8) |
| 16 Tipo de sangre | Hasta confirmar campos (P2) y consentimiento (TE-02) |
| 17 Campos opcionales configurables | RFC, CURP y NSS ya son opcionales; configurar cada campo puede esperar |
| 21 Responsivo de tablas Wijmo | Revisar al final; el resto ya se adapta |

---

## 5. Siguientes pasos

| Orden | Paquete | Notas | Depende de |
|---|---|---|---|
| 1 | Perfiles, permisos y catálogo de usuarios | 1, 2 | P3 |
| 2 | Catálogos de departamentos y puestos | 15 | 1 |
| 3 | Ventanas de horario, tipos de evento y validaciones | 3, 4, 6, 20 | P6, P7 |
| 4 | Turnos rotativos y excepciones | 11 – 13 | 1, 3 |
| 5 | Excel por mes con formato | 7, 9 | P5 |
| 6 | Medios de marcaje automáticos | 5 | Terminal real |
| 7 | Ficha de personal | 16 | P2, TE-02 |

---

## 6. Reparto propuesto con Oscar

Oscar pidió quedarse con algunas tareas. Propuesta para confirmar (P9):

| Paquete | Propuesta | Por qué |
|---|---|---|
| 1, 2 Perfiles y catálogos | Danny | Ya trabajó la estructura de permisos de tickets |
| 3 Ventanas, eventos y validaciones | Danny | Toca el cálculo que se optimizó en el paquete 3 |
| 4 Turnos rotativos | Oscar | Continúa su trabajo de turnos nocturnos (SE-11) |
| 5 Excel por mes | Oscar | Independiente del resto |
| 6 Medios de marcaje | Oscar | Tiene acceso a la terminal |
| 7 Ficha de personal | Por definir | Después de P2 |
