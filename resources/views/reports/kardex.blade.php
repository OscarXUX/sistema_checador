@extends('layouts.app')

@section('title', 'Kardex Fiscal y Reportes — Checador Hikvision')
@section('page_title', 'Kardex Fiscal y Auditoría Laboral')
@section('page_subtitle', 'Agrupación por empleado, totales acumulados y exportación directa a Excel/CSV')

@section('content')
<!-- Barra de filtros (<search> + GET) -->
<section class="surface-card p-3 p-md-4 mb-4">
    <search>
        <form id="kardex-filter-form" action="{{ route('reports.kardex') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-12 col-sm-6 col-lg-2">
                <label for="filter-start-date" class="form-label small fw-medium">Fecha Inicio</label>
                <input id="filter-start-date"
                       name="start_date"
                       type="date"
                       class="form-control form-control-sm font-mono-tabular"
                       value="{{ $filters['start_date'] }}"
                       required>
            </div>

            <div class="col-12 col-sm-6 col-lg-2">
                <label for="filter-end-date" class="form-label small fw-medium">Fecha Fin</label>
                <input id="filter-end-date"
                       name="end_date"
                       type="date"
                       class="form-control form-control-sm font-mono-tabular"
                       value="{{ $filters['end_date'] }}"
                       required>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <label for="filter-department" class="form-label small fw-medium">Departamento</label>
                <select id="filter-department" name="department" class="form-select form-select-sm">
                    <option value="">Todos los departamentos</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept }}" @selected($filters['department'] === $dept)>{{ $dept }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <label for="filter-employee" class="form-label small fw-medium">Empleado</label>
                <select id="filter-employee" name="employee_id" class="form-select form-select-sm">
                    <option value="">Todos los empleados</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @selected($filters['employee_id'] === $emp->id)>
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
        <div class="kpi-card p-3">
            <div class="small text-secondary">Registros Kardex</div>
            <div class="h4 fw-bold mb-0 font-mono-tabular" id="summary-count">{{ count($kardexRows) }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3">
            <div class="small text-secondary">Horas Netas Trabajadas</div>
            <div class="h4 fw-bold mb-0 font-mono-tabular text-info" id="summary-worked-hrs">
                {{ number_format(collect($kardexRows)->sum('worked_hours'), 2) }} <small class="fs-6 text-secondary">hrs</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3">
            <div class="small text-secondary">Horas Extra Totales</div>
            <div class="h4 fw-bold mb-0 font-mono-tabular text-warning" id="summary-overtime-hrs">
                {{ number_format(collect($kardexRows)->sum('overtime_hours'), 2) }} <small class="fs-6 text-secondary">hrs</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card p-3">
            <div class="small text-secondary">Minutos de Retardo</div>
            <div class="h4 fw-bold mb-0 font-mono-tabular text-danger" id="summary-late-mins">
                {{ collect($kardexRows)->sum('late_minutes') }} <small class="fs-6 text-secondary">min</small>
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
            <button id="btn-expand-groups" type="button" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrows-expand me-1" aria-hidden="true"></i>Expandir
            </button>
            <button id="btn-collapse-groups" type="button" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrows-collapse me-1" aria-hidden="true"></i>Colapsar
            </button>
            <button id="btn-export-xlsx" type="button" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Exportar Excel (.xlsx)
            </button>
            <button id="btn-export-csv" type="button" class="btn btn-outline-info btn-sm">
                <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Exportar CSV
            </button>
        </div>
    </div>

    <div id="kardex-grid" aria-label="Tabla Kardex fiscal agrupada por empleado"></div>
</section>

<!-- Bitácora cruda inmutable para auditoría -->
<section class="surface-card p-3 p-md-4 isolated-panel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0">Bitácora Cruda Inmutable (Auditoría ISAPI)</h2>
            <small class="text-secondary">Eventos originales recibidos de la terminal DS-K1T804AEF con respaldo JSON</small>
        </div>
        <span class="badge text-bg-dark border border-secondary-subtle font-mono-tabular">
            {{ count($auditLogs) }} eventos en rango
        </span>
    </div>

    <div id="audit-logs-grid" aria-label="Bitácora inmutable de eventos biométricos"></div>
</section>

<!-- Modal nativo <dialog>: Inspector JSON de carga cruda ISAPI -->
<dialog id="payload-dialog" class="app-dialog" closedby="any" aria-labelledby="payload-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="payload-dialog-title" class="h6 mb-0 fw-semibold">Payload Crudo ISAPI</h2>
            <button type="button" class="btn-close" id="payload-close-x" aria-label="Cerrar ventana"></button>
        </div>
        <div class="p-3 p-md-4">
            <div class="small text-secondary mb-2" id="payload-meta"></div>
            <pre id="payload-json-view" class="p-3 rounded bg-dark border border-secondary-subtle text-info small font-mono-tabular mb-0" style="max-height: 55dvh; overflow: auto;"></pre>
        </div>
        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="payload-close-btn" autofocus>Cerrar</button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="kardex-initial-data" type="application/json">@json(['rows' => $kardexRows, 'logs' => $auditLogs, 'filters' => $filters])</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid, filter: wjFilter, gridXlsx: wjGridXlsx } = window.AppUI.wijmo;
    const { showToast, escapeHtml } = window.AppUI;

    const initial = JSON.parse(document.getElementById('kardex-initial-data').textContent);

    // 1. CollectionView agrupado por empleado
    const kardexView = new wjCore.CollectionView(initial.rows, {
        groupDescriptions: [new wjCore.PropertyGroupDescription('employee_label')],
    });

    const kardexGrid = new wjGrid.FlexGrid('#kardex-grid', {
        itemsSource: kardexView,
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        showGroups: true,
        columns: [
            { binding: 'work_date', header: 'Fecha', width: 110, cssClass: 'font-mono-tabular' },
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
        ],
    });

    // Totales generales al pie de la tabla
    kardexGrid.columnFooters.rows.push(new wjGrid.GroupRow());
    kardexGrid.columnFooters.setCellData(0, 0, 'TOTALES');

    // Filtros por columna estilo Excel
    const kardexFilter = new wjFilter.FlexGridFilter(kardexGrid);

    const statusBadgeMap = {
        on_time: 'border-success text-success',
        late: 'border-warning text-warning',
        in_meal: 'border-info text-info',
        meal_exceeded: 'border-danger text-danger',
        incomplete: 'border-secondary text-secondary',
        absent: 'border-danger text-danger',
    };

    kardexGrid.formatItem.addHandler((s, e) => {
        if (e.panel === s.cells) {
            const row = s.rows[e.row];
            if (row instanceof wjGrid.GroupRow) {
                return;
            }
            const col = s.columns[e.col];
            const item = row.dataItem;
            if (!item) return;

            if (col.binding === 'status_label') {
                const cls = statusBadgeMap[item.status] || 'border-secondary text-secondary';
                e.cell.innerHTML = `<span class="badge bg-dark border ${cls}">${escapeHtml(item.status_label)}</span>`;
            }
        }
    });

    // Sincronizar tarjetas resumen cuando el usuario aplica filtros de columna Wijmo
    kardexView.collectionChanged.addHandler(() => {
        const items = kardexView.items.filter((i) => i && typeof i.worked_hours === 'number');
        const sum = (key) => items.reduce((acc, row) => acc + Number(row[key] || 0), 0);

        document.getElementById('summary-count').textContent = items.length;
        document.getElementById('summary-worked-hrs').innerHTML = `${sum('worked_hours').toFixed(2)} <small class="fs-6 text-secondary">hrs</small>`;
        document.getElementById('summary-overtime-hrs').innerHTML = `${sum('overtime_hours').toFixed(2)} <small class="fs-6 text-secondary">hrs</small>`;
        document.getElementById('summary-late-mins').innerHTML = `${sum('late_minutes')} <small class="fs-6 text-secondary">min</small>`;
    });

    document.getElementById('btn-expand-groups').addEventListener('click', () => {
        kardexGrid.collapseGroupsToLevel(10);
    });
    document.getElementById('btn-collapse-groups').addEventListener('click', () => {
        kardexGrid.collapseGroupsToLevel(0);
    });

    // Exportación directa a Excel (.xlsx) usando Wijmo FlexGridXlsxConverter
    document.getElementById('btn-export-xlsx').addEventListener('click', () => {
        const fileName = `Kardex_Fiscal_${initial.filters.start_date}_a_${initial.filters.end_date}.xlsx`;
        wjGridXlsx.FlexGridXlsxConverter.saveAsync(
            kardexGrid,
            {
                includeColumnHeaders: true,
                includeCellStyles: false,
            },
            fileName,
            () => showToast(`Archivo Excel "${fileName}" descargado correctamente.`, 'success'),
            (reason) => showToast(`No se pudo exportar a Excel: ${reason}`, 'danger')
        );
    });

    // Exportación directa a CSV (UTF-8 con BOM para Excel México/LatAm)
    document.getElementById('btn-export-csv').addEventListener('click', () => {
        const rows = kardexView.items;
        const headers = [
            'ID Reloj', 'Empleado', 'RFC', 'CURP', 'NSS', 'Departamento',
            'Turno', 'Fecha', 'Entrada', 'Salida Comida', 'Regreso Comida',
            'Salida', 'Comida (min)', 'Retardo (min)', 'Horas Ordinarias',
            'Horas Extra', 'Horas Totales', 'Estatus Fiscal',
        ];

        const csvEscape = (v) => `"${String(v ?? '').replaceAll('"', '""')}"`;
        const lines = [headers.map(csvEscape).join(',')];

        rows.forEach((r) => {
            lines.push([
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
            ].map(csvEscape).join(','));
        });

        const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const fileName = `Kardex_Fiscal_${initial.filters.start_date}_a_${initial.filters.end_date}.csv`;
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        link.click();
        URL.revokeObjectURL(link.href);
        showToast(`Archivo CSV "${fileName}" descargado correctamente.`, 'success');
    });

    // 2. FlexGrid secundario: Bitácora Cruda Inmutable
    const auditGrid = new wjGrid.FlexGrid('#audit-logs-grid', {
        itemsSource: new wjCore.CollectionView(initial.logs),
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

    auditGrid.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const row = s.rows[e.row]?.dataItem;
        if (!row) return;

        if (col.binding === 'id') {
            e.cell.innerHTML = `<button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-inspect-log="${row.id}">JSON</button>`;
        }
    });

    const payloadDialog = document.getElementById('payload-dialog');
    const closePayload = () => payloadDialog.close();
    document.getElementById('payload-close-x').addEventListener('click', closePayload);
    document.getElementById('payload-close-btn').addEventListener('click', closePayload);

    document.getElementById('audit-logs-grid').addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-inspect-log]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-inspect-log'));
        const log = initial.logs.find((l) => l.id === id);
        if (!log) return;

        document.getElementById('payload-meta').textContent = `[${log.employee_no}] ${log.employee_name} · ${log.punched_at} · ${log.device_ip}`;
        document.getElementById('payload-json-view').textContent = log.raw_payload || '{}';
        payloadDialog.showModal();
    });
});
</script>
@endpush
