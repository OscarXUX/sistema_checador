<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sin permiso — Checador Hikvision</title>
    @vite(['resources/css/app.css'])
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
<main class="surface-card p-4 text-center" style="max-width: 440px;">
    <i class="bi bi-slash-circle fs-1 text-danger" aria-hidden="true"></i>
    <h1 class="h5 fw-semibold mt-3">No tienes permiso para entrar aquí</h1>
    <p class="small text-secondary">Si lo necesitas, pide al administrador que lo agregue a tu perfil.</p>
    <a id="btnRegresar" href="{{ url('/inicio') }}" class="btn btn-outline-secondary btn-sm">Ir a mi inicio</a>
</main>
</body>
</html>
