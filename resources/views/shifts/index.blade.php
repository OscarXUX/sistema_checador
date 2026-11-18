@extends('layouts.app')

@section('title', 'Turnos y Reglas Fiscales — Checador Hikvision')
@section('page_title', 'Turnos Laborales y Reglas Fiscales')
@section('page_subtitle', 'Configuración de horarios, tolerancia de retardo, tiempo de comida y tope semanal LFT')

@section('content')
<section class="surface-card p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-0">Catálogo de Turnos</h2>
            <small class="text-secondary">Los cálculos de retardo, comida y horas extra se basan en estas reglas</small>
        </div>
        <button id="btn-new-shift" type="button" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Turno
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th>Turno</th>
                    <th>Horario</th>
                    <th>Jornada Neta</th>
                    <th>Comida Máx.</th>
                    <th>Tolerancia</th>
                    <th>Tope Semanal</th>
                    <th>Empleados</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                @foreach($shifts as $shift)
                    <tr>
                        <td class="fw-semibold">{{ $shift->name }}</td>
                        <td class="font-mono-tabular">{{ $shift->schedule_label }}</td>
                        <td class="font-mono-tabular text-info">{{ number_format($shift->ordinaryDailyMinutes() / 60, 2) }} hrs/día</td>
                        <td class="font-mono-tabular">{{ $shift->meal_duration_minutes }} min</td>
                        <td class="font-mono-tabular">{{ $shift->tolerance_minutes }} min</td>
                        <td class="font-mono-tabular">{{ $shift->max_weekly_hours }} hrs</td>
                        <td><span class="badge text-bg-secondary">{{ $shift->employees_count }}</span></td>
                        <td class="text-end">
                            <button type="button"
                                    class="btn btn-outline-light btn-sm btn-edit-shift"
                                    data-shift='@json($shift)'>
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<dialog id="shift-dialog" class="app-dialog" aria-labelledby="shift-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="shift-dialog-title" class="h6 mb-0 fw-semibold">Nuevo Turno Laboral</h2>
            <button type="button" class="btn-close" id="shift-close-x" aria-label="Cerrar ventana"></button>
        </div>

        <form id="shift-form" method="POST" action="{{ route('shifts.store') }}">
            @csrf
            <input type="hidden" id="shift-method" name="_method" value="POST">

            <div class="p-3 p-md-4 row g-3">
                <div class="col-12">
                    <label for="shift-name" class="form-label small fw-medium">Nombre del Turno <span class="text-danger">*</span></label>
                    <input id="shift-name" name="name" type="text" class="form-control" required maxlength="100" autofocus>
                </div>

                <div class="col-6">
                    <label for="shift-entry" class="form-label small fw-medium">Hora de Entrada <span class="text-danger">*</span></label>
                    <input id="shift-entry" name="entry_time" type="time" class="form-control font-mono-tabular" required value="09:00">
                </div>

                <div class="col-6">
                    <label for="shift-exit" class="form-label small fw-medium">Hora de Salida <span class="text-danger">*</span></label>
                    <input id="shift-exit" name="exit_time" type="time" class="form-control font-mono-tabular" required value="18:00">
                </div>

                <div class="col-4">
                    <label for="shift-meal" class="form-label small fw-medium">Comida (min)</label>
                    <input id="shift-meal" name="meal_duration_minutes" type="number" class="form-control font-mono-tabular" min="15" max="180" required value="60">
                </div>

                <div class="col-4">
                    <label for="shift-tolerance" class="form-label small fw-medium">Tolerancia (min)</label>
                    <input id="shift-tolerance" name="tolerance_minutes" type="number" class="form-control font-mono-tabular" min="0" max="60" required value="10">
                </div>

                <div class="col-4">
                    <label for="shift-weekly" class="form-label small fw-medium">Tope Semanal (h)</label>
                    <input id="shift-weekly" name="max_weekly_hours" type="number" class="form-control font-mono-tabular" min="20" max="60" required value="48">
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="shift-close-btn">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar Turno</button>
            </div>
        </form>
    </div>
</dialog>
@endsection

@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('shift-dialog');
    const form = document.getElementById('shift-form');
    const methodInput = document.getElementById('shift-method');
    const storeUrl = '{{ route("shifts.store") }}';
    const updateBase = '{{ url("/shifts") }}';

    document.getElementById('shift-close-x').addEventListener('click', () => dialog.close());
    document.getElementById('shift-close-btn').addEventListener('click', () => dialog.close());

    document.getElementById('btn-new-shift').addEventListener('click', () => {
        form.action = storeUrl;
        methodInput.value = 'POST';
        document.getElementById('shift-dialog-title').textContent = 'Nuevo Turno Laboral';
        form.reset();
        dialog.showModal();
    });

    document.querySelectorAll('.btn-edit-shift').forEach((btn) => {
        btn.addEventListener('click', () => {
            const shift = JSON.parse(btn.getAttribute('data-shift'));
            form.action = `${updateBase}/${shift.id}`;
            methodInput.value = 'PUT';
            document.getElementById('shift-dialog-title').textContent = `Editar Turno: ${shift.name}`;
            document.getElementById('shift-name').value = shift.name;
            document.getElementById('shift-entry').value = String(shift.entry_time).slice(0, 5);
            document.getElementById('shift-exit').value = String(shift.exit_time).slice(0, 5);
            document.getElementById('shift-meal').value = shift.meal_duration_minutes;
            document.getElementById('shift-tolerance').value = shift.tolerance_minutes;
            document.getElementById('shift-weekly').value = shift.max_weekly_hours;
            dialog.showModal();
        });
    });
});
</script>
@endpush

