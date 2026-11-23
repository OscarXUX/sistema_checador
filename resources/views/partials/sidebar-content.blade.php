@php
    $deviceUrl = config('services.hikvision.url', 'http://192.168.0.69');
    $deviceIp = parse_url($deviceUrl, PHP_URL_HOST) ?: $deviceUrl;
    $deviceSerial = config('services.hikvision.device_serial', 'DS-K1T804AEF');
@endphp

<div class="p-3 border-bottom border-secondary-subtle d-flex align-items-center justify-content-between gap-2">
    <a href="{{ route('home') }}" class="d-flex align-items-center gap-2 text-decoration-none text-light">
        <span class="brand-badge" aria-hidden="true">
            <i class="bi bi-fingerprint"></i>
        </span>
        <div>
            <div class="fw-bold lh-sm">Checador ISAPI</div>
            <small class="text-secondary d-block" style="font-size: 0.72rem;">Control Fiscal Biométrico</small>
        </div>
    </a>
    @if(!empty($isMobileDrawer))
        <button id="mobile-nav-close" type="button" class="btn btn-sm btn-outline-secondary" aria-label="Cerrar menú de navegación" autofocus>
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    @endif
</div>

<nav class="p-3 flex-grow-1 sidebar-nav" aria-label="Navegación principal">
    <div class="text-uppercase text-secondary fw-semibold mb-2 px-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">
        Operación y Auditoría
    </div>
    <ul class="nav flex-column gap-1">
        @can('dashboard.consultar')
        <li class="nav-item">
            <a href="{{ route('dashboard') }}"
               class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
                <span>Dashboard Ejecutivo</span>
            </a>
        </li>
        @endcan
        @can('empleados.consultar')
        <li class="nav-item">
            <a href="{{ route('employees.index') }}"
               class="nav-link {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span>Empleados y Huellas</span>
            </a>
        </li>
        @endcan
        @can('kardex.consultar')
        <li class="nav-item">
            <a href="{{ route('reports.kardex') }}"
               class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>
                <span>Kardex Fiscal</span>
            </a>
        </li>
        @endcan
        @can('turnos.consultar')
        <li class="nav-item">
            <a href="{{ route('shifts.index') }}"
               class="nav-link {{ request()->routeIs('shifts.*') ? 'active' : '' }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <span>Turnos y Tolerancias</span>
            </a>
        </li>
        @endcan
        @can('medios.consultar')
        <li class="nav-item">
            <a href="{{ route('marking-methods.index') }}"
               class="nav-link {{ request()->routeIs('marking-methods.*') ? 'active' : '' }}">
                <i class="bi bi-credit-card-2-front" aria-hidden="true"></i>
                <span>Medios de Marcaje</span>
            </a>
        </li>
        @endcan
    </ul>

    @can('catalogos.consultar')
    <div class="text-uppercase text-secondary fw-semibold mb-2 mt-4 px-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">
        Catálogos
    </div>
    <ul class="nav flex-column gap-1">
        <li class="nav-item">
            <a href="{{ route('catalogs.windows') }}"
               class="nav-link {{ request()->routeIs('catalogs.windows*') ? 'active' : '' }}">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <span>Ventanas de horario</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('catalogs.event-types') }}"
               class="nav-link {{ request()->routeIs('catalogs.event-types*') ? 'active' : '' }}">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <span>Tipos de evento</span>
            </a>
        </li>
    </ul>
    @endcan

    @can('administrar')
    <div class="text-uppercase text-secondary fw-semibold mb-2 mt-4 px-2" style="font-size: 0.7rem; letter-spacing: 0.08em;">
        Administración
    </div>
    <ul class="nav flex-column gap-1">
        <li class="nav-item">
            <a href="{{ route('admin.users') }}"
               class="nav-link {{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                <i class="bi bi-person-gear" aria-hidden="true"></i>
                <span>Usuarios</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('admin.profiles') }}"
               class="nav-link {{ request()->routeIs('admin.profiles*') ? 'active' : '' }}">
                <i class="bi bi-person-badge" aria-hidden="true"></i>
                <span>Perfiles</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('admin.permissions') }}"
               class="nav-link {{ request()->routeIs('admin.permissions*') ? 'active' : '' }}">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span>Permisos</span>
            </a>
        </li>
    </ul>
    @endcan
</nav>

<div class="p-3 border-top border-secondary-subtle">
    <div class="p-3 rounded-3 border border-secondary-subtle bg-dark bg-opacity-50" data-device-status-widget>
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-semibold text-light d-flex align-items-center gap-2">
                <span class="status-dot checking" data-device-dot aria-hidden="true"></span>
                <span>Terminal Hikvision</span>
            </span>
            <span class="small fw-semibold text-warning" data-device-label>Verificando...</span>
        </div>
        <div class="small text-secondary font-mono-tabular mb-3" style="font-size: 0.75rem;" data-device-meta>
            {{ $deviceSerial }} · {{ $deviceIp }}
        </div>
        <div class="d-grid gap-2">
            @can('terminal.sincronizar')
            <button type="button" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center gap-2" data-sync-now-btn>
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                <span>Sincronizar Ahora</span>
            </button>
            @endcan
            <button type="button" class="btn btn-outline-secondary btn-sm" data-refresh-device-status>
                <i class="bi bi-wifi me-1" aria-hidden="true"></i>Probar Conectividad
            </button>
        </div>
    </div>
</div>

