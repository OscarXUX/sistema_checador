@extends('layouts.app')

@section('title', 'Kardex Fiscal y Reportes — Checador Hikvision')
@section('page_title', 'Kardex Fiscal y Auditoría Laboral')
@section('page_subtitle', 'Agrupación por empleado, totales acumulados y exportación directa a Excel/CSV')

@push('styles')
    {{-- Wijmo solo en esta página (auditoría OP-06) --}}
    @vite(['resources/js/wijmo/grid.js', 'resources/js/wijmo/kardex.js'])
@endpush

@section('content')
@if($dataFiltros['range_adjusted'])
    <div class="alert alert-warning alert-dismissible fade show" role="status">
        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
        El rango máximo es de {{ $dataFiltros['max_range_days'] }} días. La fecha de inicio se ajustó a {{ \Carbon\Carbon::parse($dataFiltros['start_date'])->format('d/m/Y') }}.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar aviso"></button>
    </div>
@endif

<!-- Barra de filtros (<search> + GET) -->
<section class="surface-card p-3 p-md-4 mb-4">
    <search>
        <form id="frmFiltros" action="{{ route('reports.kardex') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-12 col-sm-6 col-lg-2">
                <label for="dtFechaInicio" class="form-label small fw-medium">Fecha Inicio</label>
                <input id="dtFechaInicio"
                       name="start_date"
                       type="date"
                       class="form-control form-control-sm font-mono-tabular"
                       value="{{ $dataFiltros['start_date'] }}"
                       required>
            </div>

            <div class="col-12 col-sm-6 col-lg-2">
                <label for="dtFechaFin" class="form-label small fw-medium">Fecha Fin</label>
                <input id="dtFechaFin"
                       name="end_date"
                       type="date"
                       class="form-control form-control-sm font-mono-tabular"
                       value="{{ $dataFiltros['end_date'] }}"
                       required>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <label for="cbDepartamento" class="form-label small fw-medium">Departamento</label>
                <select id="cbDepartamento" name="department" class="form-select form-select-sm">
                    <option value="">Todos los departamentos</option>
                    @foreach($lstDepartamentos as $dept)
                        <option value="{{ $dept }}" @selected($dataFiltros['department'] === $dept)>{{ $dept }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <label for="cbEmpleado" class="form-label small fw-medium">Empleado</label>
                <select id="cbEmpleado" name="employee_id" class="form-select form-select-sm">
                    <option value="">Todos los empleados</option>
                    @foreach($lstEmpleados as $emp)
                        <option value="{{ $emp->id }}" @selected($dataFiltros['employee_id'] === $emp->id)>
                            [{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
                </button>
                <a href="{{ route('reports.kardex') }}" class="btn btn-outline-secondary btn-sm" title="Restablecer filtros">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                </a>
            </div>
        </form>
    </search>
</section>

<!-- Resumen rápido del período filtrado -->
<section class="row g-3 mb-4" aria-label="Totales fiscales del periodo">
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3 h-100">
            <div class="small text-secondary">Registros Kardex</div>
            <div class="kpi-value fw-bold mb-0 font-mono-tabular" id="lblRegistros">{{ count($lstFilasKardex) }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3 h-100">
            <div class="small text-secondary">Horas Netas Trabajadas</div>
            <div class="kpi-value fw-bold mb-0 font-mono-tabular text-info" id="lblHorasTrabajadas">
                {{ number_format(collect($lstFilasKardex)->sum('worked_hours'), 2) }} <small class="fs-6 text-secondary">hrs</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3 h-100">
            <div class="small text-secondary">Horas Extra Totales</div>
            <div class="kpi-value fw-bold mb-0 font-mono-tabular text-warning" id="lblHorasExtra">
                {{ number_format(collect($lstFilasKardex)->sum('overtime_hours'), 2) }} <small class="fs-6 text-secondary">hrs</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3 h-100">
            <div class="small text-secondary">Minutos de Retardo</div>
            <div class="kpi-value fw-bold mb-0 font-mono-tabular text-danger" id="lblMinutosRetardo">
                {{ collect($lstFilasKardex)->sum('late_minutes') }} <small class="fs-6 text-secondary">min</small>
            </div>
        </div>
    </div>
</section>

<!-- Grid empresarial con agrupación y exportación -->
<section class="surface-card p-3 p-md-4 mb-4 isolated-panel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0">Kardex Diario Calculado (Agrupado por Empleado)</h2>
            <small class="text-secondary">Incluye filtros por columna en encabezados y totales generales al pie</small>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button id="btnExpandir" type="button" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrows-expand me-1" aria-hidden="true"></i>Expandir
            </button>
            <button id="btnColapsar" type="button" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrows-collapse me-1" aria-hidden="true"></i>Colapsar
            </button>
            @can('kardex.exportar')
            <button id="btnExportarExcel" type="button" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Exportar Excel (.xlsx)
            </button>
            <button id="btnExportarCsv" type="button" class="btn btn-outline-info btn-sm">
                <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Exportar CSV
            </button>
            @endcan
        </div>
    </div>

    <div class="grid-region" tabindex="0" role="region" aria-label="Tabla Kardex fiscal agrupada por empleado">
        <div id="gridKardex"></div>
    </div>
</section>

<!-- Bitácora cruda inmutable para auditoría -->
<section class="surface-card p-3 p-md-4 isolated-panel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0">Bitácora Cruda Inmutable (Auditoría ISAPI)</h2>
            <small class="text-secondary">Eventos originales recibidos de la terminal DS-K1T804AEF con respaldo JSON</small>
        </div>
        <span class="badge text-bg-dark border border-secondary-subtle font-mono-tabular align-self-start align-self-md-auto">
            {{ count($lstBitacora) }} eventos en rango
        </span>
    </div>

    <div class="grid-region" tabindex="0" role="region" aria-label="Bitácora inmutable de eventos biométricos">
        <div id="gridBitacora"></div>
    </div>
</section>

<!-- Modal nativo <dialog>: Exportación mensual a Excel (.xlsx en servidor) -->
<dialog id="mdExcel" class="app-dialog" closedby="any" aria-labelledby="lblTituloExcel">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloExcel" class="h6 mb-0 fw-semibold">Exportar Kardex a Excel (.xlsx)</h2>
            <button type="button" class="btn-close" id="btnCerrarExcel" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmExcel" novalidate>
            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div id="lblErrorExcel" class="col-12 d-none">
                    <div class="alert alert-danger py-2 small mb-0" role="alert" aria-live="polite"></div>
                </div>

                <div class="col-12">
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>
                        Se genera un archivo <strong>.xlsx</strong> en el servidor con <strong>una hoja por mes</strong> (máximo 12 meses), encabezados fijos, autofiltro, totales por empleado y RFC/CURP/NSS enmascarados.
                    </div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtExcelInicio" class="form-label small fw-medium">Fecha Inicio <span class="text-danger">*</span></label>
                    <input id="dtExcelInicio" name="start_date" type="date" class="form-control font-mono-tabular" required value="{{ $dataFiltros['start_date'] }}">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtExcelFin" class="form-label small fw-medium">Fecha Fin <span class="text-danger">*</span></label>
                    <input id="dtExcelFin" name="end_date" type="date" class="form-control font-mono-tabular" required value="{{ $dataFiltros['end_date'] }}">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="cbExcelDepartamento" class="form-label small fw-medium">Departamento</label>
                    <select id="cbExcelDepartamento" name="department" class="form-select">
                        <option value="">Todos los departamentos</option>
                        @foreach($lstDepartamentos as $dept)
                            <option value="{{ $dept }}" @selected($dataFiltros['department'] === $dept)>{{ $dept }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="cbExcelEmpleado" class="form-label small fw-medium">Empleado</label>
                    <select id="cbExcelEmpleado" name="employee_id" class="form-select">
                        <option value="">Todos los empleados</option>
                        @foreach($lstEmpleados as $emp)
                            <option value="{{ $emp->id }}" @selected($dataFiltros['employee_id'] === $emp->id)>
                                [{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelarExcel">Cancelar</button>
                <button type="submit" class="btn btn-success btn-sm" id="btnDescargarExcel">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Descargar Excel (.xlsx)
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Inspector JSON de carga cruda ISAPI -->
<dialog id="mdPayload" class="app-dialog" closedby="any" aria-labelledby="lblTituloPayload">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloPayload" class="h6 mb-0 fw-semibold">Payload Crudo ISAPI</h2>
            <button type="button" class="btn-close" id="btnCerrarPayloadX" aria-label="Cerrar ventana"></button>
        </div>
        <div class="p-3 p-md-4 app-dialog-body">
            <div class="small text-secondary mb-2" id="lblPayloadDatos"></div>
            <pre id="lblPayloadJson" class="p-3 rounded bg-dark border border-secondary-subtle text-info small font-mono-tabular mb-0" style="max-height: 55dvh; overflow: auto;"></pre>
        </div>
        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCerrarPayload" autofocus>Cerrar</button>
        </div>
    </div>
</dialog>

<!-- Modal nativo <dialog>: checadas del día y corrección manual (paquete 3) -->
<dialog id="mdChecadas" class="app-dialog" closedby="any" aria-labelledby="lblTituloChecadas" style="max-width: 980px; width: 96vw;">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <div>
                <h2 id="lblTituloChecadas" class="h6 mb-0 fw-semibold">Checadas del día</h2>
                <div id="lblChecadasDatos" class="small text-secondary"></div>
            </div>
            <button type="button" class="btn-close" id="btnCerrarChecadasX" aria-label="Cerrar ventana"></button>
        </div>
        <div class="p-3 p-md-4 app-dialog-body">
            <p class="small text-secondary mb-3">La checada original no se modifica: la corrección se guarda aparte con quién, cuándo y por qué, y el día se recalcula.</p>
            <div class="table-responsive">
                <table id="gridChecadas" class="table table-dark table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Hora</th>
                            <th scope="col">Tecla del reloj</th>
                            <th scope="col">Cuenta como</th>
                            <th scope="col">Incidencias / corrección</th>
                            <th scope="col" class="col-corregir">Corregir</th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoChecadas"></tbody>
                </table>
            </div>
        </div>
        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCerrarChecadas">Cerrar</button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="dataKardexInicial" type="application/json">@json(['rows' => $lstFilasKardex, 'logs' => $lstBitacora, 'filters' => $dataFiltros])</script>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid, filter: wjFilter } = window.AppUI.wijmo;
    const { apiFetch, showToast, escapeHtml, csvEscape } = window.AppUI;
    const payloadBaseUrl = '{{ url("/reports/kardex/logs") }}';
    const exportXlsxUrl = '{{ route("reports.kardex.export.xlsx") }}';
    const dataPayloadCache = new Map();

    const dataInicial = JSON.parse(document.getElementById('dataKardexInicial').textContent);

    // 1. CollectionView agrupado por empleado
    const lstVistaKardex = new wjCore.CollectionView(dataInicial.rows, {
        groupDescriptions: [new wjCore.PropertyGroupDescription('employee_label')],
    });

    const gridKardex = new wjGrid.FlexGrid('#gridKardex', {
        itemsSource: lstVistaKardex,
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        showGroups: true,
        columns: [
            { binding: 'work_date', header: 'Fecha', width: 110, cssClass: 'font-mono-tabular' },
            { binding: 'id', header: 'Checadas', width: 105, allowSorting: false },
            { binding: 'shift_name', header: 'Turno', width: 165 },
            { binding: 'first_check_in', header: 'Entrada', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'break_out', header: 'Sal. Comida', width: 105, cssClass: 'font-mono-tabular' },
            { binding: 'break_in', header: 'Reg. Comida', width: 105, cssClass: 'font-mono-tabular' },
            { binding: 'last_check_out', header: 'Salida', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'meal_minutes', header: 'Comida (m)', width: 100, format: 'n0', aggregate: wjCore.Aggregate.Sum, cssClass: 'font-mono-tabular' },
            { binding: 'late_minutes', header: 'Retardo (m)', width: 100, format: 'n0', aggregate: wjCore.Aggregate.Sum, cssClass: 'font-mono-tabular' },
            { binding: 'ordinary_hours', header: 'Ord. (hrs)', width: 95, format: 'n2', aggregate: wjCore.Aggregate.Sum, cssClass: 'font-mono-tabular' },
            { binding: 'overtime_hours', header: 'Extra (hrs)', width: 95, format: 'n2', aggregate: wjCore.Aggregate.Sum, cssClass: 'font-mono-tabular' },
            { binding: 'worked_hours', header: 'Total (hrs)', width: 100, format: 'n2', aggregate: wjCore.Aggregate.Sum, cssClass: 'font-mono-tabular' },
            { binding: 'status_label', header: 'Estatus Fiscal', width: 140 },
            { binding: 'events_label', header: 'Eventos', width: 230 },
            { binding: 'incidents_label', header: 'Incidencias', width: 120 },
        ],
    });

    // Totales generales al pie de la tabla
    gridKardex.columnFooters.rows.push(new wjGrid.GroupRow());
    gridKardex.columnFooters.setCellData(0, 0, 'TOTALES');

    // Filtros por columna estilo Excel
    const filtroKardex = new wjFilter.FlexGridFilter(gridKardex);

    const dataColoresEstado = {
        on_time: 'border-success text-success',
        late: 'border-warning text-warning',
        in_meal: 'border-info text-info',
        meal_exceeded: 'border-danger text-danger',
        incomplete: 'border-secondary text-secondary',
        absent: 'border-danger text-danger',
    };

    gridKardex.formatItem.addHandler((s, e) => {
        if (e.panel === s.cells) {
            const row = s.rows[e.row];
            if (row instanceof wjGrid.GroupRow) {
                return;
            }
            const col = s.columns[e.col];
            const dataFila = row.dataItem;
            if (!dataFila) return;

            if (col.binding === 'status_label') {
                const cls = dataColoresEstado[dataFila.status] || 'border-secondary text-secondary';
                e.cell.innerHTML = `<span class="badge bg-dark border ${cls}">${escapeHtml(dataFila.status_label)}</span>`;
            } else if (col.binding === 'events_label') {
                // Tipos de evento del catálogo (paquete 3); el color sale de una lista cerrada
                const lstColores = ['success', 'warning', 'danger', 'info', 'secondary'];
                e.cell.innerHTML = (dataFila.events || []).map((ev) => {
                    const color = lstColores.includes(ev.color) ? ev.color : 'secondary';
                    return `<span class="badge bg-dark border border-${color} text-${color} me-1">${escapeHtml(ev.name)}</span>`;
                }).join('');
            } else if (col.binding === 'id') {
                e.cell.innerHTML = `<button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-day-summary="${Number(dataFila.id)}">Ver${dataFila.corrected_punches ? ' <i class="bi bi-pencil-square" aria-label="Con correcciones"></i>' : ''}</button>`;
            } else if (col.binding === 'incidents_label') {
                const lstIncidencias = dataFila.incidents || [];
                e.cell.innerHTML = lstIncidencias.length
                    ? `<span class="badge text-bg-warning" title="${escapeHtml(dataFila.incidents_label)}">${lstIncidencias.length} incidencia${lstIncidencias.length === 1 ? '' : 's'}</span>`
                    : '';
            }
        }
    });

    // Sincronizar tarjetas resumen cuando el usuario aplica filtros de columna Wijmo
    lstVistaKardex.collectionChanged.addHandler(() => {
        const lstFilas = lstVistaKardex.items.filter((i) => i && typeof i.worked_hours === 'number');
        const sum = (key) => lstFilas.reduce((acc, row) => acc + Number(row[key] || 0), 0);

        document.getElementById('lblRegistros').textContent = lstFilas.length;
        document.getElementById('lblHorasTrabajadas').innerHTML = `${sum('worked_hours').toFixed(2)} <small class="fs-6 text-secondary">hrs</small>`;
        document.getElementById('lblHorasExtra').innerHTML = `${sum('overtime_hours').toFixed(2)} <small class="fs-6 text-secondary">hrs</small>`;
        document.getElementById('lblMinutosRetardo').innerHTML = `${sum('late_minutes')} <small class="fs-6 text-secondary">min</small>`;
    });

    document.getElementById('btnExpandir').addEventListener('click', () => {
        gridKardex.collapseGroupsToLevel(10);
    });
    document.getElementById('btnColapsar').addEventListener('click', () => {
        gridKardex.collapseGroupsToLevel(0);
    });

    // Exportación Excel (.xlsx) generada en el servidor con PhpSpreadsheet (1 hoja por mes, hasta 12 meses)
    const mdExcel = document.getElementById('mdExcel');
    const frmExcel = document.getElementById('frmExcel');
    const lblErrorExcel = document.getElementById('lblErrorExcel');
    const lblAlertaExcel = lblErrorExcel.querySelector('.alert');
    const btnDescargarExcel = document.getElementById('btnDescargarExcel');

    const closeExportDialog = () => mdExcel.close();
    document.getElementById('btnCerrarExcel').addEventListener('click', closeExportDialog);
    document.getElementById('btnCancelarExcel').addEventListener('click', closeExportDialog);

    function showExportError(msg) {
        lblErrorExcel.classList.remove('d-none');
        lblAlertaExcel.textContent = msg;
    }

    function clearExportError() {
        lblErrorExcel.classList.add('d-none');
        lblAlertaExcel.textContent = '';
    }

    function countInclusiveMonths(startStr, endStr) {
        const [sy, sm] = startStr.split('-').map(Number);
        const [ey, em] = endStr.split('-').map(Number);
        if (!sy || !sm || !ey || !em) return 0;
        return (ey - sy) * 12 + (em - sm) + 1;
    }

    document.getElementById('btnExportarExcel')?.addEventListener('click', () => {
        clearExportError();
        document.getElementById('dtExcelInicio').value = document.getElementById('dtFechaInicio').value || dataInicial.filters.start_date;
        document.getElementById('dtExcelFin').value = document.getElementById('dtFechaFin').value || dataInicial.filters.end_date;
        document.getElementById('cbExcelDepartamento').value = document.getElementById('cbDepartamento').value || '';
        document.getElementById('cbExcelEmpleado').value = document.getElementById('cbEmpleado').value || '';
        mdExcel.showModal();
    });

    frmExcel.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearExportError();

        const startDate = document.getElementById('dtExcelInicio').value;
        const endDate = document.getElementById('dtExcelFin').value;
        const department = document.getElementById('cbExcelDepartamento').value;
        const employeeId = document.getElementById('cbExcelEmpleado').value;

        if (!startDate || !endDate) {
            showExportError('Debes seleccionar fecha de inicio y fin.');
            return;
        }
        if (startDate > endDate) {
            showExportError('La fecha de fin debe ser igual o posterior a la fecha de inicio.');
            return;
        }
        if (countInclusiveMonths(startDate, endDate) > 12) {
            showExportError('El rango máximo para exportar a Excel es de 12 meses.');
            return;
        }

        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
        });
        if (department) params.set('department', department);
        if (employeeId) params.set('employee_id', employeeId);

        const originalHtml = btnDescargarExcel.innerHTML;
        btnDescargarExcel.disabled = true;
        btnDescargarExcel.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Generando...';

        try {
            const response = await fetch(`${exportXlsxUrl}?${params}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.status === 401 || response.status === 419) {
                const loginUrl = document.body.dataset.loginUrl || '/login';
                window.location.assign(`${loginUrl}?expirada=1`);
                return;
            }

            if (!response.ok) {
                const dataError = await response.json().catch(() => ({}));
                throw new Error(dataError.message || `Error HTTP ${response.status} al exportar Excel.`);
            }

            const blob = await response.blob();
            const fileName = `Kardex_Fiscal_${startDate}_a_${endDate}.xlsx`;
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName;
            link.click();
            URL.revokeObjectURL(url);
            mdExcel.close();
            showToast(`Archivo Excel "${fileName}" descargado correctamente.`, 'success');
        } catch (err) {
            showExportError(err.message || 'No se pudo generar el archivo Excel.');
        } finally {
            btnDescargarExcel.disabled = false;
            btnDescargarExcel.innerHTML = originalHtml;
        }
    });

    // Exportación directa a CSV (UTF-8 con BOM para Excel México/LatAm)
    document.getElementById('btnExportarCsv')?.addEventListener('click', () => {
        const lstFilas = lstVistaKardex.items;
        const lstEncabezados = [
            'ID Reloj', 'Empleado', 'RFC', 'CURP', 'NSS', 'Departamento',
            'Turno', 'Fecha', 'Entrada', 'Salida Comida', 'Regreso Comida',
            'Salida', 'Comida (min)', 'Retardo (min)', 'Horas Ordinarias',
            'Horas Extra', 'Horas Totales', 'Estatus Fiscal', 'Eventos', 'Incidencias',
        ];

        // csvEscape (resources/js/csv.js) neutraliza fórmulas: =, +, -, @ al inicio (SE-15)
        const lstLineas = [lstEncabezados.map(csvEscape).join(',')];

        lstFilas.forEach((r) => {
            lstLineas.push([
                r.hikvision_employee_no,
                r.employee_name,
                r.rfc,
                r.curp,
                r.nss,
                r.department,
                r.shift_name,
                r.work_date,
                r.first_check_in,
                r.break_out,
                r.break_in,
                r.last_check_out,
                r.meal_minutes,
                r.late_minutes,
                r.ordinary_hours,
                r.overtime_hours,
                r.worked_hours,
                r.status_label,
                r.events_label,
                r.incidents_label,
            ].map(csvEscape).join(','));
        });

        const blob = new Blob(['\uFEFF' + lstLineas.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const fileName = `Kardex_Fiscal_${dataInicial.filters.start_date}_a_${dataInicial.filters.end_date}.csv`;
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        link.click();
        URL.revokeObjectURL(link.href);
        showToast(`Archivo CSV "${fileName}" descargado correctamente.`, 'success');
    });

    // 2. FlexGrid secundario: Bitácora Cruda Inmutable
    const gridBitacora = new wjGrid.FlexGrid('#gridBitacora', {
        itemsSource: new wjCore.CollectionView(dataInicial.logs),
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        columns: [
            { binding: 'punched_at', header: 'Fecha y Hora', width: 170, cssClass: 'font-mono-tabular' },
            { binding: 'employee_no', header: 'ID Reloj', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'employee_name', header: 'Empleado', width: '*', minWidth: 190 },
            { binding: 'department', header: 'Departamento', width: 135 },
            { binding: 'event_label', header: 'Evento Crudo', width: 145 },
            { binding: 'verification_mode', header: 'Verificación', width: 145 },
            { binding: 'device_ip', header: 'IP Terminal', width: 130, cssClass: 'font-mono-tabular' },
            { binding: 'id', header: 'Payload', width: 110, allowSorting: false },
        ],
    });

    gridBitacora.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const row = s.rows[e.row]?.dataItem;
        if (!row) return;

        if (col.binding === 'id') {
            e.cell.innerHTML = `<button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-inspect-log="${row.id}">JSON</button>`;
        }
    });

    const mdPayload = document.getElementById('mdPayload');
    const closePayload = () => mdPayload.close();
    document.getElementById('btnCerrarPayloadX').addEventListener('click', closePayload);
    document.getElementById('btnCerrarPayload').addEventListener('click', closePayload);

    document.getElementById('gridBitacora').addEventListener('click', async (event) => {
        const btnInspeccionar = event.target.closest('button[data-inspect-log]');
        if (!btnInspeccionar) return;
        const id = Number(btnInspeccionar.getAttribute('data-inspect-log'));
        const dataBitacora = dataInicial.logs.find((l) => l.id === id);
        if (!dataBitacora) return;

        const lblPayloadDatos = document.getElementById('lblPayloadDatos');
        const lblPayloadJson = document.getElementById('lblPayloadJson');
        lblPayloadDatos.textContent = `[${dataBitacora.employee_no}] ${dataBitacora.employee_name} · ${dataBitacora.punched_at} · ${dataBitacora.device_ip}`;

        if (dataPayloadCache.has(id)) {
            lblPayloadJson.textContent = dataPayloadCache.get(id);
            mdPayload.showModal();
            return;
        }

        lblPayloadJson.textContent = 'Cargando payload JSON...';
        mdPayload.showModal();

        try {
            const dataDetalle = await apiFetch(`${payloadBaseUrl}/${id}/payload`, { method: 'GET' });
            const text = dataDetalle.raw_payload || '{}';
            dataPayloadCache.set(id, text);
            lblPayloadJson.textContent = text;
        } catch (err) {
            lblPayloadJson.textContent = '{}';
            showToast(err.message || 'No se pudo cargar el payload de la checada.', 'danger');
        }
    });

    // Checadas del día y corrección manual (paquete 3)
    const mdChecadas = document.getElementById('mdChecadas');
    const cuerpoChecadas = document.getElementById('cuerpoChecadas');
    const punchesBaseUrl = '{{ url("/reports/kardex") }}';
    let idResumenActual = null;
    const cerrarChecadas = () => mdChecadas.close();
    document.getElementById('btnCerrarChecadasX').addEventListener('click', cerrarChecadas);
    document.getElementById('btnCerrarChecadas').addEventListener('click', cerrarChecadas);

    async function refrescarKardex() {
        const params = new URLSearchParams({ start_date: dataInicial.filters.start_date, end_date: dataInicial.filters.end_date });
        if (dataInicial.filters.department) params.set('department', dataInicial.filters.department);
        if (dataInicial.filters.employee_id) params.set('employee_id', dataInicial.filters.employee_id);
        const dataRespuesta = await apiFetch(`{{ route('reports.kardex.data') }}?${params}`, { method: 'GET' });
        lstVistaKardex.sourceCollection = dataRespuesta.kardex_rows || [];
    }

    async function abrirChecadas(idResumen) {
        idResumenActual = idResumen;
        cuerpoChecadas.innerHTML = '<tr><td colspan="5" class="text-secondary">Cargando checadas...</td></tr>';
        if (!mdChecadas.open) mdChecadas.showModal();
        try {
            const dataRespuesta = await apiFetch(`${punchesBaseUrl}/summaries/${idResumen}/punches`, { method: 'GET' });
            document.getElementById('lblChecadasDatos').textContent = `${dataRespuesta.employee} · ${dataRespuesta.work_date} · Turno: ${dataRespuesta.shift || 'sin turno'}`;
            mdChecadas.querySelectorAll('.col-corregir').forEach((el) => el.classList.toggle('d-none', !dataRespuesta.can_correct));
            const htmlOpciones = Object.entries(dataRespuesta.types).map(([k, v]) => `<option value="${escapeHtml(k)}">${escapeHtml(v)}</option>`).join('');
            cuerpoChecadas.innerHTML = dataRespuesta.punches.length ? dataRespuesta.punches.map((dataChecada) => {
                const htmlRol = dataChecada.role_label
                    ? `<span class="badge bg-dark border ${dataChecada.role === 'not_counted' ? 'border-secondary text-secondary' : 'border-info text-info'}">${escapeHtml(dataChecada.role_label)}</span>`
                    : '<span class="text-secondary small">—</span>';
                const htmlCorreccion = dataChecada.correction
                    ? `<div class="small"><span class="badge text-bg-primary">Corregida: ${escapeHtml(dataChecada.correction.label)}</span> <span class="text-secondary">${escapeHtml(dataChecada.correction.user)} · ${escapeHtml(dataChecada.correction.at || '')}</span><div class="text-secondary">Motivo: ${escapeHtml(dataChecada.correction.reason)}</div></div>`
                    : '';
                const htmlIncidencias = dataChecada.incidents.map((i) => `<span class="badge text-bg-warning me-1">${escapeHtml(i)}</span>`).join('');
                const htmlCorregir = dataRespuesta.can_correct ? `
                    <td class="col-corregir" style="min-width: 22rem;">
                        <div class="d-flex flex-wrap gap-1">
                            <label class="visually-hidden" for="cbTipo${dataChecada.id}">Cómo debe contar la checada de las ${escapeHtml(dataChecada.time)}</label>
                            <select id="cbTipo${dataChecada.id}" class="form-select form-select-sm" style="max-width: 10rem;">${htmlOpciones}</select>
                            <label class="visually-hidden" for="txtMotivo${dataChecada.id}">Motivo</label>
                            <input id="txtMotivo${dataChecada.id}" type="text" class="form-control form-control-sm" style="max-width: 14rem;" maxlength="255" placeholder="Motivo (obligatorio)">
                            <button type="button" class="btn btn-primary btn-sm" data-guardar-correccion="${dataChecada.id}">Guardar</button>
                            ${dataChecada.correction ? `<button type="button" class="btn btn-outline-danger btn-sm" data-quitar-correccion="${dataChecada.id}">Quitar</button>` : ''}
                        </div>
                    </td>` : '';
                return `<tr>
                    <td class="font-mono-tabular">${escapeHtml(dataChecada.punched_at)}</td>
                    <td class="small">${escapeHtml(dataChecada.device_key)}</td>
                    <td>${htmlRol}</td>
                    <td>${htmlIncidencias}${htmlCorreccion}</td>
                    ${htmlCorregir}
                </tr>`;
            }).join('') : '<tr><td colspan="5" class="text-secondary">No hay checadas en esta jornada.</td></tr>';
            dataRespuesta.punches.forEach((dataChecada) => {
                const cbTipo = document.getElementById(`cbTipo${dataChecada.id}`);
                if (cbTipo) cbTipo.value = dataChecada.correction ? dataChecada.correction.type : (dataChecada.role && dataChecada.role !== 'not_counted' ? dataChecada.role : 'ignore');
            });
        } catch (err) {
            cuerpoChecadas.innerHTML = '<tr><td colspan="5" class="text-danger">No se pudieron cargar las checadas.</td></tr>';
            showToast(err.message || 'No se pudieron cargar las checadas.', 'danger');
        }
    }

    document.getElementById('gridKardex').addEventListener('click', (event) => {
        const btnVer = event.target.closest('button[data-day-summary]');
        if (btnVer) abrirChecadas(Number(btnVer.getAttribute('data-day-summary')));
    });

    cuerpoChecadas.addEventListener('click', async (event) => {
        const btnGuardar = event.target.closest('button[data-guardar-correccion]');
        const btnQuitar = event.target.closest('button[data-quitar-correccion]');
        if (!btnGuardar && !btnQuitar) return;
        const idChecada = Number((btnGuardar || btnQuitar).getAttribute(btnGuardar ? 'data-guardar-correccion' : 'data-quitar-correccion'));
        try {
            const dataRespuesta = btnGuardar
                ? await apiFetch(`${punchesBaseUrl}/punches/${idChecada}/correction`, {
                    method: 'POST',
                    body: { corrected_type: document.getElementById(`cbTipo${idChecada}`).value, reason: document.getElementById(`txtMotivo${idChecada}`).value },
                })
                : await apiFetch(`${punchesBaseUrl}/punches/${idChecada}/correction`, { method: 'DELETE' });
            showToast(dataRespuesta.message, 'success');
            await abrirChecadas(idResumenActual);
            await refrescarKardex();
        } catch (err) {
            showToast(err.message || 'No se pudo guardar la corrección.', 'danger');
        }
    });
});
</script>
@endpush
