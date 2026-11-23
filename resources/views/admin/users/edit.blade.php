@extends('layouts.app')

@section('title', 'Editar usuario — Checador Hikvision')
@section('page_title', $user->name)
@section('page_subtitle', 'Datos de la cuenta, perfil y excepciones de permisos')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.users') }}" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Usuarios</a>
</div>

<div class="row g-3">
    <section class="col-12 col-xl-4">
        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="surface-card p-3 p-md-4" novalidate>
            @csrf
            @method('PUT')
            <h2 class="h6 fw-semibold mb-3">Cuenta</h2>

            <div class="mb-3">
                <label for="txtNombre" class="form-label">Nombre</label>
                <input id="txtNombre" name="name" type="text" class="form-control" maxlength="255" value="{{ old('name', $user->name) }}" required>
            </div>
            <div class="mb-3">
                <label for="txtCorreo" class="form-label">Correo</label>
                <input id="txtCorreo" name="email" type="email" class="form-control" maxlength="255" value="{{ old('email', $user->email) }}" required>
            </div>
            <div class="mb-3">
                <label for="cbPerfil" class="form-label">Perfil</label>
                <select id="cbPerfil" name="profile_id" class="form-select" required>
                    @foreach($lstPerfiles as $perfil)
                        <option value="{{ $perfil->id }}" @selected((int) old('profile_id', $user->profile_id) === $perfil->id)>
                            {{ $perfil->name }}{{ $perfil->is_active ? '' : ' (inactivo)' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-check form-switch mb-3">
                <input type="hidden" name="is_active" value="0">
                <input id="ckActiva" name="is_active" type="checkbox" class="form-check-input" value="1" @checked(old('is_active', $user->is_active))>
                <label for="ckActiva" class="form-check-label">Cuenta activa</label>
            </div>

            <fieldset class="border-top pt-3 mb-3">
                <legend class="fs-6 fw-semibold">Cambiar contraseña <small class="text-secondary fw-normal">(opcional)</small></legend>
                <div class="mb-2">
                    <label for="txtContrasena" class="form-label">Nueva contraseña</label>
                    <input id="txtContrasena" name="password" type="password" class="form-control" autocomplete="new-password">
                </div>
                <div>
                    <label for="txtConfirmar" class="form-label">Confirmar</label>
                    <input id="txtConfirmar" name="password_confirmation" type="password" class="form-control" autocomplete="new-password">
                </div>
                <div class="form-text">Al cambiarla o al desactivar la cuenta se cierran sus sesiones abiertas.</div>
            </fieldset>

            <button id="btnGuardarUsuario" type="submit" class="btn btn-primary w-100">Guardar cuenta</button>
        </form>
    </section>

    <section class="col-12 col-xl-8">
        <form method="POST" action="{{ route('admin.users.permissions', $user) }}" class="surface-card p-3 p-md-4">
            @csrf
            @method('PUT')
            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
                <div>
                    <h2 id="lblExcepciones" class="h6 fw-semibold mb-0">Excepciones de permisos</h2>
                    <small class="text-secondary">
                        @if($user->isAdmin())
                            El perfil {{ $user->profile->name }} tiene todos los permisos; las excepciones no aplican.
                        @else
                            Por defecto la cuenta tiene lo de su perfil. Aquí puedes dar o negar un permiso solo a esta persona.
                        @endif
                    </small>
                </div>
                <button id="btnGuardarExcepciones" type="submit" class="btn btn-primary btn-sm align-self-start" @disabled($user->isAdmin())>Guardar excepciones</button>
            </div>

            <div tabindex="0" role="region" aria-labelledby="lblExcepciones" class="table-responsive">
                <table id="gridExcepciones" class="table table-dark align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Permiso</th>
                            <th scope="col">Perfil</th>
                            <th scope="col">Excepción</th>
                            <th scope="col">Resultado</th>
                        </tr>
                    </thead>
                    @foreach($lstPermisosPorModulo as $modulo => $lstPermisos)
                        <tbody>
                            <tr><th colspan="4" scope="rowgroup" class="text-info small text-uppercase pt-3">{{ $dataModulos[$modulo] ?? $modulo }}</th></tr>
                            @foreach($lstPermisos as $permiso)
                                @php($modo = $dataExcepciones[$permiso->id] ?? 'profile')
                                <tr>
                                    <th scope="row" class="fw-normal">
                                        {{ $permiso->description }}
                                        <div class="small text-secondary font-mono-tabular">{{ $permiso->key }}{{ $permiso->is_active ? '' : ' · desactivado' }}</div>
                                    </th>
                                    <td>{{ in_array($permiso->id, $lstDelPerfil, true) || $user->isAdmin() ? 'Sí' : 'No' }}</td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="radiogroup" aria-label="Excepción para {{ $permiso->description }}">
                                            @foreach(['profile' => ['Según perfil', 'Perfil'], 'grant' => ['Dar', 'Dar'], 'deny' => ['Negar', 'Negar']] as $valor => [$texto, $sufijo])
                                                <input type="radio" class="btn-check" name="exceptions[{{ $permiso->id }}]" id="rb{{ $sufijo }}{{ $permiso->id }}" value="{{ $valor }}" @checked($modo === $valor) @disabled($user->isAdmin())>
                                                <label class="btn btn-outline-{{ $valor === 'deny' ? 'danger' : ($valor === 'grant' ? 'success' : 'light') }}" for="rb{{ $sufijo }}{{ $permiso->id }}">{{ $texto }}</label>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if(in_array($permiso->key, $lstEfectivos, true))
                                            <span class="badge text-bg-success">Lo tiene</span>
                                        @else
                                            <span class="badge text-bg-secondary">No lo tiene</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </form>
    </section>
</div>
@endsection
