@extends('layouts.app')

@section('title', 'Permisos — Checador Hikvision')
@section('page_title', 'Catálogo de permisos')
@section('page_subtitle', 'Las claves las define el sistema; aquí se cambia la descripción o se desactiva un permiso para todos')

@section('content')
<section class="surface-card p-3 p-md-4">
    <p class="text-secondary small">Un permiso desactivado no lo tiene nadie, ni el administrador. Los permisos nuevos se cargan con <code>php artisan permisos:sincronizar</code>.</p>
    <div tabindex="0" role="region" aria-label="Catálogo de permisos" class="table-responsive">
        <table id="grid-permisos" class="table table-dark align-middle mb-0">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th scope="col">Clave</th>
                    <th scope="col">Descripción</th>
                    <th scope="col">Detalle</th>
                    <th scope="col">Activo</th>
                    <th scope="col" class="text-end">Acción</th>
                </tr>
            </thead>
            @foreach($lstPermisosPorModulo as $modulo => $lstPermisos)
                <tbody>
                    <tr><th colspan="5" scope="rowgroup" class="text-info small text-uppercase pt-3">{{ $dataModulos[$modulo] ?? $modulo }}</th></tr>
                    @foreach($lstPermisos as $permiso)
                        <tr>
                            <th scope="row" class="fw-normal font-mono-tabular small">{{ $permiso->key }}</th>
                            <td>
                                <label for="txt-descripcion-{{ $permiso->id }}" class="visually-hidden">Descripción de {{ $permiso->key }}</label>
                                <input id="txt-descripcion-{{ $permiso->id }}" form="frm-permiso-{{ $permiso->id }}" name="description" type="text" class="form-control form-control-sm" maxlength="150" value="{{ $permiso->description }}" required>
                            </td>
                            <td>
                                <label for="txt-detalle-{{ $permiso->id }}" class="visually-hidden">Detalle de {{ $permiso->key }}</label>
                                <input id="txt-detalle-{{ $permiso->id }}" form="frm-permiso-{{ $permiso->id }}" name="details" type="text" class="form-control form-control-sm" maxlength="500" value="{{ $permiso->details }}">
                            </td>
                            <td>
                                <input form="frm-permiso-{{ $permiso->id }}" type="hidden" name="is_active" value="0">
                                <div class="form-check form-switch mb-0">
                                    <input id="ck-activo-{{ $permiso->id }}" form="frm-permiso-{{ $permiso->id }}" name="is_active" type="checkbox" class="form-check-input" value="1" @checked($permiso->is_active)>
                                    <label for="ck-activo-{{ $permiso->id }}" class="visually-hidden">Activo</label>
                                </div>
                            </td>
                            <td class="text-end">
                                <form id="frm-permiso-{{ $permiso->id }}" method="POST" action="{{ route('admin.permissions.update', $permiso) }}">
                                    @csrf
                                    @method('PUT')
                                    <button id="btn-guardar-permiso-{{ $permiso->id }}" type="submit" class="btn btn-outline-light btn-sm">Guardar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
</section>
@endsection
