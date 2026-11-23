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
        $dataContexto = $this->loadBatchContext(collect([$employee]), $targetDay, $targetDay);

        return $this->resolveDetailFromContext($employee, $targetDay, $dataContexto);
    }

    /**
     * Genera la vista previa de horarios efectivos para un rango (por defecto 14 días) sin consultas N+1.
     *
     * @param  Collection<int, Employee>  $lstEmpleados
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
    public function previewForRange(Collection $lstEmpleados, CarbonInterface $startDate, int $days = 14): array
    {
        $days = max(1, min(31, $days));
        $start = CarbonImmutable::instance($startDate)->startOfDay();
        $end = $start->addDays($days - 1);

        $lstFechas = [];
        for ($i = 0; $i < $days; $i++) {
            $lstFechas[] = $start->addDays($i);
        }

        if ($lstEmpleados->isEmpty()) {
            return ['dates' => $lstFechas, 'rows' => []];
        }

        $dataContexto = $this->loadBatchContext($lstEmpleados, $start, $end);
        $lstFilas = [];

        foreach ($lstEmpleados as $employee) {
            $lstDiasEmpleado = [];
            foreach ($lstFechas as $day) {
                $detail = $this->resolveDetailFromContext($employee, $day, $dataContexto);
                $shift = $detail['shift'];
                $lstDiasEmpleado[] = [
                    'date' => $day->toDateString(),
                    'shift' => $shift,
                    'shift_name' => $shift?->name ?? 'Sin turno',
                    'schedule_label' => $shift?->schedule_label ?? '-',
                    'source' => $detail['source'],
                    'source_label' => $detail['source_label'],
                    'meta' => $detail['meta'],
                ];
            }

            $lstFilas[] = [
                'employee' => $employee,
                'days' => $lstDiasEmpleado,
            ];
        }

        return [
            'dates' => $lstFechas,
            'rows' => $lstFilas,
        ];
    }

    /**
     * Precarga excepciones, grupos y asignaciones activas para un conjunto de empleados y rango de fechas.
     *
     * @param  Collection<int, Employee>  $lstEmpleados
     * @return array{
     *     exceptionsByEmployee: Collection<int, Collection<int, ShiftException>>,
     *     groupIdsByEmployee: array<int, list<int>>,
     *     employeeAssignments: Collection<int, Collection<int, ShiftRotationAssignment>>,
     *     groupAssignments: Collection<int, Collection<int, ShiftRotationAssignment>>,
     *     departmentAssignments: Collection<string, Collection<int, ShiftRotationAssignment>>
     * }
     */
    public function loadBatchContext(Collection $lstEmpleados, CarbonInterface $start, CarbonInterface $end): array
    {
        EloquentCollection::make($lstEmpleados->all())->loadMissing(['workShift', 'groups:id']);

        $lstIdsEmpleados = $lstEmpleados->pluck('id')->filter()->values()->all();
        $dataGruposPorEmpleado = [];
        $dataIdsGrupos = [];
        $dataDepartamentos = [];

        foreach ($lstEmpleados as $emp) {
            $lstIdsGruposEmpleado = $emp->groups->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $dataGruposPorEmpleado[$emp->id] = $lstIdsGruposEmpleado;
            foreach ($lstIdsGruposEmpleado as $gid) {
                $dataIdsGrupos[$gid] = true;
            }
            $dept = trim((string) $emp->department);
            if ($dept !== '') {
                $dataDepartamentos[$dept] = true;
            }
        }

        $startStr = CarbonImmutable::instance($start)->toDateString();
        $endStr = CarbonImmutable::instance($end)->toDateString();

        $lstExcepciones = $lstIdsEmpleados === []
            ? collect()
            : ShiftException::with('workShift')
                ->whereIn('employee_id', $lstIdsEmpleados)
                ->where('starts_on', '<=', $endStr)
                ->where('ends_on', '>=', $startStr)
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get()
                ->groupBy('employee_id');

        $lstIdsGrupos = array_keys($dataIdsGrupos);
        $lstDepartamentos = array_keys($dataDepartamentos);

        $lstAsignaciones = ($lstIdsEmpleados === [] && $lstIdsGrupos === [] && $lstDepartamentos === [])
            ? collect()
            : ShiftRotationAssignment::with(['rotation.steps.workShift', 'group:id,name'])
                ->where('is_active', true)
                ->where('starts_on', '<=', $endStr)
                ->where(function ($q) use ($startStr): void {
                    $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startStr);
                })
                ->whereHas('rotation', fn ($q) => $q->where('is_active', true))
                ->whereHas('rotation.steps')
                ->where(function ($q) use ($lstIdsEmpleados, $lstIdsGrupos, $lstDepartamentos): void {
                    $added = false;
                    if ($lstIdsEmpleados !== []) {
                        $q->where(function ($sub) use ($lstIdsEmpleados): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_EMPLOYEE)
                                ->whereIn('employee_id', $lstIdsEmpleados);
                        });
                        $added = true;
                    }
                    if ($lstIdsGrupos !== []) {
                        $method = $added ? 'orWhere' : 'where';
                        $q->{$method}(function ($sub) use ($lstIdsGrupos): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_GROUP)
                                ->whereIn('employee_group_id', $lstIdsGrupos);
                        });
                        $added = true;
                    }
                    if ($lstDepartamentos !== []) {
                        $method = $added ? 'orWhere' : 'where';
                        $q->{$method}(function ($sub) use ($lstDepartamentos): void {
                            $sub->where('target_type', ShiftRotationAssignment::TARGET_DEPARTMENT)
                                ->whereIn('department', $lstDepartamentos);
                        });
                    }
                })
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get();

        return [
            'exceptionsByEmployee' => $lstExcepciones,
            'groupIdsByEmployee' => $dataGruposPorEmpleado,
            'employeeAssignments' => $lstAsignaciones->where('target_type', ShiftRotationAssignment::TARGET_EMPLOYEE)->groupBy('employee_id'),
            'groupAssignments' => $lstAsignaciones->where('target_type', ShiftRotationAssignment::TARGET_GROUP)->groupBy('employee_group_id'),
            'departmentAssignments' => $lstAsignaciones->where('target_type', ShiftRotationAssignment::TARGET_DEPARTMENT)->groupBy('department'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dataContexto
     * @return array{shift: ?WorkShift, source: string, source_label: string, meta: ?string}
     */
    public function resolveDetailFromContext(Employee $employee, CarbonImmutable $targetDay, array $dataContexto): array
    {
        $dateStr = $targetDay->toDateString();

        // 1. Excepción puntual del empleado
        /** @var Collection<int, ShiftException> $lstExcepcionesEmpleado */
        $lstExcepcionesEmpleado = $dataContexto['exceptionsByEmployee']->get($employee->id, collect());
        $exception = $lstExcepcionesEmpleado->first(function (ShiftException $ex) use ($dateStr): bool {
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
        /** @var Collection<int, ShiftRotationAssignment> $lstAsignacionesEmpleado */
        $lstAsignacionesEmpleado = $dataContexto['employeeAssignments']->get($employee->id, collect());
        if ($resolved = $this->firstMatchingAssignment($lstAsignacionesEmpleado, $targetDay)) {
            [$assignment, $shift] = $resolved;

            return [
                'shift' => $shift,
                'source' => self::SOURCE_EMPLOYEE_ROTATION,
                'source_label' => 'Rot. Empleado',
                'meta' => $assignment->rotation?->name,
            ];
        }

        // 3. Rotación asignada a cualquiera de sus grupos (más reciente primero en caso de múltiples)
        $lstIdsGruposEmpleado = $dataContexto['groupIdsByEmployee'][$employee->id] ?? [];
        if ($lstIdsGruposEmpleado !== []) {
            $lstAsignacionesGrupo = collect();
            foreach ($lstIdsGruposEmpleado as $gid) {
                $lstAsignacionesGrupo = $lstAsignacionesGrupo->merge(
                    $dataContexto['groupAssignments']->get($gid, collect())
                );
            }
            $lstAsignacionesGrupo = $lstAsignacionesGrupo->sort(function (ShiftRotationAssignment $a, ShiftRotationAssignment $b): int {
                $cmp = strcmp((string) $b->starts_on?->toDateString(), (string) $a->starts_on?->toDateString());

                return $cmp !== 0 ? $cmp : ($b->id <=> $a->id);
            });

            if ($resolved = $this->firstMatchingAssignment($lstAsignacionesGrupo, $targetDay)) {
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
            /** @var Collection<int, ShiftRotationAssignment> $lstAsignacionesDepto */
            $lstAsignacionesDepto = $dataContexto['departmentAssignments']->get($dept, collect());
            if ($resolved = $this->firstMatchingAssignment($lstAsignacionesDepto, $targetDay)) {
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
     * @param  Collection<int, ShiftRotationAssignment>  $lstAsignaciones
     * @return array{0: ShiftRotationAssignment, 1: WorkShift}|null
     */
    private function firstMatchingAssignment(Collection $lstAsignaciones, CarbonImmutable $targetDay): ?array
    {
        $dateStr = $targetDay->toDateString();

        foreach ($lstAsignaciones as $assignment) {
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
