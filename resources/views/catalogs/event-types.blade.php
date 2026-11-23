@extends('layouts.app')

@section('title', 'Tipos de evento — Checador Hikvision')
@section('page_title', 'Tipos de evento')
@section('page_subtitle', 'Cómo se califica cada entrada, salida y comida según sus minutos')

@section('content')
<div class="row g-3">
    <section class="col-12 {{ auth()->user()->can('catalogos.administrar') ? 'col-xl-8' : '' }}">
        <div class="surface-card p-3 p-md-4">
            <p class="text-secondary small">
                Entrada: minutos de retardo (0 si llegó dentro de la tolerancia del turno; si no, se cuentan desde la hora de entrada). Salida: minutos antes de la hora de salida.
                Comida: minutos por encima de la comida permitida. "Hasta" vacío = sin límite. Al guardar se actualizan todos los resúmenes.
            </p>
            <div tabindex="0" role="region" aria-labelledby="lblTipos" class="table-responsive">
                <h2 id="lblTipos" class="visually-hidden">Tipos de evento</h2>
                <table id="gridTiposEvento" class="table table-dark align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Nombre</th>
                            <th scope="col">Desde (min)</th>
                            <th scope="col">Hasta (min)</th>
                            <th scope="col">Color</th>
                            <th scope="col">Orden</th>
                            <th scope="col">Activo</th>
                            @can('catalogos.administrar')<th scope="col" class="text-end">Acción</th>@endcan
                        </tr>
                    </thead>
                    @foreach($dataAplicaA as $tipo => $titulo)
                        <tbody>
                            <tr><th colspan="7" scope="rowgroup" class="text-info small text-uppercase pt-3">{{ $titulo }}</th></tr>
                            @forelse($lstPorTipo->get($tipo, collect()) as $evento)
                                @php($idFormulario = 'frmEvento'.$evento->id)
                                <tr>
                                    <th scope="row" class="fw-normal">
                                        <label for="txtNombre{{ $evento->id }}" class="visually-hidden">Nombre</label>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge text-bg-{{ $evento->color }}" aria-hidden="true">&nbsp;</span>
                                            <input id="txtNombre{{ $evento->id }}" form="{{ $idFormulario }}" name="name" type="text" class="form-control form-control-sm" maxlength="80" value="{{ $evento->name }}" required @cannot('catalogos.administrar') disabled @endcannot>
                                        </div>
                                    </th>
                                    <td>
                                        <label for="txtDesde{{ $evento->id }}" class="visually-hidden">Desde</label>
                                        <input id="txtDesde{{ $evento->id }}" form="{{ $idFormulario }}" name="min_minutes" type="number" min="0" max="1440" class="form-control form-control-sm font-mono-tabular" value="{{ $evento->min_minutes }}" required @cannot('catalogos.administrar') disabled @endcannot>
                                    </td>
                                    <td>
                                        <label for="txtHasta{{ $evento->id }}" class="visually-hidden">Hasta</label>
                                        <input id="txtHasta{{ $evento->id }}" form="{{ $idFormulario }}" name="max_minutes" type="number" min="0" max="1440" class="form-control form-control-sm font-mono-tabular" value="{{ $evento->max_minutes }}" placeholder="Sin límite" @cannot('catalogos.administrar') disabled @endcannot>
                                    </td>
                                    <td>
                                        <label for="cbColor{{ $evento->id }}" class="visually-hidden">Color</label>
                                        <select id="cbColor{{ $evento->id }}" form="{{ $idFormulario }}" name="color" class="form-select form-select-sm" @cannot('catalogos.administrar') disabled @endcannot>
                                            @foreach($dataColores as $color => $nombreColor)
                                                <option value="{{ $color }}" @selected($evento->color === $color)>{{ $nombreColor }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td style="max-width: 5rem;">
                                        <label for="txtOrden{{ $evento->id }}" class="visually-hidden">Orden</label>
                                        <input id="txtOrden{{ $evento->id }}" form="{{ $idFormulario }}" name="sort_order" type="number" min="0" max="999" class="form-control form-control-sm font-mono-tabular" value="{{ $evento->sort_order }}" @cannot('catalogos.administrar') disabled @endcannot>
                                    </td>
                                    <td>
                                        <input form="{{ $idFormulario }}" type="hidden" name="is_active" value="0">
                                        <div class="form-check form-switch mb-0">
                                            <input id="ckActivo{{ $evento->id }}" form="{{ $idFormulario }}" name="is_active" type="checkbox" class="form-check-input" value="1" @checked($evento->is_active) @cannot('catalogos.administrar') disabled @endcannot>
                                            <label for="ckActivo{{ $evento->id }}" class="visually-hidden">Activo</label>
                                        </div>
                                    </td>
                                    @can('catalogos.administrar')
                                        <td class="text-end text-nowrap">
                                            <form id="{{ $idFormulario }}" method="POST" action="{{ route('catalogs.event-types.update', $evento) }}" class="d-inline">
                                                @csrf
                                                @method('PUT')
                                                <button id="btnGuardarEvento{{ $evento->id }}" type="submit" class="btn btn-outline-light btn-sm">Guardar</button>
                                            </form>
                                            @unless($evento->is_system)
                                                <form method="POST" action="{{ route('catalogs.event-types.destroy', $evento) }}" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button id="btnEliminarEvento{{ $evento->id }}" type="submit" class="btn btn-outline-danger btn-sm" aria-label="Eliminar {{ $evento->name }}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                                </form>
                                            @endunless
                                        </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-secondary small">No hay.</td></tr>
                            @endforelse
                        </tbody>
                    @endforeach
                </table>
            </div>
        </div>
    </section>

    @can('catalogos.administrar')
    <section class="col-12 col-xl-4">
        <form method="POST" action="{{ route('catalogs.event-types.store') }}" class="surface-card p-3 p-md-4" novalidate>
            @csrf
            <h2 class="h6 fw-semibold mb-3">Nuevo tipo de evento</h2>
            <div class="mb-3">
                <label for="txtNombreNuevo" class="form-label">Nombre</label>
                <input id="txtNombreNuevo" name="name" type="text" class="form-control" maxlength="80" value="{{ old('name') }}" required>
            </div>
            <div class="mb-3">
                <label for="cbAplicaNuevo" class="form-label">Se aplica a</label>
                <select id="cbAplicaNuevo" name="applies_to" class="form-select">
                    @foreach($dataAplicaA as $tipo => $titulo)
                        <option value="{{ $tipo }}" @selected(old('applies_to') === $tipo)>{{ $titulo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label for="txtDesdeNuevo" class="form-label">Desde (min)</label>
                    <input id="txtDesdeNuevo" name="min_minutes" type="number" min="0" max="1440" class="form-control" value="{{ old('min_minutes', 0) }}" required>
                </div>
                <div class="col-6">
                    <label for="txtHastaNuevo" class="form-label">Hasta (min)</label>
                    <input id="txtHastaNuevo" name="max_minutes" type="number" min="0" max="1440" class="form-control" value="{{ old('max_minutes') }}" placeholder="Sin límite">
                </div>
            </div>
            <div class="mb-3">
                <label for="cbColorNuevo" class="form-label">Color</label>
                <select id="cbColorNuevo" name="color" class="form-select">
                    @foreach($dataColores as $color => $nombreColor)
                        <option value="{{ $color }}" @selected(old('color') === $color)>{{ $nombreColor }}</option>
                    @endforeach
                </select>
            </div>
            <p class="form-text">No puede cruzarse con otro evento activo del mismo tipo: ajusta o desactiva primero el que ya cubre esos minutos.</p>
            <button id="btnCrearEvento" type="submit" class="btn btn-primary w-100">Crear tipo de evento</button>
        </form>
    </section>
    @endcan
</div>
@endsection
