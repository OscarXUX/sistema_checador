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
    $kpis = $dashboardData['kpis'];
@endphp

<!-- 4 Tarjetas KPI de Bootstrap 5 -->
<section aria-label="Indicadores clave de asistencia" class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">Empleados en Planta</div>
                    <div class="display-6 fw-bold mt-1 font-mono-tabular" id="kpi-in-plant">{{ $kpis['in_plant'] }}</div>
                </div>
                <div class="kpi-icon bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                    <i class="bi bi-building-check" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                De <span class="text-light fw-semibold font-mono-tabular" id="kpi-active-total">{{ $kpis['active_employees'] }}</span> empleados activos en padrón
            </div>
        </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">En Hora de Comida</div>
                    <div class="display-6 fw-bold mt-1 font-mono-tabular" id="kpi-in-meal">{{ $kpis['in_meal'] }}</div>
                </div>
                <div class="kpi-icon bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">
                    <i class="bi bi-cup-hot" aria-hidden="true"></i>
                </div>
            </div>
            <div id="kpi-meal-alert-badge" class="small">
                @if($kpis['meal_exceeded_count'] > 0)
                    <span class="badge text-bg-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                        {{ $kpis['meal_exceeded_count'] }} exceso(s) de comida hoy
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
                    <div class="display-6 fw-bold mt-1 font-mono-tabular text-warning" id="kpi-late-today">{{ $kpis['late_today'] }}</div>
                </div>
                <div class="kpi-icon bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">
                    <i class="bi bi-alarm" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                Acumulado hoy: <span class="text-light fw-semibold font-mono-tabular" id="kpi-late-minutes">{{ $kpis['late_today_minutes'] }}</span> min fuera de tolerancia
            </div>
        </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <article class="kpi-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-secondary small fw-medium">Horas Extra (Semana)</div>
                    <div class="display-6 fw-bold mt-1 font-mono-tabular text-info">
                        <span id="kpi-overtime-hours">{{ number_format($kpis['weekly_overtime_hours'], 1) }}</span>
                        <span class="fs-5 text-secondary fw-normal">hrs</span>
                    </div>
                </div>
                <div class="kpi-icon bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                    <i class="bi bi-stopwatch" aria-hidden="true"></i>
                </div>
            </div>
            <div class="small text-secondary">
                Equivalente a <span class="text-light fw-semibold font-mono-tabular" id="kpi-overtime-minutes">{{ $kpis['weekly_overtime_minutes'] }}</span> minutos extras
            </div>
        </article>
    </div>
</section>

<!-- Alerta detallada de exceso de comida -->
<section id="meal-alerts-banner" class="mb-4 {{ count($kpis['meal_alerts']) === 0 ? 'd-none' : '' }}" aria-live="polite">
    <div class="alert alert-danger border-danger-subtle bg-danger bg-opacity-10 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-0">
        <div>
            <div class="fw-semibold text-danger-emphasis">
                <i class="bi bi-exclamation-octagon-fill me-2" aria-hidden="true"></i>
                Alerta Fiscal de Comida Excedida
            </div>
            <div class="small text-light mt-1" id="meal-alerts-list">
                @foreach($kpis['meal_alerts'] as $alert)
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

<!-- Gráficas con Wijmo FlexChart -->
<section class="row g-3 mb-4" aria-label="Gráficas analíticas">
    <div class="col-12 col-lg-6">
        <div class="surface-card p-3 p-md-4 h-100 isolated-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h6 fw-semibold mb-0">Puntualidad Semanal</h2>
                    <small class="text-secondary">Comparativa diaria: A tiempo vs Retardos (últimos 7 días)</small>
                </div>
                <span class="badge text-bg-dark border border-secondary-subtle">Wijmo FlexChart</span>
            </div>
            <div id="punctuality-chart" role="img" aria-label="Gráfica de puntualidad semanal"></div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="surface-card p-3 p-md-4 h-100 isolated-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h6 fw-semibold mb-0">Horas Ordinarias vs Extra por Departamento</h2>
                    <small class="text-secondary">Acumulado semanal por área operativa</small>
                </div>
                <span class="badge text-bg-dark border border-secondary-subtle">Wijmo FlexChart</span>
            </div>
            <div id="department-chart" role="img" aria-label="Gráfica comparativa de horas por departamento"></div>
        </div>
    </div>
</section>

<!-- Monitor en Vivo con Wijmo FlexGrid -->
<section class="surface-card p-3 p-md-4 isolated-panel" aria-label="Monitor de checadas en vivo">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0">Monitor en Vivo de Checadas (Hoy)</h2>
            <small class="text-secondary">
                Última actualización: <span id="dashboard-generated-at" class="font-mono-tabular">{{ $dashboardData['generated_at'] }}</span>
            </small>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <search>
                <form id="live-filter-form" class="d-flex align-items-center">
                    <label for="live-search-input" class="visually-hidden">Filtrar checadas del día</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-dark border-secondary-subtle text-secondary">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input id="live-search-input"
                               type="search"
                               class="form-control bg-dark border-secondary-subtle"
                               placeholder="Filtrar empleado, depto, evento..."
                               autocorrect="off"
                               autocapitalize="none"
                               spellcheck="false">
                    </div>
                </form>
            </search>
            <button id="refresh-dashboard-btn" type="button" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refrescar
            </button>
        </div>
    </div>

    <div id="live-monitor-grid" aria-label="Tabla en vivo de checadas biométricas"></div>
</section>
@endsection

@push('scripts')
<script id="dashboard-initial-data" type="application/json">@json($dashboardData)</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid, chart: wjChart } = window.AppUI.wijmo;
    const { apiFetch, escapeHtml } = window.AppUI;

    let payload = JSON.parse(document.getElementById('dashboard-initial-data').textContent);

    // 1. FlexChart: Puntualidad semanal
    const punctualityChart = new wjChart.FlexChart('#punctuality-chart', {
        itemsSource: payload.charts.punctuality,
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
    punctualityChart.axisY.min = 0;
    punctualityChart.axisY.format = 'n0';

    // 2. FlexChart: Horas Ordinarias vs Extra por Departamento
    const departmentChart = new wjChart.FlexChart('#department-chart', {
        itemsSource: payload.charts.departments,
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
    departmentChart.axisY.min = 0;
    departmentChart.axisY.title = 'Horas';

    // 3. FlexGrid: Monitor en Vivo
    const liveView = new wjCore.CollectionView(payload.live_logs);
    const searchInput = document.getElementById('live-search-input');
    document.getElementById('live-filter-form')?.addEventListener('submit', (e) => e.preventDefault());

    liveView.filter = (item) => {
        const term = (searchInput.value || '').trim().toLowerCase();
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

    searchInput.addEventListener('input', () => liveView.refresh());

    const liveGrid = new wjGrid.FlexGrid('#live-monitor-grid', {
        itemsSource: liveView,
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

    const eventBadgeMap = {
        check_in: 'text-bg-success',
        break_out: 'text-bg-warning text-dark',
        break_in: 'text-bg-info text-dark',
        check_out: 'text-bg-primary',
        unclassified: 'text-bg-secondary',
    };

    const statusBadgeMap = {
        on_time: 'border-success text-success',
        late: 'border-warning text-warning',
        in_meal: 'border-info text-info',
        meal_exceeded: 'border-danger text-danger',
        incomplete: 'border-secondary text-secondary',
        absent: 'border-danger text-danger',
    };

    liveGrid.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const rowItem = s.rows[e.row]?.dataItem;
        if (!rowItem) return;

        if (col.binding === 'event_label') {
            const cls = eventBadgeMap[rowItem.event_type] || 'text-bg-secondary';
            e.cell.innerHTML = `<span class="badge ${cls}">${escapeHtml(rowItem.event_label)}</span>`;
        } else if (col.binding === 'daily_status_label') {
            const cls = statusBadgeMap[rowItem.daily_status] || 'border-secondary text-secondary';
            e.cell.innerHTML = `<span class="badge bg-dark border ${cls}">${escapeHtml(rowItem.daily_status_label)}</span>`;
        }
    });

    function renderDashboardData(next) {
        payload = next;
        const k = next.kpis;

        document.getElementById('kpi-in-plant').textContent = k.in_plant;
        document.getElementById('kpi-active-total').textContent = k.active_employees;
        document.getElementById('kpi-in-meal').textContent = k.in_meal;
        document.getElementById('kpi-late-today').textContent = k.late_today;
        document.getElementById('kpi-late-minutes').textContent = k.late_today_minutes;
        document.getElementById('kpi-overtime-hours').textContent = Number(k.weekly_overtime_hours).toFixed(1);
        document.getElementById('kpi-overtime-minutes').textContent = k.weekly_overtime_minutes;
        document.getElementById('dashboard-generated-at').textContent = next.generated_at;

        const alertBadge = document.getElementById('kpi-meal-alert-badge');
        if (k.meal_exceeded_count > 0) {
            alertBadge.innerHTML = `<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>${escapeHtml(k.meal_exceeded_count)} exceso(s) de comida hoy</span>`;
        } else {
            alertBadge.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Sin excesos de tiempo</span>`;
        }

        const banner = document.getElementById('meal-alerts-banner');
        const listEl = document.getElementById('meal-alerts-list');
        if (k.meal_alerts.length > 0) {
            banner.classList.remove('d-none');
            listEl.innerHTML = k.meal_alerts.map((a) => (
                `<span class="badge text-bg-dark border border-danger me-1 mb-1">${escapeHtml(a.employee_name)}: ${escapeHtml(a.elapsed_minutes)} / ${escapeHtml(a.allowed_minutes)} min${a.active ? ' (En curso)' : ''}</span>`
            )).join('');
        } else {
            banner.classList.add('d-none');
            listEl.innerHTML = '';
        }

        punctualityChart.itemsSource = next.charts.punctuality;
        departmentChart.itemsSource = next.charts.departments;
        liveView.sourceCollection = next.live_logs;
        liveView.refresh();
    }

    async function refreshDashboard() {
        try {
            const fresh = await apiFetch('{{ route("dashboard.data") }}', { method: 'GET' });
            renderDashboardData(fresh);
        } catch (err) {
            window.AppUI.showToast(err.message || 'No se pudo actualizar el dashboard.', 'danger');
        }
    }

    document.getElementById('refresh-dashboard-btn').addEventListener('click', refreshDashboard);
    window.addEventListener('hikvision:synced', refreshDashboard);
});
</script>
@endpush
