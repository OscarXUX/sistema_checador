<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sin permisos — Checador Hikvision</title>
    @vite(['resources/css/app.css'])
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
<main class="surface-card p-4 text-center" style="max-width: 440px;">
    <i class="bi bi-shield-lock fs-1 text-warning" aria-hidden="true"></i>
    <h1 class="h5 fw-semibold mt-3">Tu cuenta no tiene pantallas asignadas</h1>
    <p class="small text-secondary">Pide al administrador que te asigne un perfil con permisos.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button id="btnCerrarSesion" type="submit" class="btn btn-outline-secondary btn-sm">Cerrar sesión</button>
    </form>
</main>
</body>
</html>
