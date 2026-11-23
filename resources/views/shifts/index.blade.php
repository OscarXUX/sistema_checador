@extends('layouts.app')

@section('title', 'Turnos, Rotaciones y Excepciones — Checador Hikvision')
@section('page_title', 'Turnos, Rotaciones y Excepciones')
@section('page_subtitle', 'Catálogo de turnos, ciclos rotativos, asignaciones por prioridad y vista previa de 14 días')

@php
    $sourceBadgeMap = [
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
                id="tab-turnos"
                class="app-tab"
                data-tab="turnos"
                aria-selected="{{ $activeTab === 'turnos' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'turnos' ? '0' : '-1' }}">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Turnos
        </button>
        <button type="button"
                role="tab"
                id="tab-rotaciones"
                class="app-tab"
                data-tab="rotaciones"
                aria-selected="{{ $activeTab === 'rotaciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'rotaciones' ? '0' : '-1' }}">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Rotaciones
            <span class="badge text-bg-secondary ms-1">{{ $rotations->count() }}</span>
        </button>
        <button type="button"
                role="tab"
                id="tab-asignaciones"
                class="app-tab"
                data-tab="asignaciones"
                aria-selected="{{ $activeTab === 'asignaciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'asignaciones' ? '0' : '-1' }}">
            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Asignaciones
            <span class="badge text-bg-secondary ms-1">{{ $assignments->count() }}</span>
        </button>
        <button type="button"
                role="tab"
                id="tab-excepciones"
                class="app-tab"
                data-tab="excepciones"
                aria-selected="{{ $activeTab === 'excepciones' ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === 'excepciones' ? '0' : '-1' }}">
            <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Excepciones
            <span class="badge text-bg-secondary ms-1">{{ $exceptions->count() }}</span>
        </button>
    </div>

    <!-- Panel 1: Catálogo de Turnos -->
    <section role="tabpanel"
             id="panel-turnos"
             aria-labelledby="tab-turnos"
             data-panel="turnos"
             @if($activeTab !== 'turnos') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="shifts-catalog-heading" class="h6 fw-semibold mb-0">Catálogo de Turnos</h2>
                    <small class="text-secondary">Los cálculos de retardo, comida y horas extra se basan en estas reglas</small>
                </div>
                @can('turnos.administrar')
                <button id="btn-new-shift" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Turno
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="shifts-catalog-heading" class="shifts-table-wrapper">
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
                        @forelse($shifts as $shift)
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
             id="panel-rotaciones"
             aria-labelledby="tab-rotaciones"
             data-panel="rotaciones"
             @if($activeTab !== 'rotaciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="rotations-heading" class="h6 fw-semibold mb-0">Patrones de Rotación</h2>
                    <small class="text-secondary">Secuencias ordenadas de turnos (ej. 1→2→3, 3→2→1, 2→1→3) y días por ciclo</small>
                </div>
                @can('turnos.programar')
                <button id="btn-new-rotation" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto" @disabled($shifts->isEmpty())>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Rotación
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="rotations-heading" class="shifts-table-wrapper">
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
                        @forelse($rotations as $rotation)
                            @php
                                $rotationPayload = [
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
                                                data-rotation='@json($rotationPayload)'>
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
             id="panel-asignaciones"
             aria-labelledby="tab-asignaciones"
             data-panel="asignaciones"
             @if($activeTab !== 'asignaciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="assignments-heading" class="h6 fw-semibold mb-0">Asignaciones de Rotación</h2>
                    <small class="text-secondary">Prioridad automática: Excepción &gt; Empleado &gt; Grupo &gt; Departamento &gt; Turno Base</small>
                </div>
                @can('turnos.programar')
                <div class="d-flex flex-wrap gap-2">
                    <button id="btn-new-group" type="button" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-people me-1" aria-hidden="true"></i>Nuevo Grupo
                    </button>
                    <button id="btn-new-assignment" type="button" class="btn btn-primary btn-sm" @disabled($rotations->isEmpty())>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Asignar Rotación
                    </button>
                </div>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="assignments-heading" class="shifts-table-wrapper mb-4">
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
                        @forelse($assignments as $assignment)
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
                <h3 id="groups-heading" class="h6 fw-semibold mb-2">Grupos / Cuadrillas de Empleados</h3>
                <div tabindex="0" role="region" aria-labelledby="groups-heading" class="shifts-table-wrapper">
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
                            @forelse($groups as $group)
                                @php
                                    $groupPayload = [
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
                                                    data-group='@json($groupPayload)'>
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
             id="panel-excepciones"
             aria-labelledby="tab-excepciones"
             data-panel="excepciones"
             @if($activeTab !== 'excepciones') hidden="until-found" @endif>
        <div class="tabpanel-inner">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                <div>
                    <h2 id="exceptions-heading" class="h6 fw-semibold mb-0">Excepciones por Empleado y Fechas</h2>
                    <small class="text-secondary">Máxima prioridad sobre cualquier rotación o turno asignado (guardias, permutas, cobertura)</small>
                </div>
                @can('turnos.programar')
                <button id="btn-new-exception" type="button" class="btn btn-primary btn-sm align-self-start align-self-sm-auto" @disabled($employees->isEmpty() || $shifts->isEmpty())>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Excepción
                </button>
                @endcan
            </div>

            <div tabindex="0" role="region" aria-labelledby="exceptions-heading" class="shifts-table-wrapper">
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
                        @forelse($exceptions as $exception)
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
            <input type="hidden" name="tab" id="preview-active-tab" value="{{ $activeTab }}">
            <div class="col-6 col-sm-auto">
                <label for="preview-start" class="form-label small fw-medium mb-1">Inicio</label>
                <input id="preview-start" name="preview_start" type="date" class="form-control form-control-sm font-mono-tabular" value="{{ $previewStart }}">
            </div>
            <div class="col-6 col-sm-auto">
                <label for="preview-department" class="form-label small fw-medium mb-1">Departamento</label>
                <select id="preview-department" name="preview_department" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    @foreach($departments as $dept)
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
                    @foreach($preview['dates'] as $day)
                        <th scope="col" class="text-center font-mono-tabular" style="min-width: 112px;">
                            <div>{{ $day->format('d/m') }}</div>
                            <div class="text-secondary" style="font-size: 0.7rem;">{{ $day->locale('es')->isoFormat('ddd') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($preview['rows'] as $row)
                    <tr>
                        <th scope="row" class="sticky-col">
                            <div class="fw-semibold text-truncate" style="max-width: 210px;">
                                [{{ $row['employee']->hikvision_employee_no }}] {{ $row['employee']->full_name }}
                            </div>
                            <small class="text-secondary">{{ $row['employee']->department }}</small>
                        </th>
                        @foreach($row['days'] as $cell)
                            @php
                                $badgeClass = $sourceBadgeMap[$cell['source']] ?? 'border-secondary text-secondary';
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
                        <td colspan="{{ count($preview['dates']) + 1 }}" class="text-center text-secondary py-4">
                            No hay empleados para mostrar en la vista previa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<!-- Modal <dialog>: Turno Laboral -->
<dialog id="shift-dialog" class="app-dialog" aria-labelledby="shift-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="shift-dialog-title" class="h6 mb-0 fw-semibold">Nuevo Turno Laboral</h2>
            <button type="button" class="btn-close" data-close-dialog="shift-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="shift-form" method="POST" action="{{ route('shifts.store') }}">
            @csrf
            <input type="hidden" id="shift-method" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="shift-name" class="form-label small fw-medium">Nombre del Turno <span class="text-danger">*</span></label>
                    <input id="shift-name" name="name" type="text" class="form-control" required maxlength="100" autofocus>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="shift-entry" class="form-label small fw-medium">Hora de Entrada <span class="text-danger">*</span></label>
                    <input id="shift-entry" name="entry_time" type="time" class="form-control font-mono-tabular" required value="09:00">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="shift-exit" class="form-label small fw-medium">Hora de Salida <span class="text-danger">*</span></label>
                    <input id="shift-exit" name="exit_time" type="time" class="form-control font-mono-tabular" required value="18:00">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="shift-meal" class="form-label small fw-medium">Comida (min)</label>
                    <input id="shift-meal" name="meal_duration_minutes" type="number" class="form-control font-mono-tabular" min="15" max="180" required value="60">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="shift-tolerance" class="form-label small fw-medium">Tolerancia (min)</label>
                    <input id="shift-tolerance" name="tolerance_minutes" type="number" class="form-control font-mono-tabular" min="0" max="60" required value="10">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="shift-weekly" class="form-label small fw-medium">Tope Semanal (h)</label>
                    <input id="shift-weekly" name="max_weekly_hours" type="number" class="form-control font-mono-tabular" min="20" max="60" required value="48">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="shift-dialog">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Turno</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Rotación de Turnos -->
<dialog id="rotation-dialog" class="app-dialog" aria-labelledby="rotation-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="rotation-dialog-title" class="h6 mb-0 fw-semibold">Nueva Rotación</h2>
            <button type="button" class="btn-close" data-close-dialog="rotation-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="rotation-form" method="POST" action="{{ route('shifts.rotations.store') }}">
            @csrf
            <input type="hidden" id="rotation-method" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12 col-sm-8">
                    <label for="rotation-name" class="form-label small fw-medium">Nombre de la Rotación <span class="text-danger">*</span></label>
                    <input id="rotation-name" name="name" type="text" class="form-control" required maxlength="120" placeholder="Ej. Rol 3 Turnos Semanal">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="rotation-cycle-days" class="form-label small fw-medium">Días por Ciclo <span class="text-danger">*</span></label>
                    <input id="rotation-cycle-days" name="cycle_days" type="number" class="form-control font-mono-tabular" min="1" max="365" required value="7">
                </div>

                <div class="col-12">
                    <label for="rotation-description" class="form-label small fw-medium">Descripción</label>
                    <input id="rotation-description" name="description" type="text" class="form-control" maxlength="255">
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <span class="small fw-medium">Pasos en Orden <span class="text-danger">*</span></span>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="123" title="Secuencia 1 → 2 → 3">1→2→3</button>
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="321" title="Secuencia 3 → 2 → 1">3→2→1</button>
                            <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" data-preset="213" title="Secuencia 2 → 1 → 3">2→1→3</button>
                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" id="btn-add-rotation-step">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Paso
                            </button>
                        </div>
                    </div>
                    <div id="rotation-steps-list" class="d-flex flex-column gap-2"></div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input id="rotation-active" name="is_active" class="form-check-input" type="checkbox" value="1" checked>
                        <label class="form-check-label small" for="rotation-active">Rotación Activa</label>
                    </div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="rotation-dialog">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Rotación</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Grupo de Empleados -->
<dialog id="group-dialog" class="app-dialog" aria-labelledby="group-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="group-dialog-title" class="h6 mb-0 fw-semibold">Nuevo Grupo de Empleados</h2>
            <button type="button" class="btn-close" data-close-dialog="group-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="group-form" method="POST" action="{{ route('shifts.groups.store') }}">
            @csrf
            <input type="hidden" id="group-method" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="group-name" class="form-label small fw-medium">Nombre del Grupo <span class="text-danger">*</span></label>
                    <input id="group-name" name="name" type="text" class="form-control" required maxlength="120" placeholder="Ej. Cuadrilla A">
                </div>
                <div class="col-12">
                    <label for="group-description" class="form-label small fw-medium">Descripción</label>
                    <input id="group-description" name="description" type="text" class="form-control" maxlength="255">
                </div>
                <div class="col-12">
                    <div class="small fw-medium mb-2">Integrantes</div>
                    <div class="border border-secondary-subtle rounded p-2" style="max-height: 220px; overflow-y: auto;">
                        @foreach($employees as $emp)
                            <div class="form-check">
                                <input class="form-check-input group-employee-checkbox"
                                       type="checkbox"
                                       name="employee_ids[]"
                                       value="{{ $emp->id }}"
                                       id="grp-emp-{{ $emp->id }}">
                                <label class="form-check-label small" for="grp-emp-{{ $emp->id }}">
                                    [{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}
                                    <span class="text-secondary">({{ $emp->department }})</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="group-dialog">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Grupo</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Asignación de Rotación -->
<dialog id="assignment-dialog" class="app-dialog" aria-labelledby="assignment-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="assignment-dialog-title" class="h6 mb-0 fw-semibold">Asignar Rotación</h2>
            <button type="button" class="btn-close" data-close-dialog="assignment-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="assignment-form" method="POST" action="{{ route('shifts.assignments.store') }}">
            @csrf

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="assign-rotation" class="form-label small fw-medium">Rotación <span class="text-danger">*</span></label>
                    <select id="assign-rotation" name="shift_rotation_id" class="form-select" required>
                        @foreach($rotations as $rotation)
                            <option value="{{ $rotation->id }}">{{ $rotation->name }} ({{ $rotation->cycle_days }}d)</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-5">
                    <label for="assign-target-type" class="form-label small fw-medium">Nivel de Aplicación <span class="text-danger">*</span></label>
                    <select id="assign-target-type" name="target_type" class="form-select" required>
                        <option value="employee">Empleado individual</option>
                        <option value="group">Grupo / Cuadrilla</option>
                        <option value="department">Departamento completo</option>
                    </select>
                </div>

                <div class="col-12 col-sm-7" id="assign-target-employee-wrap">
                    <label for="assign-employee" class="form-label small fw-medium">Empleado <span class="text-danger">*</span></label>
                    <select id="assign-employee" name="employee_id" class="form-select">
                        <option value="">Selecciona un empleado...</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">[{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-7 d-none" id="assign-target-group-wrap">
                    <label for="assign-group" class="form-label small fw-medium">Grupo <span class="text-danger">*</span></label>
                    <select id="assign-group" name="employee_group_id" class="form-select">
                        <option value="">Selecciona un grupo...</option>
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->employees_count }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-7 d-none" id="assign-target-department-wrap">
                    <label for="assign-department" class="form-label small fw-medium">Departamento <span class="text-danger">*</span></label>
                    <select id="assign-department" name="department" class="form-select">
                        <option value="">Selecciona un departamento...</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}">{{ $dept }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="assign-starts-on" class="form-label small fw-medium">Fecha Inicio <span class="text-danger">*</span></label>
                    <input id="assign-starts-on" name="starts_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="assign-ends-on" class="form-label small fw-medium">Fecha Fin (opcional)</label>
                    <input id="assign-ends-on" name="ends_on" type="date" class="form-control font-mono-tabular">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="assign-start-step" class="form-label small fw-medium">Paso Inicial</label>
                    <input id="assign-start-step" name="start_step_order" type="number" class="form-control font-mono-tabular" min="1" max="20" value="1">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="assignment-dialog">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Asignación</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal <dialog>: Excepción de Turno -->
<dialog id="exception-dialog" class="app-dialog" aria-labelledby="exception-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="exception-dialog-title" class="h6 mb-0 fw-semibold">Nueva Excepción de Turno</h2>
            <button type="button" class="btn-close" data-close-dialog="exception-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="exception-form" method="POST" action="{{ route('shifts.exceptions.store') }}">
            @csrf

            <div class="p-3 p-md-4 row g-3 app-dialog-body">
                <div class="col-12">
                    <label for="exc-employee" class="form-label small fw-medium">Empleado <span class="text-danger">*</span></label>
                    <select id="exc-employee" name="employee_id" class="form-select" required>
                        <option value="">Selecciona un empleado...</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">[{{ $emp->hikvision_employee_no }}] {{ $emp->full_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label for="exc-shift" class="form-label small fw-medium">Turno Temporal <span class="text-danger">*</span></label>
                    <select id="exc-shift" name="work_shift_id" class="form-select" required>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->name }} ({{ $shift->schedule_label }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="exc-starts-on" class="form-label small fw-medium">Desde <span class="text-danger">*</span></label>
                    <input id="exc-starts-on" name="starts_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="exc-ends-on" class="form-label small fw-medium">Hasta <span class="text-danger">*</span></label>
                    <input id="exc-ends-on" name="ends_on" type="date" class="form-control font-mono-tabular" required value="{{ now()->toDateString() }}">
                </div>

                <div class="col-12">
                    <label for="exc-reason" class="form-label small fw-medium">Motivo</label>
                    <input id="exc-reason" name="reason" type="text" class="form-control" maxlength="255" placeholder="Ej. Cobertura operativa / permiso">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="exception-dialog">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Excepción</button>
            </div>
        </form>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="shifts-catalog-data" type="application/json">@json($shifts->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'schedule' => $s->schedule_label])->values())</script>
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const shiftOptions = JSON.parse(document.getElementById('shifts-catalog-data').textContent);

    // 1. Pestañas accesibles (Turnos, Rotaciones, Asignaciones, Excepciones) + hidden="until-found"
    const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
    const panels = Array.from(document.querySelectorAll('[role="tabpanel"]'));
    const previewTabInput = document.getElementById('preview-active-tab');

    function activateTab(tabKey, moveFocus = false) {
        tabs.forEach((t) => {
            const selected = t.dataset.tab === tabKey;
            t.setAttribute('aria-selected', selected ? 'true' : 'false');
            t.setAttribute('tabindex', selected ? '0' : '-1');
            if (selected && moveFocus) {
                t.focus();
            }
        });
        panels.forEach((p) => {
            if (p.dataset.panel === tabKey) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', 'until-found');
            }
        });
        if (previewTabInput) {
            previewTabInput.value = tabKey;
        }
    }

    tabs.forEach((tab, idx) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.tab));
        tab.addEventListener('keydown', (e) => {
            let nextIdx = null;
            if (e.key === 'ArrowRight') nextIdx = (idx + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') nextIdx = (idx - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') nextIdx = 0;
            else if (e.key === 'End') nextIdx = tabs.length - 1;
            if (nextIdx !== null) {
                e.preventDefault();
                activateTab(tabs[nextIdx].dataset.tab, true);
            }
        });
    });

    panels.forEach((panel) => {
        panel.addEventListener('beforematch', () => {
            activateTab(panel.dataset.panel);
        });
    });

    // Cierre unificado de <dialog>
    document.querySelectorAll('[data-close-dialog]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.getElementById(btn.getAttribute('data-close-dialog'))?.close();
        });
    });

    // 2. Modal de Turno
    const shiftDialog = document.getElementById('shift-dialog');
    const shiftForm = document.getElementById('shift-form');
    const shiftMethod = document.getElementById('shift-method');
    const shiftStoreUrl = '{{ route("shifts.store") }}';
    const shiftUpdateBase = '{{ url("/shifts") }}';

    document.getElementById('btn-new-shift')?.addEventListener('click', () => {
        shiftForm.action = shiftStoreUrl;
        shiftMethod.value = 'POST';
        document.getElementById('shift-dialog-title').textContent = 'Nuevo Turno Laboral';
        shiftForm.reset();
        shiftDialog.showModal();
    });

    document.querySelectorAll('.btn-edit-shift').forEach((btn) => {
        btn.addEventListener('click', () => {
            const shift = JSON.parse(btn.getAttribute('data-shift'));
            shiftForm.action = `${shiftUpdateBase}/${shift.id}`;
            shiftMethod.value = 'PUT';
            document.getElementById('shift-dialog-title').textContent = `Editar Turno: ${shift.name}`;
            document.getElementById('shift-name').value = shift.name;
            document.getElementById('shift-entry').value = String(shift.entry_time).slice(0, 5);
            document.getElementById('shift-exit').value = String(shift.exit_time).slice(0, 5);
            document.getElementById('shift-meal').value = shift.meal_duration_minutes;
            document.getElementById('shift-tolerance').value = shift.tolerance_minutes;
            document.getElementById('shift-weekly').value = shift.max_weekly_hours;
            shiftDialog.showModal();
        });
    });

    // 3. Modal de Rotaciones con pasos ordenados (1->2->3, 3->2->1, 2->1->3)
    const rotationDialog = document.getElementById('rotation-dialog');
    const rotationForm = document.getElementById('rotation-form');
    const rotationMethod = document.getElementById('rotation-method');
    const rotationStepsList = document.getElementById('rotation-steps-list');
    const rotationStoreUrl = '{{ route("shifts.rotations.store") }}';
    const rotationUpdateBase = '{{ url("/shifts/rotations") }}';

    function renderRotationSteps(stepIds) {
        rotationStepsList.innerHTML = '';
        const list = stepIds.length ? stepIds : (shiftOptions[0] ? [shiftOptions[0].id] : []);
        list.forEach((selectedId, index) => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';

            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary font-mono-tabular';
            badge.textContent = `Paso ${index + 1}`;

            const select = document.createElement('select');
            select.name = 'steps[]';
            select.className = 'form-select form-select-sm flex-grow-1';
            select.required = true;
            shiftOptions.forEach((opt) => {
                const option = document.createElement('option');
                option.value = String(opt.id);
                option.textContent = `${opt.name} (${opt.schedule})`;
                if (Number(selectedId) === Number(opt.id)) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger btn-sm';
            removeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            removeBtn.setAttribute('aria-label', `Quitar paso ${index + 1}`);
            removeBtn.disabled = list.length <= 1;
            removeBtn.addEventListener('click', () => {
                const currentIds = Array.from(rotationStepsList.querySelectorAll('select')).map((s) => Number(s.value));
                currentIds.splice(index, 1);
                renderRotationSteps(currentIds);
            });

            row.append(badge, select, removeBtn);
            rotationStepsList.appendChild(row);
        });
    }

    document.getElementById('btn-add-rotation-step')?.addEventListener('click', () => {
        const currentIds = Array.from(rotationStepsList.querySelectorAll('select')).map((s) => Number(s.value));
        const nextDefault = shiftOptions[currentIds.length % Math.max(1, shiftOptions.length)]?.id ?? shiftOptions[0]?.id;
        if (nextDefault) {
            currentIds.push(nextDefault);
            renderRotationSteps(currentIds);
        }
    });

    document.querySelectorAll('[data-preset]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const digits = String(btn.getAttribute('data-preset')).split('').map(Number);
            const mapped = digits
                .map((d) => shiftOptions[d - 1]?.id ?? shiftOptions[0]?.id)
                .filter(Boolean);
            if (mapped.length > 0) {
                renderRotationSteps(mapped);
            }
        });
    });

    document.getElementById('btn-new-rotation')?.addEventListener('click', () => {
        rotationForm.action = rotationStoreUrl;
        rotationMethod.value = 'POST';
        document.getElementById('rotation-dialog-title').textContent = 'Nueva Rotación de Turnos';
        rotationForm.reset();
        document.getElementById('rotation-cycle-days').value = '7';
        document.getElementById('rotation-active').checked = true;
        renderRotationSteps(shiftOptions.slice(0, 3).map((s) => s.id));
        rotationDialog.showModal();
    });

    document.querySelectorAll('.btn-edit-rotation').forEach((btn) => {
        btn.addEventListener('click', () => {
            const rot = JSON.parse(btn.getAttribute('data-rotation'));
            rotationForm.action = `${rotationUpdateBase}/${rot.id}`;
            rotationMethod.value = 'PUT';
            document.getElementById('rotation-dialog-title').textContent = `Editar Rotación: ${rot.name}`;
            document.getElementById('rotation-name').value = rot.name;
            document.getElementById('rotation-description').value = rot.description || '';
            document.getElementById('rotation-cycle-days').value = rot.cycle_days;
            document.getElementById('rotation-active').checked = Boolean(rot.is_active);
            renderRotationSteps(rot.steps || []);
            rotationDialog.showModal();
        });
    });

    // 4. Modal de Grupo de Empleados
    const groupDialog = document.getElementById('group-dialog');
    const groupForm = document.getElementById('group-form');
    const groupMethod = document.getElementById('group-method');
    const groupStoreUrl = '{{ route("shifts.groups.store") }}';
    const groupUpdateBase = '{{ url("/shifts/groups") }}';

    document.getElementById('btn-new-group')?.addEventListener('click', () => {
        groupForm.action = groupStoreUrl;
        groupMethod.value = 'POST';
        document.getElementById('group-dialog-title').textContent = 'Nuevo Grupo de Empleados';
        groupForm.reset();
        document.querySelectorAll('.group-employee-checkbox').forEach((cb) => { cb.checked = false; });
        groupDialog.showModal();
    });

    document.querySelectorAll('.btn-edit-group').forEach((btn) => {
        btn.addEventListener('click', () => {
            const group = JSON.parse(btn.getAttribute('data-group'));
            groupForm.action = `${groupUpdateBase}/${group.id}`;
            groupMethod.value = 'PUT';
            document.getElementById('group-dialog-title').textContent = `Editar Grupo: ${group.name}`;
            document.getElementById('group-name').value = group.name;
            document.getElementById('group-description').value = group.description || '';
            const selected = new Set((group.employee_ids || []).map(Number));
            document.querySelectorAll('.group-employee-checkbox').forEach((cb) => {
                cb.checked = selected.has(Number(cb.value));
            });
            groupDialog.showModal();
        });
    });

    // 5. Modal de Asignación de Rotación
    const assignmentDialog = document.getElementById('assignment-dialog');
    const targetTypeSelect = document.getElementById('assign-target-type');
    const empWrap = document.getElementById('assign-target-employee-wrap');
    const grpWrap = document.getElementById('assign-target-group-wrap');
    const deptWrap = document.getElementById('assign-target-department-wrap');

    function syncAssignmentTargetFields() {
        const val = targetTypeSelect.value;
        empWrap.classList.toggle('d-none', val !== 'employee');
        grpWrap.classList.toggle('d-none', val !== 'group');
        deptWrap.classList.toggle('d-none', val !== 'department');
    }

    targetTypeSelect?.addEventListener('change', syncAssignmentTargetFields);
    document.getElementById('btn-new-assignment')?.addEventListener('click', () => {
        syncAssignmentTargetFields();
        assignmentDialog.showModal();
    });

    // 6. Modal de Excepción
    const exceptionDialog = document.getElementById('exception-dialog');
    document.getElementById('btn-new-exception')?.addEventListener('click', () => {
        exceptionDialog.showModal();
    });
});
</script>
@endpush
