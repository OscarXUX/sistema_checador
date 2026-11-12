<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Checador Biométrico Hikvision')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body data-device-status-url="{{ route('device.status') }}"
      data-device-sync-url="{{ route('device.sync') }}">

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
        <header class="app-topbar px-3 px-lg-4 py-3 d-flex align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <button id="mobile-nav-open"
                        type="button"
                        class="btn btn-outline-secondary d-lg-none"
                        aria-label="Abrir menú de navegación"
                        aria-expanded="false"
                        aria-controls="mobile-nav-drawer">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div>
                    <h1 class="h5 mb-0 fw-semibold">@yield('page_title', 'Panel Ejecutivo')</h1>
                    <p class="small text-secondary mb-0">@yield('page_subtitle', 'Control de asistencia, comidas y cumplimiento fiscal laboral')</p>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <div class="d-none d-md-flex align-items-center gap-2 px-3 py-1 rounded-pill border border-secondary-subtle bg-dark bg-opacity-50 small" data-device-status-widget>
                    <span class="status-dot checking" data-device-dot aria-hidden="true"></span>
                    <span class="font-mono-tabular text-secondary">{{ parse_url(config('services.hikvision.url'), PHP_URL_HOST) ?: '192.168.0.69' }}</span>
                    <span class="fw-semibold text-warning" data-device-label>Verificando...</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-info d-none d-sm-inline-flex align-items-center gap-1" data-sync-now-btn>
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>Sincronizar</span>
                </button>
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

