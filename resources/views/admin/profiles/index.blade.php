@extends('layouts.app')

@section('title', 'Perfiles — Checador Hikvision')
@section('page_title', 'Perfiles')
@section('page_subtitle', 'Qué puede hacer cada tipo de usuario')

@section('content')
<div class="row g-3">
    <section class="col-12 col-xl-8">
        <div class="surface-card p-3 p-md-4">
            <h2 id="lbl-perfiles" class="h6 fw-semibold mb-3">Catálogo de perfiles</h2>
            <div tabindex="0" role="region" aria-labelledby="lbl-perfiles" class="table-responsive">
                <table id="grid-perfiles" class="table table-dark table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Perfil</th>
                            <th scope="col">Permisos</th>
                            <th scope="col">Usuarios</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lstPerfiles as $perfil)
                            <tr>
                                <th scope="row" class="fw-semibold">
                                    {{ $perfil->name }}
                                    @if($perfil->description)<div class="small text-secondary fw-normal">{{ $perfil->description }}</div>@endif
                                </th>
                                <td>{{ $perfil->is_admin ? "Todos ({$intTotalPermisos})" : $perfil->permissions_count }}</td>
                                <td>{{ $perfil->users_count }}</td>
                                <td>
                                    @if($perfil->is_active)
                                        <span class="badge text-bg-success">Activo</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inactivo</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.profiles.edit', $perfil) }}" class="btn btn-outline-light btn-sm">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="col-12 col-xl-4">
        <form method="POST" action="{{ route('admin.profiles.store') }}" class="surface-card p-3 p-md-4" novalidate>
            @csrf
            <h2 class="h6 fw-semibold mb-3">Nuevo perfil</h2>
            <div class="mb-3">
                <label for="txt-nombre-perfil" class="form-label">Nombre</label>
                <input id="txt-nombre-perfil" name="name" type="text" class="form-control" maxlength="80" value="{{ old('name') }}" required>
            </div>
            <div class="mb-3">
                <label for="txt-descripcion-perfil" class="form-label">Descripción <small class="text-secondary">(opcional)</small></label>
                <input id="txt-descripcion-perfil" name="description" type="text" class="form-control" maxlength="255" value="{{ old('description') }}">
            </div>
            <button id="btn-crear-perfil" type="submit" class="btn btn-primary w-100">Crear y elegir permisos</button>
        </form>
    </section>
</div>
@endsection
