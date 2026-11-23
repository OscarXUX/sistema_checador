@extends('layouts.app')

@section('title', 'Dashboard Ejecutivo — Control Biométrico Hikvision')
@section('page_title', 'Dashboard Ejecutivo')
@section('page_subtitle', 'Monitoreo en tiempo real de planta, comidas, retardos y horas extra')

@push('styles')
    {{-- Wijmo solo en esta página (auditoría OP-06) --}}
    @vite(['resources/js/wijmo/grid.js', 'resources/js/wijmo/chart.js'])
@endpush

@section('content')
@php
    $dataKpis = $dataDashboard['kpis'];
@endphp

<!-- 4 Tarjetas KPI de Bootstrap 5 -->
<section aria-label="Indicadores clave de asistencia" class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">Empleados en Planta</div>
                    <div class="kpi-value fw-bold mt-1 font-mono-tabular" id="lblEnPlanta">{{ $dataKpis['in_plant'] }}</div>
                </div>
                <div class="kpi-icon bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                    <i class="bi bi-building-check" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                De <span class="text-light fw-semibold font-mono-tabular" id="lblEmpleadosActivos">{{ $dataKpis['active_employees'] }}</span> empleados activos en padrón
            </div>
        </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">En Hora de Comida</div>
                    <div class="kpi-value fw-bold mt-1 font-mono-tabular" id="lblEnComida">{{ $dataKpis['in_meal'] }}</div>
                </div>
                <div class="kpi-icon bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">
                    <i class="bi bi-cup-hot" aria-hidden="true"></i>
                </div>
            </div>
            <div id="lblAvisoComida" class="small">
                @if($dataKpis['meal_exceeded_count'] > 0)
                    <span class="badge text-bg-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                        {{ $dataKpis['meal_exceeded_count'] }} exceso(s) de comida hoy
                    </span>
                @else
                    <span class="text-success">
                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Sin excesos de tiempo
                    </span>
                @endif
            </div>
        </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">Retardos Hoy</div>
                    <div class="kpi-value fw-bold mt-1 font-mono-tabular text-warning" id="lblRetardosHoy">{{ $dataKpis['late_today'] }}</div>
                </div>
                <div class="kpi-icon bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">
                    <i class="bi bi-alarm" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                Acumulado hoy: <span class="text-light fw-semibold font-mono-tabular" id="lblMinutosRetardo">{{ $dataKpis['late_today_minutes'] }}</span> min fuera de tolerancia
            </div>
        </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">Horas Extra (Semana)</div>
                    <div class="kpi-value fw-bold mt-1 font-mono-tabular text-info">
                        <span id="lblHorasExtra">{{ number_format($dataKpis['weekly_overtime_hours'], 1) }}</span>
                        <span class="fs-5 text-secondary fw-normal">hrs</span>
                    </div>
                </div>
                <div class="kpi-icon bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                    <i class="bi bi-stopwatch" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                Equivalente a <span class="text-light fw-semibold font-mono-tabular" id="lblMinutosExtra">{{ $dataKpis['weekly_overtime_minutes'] }}</span> minutos extras
            </div>
        </article>
    </div>
</section>

<!-- Alerta detallada de exceso de comida -->
<section id="avisoComida" class="mb-4 {{ count($dataKpis['meal_alerts']) === 0 ? 'd-none' : '' }}" aria-live="polite">
    <div class="alert alert-danger border-danger-subtle bg-danger bg-opacity-10 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-0">
        <div>
            <div class="fw-semibold text-danger-emphasis">
                <i class="bi bi-exclamation-octagon-fill me-2" aria-hidden="true"></i>
                Alerta Fiscal de Comida Excedida
            </div>
            <div class="small text-light mt-1" id="lblAvisosComida">
                @foreach($dataKpis['meal_alerts'] as $alert)
                    <span class="badge text-bg-dark border border-danger me-1 mb-1">
                        {{ $alert['employee_name'] }}: {{ $alert['elapsed_minutes'] }} / {{ $alert['allowed_minutes'] }} min
                        @if($alert['active']) (En curso) @endif
                    </span>
                @endforeach
            </div>
        </div>
        <a href="{{ route('reports.kardex') }}" class="btn btn-sm btn-outline-danger flex-shrink-0">
            Revisar en Kardex
        </a>
    </div>
</section>

<!-- Incidencias de checadas de las jornadas en curso (paquete 3) -->
<section id="avisoIncidencias" class="mb-4 {{ $dataKpis['incidents_today'] === 0 ? 'd-none' : '' }}" aria-live="polite">
    <div class="alert alert-warning border-warning-subtle bg-warning bg-opacity-10 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-0">
        <div>
            <div class="fw-semibold text-warning-emphasis">
                <i class="bi bi-exclamation-diamond-fill me-2" aria-hidden="true"></i>
                Incidencias de checadas hoy: <span id="lblIncidenciasTotal" class="font-mono-tabular">{{ $dataKpis['incidents_today'] }}</span>
            </div>
            <div class="small text-light mt-1" id="lblIncidencias">
                @foreach($dataKpis['incident_alerts'] as $incidencia)
                    <span class="badge text-bg-dark border border-warning me-1 mb-1">
                        [{{ $incidencia['employee_no'] }}] {{ $incidencia['employee_name'] }}: {{ $incidencia['label'] }} · {{ $incidencia['at'] }}
                    </span>
                @endforeach
            </div>
            <div id="lblIncidenciasMas" class="small text-secondary {{ $dataKpis['incidents_today'] > count($dataKpis['incident_alerts']) ? '' : 'd-none' }}">Se muestran las 30 más recientes.</div>
        </div>
        @can('kardex.consultar')
        <a href="{{ route('reports.kardex', ['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]) }}" class="btn btn-sm btn-outline-warning flex-shrink-0">
            Revisar y corregir en Kardex
        </a>
        @endcan
    </div>
</section>

<!-- Gráficas con Wijmo FlexChart -->
<section class="row g-3 mb-4" aria-label="Gráficas analíticas">
    <div class="col-12 col-lg-6">
        <div class="surface-card p-3 p-md-4 h-100 isolated-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h6 fw-semibold mb-0">Puntualidad Semanal</h2>
                    <small class="text-secondary">Comparativa diaria: A tiempo vs Retardos (últimos 7 días)</small>
                </div>
                <span class="badge text-bg-dark border border-secondary-subtle">Wijmo FlexChart</span>
            </div>
            <div id="chartPuntualidad" role="img" aria-label="Gráfica de puntualidad semanal"></div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="surface-card p-3 p-md-4 h-100 isolated-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h6 fw-semibold mb-0">Horas Ordinarias vs Extra por Departamento</h2>
                    <small class="text-secondary">Acumulado semanal por área operativa</small>
                </div>
                <span class="badge text-bg-dark border border-secondary-subtle">Wijmo FlexChart</span>
            </div>
            <div id="chartDepartamentos" role="img" aria-label="Gráfica comparativa de horas por departamento"></div>
        </div>
    </div>
</section>

<!-- Monitor en Vivo con Wijmo FlexGrid -->
<section class="surface-card p-3 p-md-4 isolated-panel" aria-label="Monitor de checadas en vivo">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0" id="lblTituloMonitor">
                {{ ($dataDashboard['live_logs_scope'] ?? 'today') === 'recent' ? 'Monitor en Vivo de Checadas (Últimas registradas)' : 'Monitor en Vivo de Checadas (Hoy)' }}
            </h2>
            <small class="text-secondary">
                Última actualización: <span id="lblActualizado" class="font-mono-tabular">{{ $dataDashboard['generated_at'] }}</span>
            </small>
        </div>

        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 w-100 w-md-auto">
            <search class="flex-grow-1">
                <form id="frmFiltroMonitor" class="d-flex align-items-center">
                    <label for="txtBuscarMonitor" class="visually-hidden">Filtrar checadas del día</label>
                    <div class="input-group input-group-sm w-100">
                        <span class="input-group-text bg-dark border-secondary-subtle text-secondary">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input id="txtBuscarMonitor"
                               type="search"
                               class="form-control bg-dark border-secondary-subtle"
                               placeholder="Filtrar empleado, depto, evento..."
                               autocorrect="off"
                               autocapitalize="none"
                               spellcheck="false">
                    </div>
                </form>
            </search>
            <button id="btnRefrescar" type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refrescar
            </button>
        </div>
    </div>

    <div class="grid-region" tabindex="0" role="region" aria-label="Tabla en vivo de checadas biométricas">
        <div id="gridMonitor"></div>
    </div>
</section>
@endsection

@push('scripts')
<script id="dataDashboardInicial" type="application/json">@json($dataDashboard)</script>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid, chart: wjChart } = window.AppUI.wijmo;
    const { apiFetch, escapeHtml } = window.AppUI;

    let dataDashboard = JSON.parse(document.getElementById('dataDashboardInicial').textContent);

    // 1. FlexChart: Puntualidad semanal
    const chartPuntualidad = new wjChart.FlexChart('#chartPuntualidad', {
        itemsSource: dataDashboard.charts.punctuality,
        bindingX: 'day',
        chartType: wjChart.ChartType.Column,
        legend: { position: wjChart.Position.Bottom },
        tooltip: {
            content: '<b>{seriesName}</b><br>{x}: <strong>{y}</strong> empleados',
        },
        series: [
            {
                name: 'A tiempo',
                binding: 'on_time',
                style: { fill: '#10b981', stroke: '#059669' },
            },
            {
                name: 'Retardos',
                binding: 'late',
                style: { fill: '#f59e0b', stroke: '#d97706' },
            },
        ],
    });
    chartPuntualidad.axisY.min = 0;
    chartPuntualidad.axisY.format = 'n0';

    // 2. FlexChart: Horas Ordinarias vs Extra por Departamento
    const chartDepartamentos = new wjChart.FlexChart('#chartDepartamentos', {
        itemsSource: dataDashboard.charts.departments,
        bindingX: 'department',
        chartType: wjChart.ChartType.Column,
        stacking: wjChart.Stacking.Stacked,
        legend: { position: wjChart.Position.Bottom },
        tooltip: {
            content: '<b>{seriesName}</b><br>{x}: <strong>{y:n2}</strong> hrs',
        },
        series: [
            {
                name: 'Horas Ordinarias',
                binding: 'ordinary_hours',
                style: { fill: '#38bdf8', stroke: '#0284c7' },
            },
            {
                name: 'Horas Extra',
                binding: 'overtime_hours',
                style: { fill: '#f43f5e', stroke: '#e11d48' },
            },
        ],
    });
    chartDepartamentos.axisY.min = 0;
    chartDepartamentos.axisY.title = 'Horas';

    // 3. FlexGrid: Monitor en Vivo
    const lstMonitor = new wjCore.CollectionView(dataDashboard.live_logs);
    const txtBuscarMonitor = document.getElementById('txtBuscarMonitor');
    document.getElementById('frmFiltroMonitor')?.addEventListener('submit', (e) => e.preventDefault());

    lstMonitor.filter = (item) => {
        const term = (txtBuscarMonitor.value || '').trim().toLowerCase();
        if (!term) return true;
        return [
            item.employee_no,
            item.full_name,
            item.department,
            item.shift_name,
            item.event_label,
            item.verification_label,
            item.daily_status_label,
        ].some((val) => String(val || '').toLowerCase().includes(term));
    };

    txtBuscarMonitor.addEventListener('input', () => lstMonitor.refresh());

    const gridMonitor = new wjGrid.FlexGrid('#gridMonitor', {
        itemsSource: lstMonitor,
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        allowSorting: true,
        columns: [
            { binding: 'punched_time', header: 'Hora', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'employee_no', header: 'ID Reloj', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'full_name', header: 'Empleado', width: '*', minWidth: 190 },
            { binding: 'department', header: 'Departamento', width: 135 },
            { binding: 'shift_name', header: 'Turno', width: 165 },
            { binding: 'event_label', header: 'Checada', width: 145 },
            { binding: 'verification_label', header: 'Método', width: 135 },
            { binding: 'daily_status_label', header: 'Estado Día', width: 145 },
        ],
    });

    const dataColoresEvento = {
        check_in: 'text-bg-success',
        break_out: 'text-bg-warning text-dark',
        break_in: 'text-bg-info text-dark',
        check_out: 'text-bg-primary',
        unclassified: 'text-bg-secondary',
    };

    const dataColoresEstado = {
        on_time: 'border-success text-success',
        late: 'border-warning text-warning',
        in_meal: 'border-info text-info',
        meal_exceeded: 'border-danger text-danger',
        incomplete: 'border-secondary text-secondary',
        absent: 'border-danger text-danger',
    };

    gridMonitor.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const rowItem = s.rows[e.row]?.dataItem;
        if (!rowItem) return;

        if (col.binding === 'event_label') {
            const cls = dataColoresEvento[rowItem.event_type] || 'text-bg-secondary';
            e.cell.innerHTML = `<span class="badge ${cls}">${escapeHtml(rowItem.event_label)}</span>`;
        } else if (col.binding === 'daily_status_label') {
            const cls = dataColoresEstado[rowItem.daily_status] || 'border-secondary text-secondary';
            e.cell.innerHTML = `<span class="badge bg-dark border ${cls}">${escapeHtml(rowItem.daily_status_label)}</span>`;
        }
    });

    function pintarDashboard(dataNuevo) {
        dataDashboard = dataNuevo;
        const dataKpis = dataNuevo.kpis;

        document.getElementById('lblEnPlanta').textContent = dataKpis.in_plant;
        document.getElementById('lblEmpleadosActivos').textContent = dataKpis.active_employees;
        document.getElementById('lblEnComida').textContent = dataKpis.in_meal;
        document.getElementById('lblRetardosHoy').textContent = dataKpis.late_today;
        document.getElementById('lblMinutosRetardo').textContent = dataKpis.late_today_minutes;
        document.getElementById('lblHorasExtra').textContent = Number(dataKpis.weekly_overtime_hours).toFixed(1);
        document.getElementById('lblMinutosExtra').textContent = dataKpis.weekly_overtime_minutes;
        document.getElementById('lblActualizado').textContent = dataNuevo.generated_at;
        document.getElementById('lblTituloMonitor').textContent = dataNuevo.live_logs_scope === 'recent'
            ? 'Monitor en Vivo de Checadas (Últimas registradas)'
            : 'Monitor en Vivo de Checadas (Hoy)';

        const lblAvisoComida = document.getElementById('lblAvisoComida');
        if (dataKpis.meal_exceeded_count > 0) {
            lblAvisoComida.innerHTML = `<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>${escapeHtml(dataKpis.meal_exceeded_count)} exceso(s) de comida hoy</span>`;
        } else {
            lblAvisoComida.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Sin excesos de tiempo</span>`;
        }

        const avisoComida = document.getElementById('avisoComida');
        const lblAvisosComida = document.getElementById('lblAvisosComida');
        if (dataKpis.meal_alerts.length > 0) {
            avisoComida.classList.remove('d-none');
            lblAvisosComida.innerHTML = dataKpis.meal_alerts.map((a) => (
                `<span class="badge text-bg-dark border border-danger me-1 mb-1">${escapeHtml(a.employee_name)}: ${escapeHtml(a.elapsed_minutes)} / ${escapeHtml(a.allowed_minutes)} min${a.active ? ' (En curso)' : ''}</span>`
            )).join('');
        } else {
            avisoComida.classList.add('d-none');
            lblAvisosComida.innerHTML = '';
        }

        const avisoIncidencias = document.getElementById('avisoIncidencias');
        const lblIncidencias = document.getElementById('lblIncidencias');
        document.getElementById('lblIncidenciasTotal').textContent = dataKpis.incidents_today;
        document.getElementById('lblIncidenciasMas').classList.toggle('d-none', dataKpis.incidents_today <= dataKpis.incident_alerts.length);
        avisoIncidencias.classList.toggle('d-none', dataKpis.incidents_today === 0);
        lblIncidencias.innerHTML = dataKpis.incident_alerts.map((i) => (
            `<span class="badge text-bg-dark border border-warning me-1 mb-1">[${escapeHtml(i.employee_no)}] ${escapeHtml(i.employee_name)}: ${escapeHtml(i.label)} · ${escapeHtml(i.at)}</span>`
        )).join('');

        chartPuntualidad.itemsSource = dataNuevo.charts.punctuality;
        chartDepartamentos.itemsSource = dataNuevo.charts.departments;
        lstMonitor.sourceCollection = dataNuevo.live_logs;
        lstMonitor.refresh();
    }

    async function refrescarDashboard() {
        try {
            const dataRespuesta = await apiFetch('{{ route("dashboard.data") }}', { method: 'GET' });
            pintarDashboard(dataRespuesta);
        } catch (err) {
            window.AppUI.showToast(err.message || 'No se pudo actualizar el dashboard.', 'danger');
        }
    }

    document.getElementById('btnRefrescar').addEventListener('click', refrescarDashboard);
    window.addEventListener('hikvision:synced', refrescarDashboard);
});
</script>
@endpush
