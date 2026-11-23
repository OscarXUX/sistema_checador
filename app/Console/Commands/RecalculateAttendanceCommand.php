<?php

namespace App\Console\Commands;

use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use Illuminate\Console\Command;

/**
 * Recalcula todos los resúmenes diarios (daily_summaries) a partir de la bitácora inmutable.
 */
class RecalculateAttendanceCommand extends Command
{
    protected $signature = 'attendance:recalculate {--shift= : ID de un turno específico a recalcular}';

    protected $description = 'Recalcula los resúmenes diarios (comida, retardos, horas trabajadas y extra) sin alterar attendance_logs';

    public function handle(AttendanceCalculatorService $calculator): int
    {
        $shiftId = $this->option('shift');

        if ($shiftId !== null && $shiftId !== '') {
            $shift = WorkShift::find($shiftId);
            if ($shift === null) {
                $this->error("No existe un turno con ID {$shiftId}.");

                return self::FAILURE;
            }

            $count = $calculator->recalculateAllForShift($shift);
            $this->info("Se recalcularon {$count} jornadas del turno {$shift->name}.");

            return self::SUCCESS;
        }

        $count = $calculator->recalculateAll();
        $this->info("Se recalcularon {$count} jornadas a partir de attendance_logs.");

        return self::SUCCESS;
    }
}
