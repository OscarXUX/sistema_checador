@extends('layouts.app')

@section('title', 'Usuarios — Checador Hikvision')
@section('page_title', 'Usuarios del sistema')
@section('page_subtitle', 'Cuentas que entran al sistema, su perfil y su estado')

@section('content')
<div class="row g-3">
    <section class="col-12 col-xl-8">
        <div class="surface-card p-3 p-md-4">
            <h2 id="lblUsuarios" class="h6 fw-semibold mb-3">Cuentas registradas</h2>
            <div tabindex="0" role="region" aria-labelledby="lblUsuarios" class="table-responsive">
                <table id="gridUsuarios" class="table table-dark table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Nombre</th>
                            <th scope="col">Correo</th>
                            <th scope="col">Perfil</th>
                            <th scope="col">Excepciones</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lstUsuarios as $usuario)
                            <tr>
                                <th scope="row" class="fw-semibold">{{ $usuario->name }}</th>
                                <td>{{ $usuario->email }}</td>
                                <td>
                                    {{ $usuario->profile?->name ?? 'Sin perfil' }}
                                    @if($usuario->profile?->is_admin)<span class="badge text-bg-primary ms-1">Admin</span>@endif
                                </td>
                                <td>{{ $usuario->permission_exceptions_count ?: '—' }}</td>
                                <td>
                                    @if($usuario->is_active)
                                        <span class="badge text-bg-success">Activa</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inactiva</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.users.edit', $usuario) }}" class="btn btn-outline-light btn-sm">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="col-12 col-xl-4">
        <form method="POST" action="{{ route('admin.users.store') }}" class="surface-card p-3 p-md-4" novalidate>
            @csrf
            <h2 class="h6 fw-semibold mb-3">Nueva cuenta</h2>

            <div class="mb-3">
                <label for="txtNombre" class="form-label">Nombre</label>
                <input id="txtNombre" name="name" type="text" class="form-control" maxlength="255" value="{{ old('name') }}" required>
            </div>
            <div class="mb-3">
                <label for="txtCorreo" class="form-label">Correo</label>
                <input id="txtCorreo" name="email" type="email" class="form-control" maxlength="255" value="{{ old('email') }}" autocomplete="off" required>
            </div>
            <div class="mb-3">
                <label for="cbPerfil" class="form-label">Perfil</label>
                <select id="cbPerfil" name="profile_id" class="form-select" required>
                    <option value="">Elige un perfil</option>
                    @foreach($lstPerfiles as $perfil)
                        <option value="{{ $perfil->id }}" @selected((int) old('profile_id') === $perfil->id)>{{ $perfil->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label for="txtContrasena" class="form-label">Contraseña</label>
                <input id="txtContrasena" name="password" type="password" class="form-control" minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" required>
                <div class="form-text">Mínimo {{ \App\Support\PasswordPolicy::MIN_LENGTH }} caracteres. Una frase es más fácil de recordar.</div>
            </div>
            <div class="mb-3">
                <label for="txtConfirmar" class="form-label">Confirmar contraseña</label>
                <input id="txtConfirmar" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
            </div>
            <button id="btnCrearUsuario" type="submit" class="btn btn-primary w-100">Crear cuenta</button>
        </form>
    </section>
</div>
@endsection
