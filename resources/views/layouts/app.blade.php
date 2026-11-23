<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Checador Biométrico Hikvision')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body data-device-status-url="{{ route('device.status') }}"
      data-device-sync-url="{{ route('device.sync') }}"
      data-login-url="{{ route('login') }}">

<div class="app-shell">
    <!-- Sidebar fijo en escritorio -->
    <aside class="app-sidebar d-none d-lg-flex" aria-label="Barra lateral">
        @include('partials.sidebar-content', ['isMobileDrawer' => false])
    </aside>

    <!-- Drawer móvil nativo con <dialog> -->
    <dialog id="mobile-nav-drawer" class="mobile-drawer d-lg-none" aria-label="Menú de navegación">
        <div class="mobile-drawer-scroller">
            <aside class="mobile-drawer-sheet">
                @include('partials.sidebar-content', ['isMobileDrawer' => true])
            </aside>
        </div>
    </dialog>

    <div class="app-main">
        <header class="app-topbar px-3 px-lg-4 py-3 d-flex align-items-center justify-content-between gap-2 gap-md-3 flex-wrap">
            <div class="d-flex align-items-center gap-2 gap-sm-3 min-w-0 flex-grow-1">
                <button id="mobile-nav-open"
                        type="button"
                        class="btn btn-outline-secondary d-lg-none flex-shrink-0"
                        aria-label="Abrir menú de navegación"
                        aria-expanded="false"
                        aria-controls="mobile-nav-drawer">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="min-w-0">
                    <h1 class="h5 mb-0 fw-semibold">@yield('page_title', 'Panel Ejecutivo')</h1>
                    <p class="small text-secondary mb-0 d-none d-sm-block">@yield('page_subtitle', 'Control de asistencia, comidas y cumplimiento fiscal laboral')</p>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <div class="d-flex d-md-none align-items-center gap-1 px-2 py-1 rounded-pill border border-secondary-subtle bg-dark bg-opacity-50 small" data-device-status-widget title="Estado de la terminal Hikvision">
                    <span class="status-dot checking" data-device-dot aria-hidden="true"></span>
                    <span class="fw-semibold text-warning" data-device-label>Verif.</span>
                </div>
                <div class="d-none d-md-flex align-items-center gap-2 px-3 py-1 rounded-pill border border-secondary-subtle bg-dark bg-opacity-50 small" data-device-status-widget>
                    <span class="status-dot checking" data-device-dot aria-hidden="true"></span>
                    <span class="font-mono-tabular text-secondary">{{ parse_url(config('services.hikvision.url'), PHP_URL_HOST) ?: '192.168.0.69' }}</span>
                    <span class="fw-semibold text-warning" data-device-label>Verificando...</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-info d-inline-flex align-items-center gap-1" data-sync-now-btn aria-label="Sincronizar ahora" title="Sincronizar ahora">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Sincronizar</span>
                </button>
                @auth
                    <form method="POST" action="{{ route('logout') }}" class="d-flex align-items-center gap-2 ms-1">
                        @csrf
                        <span class="small text-secondary d-none d-md-inline" title="{{ auth()->user()->email }}">
                            <i class="bi bi-person-circle me-1" aria-hidden="true"></i>{{ auth()->user()->name }}
                        </span>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Cerrar sesión" aria-label="Cerrar sesión">
                            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        </button>
                    </form>
                @endauth
            </div>
        </header>

        <main class="flex-grow-1 p-3 p-lg-4">
            @if(session('status'))
                <div class="alert alert-success alert-dismissible fade show border-success-subtle" role="status">
                    <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar aviso"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Se detectaron errores:
                    </div>
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar error"></button>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<!-- Contenedor de toasts accesibles -->
<div id="app-toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;" aria-live="polite" aria-atomic="true"></div>

@stack('scripts')
</body>
</html>

