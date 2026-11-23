<?php

namespace App\Http\Controllers;

use App\Models\EmployeeGroup;
use App\Models\ShiftException;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftScheduleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function storeRotation(Request $request): RedirectResponse
    {
        $dataValidado = $this->validateRotation($request);

        $rotation = DB::transaction(function () use ($dataValidado): ShiftRotation {
            $rotation = ShiftRotation::create([
                'name' => $dataValidado['name'],
                'description' => $dataValidado['description'] ?? null,
                'cycle_days' => (int) $dataValidado['cycle_days'],
                'is_active' => $dataValidado['is_active'],
            ]);

            foreach (array_values($dataValidado['steps']) as $index => $shiftId) {
                $rotation->steps()->create([
                    'work_shift_id' => (int) $shiftId,
                    'step_order' => $index + 1,
                ]);
            }

            return $rotation;
        });

        $this->audit->record(
            'shift_rotation.created',
            $rotation,
            "Alta de rotación {$rotation->name} (ciclo {$rotation->cycle_days} días)",
            [],
            ['name' => $rotation->name, 'cycle_days' => $rotation->cycle_days, 'steps' => $dataValidado['steps']]
        );

        return redirect()
            ->route('shifts.index', ['tab' => 'rotaciones'])
            ->with('status', "Rotación \"{$rotation->name}\" creada correctamente.");
    }

    public function updateRotation(Request $request, ShiftRotation $rotation): RedirectResponse
    {
        $dataValidado = $this->validateRotation($request);

        $lstPasosAntes = $rotation->steps()->pluck('work_shift_id')->all();
        $beforeCycle = $rotation->cycle_days;
        $beforeActive = $rotation->is_active;

        DB::transaction(function () use ($rotation, $dataValidado): void {
            $rotation->update([
                'name' => $dataValidado['name'],
                'description' => $dataValidado['description'] ?? null,
                'cycle_days' => (int) $dataValidado['cycle_days'],
                'is_active' => $dataValidado['is_active'],
            ]);

            $rotation->steps()->delete();
            foreach (array_values($dataValidado['steps']) as $index => $shiftId) {
                $rotation->steps()->create([
                    'work_shift_id' => (int) $shiftId,
                    'step_order' => $index + 1,
                ]);
            }
        });

        $this->audit->record(
            'shift_rotation.updated',
            $rotation,
            "Actualización de rotación {$rotation->name}",
            ['cycle_days' => $beforeCycle, 'is_active' => $beforeActive, 'steps' => $lstPasosAntes],
            ['cycle_days' => (int) $dataValidado['cycle_days'], 'is_active' => $dataValidado['is_active'], 'steps' => $dataValidado['steps']]
        );

        $recalculated = $this->recalculateForRotation($rotation);

        return redirect()
            ->route('shifts.index', ['tab' => 'rotaciones'])
            ->with('status', "Rotación \"{$rotation->name}\" actualizada ({$recalculated} jornadas recalculadas).");
    }

    public function destroyRotation(ShiftRotation $rotation): RedirectResponse
    {
        $name = $rotation->name;
        $lstAsignaciones = $rotation->assignments()->get();

        $rotation->delete();

        $this->audit->record('shift_rotation.deleted', null, "Eliminación de rotación {$name}", ['name' => $name]);

        foreach ($lstAsignaciones as $assignment) {
            $this->recalculateForAssignment($assignment);
        }

        return redirect()
            ->route('shifts.index', ['tab' => 'rotaciones'])
            ->with('status', "Rotación \"{$name}\" eliminada y jornadas afectadas recalculadas.");
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:employee_groups,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $group = DB::transaction(function () use ($dataValidado): EmployeeGroup {
            $group = EmployeeGroup::create([
                'name' => $dataValidado['name'],
                'description' => $dataValidado['description'] ?? null,
            ]);

            $group->employees()->sync($dataValidado['employee_ids'] ?? []);

            return $group;
        });

        $this->audit->record(
            'employee_group.created',
            $group,
            "Alta de grupo de empleados {$group->name}",
            [],
            ['name' => $group->name, 'employee_count' => count($dataValidado['employee_ids'] ?? [])]
        );

        return redirect()
            ->route('shifts.index', ['tab' => 'asignaciones'])
            ->with('status', "Grupo \"{$group->name}\" creado correctamente.");
    }

    public function updateGroup(Request $request, EmployeeGroup $group): RedirectResponse
    {
        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('employee_groups', 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $lstIdsAntes = $group->employees()->pluck('employees.id')->all();
        $lstIdsDespues = array_map('intval', $dataValidado['employee_ids'] ?? []);

        DB::transaction(function () use ($group, $dataValidado, $lstIdsDespues): void {
            $group->update([
                'name' => $dataValidado['name'],
                'description' => $dataValidado['description'] ?? null,
            ]);
            $group->employees()->sync($lstIdsDespues);
        });

        $this->audit->record(
            'employee_group.updated',
            $group,
            "Actualización del grupo {$group->name}",
            ['employee_ids' => $lstIdsAntes],
            ['employee_ids' => $lstIdsDespues]
        );

        $lstIdsAfectados = array_values(array_unique(array_merge($lstIdsAntes, $lstIdsDespues)));
        $earliestStart = $group->assignments()->min('starts_on');
        if ($lstIdsAfectados !== [] && $earliestStart !== null) {
            foreach ($lstIdsAfectados as $empId) {
                Artisan::call('attendance:recalculate', [
                    '--employee' => $empId,
                    '--from' => $earliestStart,
                ]);
            }
        }

        return redirect()
            ->route('shifts.index', ['tab' => 'asignaciones'])
            ->with('status', "Grupo \"{$group->name}\" actualizado.");
    }

    public function destroyGroup(EmployeeGroup $group): RedirectResponse
    {
        $name = $group->name;
        $lstIdsMiembros = $group->employees()->pluck('employees.id')->all();
        $earliestStart = $group->assignments()->min('starts_on');

        $group->delete();

        $this->audit->record('employee_group.deleted', null, "Eliminación del grupo {$name}", ['name' => $name]);

        if ($lstIdsMiembros !== [] && $earliestStart !== null) {
            foreach ($lstIdsMiembros as $empId) {
                Artisan::call('attendance:recalculate', [
                    '--employee' => $empId,
                    '--from' => $earliestStart,
                ]);
            }
        }

        return redirect()
            ->route('shifts.index', ['tab' => 'asignaciones'])
            ->with('status', "Grupo \"{$name}\" eliminado.");
    }

    public function storeAssignment(Request $request): RedirectResponse
    {
        $dataValidado = $request->validate([
            'shift_rotation_id' => ['required', 'integer', 'exists:shift_rotations,id'],
            'target_type' => ['required', Rule::in(ShiftRotationAssignment::TARGETS)],
            'employee_id' => [
                'nullable',
                Rule::requiredIf($request->input('target_type') === ShiftRotationAssignment::TARGET_EMPLOYEE),
                'integer',
                'exists:employees,id',
            ],
            'employee_group_id' => [
                'nullable',
                Rule::requiredIf($request->input('target_type') === ShiftRotationAssignment::TARGET_GROUP),
                'integer',
                'exists:employee_groups,id',
            ],
            'department' => [
                'nullable',
                Rule::requiredIf($request->input('target_type') === ShiftRotationAssignment::TARGET_DEPARTMENT),
                'string',
                'max:100',
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'start_step_order' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $assignment = ShiftRotationAssignment::create([
            'shift_rotation_id' => (int) $dataValidado['shift_rotation_id'],
            'target_type' => $dataValidado['target_type'],
            'employee_id' => $dataValidado['target_type'] === ShiftRotationAssignment::TARGET_EMPLOYEE ? (int) $dataValidado['employee_id'] : null,
            'employee_group_id' => $dataValidado['target_type'] === ShiftRotationAssignment::TARGET_GROUP ? (int) $dataValidado['employee_group_id'] : null,
            'department' => $dataValidado['target_type'] === ShiftRotationAssignment::TARGET_DEPARTMENT ? trim((string) $dataValidado['department']) : null,
            'starts_on' => $dataValidado['starts_on'],
            'ends_on' => $dataValidado['ends_on'] ?? null,
            'start_step_order' => (int) ($dataValidado['start_step_order'] ?? 1),
            'is_active' => true,
        ]);

        $assignment->load(['rotation', 'employee', 'group']);

        $this->audit->record(
            'shift_assignment.created',
            $assignment,
            "Asignación de rotación {$assignment->rotation?->name} a {$assignment->targetLabel()}",
            [],
            [
                'target_type' => $assignment->target_type,
                'target' => $assignment->targetLabel(),
                'starts_on' => $assignment->starts_on?->toDateString(),
                'ends_on' => $assignment->ends_on?->toDateString(),
            ]
        );

        $this->recalculateForAssignment($assignment);

        return redirect()
            ->route('shifts.index', ['tab' => 'asignaciones'])
            ->with('status', "Rotación asignada a {$assignment->targetLabel()} y jornadas recalculadas.");
    }

    public function destroyAssignment(ShiftRotationAssignment $assignment): RedirectResponse
    {
        $assignment->load(['rotation', 'employee', 'group']);
        $label = $assignment->targetLabel();

        $assignment->delete();

        $this->audit->record(
            'shift_assignment.deleted',
            null,
            "Eliminación de asignación de rotación para {$label}",
            ['target' => $label]
        );

        $this->recalculateForAssignment($assignment);

        return redirect()
            ->route('shifts.index', ['tab' => 'asignaciones'])
            ->with('status', "Asignación para {$label} eliminada y jornadas recalculadas.");
    }

    public function storeException(Request $request): RedirectResponse
    {
        $dataValidado = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_shift_id' => ['required', 'integer', 'exists:work_shifts,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $exception = ShiftException::create($dataValidado);
        $exception->load(['employee', 'workShift']);

        $this->audit->record(
            'shift_exception.created',
            $exception,
            "Excepción de turno ({$exception->workShift?->name}) para {$exception->employee?->full_name}",
            [],
            [
                'employee_id' => $exception->employee_id,
                'work_shift_id' => $exception->work_shift_id,
                'starts_on' => $exception->starts_on?->toDateString(),
                'ends_on' => $exception->ends_on?->toDateString(),
                'reason' => $exception->reason,
            ]
        );

        Artisan::call('attendance:recalculate', [
            '--employee' => $exception->employee_id,
            '--from' => $exception->starts_on?->toDateString(),
            '--to' => $exception->ends_on?->toDateString(),
        ]);

        return redirect()
            ->route('shifts.index', ['tab' => 'excepciones'])
            ->with('status', "Excepción guardada para {$exception->employee?->full_name} y jornadas recalculadas.");
    }

    public function destroyException(ShiftException $exception): RedirectResponse
    {
        $exception->load('employee');
        $employeeId = $exception->employee_id;
        $empName = $exception->employee?->full_name ?? "#{$employeeId}";
        $from = $exception->starts_on?->toDateString();
        $to = $exception->ends_on?->toDateString();

        $exception->delete();

        $this->audit->record(
            'shift_exception.deleted',
            null,
            "Eliminación de excepción de turno para {$empName} ({$from} a {$to})",
            ['employee_id' => $employeeId, 'starts_on' => $from, 'ends_on' => $to]
        );

        Artisan::call('attendance:recalculate', array_filter([
            '--employee' => $employeeId,
            '--from' => $from,
            '--to' => $to,
        ], fn ($v): bool => $v !== null));

        return redirect()
            ->route('shifts.index', ['tab' => 'excepciones'])
            ->with('status', "Excepción eliminada y jornadas de {$empName} recalculadas.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRotation(Request $request): array
    {
        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'cycle_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*' => ['required', 'integer', 'exists:work_shifts,id'],
        ]);

        $dataValidado['is_active'] = $request->boolean('is_active', true);

        return $dataValidado;
    }

    private function recalculateForAssignment(ShiftRotationAssignment $assignment): int
    {
        $dataParametros = [
            '--from' => $assignment->starts_on?->toDateString(),
        ];

        if ($assignment->ends_on !== null) {
            $dataParametros['--to'] = $assignment->ends_on->toDateString();
        }

        if ($assignment->target_type === ShiftRotationAssignment::TARGET_EMPLOYEE && $assignment->employee_id !== null) {
            $dataParametros['--employee'] = $assignment->employee_id;
        } elseif ($assignment->target_type === ShiftRotationAssignment::TARGET_GROUP && $assignment->employee_group_id !== null) {
            $dataParametros['--group'] = $assignment->employee_group_id;
        } elseif ($assignment->target_type === ShiftRotationAssignment::TARGET_DEPARTMENT && $assignment->department !== null) {
            $dataParametros['--department'] = $assignment->department;
        } else {
            return 0;
        }

        Artisan::call('attendance:recalculate', array_filter($dataParametros, fn ($v): bool => $v !== null));

        return 1;
    }

    private function recalculateForRotation(ShiftRotation $rotation): int
    {
        $lstAsignaciones = $rotation->assignments()->get();
        if ($lstAsignaciones->isEmpty()) {
            return 0;
        }

        $earliestStart = $lstAsignaciones->min(fn (ShiftRotationAssignment $a): ?string => $a->starts_on?->toDateString());
        $dataParametros = ['--rotation' => $rotation->id];
        if ($earliestStart !== null) {
            $dataParametros['--from'] = $earliestStart;
        }

        Artisan::call('attendance:recalculate', $dataParametros);

        return $lstAsignaciones->count();
    }
}
