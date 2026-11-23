# Nomenclatura de componentes y estructuras

> Referencia · actualizado 23/11/2026 · La misma guía del sistema de tickets (Oscar, 14/10/2026). Aplica a **todo el código** (respuesta de Oscar a P4, 23/11/2026).
> Detalle de la migración: `docs/parches/2026-11-23/11-20_nomenclatura-en-todo-el-codigo.md`

---

## 1. Regla general

**Prefijo del tipo en minúsculas + nombre descriptivo en PascalCase y en español**: `txtNombreUsuario`, `lstEmpleados`. Nada de guiones en los ids (`txt-nombre` ya no se usa).

La **base de datos no cambia**, ni las llaves JSON, nombres de campos de formulario (`name="email"`), clases CSS, nombres de rutas ni URLs: son el contrato con el navegador, la base y el API.

---

## 2. Componentes (ids del HTML y la variable de JS que guarda el componente)

| Componente | Prefijo | Ejemplo en este proyecto | Incluye |
|---|---|---|---|
| DateTime | `dt` | `dtFechaInicio`, `dtTurnoEntrada` | `input type="date"`, `time`, `datetime-local` |
| Input Text / Number | `txt` | `txtCorreo`, `txtTurnoComida`, `txtFoto` | `input` de texto, número, correo, contraseña, búsqueda, oculto, archivo; `textarea` |
| ComboBox / AutoComplete | `cb` | `cbTurno`, `cbDepartamento` | `select`; `input` con `datalist` |
| Grid | `grid` | `gridEmpleados`, `gridKardex` | FlexGrid de Wijmo; `table` con datos |
| Label | `lbl` | `lblTotalEmpleados`, `lblTituloEmpleado` | Donde el código escribe un dato o un título de modal |
| RadioButton | `rb` | `rbDar12`, `rbNegar12` | `input type="radio"` |
| CheckBox | `ck` | `ckActivo`, `ckOmision3` | `checkbox` y `form-switch` |
| Button | `btn` | `btnNuevoEmpleado`, `btnTabRotaciones` | `button` y enlaces con aspecto de botón |
| Picture | `pic` | `picFoto` | `img` |
| TabPanel | `tab` | `tabRotaciones` | El panel; el botón que lo abre es `btnTab…` |
| Chart | `chart` | `chartPuntualidad` | FlexChart |
| Modal | `md` | `mdEmpleado`, `mdChecadas`, `mdMenuMovil` | `dialog` y su variable en JS |

Ids dinámicos: el número va pegado al final, `cbTipo${id}`, `txtDesde{{ $evento->id }}`.

**Sin prefijo de componente** (contenedores y elementos que la guía no lista): `contenedorAvisos`, `contenedorPasos`, `frmEmpleado`, `avisoIncidencias`, `opcionesDepartamento`, `indicadorHuella`. Los `<script type="application/json">` con los datos iniciales son estructuras: `dataEmpleadosInicial`.

---

## 3. Estructuras (variables en JavaScript y PHP)

| Estructura | Prefijo | Ejemplo | Incluye |
|---|---|---|---|
| Lista `[ ]` | `lst` | `lstEmpleados`, `$lstChecadas` | Arreglos JS, arreglos PHP con índices 0..n, `Collection` de Laravel, `CollectionView` de Wijmo |
| Objeto `{ }` | `data` | `dataRespuesta`, `$dataValidado` | Objetos JS (también el JSON recibido), arreglos asociativos de PHP (validados, antes/después de la bitácora, mapas por id) |

**No llevan prefijo:** valores simples (`$nombre`, `idResumen`, `htmlFoto`), modelos de Eloquent y objetos del framework (`$employee`, `$request`, `params` de `URLSearchParams`), el elemento de un ciclo (`foreach ($lstTurnos as $turno)`), parámetros de ruta, funciones, clases, constantes, columnas y llaves JSON.

Los datos que el controlador manda a una vista siguen la misma regla: `view('employees.index', ['lstTurnos' => …, 'dataPaginaEmpleados' => …])`.

---

## 4. Ejemplos

```js
const lstVistaEmpleados = new wjCore.CollectionView(lstEmpleados);
const dataRespuesta = await apiFetch(`${dataRutas.list}?${params}`, { method: 'GET' });
document.getElementById('lblTotalEmpleados').textContent = `${dataRespuesta.meta.total} empleados`;
const mdEmpleado = document.getElementById('mdEmpleado');
```

```php
$dataValidado = $request->validate([...]);
$lstChecadas = AttendanceLog::where('employee_id', $employee->id)->get();
foreach ($lstChecadas as $checada) { ... }
return view('shifts.index', ['lstTurnos' => $lstTurnos]);
```
