<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\ShiftRotation;
use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recalcula los resúmenes diarios (daily_summaries) a partir de la bitácora inmutable,
 * respetando turnos rotativos y excepciones resueltos por ShiftScheduleResolver.
 */
class RecalculateAttendanceCommand extends Command
{
    protected $signature = 'attendance:recalculate
        {--shift= : ID de un turno específico a recalcular}
        {--from= : Fecha inicial YYYY-MM-DD}
        {--to= : Fecha final YYYY-MM-DD}
        {--employee= : ID de un empleado específico}
        {--group= : ID de un grupo de empleados}
        {--department= : Nombre de departamento}
        {--rotation= : ID de rotación}';

    protected $description = 'Recalcula los resúmenes diarios (comida, retardos, horas trabajadas y extra) sin alterar attendance_logs';

    public function handle(AttendanceCalculatorService $calculator): int
    {
        $shiftId = $this->option('shift');
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $employeeOpt = $this->option('employee');
        $groupOpt = $this->option('group');
        $departmentOpt = $this->option('department');
        $rotationOpt = $this->option('rotation');

        $from = null;
        if ($fromOpt !== null && $fromOpt !== '') {
            try {
                $from = CarbonImmutable::parse((string) $fromOpt)->startOfDay();
            } catch (\Throwable) {
                $this->error("Fecha --from inválida: {$fromOpt}");

                return self::FAILURE;
            }
        }

        $to = null;
        if ($toOpt !== null && $toOpt !== '') {
            try {
                $to = CarbonImmutable::parse((string) $toOpt)->startOfDay();
            } catch (\Throwable) {
                $this->error("Fecha --to inválida: {$toOpt}");

                return self::FAILURE;
            }
        }

        if ($shiftId !== null && $shiftId !== '' && $from === null && $to === null && $employeeOpt === null && $groupOpt === null && $departmentOpt === null && $rotationOpt === null) {
            $shift = WorkShift::find($shiftId);
            if ($shift === null) {
                $this->error("No existe un turno con ID {$shiftId}.");

                return self::FAILURE;
            }

            $count = $calculator->recalculateAllForShift($shift);
            $this->info("Se recalcularon {$count} jornadas del turno {$shift->name}.");

            return self::SUCCESS;
        }

        $hasScopeFilter = ($employeeOpt !== null && $employeeOpt !== '')
            || ($groupOpt !== null && $groupOpt !== '')
            || ($departmentOpt !== null && $departmentOpt !== '')
            || ($rotationOpt !== null && $rotationOpt !== '');

        $lstEmpleados = null;

        if ($hasScopeFilter) {
            $lstIdsEmpleados = collect();

            if ($employeeOpt !== null && $employeeOpt !== '') {
                $lstIdsEmpleados->push((int) $employeeOpt);
            }

            if ($groupOpt !== null && $groupOpt !== '') {
                $group = EmployeeGroup::with('employees:id')->find((int) $groupOpt);
                if ($group !== null) {
                    $lstIdsEmpleados = $lstIdsEmpleados->merge($group->employees->pluck('id'));
                }
            }

            if ($departmentOpt !== null && $departmentOpt !== '') {
                $lstIdsDepartamento = Employee::where('department', (string) $departmentOpt)->pluck('id');
                $lstIdsEmpleados = $lstIdsEmpleados->merge($lstIdsDepartamento);
            }

            if ($rotationOpt !== null && $rotationOpt !== '') {
                $rotation = ShiftRotation::with('assignments')->find((int) $rotationOpt);
                if ($rotation !== null) {
                    foreach ($rotation->assignments as $assignment) {
                        if ($assignment->employee_id !== null) {
                            $lstIdsEmpleados->push($assignment->employee_id);
                        }
                        if ($assignment->employee_group_id !== null) {
                            $grp = EmployeeGroup::with('employees:id')->find($assignment->employee_group_id);
                            if ($grp !== null) {
                                $lstIdsEmpleados = $lstIdsEmpleados->merge($grp->employees->pluck('id'));
                            }
                        }
                        if ($assignment->department !== null && $assignment->department !== '') {
                            $lstIdsEmpleados = $lstIdsEmpleados->merge(
                                Employee::where('department', $assignment->department)->pluck('id')
                            );
                        }
                    }
                }
            }

            $lstIdsUnicos = $lstIdsEmpleados->filter()->unique()->values();
            $lstEmpleados = $lstIdsUnicos->isEmpty()
                ? collect()
                : Employee::with(['workShift', 'groups:id'])->whereIn('id', $lstIdsUnicos)->get();
        }

        $count = $calculator->recalculateForScope($lstEmpleados, $from, $to);
        $this->info("Se recalcularon {$count} jornadas a partir de attendance_logs.");

        return self::SUCCESS;
    }
}
