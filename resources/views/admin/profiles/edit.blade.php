@extends('layouts.app')

@section('title', 'Editar perfil — Checador Hikvision')
@section('page_title', 'Perfil: '.$profile->name)
@section('page_subtitle', $profile->users_count.' usuario(s) con este perfil')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.profiles') }}" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Perfiles</a>
</div>

<form method="POST" action="{{ route('admin.profiles.update', $profile) }}" class="surface-card p-3 p-md-4" novalidate>
    @csrf
    @method('PUT')
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <label for="txtNombrePerfil" class="form-label">Nombre</label>
            <input id="txtNombrePerfil" name="name" type="text" class="form-control" maxlength="80" value="{{ old('name', $profile->name) }}" required>
        </div>
        <div class="col-12 col-md-6">
            <label for="txtDescripcionPerfil" class="form-label">Descripción</label>
            <input id="txtDescripcionPerfil" name="description" type="text" class="form-control" maxlength="255" value="{{ old('description', $profile->description) }}">
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
                <input type="hidden" name="is_active" value="0">
                <input id="ckPerfilActivo" name="is_active" type="checkbox" class="form-check-input" value="1" @checked(old('is_active', $profile->is_active)) @disabled($profile->is_admin)>
                @if($profile->is_admin)<input type="hidden" name="is_active" value="1">@endif
                <label for="ckPerfilActivo" class="form-check-label">Activo</label>
            </div>
        </div>
    </div>

    @if($profile->is_admin)
        <p class="text-secondary mb-3">Este es el perfil de administrador: tiene todos los permisos activos y además entra a Administración. Su lista no se edita.</p>
    @else
        <h2 class="h6 fw-semibold">Permisos</h2>
        <div class="row g-3 mb-3">
            @foreach($lstPermisosPorModulo as $modulo => $lstPermisos)
                <fieldset class="col-12 col-md-6 col-xl-4">
                    <legend class="fs-6 text-info text-uppercase small fw-semibold">{{ $dataModulos[$modulo] ?? $modulo }}</legend>
                    @foreach($lstPermisos as $permiso)
                        <div class="form-check mb-1">
                            <input id="ckPermiso{{ $permiso->id }}" name="permissions[]" type="checkbox" class="form-check-input" value="{{ $permiso->id }}"
                                @checked(in_array($permiso->id, array_map('intval', old('permissions', $lstMarcados)), true))>
                            <label for="ckPermiso{{ $permiso->id }}" class="form-check-label">
                                {{ $permiso->description }}
                                @unless($permiso->is_active)<span class="badge text-bg-secondary ms-1">Desactivado</span>@endunless
                            </label>
                            @if($permiso->details)<div class="form-text mt-0">{{ $permiso->details }}</div>@endif
                        </div>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
    @endif

    <button id="btnGuardarPerfil" type="submit" class="btn btn-primary">Guardar perfil</button>
</form>

@unless($profile->is_admin)
    <form method="POST" action="{{ route('admin.profiles.destroy', $profile) }}" class="mt-3">
        @csrf
        @method('DELETE')
        <button id="btnEliminarPerfil" type="submit" class="btn btn-outline-danger btn-sm" @disabled($profile->users_count > 0)>Eliminar perfil</button>
        @if($profile->users_count > 0)
            <small class="text-secondary ms-2">Tiene usuarios: cámbialos de perfil o desactívalo.</small>
        @endif
    </form>
@endunless
@endsection
