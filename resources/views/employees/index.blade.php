@extends('layouts.app')

@section('title', 'Empleados y Enrolamiento — Checador Hikvision')
@section('page_title', 'Gestión de Empleados y Enrolamiento')
@section('page_subtitle', 'Padrón fiscal laboral conectado por ISAPI con DS-K1T804AEF')

@push('styles')
    {{-- Wijmo solo en esta página (auditoría OP-06) --}}
    @vite(['resources/js/wijmo/grid.js'])
@endpush

@section('content')
<section class="surface-card p-3 p-md-4 isolated-panel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
            <search class="flex-grow-1" style="max-width: 420px;">
                <form id="frmBuscarEmpleados" class="d-flex align-items-center">
                    <label for="txtBuscarEmpleado" class="visually-hidden">Buscar empleados</label>
                    <div class="input-group input-group-sm w-100">
                        <span class="input-group-text bg-dark border-secondary-subtle text-secondary">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input id="txtBuscarEmpleado"
                               type="search"
                               class="form-control bg-dark border-secondary-subtle"
                               placeholder="Buscar nombre, ID reloj, depto o RFC/CURP completo..."
                               autocorrect="off"
                               autocapitalize="none"
                               spellcheck="false">
                    </div>
                </form>
            </search>
            <span class="badge text-bg-dark border border-secondary-subtle" id="lblTotalEmpleados">
                {{ $dataPaginaEmpleados['meta']['total'] }} empleados
            </span>
        </div>

        @can('empleados.agregar')
        <button id="btnNuevoEmpleado" type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center justify-content-center gap-2 flex-shrink-0">
            <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
            <span>Nuevo Empleado</span>
        </button>
        @endcan
    </div>

    <div class="grid-region" tabindex="0" role="region" aria-label="Tabla de empleados y acciones biométricas">
        <div id="gridEmpleados"></div>
    </div>

    {{-- Paginación en el servidor (auditoría OP-07) --}}
    <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Páginas de empleados">
        <button id="btnPaginaAnterior" type="button" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior
        </button>
        <span id="lblPagina" class="small text-secondary font-mono-tabular" aria-live="polite">
            Página {{ $dataPaginaEmpleados['meta']['page'] }} de {{ $dataPaginaEmpleados['meta']['last_page'] }}
        </span>
        <button id="btnPaginaSiguiente" type="button" class="btn btn-outline-secondary btn-sm">
            Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
    </nav>
</section>

<!-- Modal nativo <dialog>: Crear / Editar Empleado -->
<dialog id="mdEmpleado" class="app-dialog" aria-labelledby="lblTituloEmpleado">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloEmpleado" class="h6 mb-0 fw-semibold">Nuevo Empleado</h2>
            <button type="button" class="btn-close" data-close-dialog="mdEmpleado" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmEmpleado" method="POST" action="{{ route('employees.store') }}" novalidate>
            @csrf
            <input type="hidden" id="txtIdEmpleado" name="id" value="">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div id="lblErroresEmpleado" class="col-12 d-none">
                    <div class="alert alert-danger py-2 small mb-0" role="alert" aria-live="polite"></div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtNumeroReloj" class="form-label small fw-medium">ID Reloj Hikvision <span class="text-danger">*</span></label>
                    <input id="txtNumeroReloj"
                           name="hikvision_employee_no"
                           type="text"
                           class="form-control font-mono-tabular"
                           required
                           maxlength="32"
                           pattern="[A-Za-z0-9_\-]+"
                           autocorrect="off"
                           autocapitalize="none"
                           spellcheck="false"
                           aria-describedby="lblAyudaNumeroReloj"
                           autofocus>
                    <div id="lblAyudaNumeroReloj" class="form-text small">Llave única en terminal (ej. 1015).</div>
                </div>

                <div class="col-12 col-sm-8">
                    <label for="txtNombre" class="form-label small fw-medium">Nombre Completo <span class="text-danger">*</span></label>
                    <input id="txtNombre"
                           name="full_name"
                           type="text"
                           class="form-control"
                           required
                           maxlength="150"
                           autocomplete="name">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtRfc" class="form-label small fw-medium">RFC</label>
                    <input id="txtRfc"
                           name="rfc"
                           type="text"
                           class="form-control font-mono-tabular text-uppercase"
                           maxlength="13"
                           pattern="[A-Za-zÑñ&]{3,4}\d{6}[A-Za-z0-9]{3}"
                           autocorrect="off"
                           autocapitalize="characters"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-5">
                    <label for="txtCurp" class="form-label small fw-medium">CURP</label>
                    <input id="txtCurp"
                           name="curp"
                           type="text"
                           class="form-control font-mono-tabular text-uppercase"
                           maxlength="18"
                           minlength="18"
                           autocorrect="off"
                           autocapitalize="characters"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-3">
                    <label for="txtNss" class="form-label small fw-medium">NSS (11 dígitos)</label>
                    <input id="txtNss"
                           name="nss"
                           type="text"
                           class="form-control font-mono-tabular"
                           maxlength="11"
                           inputmode="numeric"
                           pattern="\d{11}"
                           autocorrect="off"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="cbDepartamento" class="form-label small fw-medium">Departamento <span class="text-danger">*</span></label>
                    <input id="cbDepartamento"
                           name="department"
                           type="text"
                           class="form-control"
                           list="opcionesDepartamento"
                           required
                           maxlength="100">
                    <datalist id="opcionesDepartamento">
                        @foreach($lstDepartamentos as $dept)
                            <option value="{{ $dept }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="txtPuesto" class="form-label small fw-medium">Puesto <span class="text-danger">*</span></label>
                    <input id="txtPuesto"
                           name="position"
                           type="text"
                           class="form-control"
                           required
                           maxlength="100">
                </div>

                <div class="col-12 col-sm-8">
                    <label for="cbTurno" class="form-label small fw-medium">Turno Fiscal Asignado <span class="text-danger">*</span></label>
                    <select id="cbTurno" name="work_shift_id" class="form-select" required>
                        @if($lstTurnos->isEmpty())
                            <option value="" selected disabled>Sin turnos registrados — crea un turno primero</option>
                        @else
                            <option value="" disabled>Selecciona un turno...</option>
                            @foreach($lstTurnos as $shift)
                                <option value="{{ $shift->id }}">
                                    {{ $shift->name }} ({{ $shift->schedule_label }} · {{ $shift->meal_duration_minutes }}m comida)
                                </option>
                            @endforeach
                        @endif
                    </select>
                    @if($lstTurnos->isEmpty())
                        <div class="form-text text-warning d-flex align-items-center gap-1 mt-1">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>Debes registrar al menos un turno antes de dar de alta empleados.</span>
                            <a href="{{ route('shifts.index') }}" class="link-info text-decoration-underline">Crear turno ahora</a>
                        </div>
                    @endif
                </div>

                <div class="col-12 col-sm-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input id="ckActivo" name="is_active" class="form-check-input" type="checkbox" value="1" checked>
                        <label class="form-check-label small" for="ckActivo">Empleado Activo</label>
                    </div>
                </div>

                {{-- Nota 16: tipo de sangre (dato de salud, solo con permiso) y foto --}}
                @can('empleados.datos_salud')
                <div class="col-12 col-sm-4">
                    <label for="cbTipoSangre" class="form-label small fw-medium">Tipo de sangre</label>
                    <select id="cbTipoSangre" name="blood_type" class="form-select">
                        <option value="">Sin registrar</option>
                        @foreach(\App\Models\Employee::BLOOD_TYPES as $tipo)
                            <option value="{{ $tipo }}">{{ $tipo }}</option>
                        @endforeach
                    </select>
                    <div class="form-text small">Dato de salud: se guarda cifrado y cada consulta queda en la bitácora.</div>
                </div>
                @else
                <div class="col-12 col-sm-4">
                    <span class="form-label small fw-medium d-block">Tipo de sangre</span>
                    <span id="lblTipoSangre" class="small text-secondary">Necesitas el permiso "Ver y editar tipo de sangre".</span>
                </div>
                @endcan

                <div class="col-12 col-sm-8">
                    <span class="form-label small fw-medium d-block">Foto</span>
                    <div id="lblFotoNueva" class="small text-secondary">Guarda el empleado para agregar su foto.</div>
                    <div id="contenedorFoto" class="d-none d-flex align-items-center gap-3">
                        <img id="picFoto" src="" alt="Foto del empleado" width="72" height="72" class="rounded border border-secondary-subtle d-none" style="object-fit: cover;">
                        <span id="lblSinFoto" class="small text-secondary">Sin foto</span>
                        <div class="d-flex flex-wrap gap-2">
                            <label for="txtFoto" class="btn btn-outline-info btn-sm mb-0"><i class="bi bi-camera me-1" aria-hidden="true"></i>Subir foto</label>
                            <input id="txtFoto" type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
                            <button id="btnQuitarFoto" type="button" class="btn btn-outline-danger btn-sm d-none">Quitar foto</button>
                        </div>
                    </div>
                    <div class="form-text small">JPG, PNG o WebP de hasta 4 MB. Se reduce a 600 px y se le quitan los metadatos.</div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdEmpleado">
                    Cancelar
                </button>
                <button id="btnGuardarEmpleado" type="submit" class="btn btn-primary btn-sm">
                    Guardar Empleado
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Enrolamiento Remoto de Huella en Tiempo Real -->
<dialog id="mdHuella" class="app-dialog" closedby="any" aria-labelledby="lblTituloHuella">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloHuella" class="h6 mb-0 fw-semibold">Captura Remota de Huella (ISAPI)</h2>
            <button type="button" class="btn-close" data-close-dialog="mdHuella" aria-label="Cerrar ventana"></button>
        </div>

        <div class="p-4 text-center app-dialog-body">
            <div id="indicadorHuella" class="fingerprint-pulse mb-3">
                <i class="bi bi-fingerprint" aria-hidden="true"></i>
            </div>

            <h3 id="lblHuellaNombre" class="h6 fw-semibold mb-1">-</h3>
            <p class="small text-secondary font-mono-tabular mb-3" id="lblHuellaNumero">ID Reloj: -</p>

            <div class="row justify-content-center mb-3">
                <div class="col-12 col-sm-7 text-start">
                    <label for="cbDedo" class="form-label small fw-medium">Índice de dedo (1-10)</label>
                    <select id="cbDedo" class="form-select form-select-sm">
                        <option value="1">1 — Pulgar Derecho</option>
                        <option value="2" selected>2 — Índice Derecho</option>
                        <option value="3">3 — Medio Derecho</option>
                        <option value="6">6 — Pulgar Izquierdo</option>
                        <option value="7">7 — Índice Izquierdo</option>
                    </select>
                </div>
            </div>

            <div id="lblEstadoHuella" class="alert alert-secondary small text-start mb-0" role="status" aria-live="polite">
                Presiona <strong>Activar Lector Óptico</strong> para enviar <code>POST /ISAPI/AccessControl/CaptureFingerPrint</code> a la terminal y solicitar al empleado colocar su dedo.
            </div>
        </div>

        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdHuella" autofocus>
                Cerrar
            </button>
            <button id="btnIniciarHuella" type="button" class="btn btn-info btn-sm text-dark fw-semibold">
                <i class="bi bi-broadcast me-1" aria-hidden="true"></i>Activar Lector Óptico
            </button>
        </div>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Alta de Tarjeta RFID, PIN y Aviso Facial (TE-02) -->
<dialog id="mdMedios" class="app-dialog" closedby="any" aria-labelledby="lblTituloMedios">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloMedios" class="h6 mb-0 fw-semibold">Medios de Marcaje del Empleado</h2>
            <button type="button" class="btn-close" data-close-dialog="mdMedios" aria-label="Cerrar ventana"></button>
        </div>

        <div class="p-3 p-md-4 app-dialog-body d-flex flex-column gap-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-2 border-bottom border-secondary-subtle">
                <div>
                    <div id="lblMediosNombre" class="fw-semibold">-</div>
                    <small id="lblMediosNumero" class="font-mono-tabular text-secondary">ID Reloj: -</small>
                </div>
                <div class="d-flex flex-wrap gap-1" id="lblEstadoMedios"></div>
            </div>

            <!-- Alta de Tarjeta RFID -->
            <form id="frmTarjeta" class="p-3 rounded border border-secondary-subtle bg-dark bg-opacity-50">
                <h3 class="h6 fw-semibold mb-1"><i class="bi bi-credit-card-2-front me-1 text-info" aria-hidden="true"></i>Alta de Tarjeta RFID</h3>
                <p class="small text-secondary mb-3">Registra el número de tarjeta en la terminal Hikvision (<code>CardInfo/Record</code>).</p>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-sm-8">
                        <label for="txtNumeroTarjeta" class="form-label small fw-medium">Número de Tarjeta <span class="text-danger">*</span></label>
                        <input id="txtNumeroTarjeta"
                               name="card_no"
                               type="text"
                               class="form-control form-control-sm font-mono-tabular"
                               required
                               maxlength="32"
                               pattern="[A-Za-z0-9_\-]+"
                               placeholder="Ej. 0019283746">
                    </div>
                    <div class="col-12 col-sm-4">
                        <button id="btnGuardarTarjeta"
                                type="submit"
                                class="btn btn-info btn-sm text-dark fw-semibold w-100"
                                @disabled(!($dataMediosMarcaje['card']->is_enabled ?? true))>
                            Enviar Tarjeta
                        </button>
                    </div>
                </div>
            </form>

            <!-- Alta de PIN (nunca se guarda en la base) -->
            <form id="frmPin" class="p-3 rounded border border-secondary-subtle bg-dark bg-opacity-50">
                <h3 class="h6 fw-semibold mb-1"><i class="bi bi-key me-1 text-warning" aria-hidden="true"></i>Alta de PIN en Terminal</h3>
                <p class="small text-secondary mb-2">
                    El PIN se envía directo a la terminal vía ISAPI (<code>UserInfo/Modify</code>) y <strong>nunca se guarda en la base de datos</strong> ni en la bitácora.
                </p>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-sm-8">
                        <label for="txtPin" class="form-label small fw-medium">PIN numérico (4 a 8 dígitos) <span class="text-danger">*</span></label>
                        <input id="txtPin"
                               name="pin"
                               type="password"
                               inputmode="numeric"
                               autocomplete="new-password"
                               class="form-control form-control-sm font-mono-tabular"
                               required
                               minlength="4"
                               maxlength="8"
                               pattern="\d{4,8}"
                               placeholder="••••">
                    </div>
                    <div class="col-12 col-sm-4">
                        <button id="btnGuardarPin"
                                type="submit"
                                class="btn btn-warning btn-sm text-dark fw-semibold w-100"
                                @disabled(!($dataMediosMarcaje['pin']->is_enabled ?? true))>
                            Configurar PIN
                        </button>
                    </div>
                </div>
            </form>

            <!-- Reconocimiento Facial bloqueado por TE-02 -->
            <div class="p-3 rounded border border-warning-subtle bg-dark bg-opacity-50">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                    <h3 class="h6 fw-semibold mb-0"><i class="bi bi-person-bounding-box me-1 text-warning" aria-hidden="true"></i>Reconocimiento Facial</h3>
                    <span class="badge text-bg-warning">TE-02</span>
                </div>
                <p class="small text-secondary mb-0">
                    El rostro es un dato biométrico. Su enrolamiento permanece bloqueado en espera del aviso de privacidad <strong>TE-02</strong>.
                </p>
            </div>
        </div>

        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdMedios" autofocus>
                Cerrar
            </button>
        </div>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Confirmación de Baja / Eliminación -->
<dialog id="mdBaja" class="app-dialog" closedby="any" aria-labelledby="lblTituloBaja">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloBaja" class="h6 mb-0 fw-semibold text-danger">Confirmar Baja</h2>
            <button type="button" class="btn-close" data-close-dialog="mdBaja" aria-label="Cerrar ventana"></button>
        </div>

        <div class="p-3 p-md-4 app-dialog-body">
            <p class="mb-3">
                ¿Deseas dar de baja a <strong id="lblBajaNombre"></strong> (<span id="lblBajaNumero" class="font-mono-tabular"></span>)?
                Si ya tiene checadas, quedará como <strong>Inactivo</strong> y se conserva su historial.
                Si no tiene checadas, se elimina del sistema.
            </p>
            <div class="form-check">
                <input id="ckBajaTerminal" class="form-check-input" type="checkbox" value="1">
                <label class="form-check-label small" for="ckBajaTerminal">
                    Dar de baja también en la terminal Hikvision (<code>PUT /ISAPI/AccessControl/UserInfo/Delete</code>)
                </label>
            </div>
        </div>

        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdBaja" autofocus>
                Cancelar
            </button>
            <button id="btnConfirmarBaja" type="button" class="btn btn-danger btn-sm">
                Dar de Baja
            </button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="dataEmpleadosInicial" type="application/json">@json($dataPaginaEmpleados)</script>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid } = window.AppUI.wijmo;
    const { apiFetch, showToast, escapeHtml } = window.AppUI;

    // Permisos del usuario para mostrar u ocultar acciones (el servidor los vuelve a revisar en cada ruta)
    const dataPermisos = {{ \Illuminate\Support\Js::from([
        'agregar' => auth()->user()->can('empleados.agregar'),
        'actualizar' => auth()->user()->can('empleados.actualizar'),
        'baja' => auth()->user()->can('empleados.baja'),
        'terminal' => auth()->user()->can('terminal.usuarios'),
        'huellas' => auth()->user()->can('terminal.huellas'),
    ]) }};

    const dataRutas = {
        store: '{{ route("employees.store") }}',
        updateBase: '{{ url("/employees") }}',
        list: '{{ route("employees.list") }}',
    };

    // Paginación y búsqueda en el servidor (auditoría OP-07): la página solo trae 50 empleados a la vez
    const dataPaginaInicial = JSON.parse(document.getElementById('dataEmpleadosInicial').textContent);
    let lstEmpleados = dataPaginaInicial.employees || [];
    let dataMeta = dataPaginaInicial.meta;
    const lstVistaEmpleados = new wjCore.CollectionView(lstEmpleados);
    const txtBuscarEmpleado = document.getElementById('txtBuscarEmpleado');
    const btnPaginaAnterior = document.getElementById('btnPaginaAnterior');
    const btnPaginaSiguiente = document.getElementById('btnPaginaSiguiente');
    document.getElementById('frmBuscarEmpleados')?.addEventListener('submit', (e) => e.preventDefault());

    let searchTimer = null;
    txtBuscarEmpleado.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadPage(1), 300);
    });
    btnPaginaAnterior.addEventListener('click', () => loadPage(dataMeta.page - 1));
    btnPaginaSiguiente.addEventListener('click', () => loadPage(dataMeta.page + 1));

    function updateCount() {
        document.getElementById('lblTotalEmpleados').textContent = `${dataMeta.total} empleados`;
        document.getElementById('lblPagina').textContent = `Página ${dataMeta.page} de ${dataMeta.last_page}`;
        btnPaginaAnterior.disabled = dataMeta.page <= 1;
        btnPaginaSiguiente.disabled = dataMeta.page >= dataMeta.last_page;
    }
    updateCount();

    // Orden en el servidor: se aplica a todos los empleados (o a toda la búsqueda) antes de paginar.
    // La tabla no reordena localmente (el comparador siempre empata y conserva el orden del servidor);
    // solo muestra la flecha de la columna elegida.
    lstVistaEmpleados.sortComparer = () => 0;
    let dataOrden = { sort: dataMeta.sort || 'hikvision_employee_no', direction: dataMeta.direction || 'asc' };

    lstVistaEmpleados.sortDescriptions.collectionChanged.addHandler(() => {
        const sd = lstVistaEmpleados.sortDescriptions[0];
        const dataSiguiente = sd
            ? { sort: sd.property, direction: sd.ascending ? 'asc' : 'desc' }
            : { sort: 'hikvision_employee_no', direction: 'asc' };

        if (dataSiguiente.sort === dataOrden.sort && dataSiguiente.direction === dataOrden.direction) return;
        dataOrden = dataSiguiente;
        loadPage(1);
    });

    async function loadPage(page) {
        const params = new URLSearchParams({
            page: String(Math.max(1, page)),
            q: (txtBuscarEmpleado.value || '').trim(),
            sort: dataOrden.sort,
            direction: dataOrden.direction,
        });
        try {
            const dataRespuesta = await apiFetch(`${dataRutas.list}?${params}`, { method: 'GET' });
            lstEmpleados = dataRespuesta.employees || [];
            dataMeta = dataRespuesta.meta;
            lstVistaEmpleados.sourceCollection = lstEmpleados;
            lstVistaEmpleados.refresh();
            updateCount();
        } catch (err) {
            showToast(err.message || 'No se pudo cargar la lista de empleados.', 'danger');
        }
    }

    const gridEmpleados = new wjGrid.FlexGrid('#gridEmpleados', {
        itemsSource: lstVistaEmpleados,
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        columns: [
            { binding: 'hikvision_employee_no', header: 'ID Reloj', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'full_name', header: 'Nombre Completo', width: '*', minWidth: 200 },
            { binding: 'rfc', header: 'RFC', width: 140, cssClass: 'font-mono-tabular', allowSorting: false },
            { binding: 'department', header: 'Departamento', width: 130 },
            { binding: 'shift_name', header: 'Turno', width: 165 },
            { binding: 'status_label', header: 'Estado', width: 95 },
            { binding: 'device_synced_at', header: 'Reloj / Medios', width: 225 },
            { binding: 'id', header: 'Acciones Hikvision / CRUD', width: 375, allowSorting: false },
        ],
    });
    gridEmpleados.rows.defaultSize = 48;

    gridEmpleados.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const dataFila = s.rows[e.row]?.dataItem;
        if (!dataFila) return;

        if (col.binding === 'full_name') {
            // Miniatura de la foto (nota 16); la ruta revisa el permiso
            const htmlFoto = dataFila.photo_url
                ? `<img src="${escapeHtml(dataFila.photo_url)}" alt="" width="28" height="28" loading="lazy" class="rounded-circle me-2 border border-secondary-subtle" style="object-fit: cover;">`
                : '<span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary bg-opacity-25 text-secondary me-2" style="width: 28px; height: 28px;" aria-hidden="true"><i class="bi bi-person"></i></span>';
            e.cell.innerHTML = `<span class="d-inline-flex align-items-center">${htmlFoto}${escapeHtml(dataFila.full_name)}</span>`;
        } else if (col.binding === 'status_label') {
            const cls = dataFila.is_active ? 'text-bg-success' : 'text-bg-secondary';
            e.cell.innerHTML = `<span class="badge ${cls}">${escapeHtml(dataFila.status_label)}</span>`;
        } else if (col.binding === 'device_synced_at') {
            const syncHtml = dataFila.device_synced_at
                ? `<span class="badge text-bg-dark border border-info text-info me-1" title="Última sincronización">${escapeHtml(dataFila.device_synced_at)}</span>`
                : `<span class="badge text-bg-dark border border-secondary text-secondary me-1">Pendiente</span>`;
            const fpHtml = dataFila.fingerprint_enrolled
                ? `<span class="badge text-bg-dark border border-success text-success"><i class="bi bi-fingerprint me-1"></i>Huella</span>`
                : `<span class="badge text-bg-dark border border-warning text-warning">Sin Huella</span>`;
            const cardHtml = dataFila.card_enrolled
                ? `<span class="badge text-bg-dark border border-info text-info" title="Tarjeta ${escapeHtml(dataFila.card_no || '')}"><i class="bi bi-credit-card me-1"></i>Tarjeta</span>`
                : '';
            const pinHtml = dataFila.pin_configured
                ? `<span class="badge text-bg-dark border border-warning text-warning"><i class="bi bi-key me-1"></i>PIN</span>`
                : '';
            e.cell.innerHTML = `<div class="d-flex flex-wrap gap-1">${syncHtml}${fpHtml}${cardHtml}${pinHtml}</div>`;
        } else if (col.binding === 'id') {
            const lstBotones = [];
            if (dataPermisos.terminal) {
                lstBotones.push(`<button type="button" class="btn btn-outline-info" data-action="sync" data-id="${dataFila.id}" title="Sincronizar a Hikvision">
                        <i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Sincronizar
                    </button>`);
            }
            if (dataPermisos.huellas) {
                lstBotones.push(`<button type="button" class="btn btn-outline-warning" data-action="fingerprint" data-id="${dataFila.id}" title="Capturar Huella Remota">
                        <i class="bi bi-fingerprint me-1" aria-hidden="true"></i>Huella
                    </button>`);
            }
            if (dataPermisos.terminal) {
                lstBotones.push(`<button type="button" class="btn btn-outline-primary" data-action="credentials" data-id="${dataFila.id}" title="Tarjeta RFID / PIN">
                        <i class="bi bi-key me-1" aria-hidden="true"></i>Medios
                    </button>`);
            }
            if (dataPermisos.actualizar) {
                lstBotones.push(`<button type="button" class="btn btn-outline-light" data-action="edit" data-id="${dataFila.id}" title="Editar empleado">
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                    </button>`);
            }
            if (dataPermisos.baja) {
                lstBotones.push(`<button type="button" class="btn btn-outline-danger" data-action="delete" data-id="${dataFila.id}" title="Dar de baja">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>`);
            }
            e.cell.innerHTML = lstBotones.length
                ? `<div class="btn-group btn-group-sm" role="group" aria-label="Acciones de empleado">${lstBotones.join('')}</div>`
                : '<span class="small text-secondary">Solo consulta</span>';
        }
    });

    // Cierre unificado de <dialog>
    document.querySelectorAll('[data-close-dialog]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.getElementById(btn.getAttribute('data-close-dialog'))?.close();
        });
    });

    const mdEmpleado = document.getElementById('mdEmpleado');
    const frmEmpleado = document.getElementById('frmEmpleado');
    const lblErroresEmpleado = document.getElementById('lblErroresEmpleado');
    const btnGuardarEmpleado = document.getElementById('btnGuardarEmpleado');

    function clearFormErrors() {
        lblErroresEmpleado.classList.add('d-none');
        lblErroresEmpleado.querySelector('.alert').textContent = '';
        frmEmpleado.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    }

    // Foto del empleado (nota 16): se sube aparte, solo cuando el empleado ya existe
    function renderPhoto(dataEmpleado) {
        const picFoto = document.getElementById('picFoto');
        document.getElementById('lblFotoNueva').classList.toggle('d-none', Boolean(dataEmpleado));
        document.getElementById('contenedorFoto').classList.toggle('d-none', !dataEmpleado);
        const urlFoto = dataEmpleado?.photo_url || '';
        picFoto.classList.toggle('d-none', !urlFoto);
        picFoto.src = urlFoto;
        document.getElementById('lblSinFoto').classList.toggle('d-none', Boolean(urlFoto));
        document.getElementById('btnQuitarFoto').classList.toggle('d-none', !urlFoto);
    }

    document.getElementById('txtFoto').addEventListener('change', async (event) => {
        const fileFoto = event.target.files[0];
        const empId = document.getElementById('txtIdEmpleado').value;
        if (!fileFoto || !empId) return;
        const dataForm = new FormData();
        dataForm.append('photo', fileFoto);
        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${empId}/photo`, { method: 'POST', body: dataForm });
            renderPhoto({ photo_url: dataRespuesta.photo_url });
            showToast(dataRespuesta.message, 'success');
            await reloadEmployees();
        } catch (err) {
            showToast(err.payload?.errors ? Object.values(err.payload.errors).flat().join(' · ') : (err.message || 'No se pudo subir la foto.'), 'danger');
        } finally {
            event.target.value = '';
        }
    });

    document.getElementById('btnQuitarFoto').addEventListener('click', async () => {
        const empId = document.getElementById('txtIdEmpleado').value;
        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${empId}/photo`, { method: 'DELETE' });
            renderPhoto({ photo_url: null });
            showToast(dataRespuesta.message, 'success');
            await reloadEmployees();
        } catch (err) {
            showToast(err.message || 'No se pudo quitar la foto.', 'danger');
        }
    });

    function openCreateModal() {
        clearFormErrors();
        frmEmpleado.reset();
        renderPhoto(null);
        setPersonalIdsLocked(false);
        document.getElementById('txtIdEmpleado').value = '';
        document.getElementById('ckActivo').checked = true;
        document.getElementById('lblTituloEmpleado').textContent = 'Nuevo Empleado';
        btnGuardarEmpleado.textContent = 'Guardar Empleado';
        mdEmpleado.showModal();
    }

    // La tabla trae RFC, CURP y NSS enmascarados; los completos se piden solo al editar (SE-05)
    async function openEditModal(dataFila) {
        let dataEmpleado;
        try {
            dataEmpleado = (await apiFetch(`${dataRutas.updateBase}/${dataFila.id}`, { method: 'GET' })).employee;
        } catch (err) {
            showToast(err.message || 'No se pudieron cargar los datos del empleado.', 'danger');
            return;
        }

        clearFormErrors();
        document.getElementById('txtIdEmpleado').value = dataEmpleado.id;
        document.getElementById('txtNumeroReloj').value = dataEmpleado.hikvision_employee_no || '';
        document.getElementById('txtNombre').value = dataEmpleado.full_name || '';
        document.getElementById('txtRfc').value = dataEmpleado.rfc || '';
        document.getElementById('txtCurp').value = dataEmpleado.curp || '';
        document.getElementById('txtNss').value = dataEmpleado.nss || '';
        // Sin permiso de datos personales llegan enmascarados: se muestran bloqueados y no se envían
        setPersonalIdsLocked(Boolean(dataEmpleado.personal_ids_masked));
        const cbTipoSangre = document.getElementById('cbTipoSangre');
        if (cbTipoSangre) cbTipoSangre.value = dataEmpleado.blood_type || '';
        const lblTipoSangre = document.getElementById('lblTipoSangre');
        if (lblTipoSangre) lblTipoSangre.textContent = dataEmpleado.has_blood_type ? 'Registrado (protegido)' : 'Sin registrar';
        renderPhoto(dataEmpleado);
        document.getElementById('cbDepartamento').value = dataEmpleado.department || '';
        document.getElementById('txtPuesto').value = dataEmpleado.position || '';
        document.getElementById('cbTurno').value = String(dataEmpleado.work_shift_id || '');
        document.getElementById('ckActivo').checked = Boolean(dataEmpleado.is_active);
        document.getElementById('lblTituloEmpleado').textContent = `Editar Empleado #${dataEmpleado.hikvision_employee_no}`;
        btnGuardarEmpleado.textContent = 'Actualizar Cambios';
        mdEmpleado.showModal();
    }

    function setPersonalIdsLocked(locked) {
        ['txtRfc', 'txtCurp', 'txtNss'].forEach((id) => {
            const txtCampo = document.getElementById(id);
            txtCampo.disabled = locked;
            txtCampo.title = locked ? 'Necesitas el permiso "Ver y editar RFC, CURP y NSS" para cambiar este dato.' : '';
        });
    }

    document.getElementById('btnNuevoEmpleado')?.addEventListener('click', openCreateModal);

    // Validación por campo tras interacción (blur) y limpieza en input
    frmEmpleado.querySelectorAll('input, select').forEach((txtCampo) => {
        txtCampo.addEventListener('input', () => {
            txtCampo.removeAttribute('aria-invalid');
        });
        txtCampo.addEventListener('blur', () => {
            if (!txtCampo.checkValidity()) {
                txtCampo.setAttribute('aria-invalid', 'true');
            }
        });
    });

    frmEmpleado.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFormErrors();

        if (!frmEmpleado.checkValidity()) {
            const txtInvalido = frmEmpleado.querySelector(':invalid');
            if (txtInvalido) {
                txtInvalido.setAttribute('aria-invalid', 'true');
                txtInvalido.focus();
                txtInvalido.reportValidity();
            }
            return;
        }

        const empId = document.getElementById('txtIdEmpleado').value;
        const isEdit = empId !== '';
        const url = isEdit ? `${dataRutas.updateBase}/${empId}` : dataRutas.store;

        const dataPayload = {
            hikvision_employee_no: document.getElementById('txtNumeroReloj').value,
            full_name: document.getElementById('txtNombre').value,
            department: document.getElementById('cbDepartamento').value,
            position: document.getElementById('txtPuesto').value,
            work_shift_id: Number(document.getElementById('cbTurno').value),
            is_active: document.getElementById('ckActivo').checked,
        };
        const cbTipoSangre = document.getElementById('cbTipoSangre');
        if (cbTipoSangre) {
            dataPayload.blood_type = cbTipoSangre.value || null;
        }
        if (!document.getElementById('txtRfc').disabled) {
            dataPayload.rfc = document.getElementById('txtRfc').value || null;
            dataPayload.curp = document.getElementById('txtCurp').value || null;
            dataPayload.nss = document.getElementById('txtNss').value || null;
        }

        btnGuardarEmpleado.disabled = true;
        try {
            const dataRespuesta = await apiFetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                body: dataPayload,
            });
            await reloadEmployees();
            mdEmpleado.close();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            const validationErrors = err.payload?.errors
                ? Object.values(err.payload.errors).flat().join(' · ')
                : err.message;
            lblErroresEmpleado.classList.remove('d-none');
            lblErroresEmpleado.querySelector('.alert').textContent = validationErrors;
        } finally {
            btnGuardarEmpleado.disabled = false;
        }
    });

    // Modal de Tarjeta RFID y PIN (O3)
    const mdMedios = document.getElementById('mdMedios');
    const frmTarjeta = document.getElementById('frmTarjeta');
    const frmPin = document.getElementById('frmPin');
    const btnGuardarTarjeta = document.getElementById('btnGuardarTarjeta');
    const btnGuardarPin = document.getElementById('btnGuardarPin');
    let dataEmpleadoMedios = null;

    function renderCredBadges(dataEmpleado) {
        const container = document.getElementById('lblEstadoMedios');
        container.innerHTML = '';
        const lstEtiquetas = [
            dataEmpleado.card_enrolled
                ? `<span class="badge text-bg-dark border border-info text-info">Tarjeta: ${escapeHtml(dataEmpleado.card_no || 'OK')}</span>`
                : `<span class="badge text-bg-dark border border-secondary text-secondary">Sin Tarjeta</span>`,
            dataEmpleado.pin_configured
                ? `<span class="badge text-bg-dark border border-warning text-warning">PIN Configurado</span>`
                : `<span class="badge text-bg-dark border border-secondary text-secondary">Sin PIN</span>`,
        ];
        container.innerHTML = lstEtiquetas.join('');
    }

    function openCredentialsModal(dataEmpleado) {
        dataEmpleadoMedios = dataEmpleado;
        document.getElementById('lblMediosNombre').textContent = dataEmpleado.full_name;
        document.getElementById('lblMediosNumero').textContent = `ID Reloj Hikvision: ${dataEmpleado.hikvision_employee_no}`;
        document.getElementById('txtNumeroTarjeta').value = dataEmpleado.card_no || '';
        document.getElementById('txtPin').value = '';
        renderCredBadges(dataEmpleado);
        mdMedios.showModal();
    }

    frmTarjeta.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!dataEmpleadoMedios || !frmTarjeta.reportValidity()) return;

        btnGuardarTarjeta.disabled = true;
        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${dataEmpleadoMedios.id}/enroll-card`, {
                method: 'POST',
                body: { card_no: document.getElementById('txtNumeroTarjeta').value.trim() },
            });
            dataEmpleadoMedios = dataRespuesta.employee;
            renderCredBadges(dataEmpleadoMedios);
            await reloadEmployees();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            showToast(err.message || 'No se pudo asignar la tarjeta en la terminal.', 'danger');
        } finally {
            btnGuardarTarjeta.disabled = false;
        }
    });

    frmPin.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!dataEmpleadoMedios || !frmPin.reportValidity()) return;

        const txtPin = document.getElementById('txtPin');
        btnGuardarPin.disabled = true;
        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${dataEmpleadoMedios.id}/enroll-pin`, {
                method: 'POST',
                body: { pin: txtPin.value.trim() },
            });
            txtPin.value = '';
            dataEmpleadoMedios = dataRespuesta.employee;
            renderCredBadges(dataEmpleadoMedios);
            await reloadEmployees();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            showToast(err.message || 'No se pudo configurar el PIN en la terminal.', 'danger');
        } finally {
            btnGuardarPin.disabled = false;
        }
    });

    // Modal de captura remota de huella
    const mdHuella = document.getElementById('mdHuella');
    const indicadorHuella = document.getElementById('indicadorHuella');
    const lblEstadoHuella = document.getElementById('lblEstadoHuella');
    const btnIniciarHuella = document.getElementById('btnIniciarHuella');
    let dataEmpleadoHuella = null;

    function openFingerprintModal(dataEmpleado) {
        dataEmpleadoHuella = dataEmpleado;
        document.getElementById('lblHuellaNombre').textContent = dataEmpleado.full_name;
        document.getElementById('lblHuellaNumero').textContent = `ID Reloj Hikvision: ${dataEmpleado.hikvision_employee_no}`;
        indicadorHuella.classList.remove('scanning', 'success', 'error');
        lblEstadoHuella.className = 'alert alert-secondary small text-start mb-0';
        lblEstadoHuella.innerHTML = 'Presiona <strong>Activar Lector Óptico</strong> para enviar <code>POST /ISAPI/AccessControl/CaptureFingerPrint</code> a la terminal y solicitar al empleado colocar su dedo.';
        btnIniciarHuella.disabled = false;
        mdHuella.showModal();
    }

    btnIniciarHuella.addEventListener('click', async () => {
        if (!dataEmpleadoHuella) return;

        const fingerNo = Number(document.getElementById('cbDedo').value || 2);
        btnIniciarHuella.disabled = true;
        indicadorHuella.classList.remove('success', 'error');
        indicadorHuella.classList.add('scanning');
        lblEstadoHuella.className = 'alert alert-info small text-start mb-0';
        lblEstadoHuella.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Esperando lectura en el sensor óptico del DS-K1T804AEF... Coloque el dedo en el reloj.';

        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${dataEmpleadoHuella.id}/capture-fingerprint`, {
                method: 'POST',
                body: { finger_no: fingerNo },
            });
            indicadorHuella.classList.remove('scanning');
            indicadorHuella.classList.add('success');
            lblEstadoHuella.className = 'alert alert-success small text-start mb-0';
            lblEstadoHuella.textContent = dataRespuesta.message || 'Huella capturada y vinculada correctamente.';
            await reloadEmployees();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            indicadorHuella.classList.remove('scanning');
            indicadorHuella.classList.add('error');
            lblEstadoHuella.className = 'alert alert-danger small text-start mb-0';
            lblEstadoHuella.textContent = err.message || 'No se pudo completar la captura remota de huella.';
        } finally {
            btnIniciarHuella.disabled = false;
        }
    });

    // Modal de eliminación
    const mdBaja = document.getElementById('mdBaja');
    const btnConfirmarBaja = document.getElementById('btnConfirmarBaja');
    let dataEmpleadoBaja = null;

    function openDeleteModal(dataEmpleado) {
        dataEmpleadoBaja = dataEmpleado;
        document.getElementById('lblBajaNombre').textContent = dataEmpleado.full_name;
        document.getElementById('lblBajaNumero').textContent = `#${dataEmpleado.hikvision_employee_no}`;
        document.getElementById('ckBajaTerminal').checked = false;
        mdBaja.showModal();
    }

    btnConfirmarBaja.addEventListener('click', async () => {
        if (!dataEmpleadoBaja) return;
        btnConfirmarBaja.disabled = true;

        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${dataEmpleadoBaja.id}`, {
                method: 'DELETE',
                body: {
                    delete_from_device: document.getElementById('ckBajaTerminal').checked,
                },
            });
            await reloadEmployees();
            mdBaja.close();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            showToast(err.message || 'Error al eliminar el empleado.', 'danger');
        } finally {
            btnConfirmarBaja.disabled = false;
        }
    });

    async function syncEmployeeToHikvision(dataEmpleado, btnAccion) {
        const original = btnAccion.innerHTML;
        btnAccion.disabled = true;
        btnAccion.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

        try {
            const dataRespuesta = await apiFetch(`${dataRutas.updateBase}/${dataEmpleado.id}/sync-device`, { method: 'POST' });
            await reloadEmployees();
            showToast(dataRespuesta.message, 'success');
        } catch (err) {
            showToast(err.message || 'Error al sincronizar usuario en Hikvision.', 'danger');
        } finally {
            btnAccion.disabled = false;
            btnAccion.innerHTML = original;
        }
    }

    async function reloadEmployees() {
        // Recarga la página actual con la búsqueda actual
        await loadPage(dataMeta.page);
    }

    // Delegación única de eventos sobre FlexGrid
    document.getElementById('gridEmpleados').addEventListener('click', (event) => {
        const btnAccion = event.target.closest('button[data-action]');
        if (!btnAccion) return;

        const id = Number(btnAccion.getAttribute('data-id'));
        const action = btnAccion.getAttribute('data-action');
        const dataEmpleado = lstEmpleados.find((e) => e.id === id);
        if (!dataEmpleado) return;

        if (action === 'edit') {
            openEditModal(dataEmpleado);
        } else if (action === 'sync') {
            syncEmployeeToHikvision(dataEmpleado, btnAccion);
        } else if (action === 'fingerprint') {
            openFingerprintModal(dataEmpleado);
        } else if (action === 'credentials') {
            openCredentialsModal(dataEmpleado);
        } else if (action === 'delete') {
            openDeleteModal(dataEmpleado);
        }
    });
});
</script>
@endpush
