<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ShiftException;
use App\Models\ShiftRotationAssignment;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Resuelve qué turno laboral aplica a un empleado en una fecha determinada.
 *
 * Prioridad estricta (O2):
 * excepción > rotación por empleado > rotación por grupo > rotación por departamento > turno actual del empleado.
 */
class ShiftScheduleResolver
{
    public const SOURCE_EXCEPTION = 'exception';

    public const SOURCE_EMPLOYEE_ROTATION = 'employee_rotation';

    public const SOURCE_GROUP_ROTATION = 'group_rotation';

    public const SOURCE_DEPARTMENT_ROTATION = 'department_rotation';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_NONE = 'none';

    public function resolveForEmployeeOnDate(Employee $employee, CarbonInterface $date): ?WorkShift
    {
        return $this->resolveDetailForEmployeeOnDate($employee, $date)['shift'];
    }

    /**
     * @return array{shift: ?WorkShift, source: string, source_label: string, meta: ?string}
     */
    public function resolveDetailForEmployeeOnDate(Employee $employee, CarbonInterface $date): array
    {
        $targetDay = CarbonImmutable::instance($date)->startOfDay();
        $context = $this->loadBatchContext(collect([$employee]), $targetDay, $targetDay);

        return $this->resolveDetailFromContext($employee, $targetDay, $context);
    }

    /**
     * Genera la vista previa de horarios efectivos para un rango (por defecto 14 días) sin consultas N+1.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     dates: list<CarbonImmutable>,
     *     rows: list<array{
     *         employee: Employee,
     *         days: list<array{
     *             date: string,
     *             shift: ?WorkShift,
     *             shift_name: string,
     *             schedule_label: string,
     *             source: string,
     *             source_label: string,
     *             meta: ?string
     *         }>
     *     }>
     * }
     */
    public function previewForRange(Collection $employees, CarbonInterface $startDate, int $days = 14): array
    {
        $days = max(1, min(31, $days));
        $start = CarbonImmutable::instance($startDate)->startOfDay();
        $end = $start->addDays($days - 1);

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $start->addDays($i);
        }

        if ($employees->isEmpty()) {
            return ['dates' => $dates, 'rows' => []];
        }

        $context = $this->loadBatchContext($employees, $start, $end);
        $rows = [];

        foreach ($employees as $employee) {
            $empDays = [];
            foreach ($dates as $day) {
                $detail = $this->resolveDetailFromContext($employee, $day, $context);
                $shift = $detail['shift'];
                $empDays[] = [
                    'date' => $day->toDateString(),
                    'shift' => $shift,
                    'shift_name' => $shift?->name ?? 'Sin turno',
                    'schedule_label' => $shift?->schedule_label ?? '-',
                    'source' => $detail['source'],
                    'source_label' => $detail['source_label'],
                    'meta' => $detail['meta'],
                ];
            }

            $rows[] = [
                'employee' => $employee,
                'days' => $empDays,
            ];
        }

        return [
            'dates' => $dates,
            'rows' => $rows,
        ];
    }

    /**
     * Precarga excepciones, grupos y asignaciones activas para un conjunto de empleados y rango de fechas.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     exceptionsByEmployee: Collection<int, Collection<int, ShiftException>>,
     *     groupIdsByEmployee: array<int, list<int>>,
     *     employeeAssignments: Collection<int, Collection<int, ShiftRotationAssignment>>,
     *     groupAssignments: Collection<int, Collection<int, ShiftRotationAssignment>>,
     *     departmentAssignments: Collection<string, Collection<int, ShiftRotationAssignment>>
     * }
     */
    public function loadBatchContext(Collection $employees, CarbonInterface $start, CarbonInterface $end): array
    {
        EloquentCollection::make($employees->all())->loadMissing(['workShift', 'groups:id']);

        $employeeIds = $employees->pluck('id')->filter()->values()->all();
        $groupIdsByEmployee = [];
        $allGroupIds = [];
        $departments = [];

        foreach ($employees as $emp) {
            $empGroupIds = $emp->groups->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $groupIdsByEmployee[$emp->id] = $empGroupIds;
            foreach ($empGroupIds as $gid) {
                $allGroupIds[$gid] = true;
            }
            $dept = trim((string) $emp->department);
            if ($dept !== '') {
                $departments[$dept] = true;
            }
        }

        $startStr = CarbonImmutable::instance($start)->toDateString();
        $endStr = CarbonImmutable::instance($end)->toDateString();

        $exceptions = $employeeIds === []
            ? collect()
            : ShiftException::with('workShift')
                ->whereIn('employee_id', $employeeIds)
                ->where('starts_on', '<=', $endStr)
                ->where('ends_on', '>=', $startStr)
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->groupBy('employee_id');

        $allGroupIdList = array_keys($allGroupIds);
        $departmentList = array_keys($departments);

        $assignments = ($employeeIds === [] && $allGroupIdList === [] && $departmentList === [])
            ? collect()
            : ShiftRotationAssignment::with(['rotation.steps.workShift', 'group:id,name'])
                ->where('is_active', true)
                ->where('starts_on', '<=', $endStr)
                ->where(function ($q) use ($startStr): void {
                    $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startStr);
                })
                ->whereHas('rotation', fn ($q) => $q->where('is_active', true))
                ->whereHas('rotation.steps')
                ->where(function ($q) use ($employeeIds, $allGroupIdList, $departmentList): void {
                    $added = false;
                    if ($employeeIds !== []) {
                        $q->where(function ($sub) use ($employeeIds): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_EMPLOYEE)
                                ->whereIn('employee_id', $employeeIds);
                        });
                        $added = true;
                    }
                    if ($allGroupIdList !== []) {
                        $method = $added ? 'orWhere' : 'where';
                        $q->{$method}(function ($sub) use ($allGroupIdList): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_GROUP)
                                ->whereIn('employee_group_id', $allGroupIdList);
                        });
                        $added = true;
                    }
                    if ($departmentList !== []) {
                        $method = $added ? 'orWhere' : 'where';
                        $q->{$method}(function ($sub) use ($departmentList): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_DEPARTMENT)
                                ->whereIn('department', $departmentList);
                        });
                    }
                })
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get();

        return [
            'exceptionsByEmployee' => $exceptions,
            'groupIdsByEmployee' => $groupIdsByEmployee,
            'employeeAssignments' => $assignments->where('target_type', ShiftRotationAssignment::TARGET_EMPLOYEE)->groupBy('employee_id'),
            'groupAssignments' => $assignments->where('target_type', ShiftRotationAssignment::TARGET_GROUP)->groupBy('employee_group_id'),
            'departmentAssignments' => $assignments->where('target_type', ShiftRotationAssignment::TARGET_DEPARTMENT)->groupBy('department'),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{shift: ?WorkShift, source: string, source_label: string, meta: ?string}
     */
    public function resolveDetailFromContext(Employee $employee, CarbonImmutable $targetDay, array $context): array
    {
        $dateStr = $targetDay->toDateString();

        // 1. Excepción puntual del empleado
        /** @var Collection<int, ShiftException> $empExceptions */
        $empExceptions = $context['exceptionsByEmployee']->get($employee->id, collect());
        $exception = $empExceptions->first(function (ShiftException $ex) use ($dateStr): bool {
            return $ex->starts_on !== null
                && $ex->ends_on !== null
                && $ex->starts_on->toDateString() <= $dateStr
                && $ex->ends_on->toDateString() >= $dateStr;
        });

        if ($exception !== null && $exception->workShift !== null) {
            return [
                'shift' => $exception->workShift,
                'source' => self::SOURCE_EXCEPTION,
                'source_label' => 'Excepción',
                'meta' => $exception->reason ?: 'Excepción puntual',
            ];
        }

        // 2. Rotación asignada directamente al empleado
        /** @var Collection<int, ShiftRotationAssignment> $empAssignments */
        $empAssignments = $context['employeeAssignments']->get($employee->id, collect());
        if ($resolved = $this->firstMatchingAssignment($empAssignments, $targetDay)) {
            [$assignment, $shift] = $resolved;

            return [
                'shift' => $shift,
                'source' => self::SOURCE_EMPLOYEE_ROTATION,
                'source_label' => 'Rot. Empleado',
                'meta' => $assignment->rotation?->name,
            ];
        }

        // 3. Rotación asignada a cualquiera de sus grupos (más reciente primero en caso de múltiples)
        $empGroupIds = $context['groupIdsByEmployee'][$employee->id] ?? [];
        if ($empGroupIds !== []) {
            $candidateGroupAssignments = collect();
            foreach ($empGroupIds as $gid) {
                $candidateGroupAssignments = $candidateGroupAssignments->merge(
                    $context['groupAssignments']->get($gid, collect())
                );
            }
            $candidateGroupAssignments = $candidateGroupAssignments->sort(function (ShiftRotationAssignment $a, ShiftRotationAssignment $b): int {
                $cmp = strcmp((string) $b->starts_on?->toDateString(), (string) $a->starts_on?->toDateString());

                return $cmp !== 0 ? $cmp : ($b->id <=> $a->id);
            });

            if ($resolved = $this->firstMatchingAssignment($candidateGroupAssignments, $targetDay)) {
                [$assignment, $shift] = $resolved;

                return [
                    'shift' => $shift,
                    'source' => self::SOURCE_GROUP_ROTATION,
                    'source_label' => 'Rot. Grupo',
                    'meta' => trim(($assignment->group?->name ? $assignment->group->name.' · ' : '').($assignment->rotation?->name ?? '')),
                ];
            }
        }

        // 4. Rotación asignada a su departamento
        $dept = trim((string) $employee->department);
        if ($dept !== '') {
            /** @var Collection<int, ShiftRotationAssignment> $deptAssignments */
            $deptAssignments = $context['departmentAssignments']->get($dept, collect());
            if ($resolved = $this->firstMatchingAssignment($deptAssignments, $targetDay)) {
                [$assignment, $shift] = $resolved;

                return [
                    'shift' => $shift,
                    'source' => self::SOURCE_DEPARTMENT_ROTATION,
                    'source_label' => 'Rot. Depto',
                    'meta' => $assignment->rotation?->name,
                ];
            }
        }

        // 5. Turno base actual del empleado
        $employee->loadMissing('workShift');
        if ($employee->workShift !== null) {
            return [
                'shift' => $employee->workShift,
                'source' => self::SOURCE_DEFAULT,
                'source_label' => 'Base',
                'meta' => 'Turno asignado',
            ];
        }

        return [
            'shift' => null,
            'source' => self::SOURCE_NONE,
            'source_label' => 'Sin turno',
            'meta' => null,
        ];
    }

    /**
     * @param  Collection<int, ShiftRotationAssignment>  $assignments
     * @return array{0: ShiftRotationAssignment, 1: WorkShift}|null
     */
    private function firstMatchingAssignment(Collection $assignments, CarbonImmutable $targetDay): ?array
    {
        $dateStr = $targetDay->toDateString();

        foreach ($assignments as $assignment) {
            if ($assignment->starts_on === null || $assignment->starts_on->toDateString() > $dateStr) {
                continue;
            }
            if ($assignment->ends_on !== null && $assignment->ends_on->toDateString() < $dateStr) {
                continue;
            }
            $rotation = $assignment->rotation;
            if ($rotation === null || ! $rotation->is_active) {
                continue;
            }
            $shift = $rotation->resolveShiftForDate(
                $targetDay,
                $assignment->starts_on,
                (int) $assignment->start_step_order
            );
            if ($shift !== null) {
                return [$assignment, $shift];
            }
        }

        return null;
    }
}
