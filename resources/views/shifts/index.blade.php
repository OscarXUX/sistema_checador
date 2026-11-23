@extends('layouts.app')

@section('title', 'Turnos, Rotaciones y Excepciones — Checador Hikvision')
@section('page_title', 'Turnos, Rotaciones y Excepciones')
@section('page_subtitle', 'Catálogo de turnos, ciclos rotativos, asignaciones por prioridad y vista previa de 14 días')

@php
    $dataColoresOrigen = [
        \App\Services\ShiftScheduleResolver::SOURCE_EXCEPTION => 'border-danger text-danger',
        \App\Services\ShiftScheduleResolver::SOURCE_EMPLOYEE_ROTATION => 'border-info text-info',
        \App\Services\ShiftScheduleResolver::SOURCE_GROUP_ROTATION => 'border-warning text-warning',
        \App\Services\ShiftScheduleResolver::SOURCE_DEPARTMENT_ROTATION => 'border-primary text-primary',
        \App\Services\ShiftScheduleResolver::SOURCE_DEFAULT => 'border-secondary text-secondary',
        \App\Services\ShiftScheduleResolver::SOURCE_NONE => 'border-dark text-secondary',
    ];
@endphp

@section('content')
<section class="surface-card p-3 p-md-4">
    <div class="app-tablist" role="tablist" aria-label="Configuración de turnos y horarios">
        <button type="button"
                role="tab"
                id="btnTabTurnos"
                class="app-tab"
                data-tab="turnos"
                aria-selected="{{ $activeTab === 'turnos' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'turnos' ? '0' : '-1' }}">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Turnos
        </button>
        <button type="button"
                role="tab"
                id="btnTabRotaciones"
                class="app-tab"
                data-tab="rotaciones"
                aria-selected="{{ $activeTab === 'rotaciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'rotaciones' ? '0' : '-1' }}">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Rotaciones
            <span class="badge text-bg-secondary ms-1">{{ $lstRotaciones->count() }}</span>
        </button>
        <button type="button"
                role="tab"
                id="btnTabAsignaciones"
                class="app-tab"
                data-tab="asignaciones"
                aria-selected="{{ $activeTab === 'asignaciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'asignaciones' ? '0' : '-1' }}">
            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Asignaciones
            <span class="badge text-bg-secondary ms-1">{{ $lstAsignaciones->count() }}</span>
        </button>
        <button type="button"
                role="tab"
                id="btnTabExcepciones"
                class="app-tab"
                data-tab="excepciones"
                aria-selected="{{ $activeTab === 'excepciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'excepciones' ? '0' : '-1' }}">
            <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Excepciones
            <span class="badge text-bg-secondary ms-1">{{ $lstExcepciones->count() }}</span>
        </button>
    </div>

    <!-- Panel 1: Catálogo de Turnos -->
    <section role="tabpanel"
             id="tabTurnos"
             aria-labelledby="btnTabTurnos"
             data-panel="turnos"
             @if($activeTab !== 'turnos') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="lblCatalogoTurnos" class="h6 fw-semibold mb-0">Catálogo de Turnos</h2>
                    <small class="text-secondary">Los cálculos de retardo, comida y horas extra se basan en estas reglas</small>
                </div>
                @can('turnos.administrar')
                <button id="btnNuevoTurno" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Turno
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="lblCatalogoTurnos" class="shifts-table-wrapper">
                <table class="table table-dark table-hover align-middle mb-0 shifts-table">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Turno</th>
                            <th scope="col">Horario</th>
                            <th scope="col">Jornada Neta</th>
                            <th scope="col">Comida Máx.</th>
                            <th scope="col">Tolerancia</th>
                            <th scope="col">Tope Semanal</th>
                            <th scope="col">Empleados Base</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lstTurnos as $shift)
                            <tr>
                                <th scope="row" class="fw-semibold">{{ $shift->name }}</th>
                                <td data-label="Horario" class="font-mono-tabular">{{ $shift->schedule_label }}</td>
                                <td data-label="Jornada Neta" class="font-mono-tabular text-info">{{ number_format($shift->ordinaryDailyMinutes() / 60, 2) }} hrs/día</td>
                                <td data-label="Comida Máx." class="font-mono-tabular">{{ $shift->meal_duration_minutes }} min</td>
                                <td data-label="Tolerancia" class="font-mono-tabular">{{ $shift->tolerance_minutes }} min</td>
                                <td data-label="Tope Semanal" class="font-mono-tabular">{{ $shift->max_weekly_hours }} hrs</td>
                                <td data-label="Empleados Base"><span class="badge text-bg-secondary">{{ $shift->employees_count }}</span></td>
                                <td data-label="Acción" class="text-end">
                                    @can('turnos.administrar')
                                    <button type="button"
                                            class="btn btn-outline-light btn-sm btn-edit-shift"
                                            data-shift='@json($shift)'>
                                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar
                                    </button>
                                    @else
                                    <span class="small text-secondary">Solo consulta</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-secondary py-4">No hay turnos registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Panel 2: Rotaciones de Turnos -->
    <section role="tabpanel"
             id="tabRotaciones"
             aria-labelledby="btnTabRotaciones"
             data-panel="rotaciones"
             @if($activeTab !== 'rotaciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="lblRotaciones" class="h6 fw-semibold mb-0">Patrones de Rotación</h2>
                    <small class="text-secondary">Secuencias ordenadas de turnos (ej. 1→2→3, 3→2→1, 2→1→3) y días por ciclo</small>
                </div>
                @can('turnos.programar')
                <button id="btnNuevaRotacion" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto" @disabled($lstTurnos->isEmpty())>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Rotación
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="lblRotaciones" class="shifts-table-wrapper">
                <table class="table table-dark table-hover align-middle mb-0 shifts-table">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Rotación</th>
                            <th scope="col">Días por Ciclo</th>
                            <th scope="col">Secuencia en Orden</th>
                            <th scope="col">Asignaciones</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lstRotaciones as $rotation)
                            @php
                                $dataRotacion = [
                                    'id' => $rotation->id,
                                    'name' => $rotation->name,
                                    'description' => $rotation->description,
                                    'cycle_days' => $rotation->cycle_days,
                                    'is_active' => $rotation->is_active,
                                    'steps' => $rotation->steps->pluck('work_shift_id')->values()->all(),
                                ];
                            @endphp
                            <tr>
                                <th scope="row">
                                    <div class="fw-semibold">{{ $rotation->name }}</div>
                                    @if($rotation->description)
                                        <small class="text-secondary d-block">{{ $rotation->description }}</small>
                                    @endif
                                </th>
                                <td data-label="Días por Ciclo" class="font-mono-tabular">{{ $rotation->cycle_days }} días</td>
                                <td data-label="Secuencia">
                                    <div class="d-flex flex-wrap align-items-center gap-1 justify-content-end justify-content-md-start">
                                        @foreach($rotation->steps as $idx => $step)
                                            <span class="badge text-bg-dark border border-info text-info">
                                                {{ $idx + 1 }}. {{ $step->workShift?->name ?? 'Turno' }}
                                            </span>
                                            @if(!$loop->last)
                                                <i class="bi bi-arrow-right text-secondary small" aria-hidden="true"></i>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                                <td data-label="Asignaciones">
                                    <span class="badge text-bg-secondary">{{ $rotation->assignments_count }}</span>
                                </td>
                                <td data-label="Estado">
                                    <span class="badge {{ $rotation->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $rotation->is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td data-label="Acciones" class="text-end">
                                    @can('turnos.programar')
                                    <div class="d-inline-flex gap-1">
                                        <button type="button"
                                                class="btn btn-outline-light btn-sm btn-edit-rotation"
                                                data-rotation='@json($dataRotacion)'>
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                        <form method="POST" action="{{ route('shifts.rotations.destroy', $rotation) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar rotación" aria-label="Eliminar rotación {{ $rotation->name }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                    @else
                                    <span class="small text-secondary">Solo consulta</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-secondary py-4">No hay rotaciones registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Panel 3: Asignaciones (Empleado / Grupo / Departamento) + Grupos -->
    <section role="tabpanel"
             id="tabAsignaciones"
             aria-labelledby="btnTabAsignaciones"
             data-panel="asignaciones"
             @if($activeTab !== 'asignaciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="lblAsignaciones" class="h6 fw-semibold mb-0">Asignaciones de Rotación</h2>
                    <small class="text-secondary">Prioridad automática: Excepción &gt; Empleado &gt; Grupo &gt; Departamento &gt; Turno Base</small>
                </div>
                @can('turnos.programar')
                <div class="d-flex flex-wrap gap-2">
                    <button id="btnNuevoGrupo" type="button" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-people me-1" aria-hidden="true"></i>Nuevo Grupo
                    </button>
                    <button id="btnNuevaAsignacion" type="button" class="btn btn-primary btn-sm" @disabled($lstRotaciones->isEmpty())>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Asignar Rotación
                    </button>
                </div>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="lblAsignaciones" class="shifts-table-wrapper mb-4">
                <table class="table table-dark table-hover align-middle mb-0 shifts-table">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Destino</th>
                            <th scope="col">Nivel</th>
                            <th scope="col">Rotación</th>
                            <th scope="col">Vigencia</th>
                            <th scope="col">Paso Inicial</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lstAsignaciones as $assignment)
                            <tr>
                                <th scope="row" class="fw-semibold">{{ $assignment->targetLabel() }}</th>
                                <td data-label="Nivel">
                                    <span class="badge text-bg-dark border border-secondary text-light">
                                        {{ \App\Models\ShiftRotationAssignment::targetTypeLabel($assignment->target_type) }}
                                    </span>
                                </td>
                                <td data-label="Rotación">{{ $assignment->rotation?->name ?? '-' }}</td>
                                <td data-label="Vigencia" class="font-mono-tabular">
                                    {{ $assignment->starts_on?->format('d/m/Y') }}
                                    →
                                    {{ $assignment->ends_on?->format('d/m/Y') ?? 'Indefinida' }}
                                </td>
                                <td data-label="Paso Inicial" class="font-mono-tabular">Paso {{ $assignment->start_step_order }}</td>
                                <td data-label="Acción" class="text-end">
                                    @can('turnos.programar')
                                    <form method="POST" action="{{ route('shifts.assignments.destroy', $assignment) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Eliminar asignación">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Quitar
                                        </button>
                                    </form>
                                    @else
                                    <span class="small text-secondary">Solo consulta</span>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-secondary py-4">No hay rotaciones asignadas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pt-3 border-top border-secondary-subtle">
                <h3 id="lblGrupos" class="h6 fw-semibold mb-2">Grupos / Cuadrillas de Empleados</h3>
                <div tabindex="0" role="region" aria-labelledby="lblGrupos" class="shifts-table-wrapper">
                    <table class="table table-dark table-hover align-middle mb-0 shifts-table">
                        <thead>
                            <tr class="text-secondary small text-uppercase">
                                <th scope="col">Grupo</th>
                                <th scope="col">Integrantes</th>
                                <th scope="col">Descripción</th>
                                <th scope="col" class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lstGrupos as $group)
                                @php
                                    $dataGrupo = [
                                        'id' => $group->id,
                                        'name' => $group->name,
                                        'description' => $group->description,
                                        'employee_ids' => $group->employees->pluck('id')->values()->all(),
                                    ];
                                @endphp
                                <tr>
                                    <th scope="row" class="fw-semibold">{{ $group->name }}</th>
                                    <td data-label="Integrantes">
                                        <span class="badge text-bg-info">{{ $group->employees_count }} empleados</span>
                                    </td>
                                    <td data-label="Descripción" class="text-secondary">{{ $group->description ?: '-' }}</td>
                                    <td data-label="Acciones" class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            @can('turnos.programar')
                                            <button type="button"
                                                    class="btn btn-outline-light btn-sm btn-edit-group"
                                                    data-group='@json($dataGrupo)'>
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="{{ route('shifts.groups.destroy', $group) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Eliminar grupo {{ $group->name }}">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </div>
                                            @else
                                            <span class="small text-secondary">Solo consulta</span>
                                            @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-secondary py-3">Sin grupos registrados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- Panel 4: Excepciones Puntuales -->
    <section role="tabpanel"
             id="tabExcepciones"
             aria-labelledby="btnTabExcepciones"
             data-panel="excepciones"
             @if($activeTab !== 'excepciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="lblExcepciones" class="h6 fw-semibold mb-0">Excepciones por Empleado y Fechas</h2>
                    <small class="text-secondary">Máxima prioridad sobre cualquier rotación o turno asignado (guardias, permutas, cobertura)</small>
                </div>
                @can('turnos.programar')
                <button id="btnNuevaExcepcion" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto" @disabled($lstEmpleados->isEmpty() || $lstTurnos->isEmpty())>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Excepción
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="lblExcepciones" class="shifts-table-wrapper">
                <table class="table table-dark table-hover align-middle mb-0 shifts-table">
                    <thead>
                        <tr class="text-secondary small text-uppercase">
                            <th scope="col">Empleado</th>
                            <th scope="col">Turno Temporal</th>
                            <th scope="col">Rango de Fechas</th>
                            <th scope="col">Motivo</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lstExcepciones as $exception)
                            <tr>
                                <th scope="row" class="fw-semibold">
                                    [{{ $exception->employee?->hikvision_employee_no ?? '-' }}] {{ $exception->employee?->full_name ?? 'N/D' }}
                                </th>
                                <td data-label="Turno Temporal">
                                    {{ $exception->workShift?->name ?? '-' }}
                                    <small class="text-secondary font-mono-tabular">({{ $exception->workShift?->schedule_label ?? '-' }})</small>
                                </td>
                                <td data-label="Rango de Fechas" class="font-mono-tabular">
                                    {{ $exception->starts_on?->format('d/m/Y') }} — {{ $exception->ends_on?->format('d/m/Y') }}
                                </td>
                                <td data-label="Motivo">{{ $exception->reason ?: '-' }}</td>
                                <td data-label="Acción" class="text-end">
                                    @can('turnos.programar')
                                    <form method="POST" action="{{ route('shifts.exceptions.destroy', $exception) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Eliminar excepción">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Quitar
                                        </button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-secondary py-4">No hay excepciones registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</section>

<!-- Vista Previa de 14 Días (O2) -->
<section class="surface-card p-3 p-md-4 mt-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-1">Vista Previa de 14 Días (Horario Efectivo)</h2>
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge bg-dark border border-danger text-danger">Excepción</span>
                <span class="badge bg-dark border border-info text-info">Rot. Empleado</span>
                <span class="badge bg-dark border border-warning text-warning">Rot. Grupo</span>
                <span class="badge bg-dark border border-primary text-primary">Rot. Depto</span>
                <span class="badge bg-dark border border-secondary text-secondary">Base</span>
            </div>
        </div>

        <form method="GET" action="{{ route('shifts.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="tab" id="txtPestanaActiva" value="{{ $activeTab }}">
            <div class="col-6 col-sm-auto">
                <label for="dtVistaInicio" class="form-label small fw-medium mb-1">Inicio</label>
                <input id="dtVistaInicio" name="preview_start" type="date" class="form-control form-control-sm font-mono-tabular" value="{{ $previewStart }}">
            </div>
            <div class="col-6 col-sm-auto">
                <label for="cbVistaDepartamento" class="form-label small fw-medium mb-1">Departamento</label>
                <select id="cbVistaDepartamento" name="preview_department" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    @foreach($lstDepartamentos as $dept)
                        <option value="{{ $dept }}" @selected($previewDepartment === $dept)>{{ $dept }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-sm-auto">
                <button type="submit" class="btn btn-outline-info btn-sm w-100">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Actualizar Vista Previa
                </button>
            </div>
        </form>
    </div>

    <div class="schedule-preview-scroll" tabindex="0" role="region" aria-label="Calendario de vista previa de 14 días">
        <table class="table table-dark table-sm align-middle mb-0 schedule-preview-table">
            <thead>
                <tr class="text-secondary small">
                    <th scope="col" class="sticky-col">Empleado</th>
                    @foreach($dataVistaPrevia['dates'] as $day)
                        <th scope="col" class="text-center font-mono-tabular" style="min-width: 112px;">
                            <div>{{ $day->format('d/m') }}</div>
                            <div class="text-secondary" style="font-size: 0.7rem;">{{ $day->locale('es')->isoFormat('ddd') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($dataVistaPrevia['rows'] as $row)
                    <tr>
                        <th scope="row" class="sticky-col">
                            <div class="fw-semibold text-truncate" style="max-width: 210px;">
                                [{{ $row['employee']->hikvision_employee_no }}] {{ $row['employee']->full_name }}
                            </div>
                            <small class="text-secondary">{{ $row['employee']->department }}</small>
                        </th>
                        @foreach($row['days'] as $cell)
                            @php
                                $badgeClass = $dataColoresOrigen[$cell['source']] ?? 'border-secondary text-secondary';
                            @endphp
                            <td class="text-center p-2" title="{{ $cell['meta'] ?? '' }}">
                                <div class="fw-medium text-truncate" style="max-width: 108px;">{{ $cell['shift_name'] }}</div>
                                <div class="font-mono-tabular text-secondary" style="font-size: 0.7rem;">{{ $cell['schedule_label'] }}</div>
                                <span class="badge bg-dark border {{ $badgeClass }}" style="font-size: 0.65rem;">{{ $cell['source_label'] }}</span>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($dataVistaPrevia['dates']) + 1 }}" class="text-center text-secondary py-4">
                            No hay empleados para mostrar en la vista previa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<!-- Modal <dialog>: Turno Laboral -->
<dialog id="mdTurno" class="app-dialog" aria-labelledby="lblTituloTurno">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloTurno" class="h6 mb-0 fw-semibold">Nuevo Turno Laboral</h2>
            <button type="button" class="btn-close" data-close-dialog="mdTurno" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmTurno" method="POST" action="{{ route('shifts.store') }}">
            @csrf
            <input type="hidden" id="txtTurnoMetodo" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="txtTurnoNombre" class="form-label small fw-medium">Nombre del Turno <span class="text-danger">*</span></label>
                    <input id="txtTurnoNombre" name="name" type="text" class="form-control" required maxlength="100" autofocus>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtTurnoEntrada" class="form-label small fw-medium">Hora de Entrada <span class="text-danger">*</span></label>
                    <input id="dtTurnoEntrada" name="entry_time" type="time" class="form-control font-mono-tabular" required value="09:00">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtTurnoSalida" class="form-label small fw-medium">Hora de Salida <span class="text-danger">*</span></label>
                    <input id="dtTurnoSalida" name="exit_time" type="time" class="form-control font-mono-tabular" required value="18:00">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtTurnoComida" class="form-label small fw-medium">Comida (min)</label>
                    <input id="txtTurnoComida" name="meal_duration_minutes" type="number" class="form-control font-mono-tabular" min="15" max="180" required value="60">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtTurnoTolerancia" class="form-label small fw-medium">Tolerancia (min)</label>
                    <input id="txtTurnoTolerancia" name="tolerance_minutes" type="number" class="form-control font-mono-tabular" min="0" max="60" required value="10">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtTurnoTopeSemanal" class="form-label small fw-medium">Tope Semanal (h)</label>
                    <input id="txtTurnoTopeSemanal" name="max_weekly_hours" type="number" class="form-control font-mono-tabular" min="20" max="60" required value="48">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdTurno">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Turno</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Rotación de Turnos -->
<dialog id="mdRotacion" class="app-dialog" aria-labelledby="lblTituloRotacion">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloRotacion" class="h6 mb-0 fw-semibold">Nueva Rotación</h2>
            <button type="button" class="btn-close" data-close-dialog="mdRotacion" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmRotacion" method="POST" action="{{ route('shifts.rotations.store') }}">
            @csrf
            <input type="hidden" id="txtRotacionMetodo" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12 col-sm-8">
                    <label for="txtRotacionNombre" class="form-label small fw-medium">Nombre de la Rotación <span class="text-danger">*</span></label>
                    <input id="txtRotacionNombre" name="name" type="text" class="form-control" required maxlength="120" placeholder="Ej. Rol 3 Turnos Semanal">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtRotacionDias" class="form-label small fw-medium">Días por Ciclo <span class="text-danger">*</span></label>
                    <input id="txtRotacionDias" name="cycle_days" type="number" class="form-control font-mono-tabular" min="1" max="365" required value="7">
                </div>

                <div class="col-12">
                    <label for="txtRotacionDescripcion" class="form-label small fw-medium">Descripción</label>
                    <input id="txtRotacionDescripcion" name="description" type="text" class="form-control" maxlength="255">
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <span class="small fw-medium">Pasos en Orden <span class="text-danger">*</span></span>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="123" title="Secuencia 1 → 2 → 3">1→2→3</button>
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="321" title="Secuencia 3 → 2 → 1">3→2→1</button>
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="213" title="Secuencia 2 → 1 → 3">2→1→3</button>
                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" id="btnAgregarPaso">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Paso
                            </button>
                        </div>
                    </div>
                    <div id="contenedorPasos" class="d-flex flex-column gap-2"></div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input id="ckRotacionActiva" name="is_active" class="form-check-input" type="checkbox" value="1" checked>
                        <label class="form-check-label small" for="ckRotacionActiva">Rotación Activa</label>
                    </div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdRotacion">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Rotación</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Grupo de Empleados -->
<dialog id="mdGrupo" class="app-dialog" aria-labelledby="lblTituloGrupo">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloGrupo" class="h6 mb-0 fw-semibold">Nuevo Grupo de Empleados</h2>
            <button type="button" class="btn-close" data-close-dialog="mdGrupo" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmGrupo" method="POST" action="{{ route('shifts.groups.store') }}">
            @csrf
            <input type="hidden" id="txtGrupoMetodo" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="txtGrupoNombre" class="form-label small fw-medium">Nombre del Grupo <span class="text-danger">*</span></label>
                    <input id="txtGrupoNombre" name="name" type="text" class="form-control" required maxlength="120" placeholder="Ej. Cuadrilla A">
                </div>
                <div class="col-12">
                    <label for="txtGrupoDescripcion" class="form-label small fw-medium">Descripción</label>
                    <input id="txtGrupoDescripcion" name="description" type="text" class="form-control" maxlength="255">
                </div>
                <div class="col-12">
                    <div class="small fw-medium mb-2">Integrantes</div>
                    <div class="border border-secondary-subtle rounded p-2" style="max-height: 220px; overflow-y: auto;">
                        @foreach($lstEmpleados as $emp)
                            <div class="form-check">
                                <input class="form-check-input group-employee-checkbox"
                                       type="checkbox"
                                       name="employee_ids[]"
                                       value="{{ $emp->id }}"
                                       id="ckGrupoEmpleado{{ $emp->id }}">
                                <label class="form-check-label small" for="ckGrupoEmpleado{{ $emp->id }}">
                                    [{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}
                                    <span class="text-secondary">({{ $emp->department }})</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdGrupo">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Grupo</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Asignación de Rotación -->
<dialog id="mdAsignacion" class="app-dialog" aria-labelledby="lblTituloAsignacion">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloAsignacion" class="h6 mb-0 fw-semibold">Asignar Rotación</h2>
            <button type="button" class="btn-close" data-close-dialog="mdAsignacion" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmAsignacion" method="POST" action="{{ route('shifts.assignments.store') }}">
            @csrf

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="cbAsignarRotacion" class="form-label small fw-medium">Rotación <span class="text-danger">*</span></label>
                    <select id="cbAsignarRotacion" name="shift_rotation_id" class="form-select" required>
                        @foreach($lstRotaciones as $rotation)
                            <option value="{{ $rotation->id }}">{{ $rotation->name }} ({{ $rotation->cycle_days }}d)</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-5">
                    <label for="cbAsignarNivel" class="form-label small fw-medium">Nivel de Aplicación <span class="text-danger">*</span></label>
                    <select id="cbAsignarNivel" name="target_type" class="form-select" required>
                        <option value="employee">Empleado individual</option>
                        <option value="group">Grupo / Cuadrilla</option>
                        <option value="department">Departamento completo</option>
                    </select>
                </div>

                <div class="col-12 col-sm-7" id="contenedorAsignarEmpleado">
                    <label for="cbAsignarEmpleado" class="form-label small fw-medium">Empleado <span class="text-danger">*</span></label>
                    <select id="cbAsignarEmpleado" name="employee_id" class="form-select">
                        <option value="">Selecciona un empleado...</option>
                        @foreach($lstEmpleados as $emp)
                            <option value="{{ $emp->id }}">[{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-7 d-none" id="contenedorAsignarGrupo">
                    <label for="cbAsignarGrupo" class="form-label small fw-medium">Grupo <span class="text-danger">*</span></label>
                    <select id="cbAsignarGrupo" name="employee_group_id" class="form-select">
                        <option value="">Selecciona un grupo...</option>
                        @foreach($lstGrupos as $group)
                            <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->employees_count }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-7 d-none" id="contenedorAsignarDepartamento">
                    <label for="cbAsignarDepartamento" class="form-label small fw-medium">Departamento <span class="text-danger">*</span></label>
                    <select id="cbAsignarDepartamento" name="department" class="form-select">
                        <option value="">Selecciona un departamento...</option>
                        @foreach($lstDepartamentos as $dept)
                            <option value="{{ $dept }}">{{ $dept }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="dtAsignarInicio" class="form-label small fw-medium">Fecha Inicio <span class="text-danger">*</span></label>
                    <input id="dtAsignarInicio" name="starts_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="dtAsignarFin" class="form-label small fw-medium">Fecha Fin (opcional)</label>
                    <input id="dtAsignarFin" name="ends_on" type="date" class="form-control font-mono-tabular">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="txtAsignarPasoInicial" class="form-label small fw-medium">Paso Inicial</label>
                    <input id="txtAsignarPasoInicial" name="start_step_order" type="number" class="form-control font-mono-tabular" min="1" max="20" value="1">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdAsignacion">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Asignación</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Excepción de Turno -->
<dialog id="mdExcepcion" class="app-dialog" aria-labelledby="lblTituloExcepcion">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="lblTituloExcepcion" class="h6 mb-0 fw-semibold">Nueva Excepción de Turno</h2>
            <button type="button" class="btn-close" data-close-dialog="mdExcepcion" aria-label="Cerrar ventana"></button>
        </div>

        <form id="frmExcepcion" method="POST" action="{{ route('shifts.exceptions.store') }}">
            @csrf

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="cbExcepcionEmpleado" class="form-label small fw-medium">Empleado <span class="text-danger">*</span></label>
                    <select id="cbExcepcionEmpleado" name="employee_id" class="form-select" required>
                        <option value="">Selecciona un empleado...</option>
                        @foreach($lstEmpleados as $emp)
                            <option value="{{ $emp->id }}">[{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label for="cbExcepcionTurno" class="form-label small fw-medium">Turno Temporal <span class="text-danger">*</span></label>
                    <select id="cbExcepcionTurno" name="work_shift_id" class="form-select" required>
                        @foreach($lstTurnos as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->name }} ({{ $shift->schedule_label }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtExcepcionInicio" class="form-label small fw-medium">Desde <span class="text-danger">*</span></label>
                    <input id="dtExcepcionInicio" name="starts_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="dtExcepcionFin" class="form-label small fw-medium">Hasta <span class="text-danger">*</span></label>
                    <input id="dtExcepcionFin" name="ends_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12">
                    <label for="txtExcepcionMotivo" class="form-label small fw-medium">Motivo</label>
                    <input id="txtExcepcionMotivo" name="reason" type="text" class="form-control" maxlength="255" placeholder="Ej. Cobertura operativa / permiso">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="mdExcepcion">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Excepción</button>
            </div>
        </form>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="dataCatalogoTurnos" type="application/json">@json($lstTurnos->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'schedule' => $s->schedule_label])->values())</script>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const lstTurnos = JSON.parse(document.getElementById('dataCatalogoTurnos').textContent);

    // 1. Pestañas accesibles (Turnos, Rotaciones, Asignaciones, Excepciones) + hidden="until-found"
    const lstPestanas = Array.from(document.querySelectorAll('[role="tab"]'));
    const lstPaneles = Array.from(document.querySelectorAll('[role="tabpanel"]'));
    const txtPestanaActiva = document.getElementById('txtPestanaActiva');

    function activateTab(tabKey, moveFocus = false) {
        lstPestanas.forEach((t) => {
            const selected = t.dataset.tab === tabKey;
            t.setAttribute('aria-selected', selected ? 'true' : 'false');
            t.setAttribute('tabindex', selected ? '0' : '-1');
            if (selected && moveFocus) {
                t.focus();
            }
        });
        lstPaneles.forEach((p) => {
            if (p.dataset.panel === tabKey) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', 'until-found');
            }
        });
        if (txtPestanaActiva) {
            txtPestanaActiva.value = tabKey;
        }
    }

    lstPestanas.forEach((tab, idx) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.tab));
        tab.addEventListener('keydown', (e) => {
            let nextIdx = null;
            if (e.key === 'ArrowRight') nextIdx = (idx + 1) % lstPestanas.length;
            else if (e.key === 'ArrowLeft') nextIdx = (idx - 1 + lstPestanas.length) % lstPestanas.length;
            else if (e.key === 'Home') nextIdx = 0;
            else if (e.key === 'End') nextIdx = lstPestanas.length - 1;
            if (nextIdx !== null) {
                e.preventDefault();
                activateTab(lstPestanas[nextIdx].dataset.tab, true);
            }
        });
    });

    lstPaneles.forEach((panel) => {
        panel.addEventListener('beforematch', () => {
            activateTab(panel.dataset.panel);
        });
    });

    // Cierre unificado de <dialog>
    document.querySelectorAll('[data-close-dialog]').forEach((btnAccion) => {
        btnAccion.addEventListener('click', () => {
            document.getElementById(btnAccion.getAttribute('data-close-dialog'))?.close();
        });
    });

    // 2. Modal de Turno
    const mdTurno = document.getElementById('mdTurno');
    const frmTurno = document.getElementById('frmTurno');
    const txtTurnoMetodo = document.getElementById('txtTurnoMetodo');
    const shiftStoreUrl = '{{ route("shifts.store") }}';
    const shiftUpdateBase = '{{ url("/shifts") }}';

    document.getElementById('btnNuevoTurno')?.addEventListener('click', () => {
        frmTurno.action = shiftStoreUrl;
        txtTurnoMetodo.value = 'POST';
        document.getElementById('lblTituloTurno').textContent = 'Nuevo Turno Laboral';
        frmTurno.reset();
        mdTurno.showModal();
    });

    document.querySelectorAll('.btn-edit-shift').forEach((btnAccion) => {
        btnAccion.addEventListener('click', () => {
            const dataTurno = JSON.parse(btnAccion.getAttribute('data-shift'));
            frmTurno.action = `${shiftUpdateBase}/${dataTurno.id}`;
            txtTurnoMetodo.value = 'PUT';
            document.getElementById('lblTituloTurno').textContent = `Editar Turno: ${dataTurno.name}`;
            document.getElementById('txtTurnoNombre').value = dataTurno.name;
            document.getElementById('dtTurnoEntrada').value = String(dataTurno.entry_time).slice(0, 5);
            document.getElementById('dtTurnoSalida').value = String(dataTurno.exit_time).slice(0, 5);
            document.getElementById('txtTurnoComida').value = dataTurno.meal_duration_minutes;
            document.getElementById('txtTurnoTolerancia').value = dataTurno.tolerance_minutes;
            document.getElementById('txtTurnoTopeSemanal').value = dataTurno.max_weekly_hours;
            mdTurno.showModal();
        });
    });

    // 3. Modal de Rotaciones con pasos ordenados (1->2->3, 3->2->1, 2->1->3)
    const mdRotacion = document.getElementById('mdRotacion');
    const frmRotacion = document.getElementById('frmRotacion');
    const txtRotacionMetodo = document.getElementById('txtRotacionMetodo');
    const contenedorPasos = document.getElementById('contenedorPasos');
    const rotationStoreUrl = '{{ route("shifts.rotations.store") }}';
    const rotationUpdateBase = '{{ url("/shifts/rotations") }}';

    function renderRotationSteps(stepIds) {
        contenedorPasos.innerHTML = '';
        const lstPasos = stepIds.length ? stepIds : (lstTurnos[0] ? [lstTurnos[0].id] : []);
        lstPasos.forEach((selectedId, index) => {
            const filaPaso = document.createElement('div');
            filaPaso.className = 'd-flex align-items-center gap-2';

            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary font-mono-tabular';
            badge.textContent = `Paso ${index + 1}`;

            const cbPaso = document.createElement('select');
            cbPaso.name = 'steps[]';
            cbPaso.className = 'form-select form-select-sm flex-grow-1';
            cbPaso.required = true;
            lstTurnos.forEach((opt) => {
                const opcion = document.createElement('option');
                opcion.value = String(opt.id);
                opcion.textContent = `${opt.name} (${opt.schedule})`;
                if (Number(selectedId) === Number(opt.id)) {
                    opcion.selected = true;
                }
                cbPaso.appendChild(opcion);
            });

            const btnQuitarPaso = document.createElement('button');
            btnQuitarPaso.type = 'button';
            btnQuitarPaso.className = 'btn btn-outline-danger btn-sm';
            btnQuitarPaso.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            btnQuitarPaso.setAttribute('aria-label', `Quitar paso ${index + 1}`);
            btnQuitarPaso.disabled = lstPasos.length <= 1;
            btnQuitarPaso.addEventListener('click', () => {
                const lstIdsActuales = Array.from(contenedorPasos.querySelectorAll('select')).map((s) => Number(s.value));
                lstIdsActuales.splice(index, 1);
                renderRotationSteps(lstIdsActuales);
            });

            filaPaso.append(badge, cbPaso, btnQuitarPaso);
            contenedorPasos.appendChild(filaPaso);
        });
    }

    document.getElementById('btnAgregarPaso')?.addEventListener('click', () => {
        const lstIdsActuales = Array.from(contenedorPasos.querySelectorAll('select')).map((s) => Number(s.value));
        const nextDefault = lstTurnos[lstIdsActuales.length % Math.max(1, lstTurnos.length)]?.id ?? lstTurnos[0]?.id;
        if (nextDefault) {
            lstIdsActuales.push(nextDefault);
            renderRotationSteps(lstIdsActuales);
        }
    });

    document.querySelectorAll('[data-preset]').forEach((btnAccion) => {
        btnAccion.addEventListener('click', () => {
            const lstDigitos = String(btnAccion.getAttribute('data-preset')).split('').map(Number);
            const lstIdsPreset = lstDigitos
                .map((d) => lstTurnos[d - 1]?.id ?? lstTurnos[0]?.id)
                .filter(Boolean);
            if (lstIdsPreset.length > 0) {
                renderRotationSteps(lstIdsPreset);
            }
        });
    });

    document.getElementById('btnNuevaRotacion')?.addEventListener('click', () => {
        frmRotacion.action = rotationStoreUrl;
        txtRotacionMetodo.value = 'POST';
        document.getElementById('lblTituloRotacion').textContent = 'Nueva Rotación de Turnos';
        frmRotacion.reset();
        document.getElementById('txtRotacionDias').value = '7';
        document.getElementById('ckRotacionActiva').checked = true;
        renderRotationSteps(lstTurnos.slice(0, 3).map((s) => s.id));
        mdRotacion.showModal();
    });

    document.querySelectorAll('.btn-edit-rotation').forEach((btnAccion) => {
        btnAccion.addEventListener('click', () => {
            const dataRotacion = JSON.parse(btnAccion.getAttribute('data-rotation'));
            frmRotacion.action = `${rotationUpdateBase}/${dataRotacion.id}`;
            txtRotacionMetodo.value = 'PUT';
            document.getElementById('lblTituloRotacion').textContent = `Editar Rotación: ${dataRotacion.name}`;
            document.getElementById('txtRotacionNombre').value = dataRotacion.name;
            document.getElementById('txtRotacionDescripcion').value = dataRotacion.description || '';
            document.getElementById('txtRotacionDias').value = dataRotacion.cycle_days;
            document.getElementById('ckRotacionActiva').checked = Boolean(dataRotacion.is_active);
            renderRotationSteps(dataRotacion.steps || []);
            mdRotacion.showModal();
        });
    });

    // 4. Modal de Grupo de Empleados
    const mdGrupo = document.getElementById('mdGrupo');
    const frmGrupo = document.getElementById('frmGrupo');
    const txtGrupoMetodo = document.getElementById('txtGrupoMetodo');
    const groupStoreUrl = '{{ route("shifts.groups.store") }}';
    const groupUpdateBase = '{{ url("/shifts/groups") }}';

    document.getElementById('btnNuevoGrupo')?.addEventListener('click', () => {
        frmGrupo.action = groupStoreUrl;
        txtGrupoMetodo.value = 'POST';
        document.getElementById('lblTituloGrupo').textContent = 'Nuevo Grupo de Empleados';
        frmGrupo.reset();
        document.querySelectorAll('.group-employee-checkbox').forEach((ckEmpleado) => { ckEmpleado.checked = false; });
        mdGrupo.showModal();
    });

    document.querySelectorAll('.btn-edit-group').forEach((btnAccion) => {
        btnAccion.addEventListener('click', () => {
            const dataGrupo = JSON.parse(btnAccion.getAttribute('data-group'));
            frmGrupo.action = `${groupUpdateBase}/${dataGrupo.id}`;
            txtGrupoMetodo.value = 'PUT';
            document.getElementById('lblTituloGrupo').textContent = `Editar Grupo: ${dataGrupo.name}`;
            document.getElementById('txtGrupoNombre').value = dataGrupo.name;
            document.getElementById('txtGrupoDescripcion').value = dataGrupo.description || '';
            const lstSeleccionados = new Set((dataGrupo.employee_ids || []).map(Number));
            document.querySelectorAll('.group-employee-checkbox').forEach((ckEmpleado) => {
                ckEmpleado.checked = lstSeleccionados.has(Number(ckEmpleado.value));
            });
            mdGrupo.showModal();
        });
    });

    // 5. Modal de Asignación de Rotación
    const mdAsignacion = document.getElementById('mdAsignacion');
    const cbAsignarNivel = document.getElementById('cbAsignarNivel');
    const contenedorAsignarEmpleado = document.getElementById('contenedorAsignarEmpleado');
    const contenedorAsignarGrupo = document.getElementById('contenedorAsignarGrupo');
    const contenedorAsignarDepartamento = document.getElementById('contenedorAsignarDepartamento');

    function syncAssignmentTargetFields() {
        const val = cbAsignarNivel.value;
        contenedorAsignarEmpleado.classList.toggle('d-none', val !== 'employee');
        contenedorAsignarGrupo.classList.toggle('d-none', val !== 'group');
        contenedorAsignarDepartamento.classList.toggle('d-none', val !== 'department');
    }

    cbAsignarNivel?.addEventListener('change', syncAssignmentTargetFields);
    document.getElementById('btnNuevaAsignacion')?.addEventListener('click', () => {
        syncAssignmentTargetFields();
        mdAsignacion.showModal();
    });

    // 6. Modal de Excepción
    const mdExcepcion = document.getElementById('mdExcepcion');
    document.getElementById('btnNuevaExcepcion')?.addEventListener('click', () => {
        mdExcepcion.showModal();
    });
});
</script>
@endpush
