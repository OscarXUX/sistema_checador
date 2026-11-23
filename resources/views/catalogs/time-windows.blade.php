@extends('layouts.app')

@section('title', 'Ventanas de horario — Checador Hikvision')
@section('page_title', 'Ventanas de horario')
@section('page_subtitle', 'Por turno: de qué hora a qué hora una checada es entrada y cuándo es salida; lo de en medio es comida')

@section('content')
<section class="surface-card p-3 p-md-4">
    <p class="text-secondary small mb-3">
        Si un turno no tiene ventanas propias se usan las de omisión: entrada de {{ $dataOmision[0][0] }} min antes a {{ $dataOmision[0][1] }} min después de su hora,
        y salida de {{ $dataOmision[1][0] }} min antes a {{ $dataOmision[1][1] }} min después. Una checada a menos de los minutos de "Repetida" de la anterior
        se ignora y queda como incidencia. Al guardar se recalculan los resúmenes del turno.
    </p>

    <div tabindex="0" role="region" aria-labelledby="lbl-ventanas" class="table-responsive">
        <h2 id="lbl-ventanas" class="visually-hidden">Ventanas por turno</h2>
        <table id="grid-ventanas" class="table table-dark align-middle mb-0">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th scope="col">Turno</th>
                    <th scope="col">Entrada (desde – hasta)</th>
                    <th scope="col">Salida (desde – hasta)</th>
                    <th scope="col">Repetida (min)</th>
                    <th scope="col">Por omisión</th>
                    @can('catalogos.administrar')<th scope="col" class="text-end">Acción</th>@endcan
                </tr>
            </thead>
            <tbody>
                @forelse($lstTurnos as $turno)
                    @php($dataHoras = $turno->windowTimes())
                    @php($strForm = 'frm-ventana-'.$turno->id)
                    <tr>
                        <th scope="row">
                            <div class="fw-semibold">{{ $turno->name }}</div>
                            <small class="text-secondary font-mono-tabular">{{ $turno->schedule_label }}</small>
                        </th>
                        @foreach(['entry' => 'entrada', 'exit' => 'salida'] as $strCampo => $strTipo)
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <label for="txt-{{ $strCampo }}-inicio-{{ $turno->id }}" class="visually-hidden">{{ ucfirst($strTipo) }} desde, {{ $turno->name }}</label>
                                    <input id="txt-{{ $strCampo }}-inicio-{{ $turno->id }}" form="{{ $strForm }}" name="{{ $strCampo }}_window_start" type="time" class="form-control form-control-sm font-mono-tabular" value="{{ $dataHoras[$strTipo][0] }}" @cannot('catalogos.administrar') disabled @endcannot>
                                    <span class="text-secondary">–</span>
                                    <label for="txt-{{ $strCampo }}-fin-{{ $turno->id }}" class="visually-hidden">{{ ucfirst($strTipo) }} hasta, {{ $turno->name }}</label>
                                    <input id="txt-{{ $strCampo }}-fin-{{ $turno->id }}" form="{{ $strForm }}" name="{{ $strCampo }}_window_end" type="time" class="form-control form-control-sm font-mono-tabular" value="{{ $dataHoras[$strTipo][1] }}" @cannot('catalogos.administrar') disabled @endcannot>
                                </div>
                            </td>
                        @endforeach
                        <td style="max-width: 6rem;">
                            <label for="txt-repetida-{{ $turno->id }}" class="visually-hidden">Minutos para considerar repetida, {{ $turno->name }}</label>
                            <input id="txt-repetida-{{ $turno->id }}" form="{{ $strForm }}" name="repeat_threshold_minutes" type="number" min="0" max="30" class="form-control form-control-sm font-mono-tabular" value="{{ $turno->repeat_threshold_minutes }}" @cannot('catalogos.administrar') disabled @endcannot>
                        </td>
                        <td>
                            <div class="form-check mb-0">
                                <input id="ck-omision-{{ $turno->id }}" form="{{ $strForm }}" name="use_defaults" type="checkbox" class="form-check-input" value="1" @checked(! $turno->hasCustomWindows()) @cannot('catalogos.administrar') disabled @endcannot>
                                <label for="ck-omision-{{ $turno->id }}" class="form-check-label small">Usar valores por omisión</label>
                            </div>
                        </td>
                        @can('catalogos.administrar')
                            <td class="text-end">
                                <form id="{{ $strForm }}" method="POST" action="{{ route('catalogs.windows.update', $turno) }}">
                                    @csrf
                                    @method('PUT')
                                    <button id="btn-guardar-ventana-{{ $turno->id }}" type="submit" class="btn btn-outline-light btn-sm">Guardar</button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-secondary py-4">No hay turnos registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="small text-secondary mt-3 mb-0">Para usar horas propias, quita la marca de "Usar valores por omisión", cambia las horas y guarda.</p>
</section>
@endsection
