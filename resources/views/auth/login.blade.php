<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <title>Iniciar sesión — Checador Hikvision</title>

    {{-- Solo estilos: el JS del sistema consulta rutas protegidas --}}
    @vite(['resources/css/app.css'])
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
<main class="w-100" style="max-width: 400px;">
    <div class="d-flex align-items-center gap-2 mb-4 justify-content-center">
        <span class="brand-badge" aria-hidden="true">
            <i class="bi bi-fingerprint"></i>
        </span>
        <div>
            <div class="fw-bold lh-sm">Checador ISAPI</div>
            <small class="text-secondary d-block" style="font-size: 0.72rem;">Control Fiscal Biométrico</small>
        </div>
    </div>

    <section class="surface-card p-4">
        <h1 class="h5 fw-semibold mb-1">Iniciar sesión</h1>
        <p class="small text-secondary mb-4">Usa la cuenta que te asignó el administrador.</p>

        @if($sessionExpired)
            <div class="alert alert-warning small" role="status">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Tu sesión expiró. Vuelve a iniciar sesión.
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger small" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf

            <div class="mb-3">
                <label for="txtCorreo" class="form-label small fw-medium">Correo</label>
                <input id="txtCorreo"
                       name="email"
                       type="email"
                       class="form-control"
                       value="{{ old('email') }}"
                       autocomplete="username"
                       autocorrect="off"
                       autocapitalize="none"
                       spellcheck="false"
                       maxlength="255"
                       required
                       autofocus>
            </div>

            <div class="mb-4">
                <label for="txtContrasena" class="form-label small fw-medium">Contraseña</label>
                <div class="input-group">
                    <input id="txtContrasena"
                           name="password"
                           type="password"
                           class="form-control"
                           autocomplete="current-password"
                           maxlength="255"
                           required>
                    <button id="btnVerContrasena"
                            type="button"
                            class="btn btn-outline-secondary border-secondary-subtle"
                            aria-pressed="false"
                            aria-label="Mostrar contraseña"
                            aria-controls="txtContrasena"
                            aria-describedby="lblAvisoContrasena">
                        <i id="iconoVerContrasena" class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <span id="lblAvisoContrasena" class="visually-hidden">Advertencia: esto mostrará tu contraseña en la pantalla.</span>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Entrar
            </button>
        </form>
    </section>

    <p class="small text-secondary text-center mt-3 mb-0">
        ¿Olvidaste tu contraseña? Pide al administrador que la restablezca.
    </p>
</main>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const txtContrasena = document.getElementById('txtContrasena');
    const btnVerContrasena = document.getElementById('btnVerContrasena');
    const iconoVerContrasena = document.getElementById('iconoVerContrasena');
    const frmLogin = txtContrasena?.closest('form');
    if (!txtContrasena || !btnVerContrasena || !iconoVerContrasena) return;

    const setVisible = (visible) => {
        txtContrasena.type = visible ? 'text' : 'password';
        btnVerContrasena.setAttribute('aria-pressed', visible ? 'true' : 'false');
        btnVerContrasena.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
        iconoVerContrasena.className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    };

    btnVerContrasena.addEventListener('click', () => {
        setVisible(txtContrasena.type === 'password');
    });

    frmLogin?.addEventListener('submit', () => {
        if (txtContrasena.type === 'text') {
            setVisible(false);
        }
    });
});
</script>
</body>
</html>
