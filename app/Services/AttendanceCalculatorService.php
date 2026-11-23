<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\WorkShift;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Motor de cálculo laboral y cumplimiento fiscal para checadas biométricas.
 */
class AttendanceCalculatorService
{
    /**
     * Filas por sentencia INSERT al guardar checadas descargadas.
     */
    private const INSERT_CHUNK = 500;

    /**
     * Descarga eventos desde el dispositivo ISAPI en un rango y los procesa en bitácora y kardex.
     *
     * Optimizado (auditoría OP-02): empleados en un mapa, checadas existentes del rango en una
     * consulta, inserción en lote con insertOrIgnore y un solo recálculo por empleado y día.
     *
     * @return array<string, int|string>
     */
    public function syncFromDevice(
        HikvisionIsapiService $isapi,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $events = $isapi->fetchAttendanceEvents($start, $end);

        /** @var \Illuminate\Support\Collection<string, Employee> $knownEmployees */
        $knownEmployees = Employee::with('workShift')->get()->keyBy('hikvision_employee_no');

        $unmatched = 0;
        $candidates = [];

        foreach ($events as $event) {
            $employee = $knownEmployees->get((string) ($event['employee_no'] ?? ''));

            if ($employee === null) {
                $unmatched++;

                continue;
            }

            $punchedAt = $event['punched_at'] instanceof CarbonInterface
                ? Carbon::instance($event['punched_at'])
                : Carbon::parse((string) $event['punched_at']);

            // La misma checada puede venir repetida en la misma descarga
            $candidates[$employee->id.'|'.$punchedAt->format('Y-m-d H:i:s')] = [$employee, $punchedAt, $event];
        }

        $existing = $this->existingPunchKeys($candidates);
        $now = now();
        $rows = [];
        $touchedDays = [];

        foreach ($candidates as $key => [$employee, $punchedAt, $event]) {
            if (isset($existing[$key])) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'device_ip' => (string) ($event['device_ip'] ?? $isapi->getDeviceIp()),
                'punched_at' => $punchedAt->format('Y-m-d H:i:s'),
                'event_type' => (string) ($event['event_type'] ?? AttendanceLog::EVENT_UNCLASSIFIED),
                'verification_mode' => mb_substr((string) ($event['verification_mode'] ?? 'fingerprint'), 0, 32),
                'raw_payload' => json_encode($event['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $workDay = $this->resolveWorkDateForEmployeePunch($employee, $punchedAt);
            $touchedDays[$employee->id.'|'.$workDay->toDateString()] = [$employee, $workDay];
        }

        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            // insertOrIgnore respeta el índice único si otra sincronización ya insertó la checada
            $inserted += DB::table('attendance_logs')->insertOrIgnore($chunk);
        }

        foreach ($touchedDays as [$employee, $workDate]) {
            $this->recalculateForEmployeeDate($employee, $workDate);
        }

        $matched = count($events) - $unmatched;

        return [
            'received' => count($events),
            'inserted' => $inserted,
            'skipped' => $matched - $inserted,
            'unmatched' => $unmatched,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Llaves "empleado|fecha y hora" que ya existen en la bitácora para los candidatos, en una sola consulta.
     *
     * @param  array<string, array{0: Employee, 1: Carbon, 2: array<string, mixed>}>  $candidates
     * @return array<string, true>
     */
    private function existingPunchKeys(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $times = array_map(fn (array $c): string => $c[1]->format('Y-m-d H:i:s'), $candidates);
        $employeeIds = array_values(array_unique(array_map(fn (array $c): int => $c[0]->id, $candidates)));

        $keys = [];
        AttendanceLog::query()
            ->whereBetween('punched_at', [min($times), max($times)])
            ->whereIn('employee_id', $employeeIds)
            ->toBase()
            ->select(['employee_id', 'punched_at'])
            ->orderBy('id')
            ->chunk(5000, function ($logs) use (&$keys): void {
                foreach ($logs as $log) {
                    $keys[$log->employee_id.'|'.Carbon::parse($log->punched_at)->format('Y-m-d H:i:s')] = true;
                }
            });

        return $keys;
    }

    /**
     * Registra un evento de checada en la bitácora inmutable evitando duplicados
     * y recalcula automáticamente el Kardex diario cuando es un registro nuevo.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function ingestEvent(array $eventData): ?AttendanceLog
    {
        $employeeNo = (string) ($eventData['employee_no'] ?? '');
        if ($employeeNo === '') {
            return null;
        }

        $employee = Employee::where('hikvision_employee_no', $employeeNo)->first();
        if (! $employee) {
            return null;
        }

        $punchedAt = $eventData['punched_at'] instanceof CarbonInterface
            ? Carbon::instance($eventData['punched_at'])
            : Carbon::parse((string) $eventData['punched_at']);

        try {
            $existing = AttendanceLog::where('employee_id', $employee->id)
                ->where('punched_at', $punchedAt->format('Y-m-d H:i:s'))
                ->first();

            if ($existing) {
                return null;
            }

            $log = AttendanceLog::withoutEvents(function () use ($employee, $eventData, $punchedAt): AttendanceLog {
                return AttendanceLog::create([
                    'employee_id' => $employee->id,
                    'device_ip' => (string) ($eventData['device_ip'] ?? '192.168.0.69'),
                    'punched_at' => $punchedAt,
                    'event_type' => (string) ($eventData['event_type'] ?? AttendanceLog::EVENT_UNCLASSIFIED),
                    'verification_mode' => mb_substr((string) ($eventData['verification_mode'] ?? 'fingerprint'), 0, 32),
                    'raw_payload' => $eventData['raw_payload'] ?? $eventData,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->recalculateForPunch($employee, $punchedAt);

        return $log;
    }

    /**
     * Recalcula el DailySummary de la jornada correspondiente a la marca biométrica dada.
     */
    public function recalculateForPunch(Employee $employee, CarbonInterface $punchedAt): DailySummary
    {
        $workDate = $this->resolveWorkDateForEmployeePunch($employee, $punchedAt);

        return $this->recalculateForEmployeeDate($employee, $workDate);
    }

    /**
     * Recalcula el DailySummary de un empleado para una fecha de inicio de jornada específica.
     */
    public function recalculateForEmployeeDate(Employee $employee, CarbonInterface $workDate): DailySummary
    {
        $employee->loadMissing('workShift');
        $shift = $employee->workShift;

        $date = CarbonImmutable::instance($workDate)->startOfDay();
        [$windowStart, $windowEnd] = $shift !== null
            ? $shift->shiftWindowForDate($date)
            : [$date->startOfDay(), $date->endOfDay()];

        /** @var Collection<int, AttendanceLog> $logs */
        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$windowStart, $windowEnd])
            ->orderBy('punched_at')
            ->get();

        $isActiveShiftWindow = CarbonImmutable::now()->betweenIncluded($windowStart, $windowEnd);

        $slots = $this->resolveDailyPunchSlots($logs, $shift, $date, $isActiveShiftWindow);

        $firstCheckIn = $slots['check_in'];
        $breakOut = $slots['break_out'];
        $breakIn = $slots['break_in'];
        $lastCheckOut = $slots['check_out'];

        $lateMinutes = 0;
        if ($firstCheckIn !== null && $shift !== null) {
            $scheduledEntry = CarbonImmutable::parse($date->toDateString().' '.$shift->entry_time);
            $toleranceDeadline = $scheduledEntry->addMinutes($shift->tolerance_minutes);

            if ($firstCheckIn->greaterThan($toleranceDeadline)) {
                $lateMinutes = (int) $scheduledEntry->diffInMinutes($firstCheckIn);
            }
        }

        $mealMinutes = 0;
        if ($breakOut !== null && $breakIn !== null && $breakIn->greaterThanOrEqualTo($breakOut)) {
            $mealMinutes = (int) $breakOut->diffInMinutes($breakIn);
        }

        $workedMinutes = 0;
        $overtimeMinutes = 0;
        if ($firstCheckIn !== null && $lastCheckOut !== null && $lastCheckOut->greaterThan($firstCheckIn)) {
            $grossMinutes = (int) $firstCheckIn->diffInMinutes($lastCheckOut);
            $workedMinutes = max(0, $grossMinutes - $mealMinutes);

            if ($shift !== null) {
                $ordinaryDaily = $shift->ordinaryDailyMinutes();
                $overtimeMinutes = max(0, $workedMinutes - $ordinaryDaily);
            }
        }

        $status = $this->determineDailyStatus(
            logsCount: $logs->count(),
            isActiveShiftWindow: $isActiveShiftWindow,
            firstCheckIn: $firstCheckIn,
            breakOut: $breakOut,
            breakIn: $breakIn,
            lastCheckOut: $lastCheckOut,
            lateMinutes: $lateMinutes,
            mealMinutes: $mealMinutes,
            allowedMealMinutes: $shift?->meal_duration_minutes ?? 60,
        );

        $attributes = [
            'first_check_in' => $firstCheckIn,
            'break_out' => $breakOut,
            'break_in' => $breakIn,
            'last_check_out' => $lastCheckOut,
            'worked_minutes' => $workedMinutes,
            'meal_minutes' => $mealMinutes,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'status' => $status,
        ];

        // Comparación directa (sin whereDate) para usar el índice único (OP-03).
        // updateOrCreate dentro de una transacción evita duplicar el resumen si dos procesos coinciden (SE-13).
        return DB::transaction(fn (): DailySummary => DailySummary::updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $date->toDateString()],
            $attributes,
        ));
    }

    /**
     * Recalcula todos los resúmenes diarios vinculados a un turno laboral (ej. tras editar tolerancia o entrada).
     */
    public function recalculateAllForShift(WorkShift $shift): int
    {
        $employees = Employee::with('workShift')
            ->where('work_shift_id', $shift->id)
            ->get();

        return $this->recalculateForEmployees($employees);
    }

    /**
     * Recalcula todos los resúmenes diarios a partir de los registros históricos de bitácora.
     */
    public function recalculateAll(): int
    {
        $employees = Employee::with('workShift')->get();

        return $this->recalculateForEmployees($employees);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function recalculateForEmployees(Collection $employees): int
    {
        if ($employees->isEmpty()) {
            return 0;
        }

        $byId = $employees->keyBy('id');
        $targets = [];

        AttendanceLog::whereIn('employee_id', $byId->keys())
            ->orderBy('punched_at')
            ->get(['id', 'employee_id', 'punched_at'])
            ->each(function (AttendanceLog $log) use ($byId, &$targets): void {
                $employee = $byId->get($log->employee_id);
                if ($employee === null) {
                    return;
                }

                $workDate = $this->resolveWorkDateForEmployeePunch($employee, $log->punched_at);
                $targets[$employee->id.'|'.$workDate->toDateString()] = [$employee, $workDate];
            });

        DailySummary::whereIn('employee_id', $byId->keys())
            ->get(['id', 'employee_id', 'work_date'])
            ->each(function (DailySummary $summary) use ($byId, &$targets): void {
                $employee = $byId->get($summary->employee_id);
                if ($employee === null || $summary->work_date === null) {
                    return;
                }

                $workDate = CarbonImmutable::instance($summary->work_date)->startOfDay();
                $targets[$employee->id.'|'.$workDate->toDateString()] ??= [$employee, $workDate];
            });

        foreach ($targets as [$employee, $workDate]) {
            $this->recalculateForEmployeeDate($employee, $workDate);
        }

        return count($targets);
    }

    /**
     * Resuelve las 4 marcas diarias respetando primero el attendanceStatus explícito,
     * normalizando comidas invertidas o huérfanas y aplicando respaldo cronológico
     * cuando existen checadas sin clasificar.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array<string, CarbonImmutable|null>
     */
    private function resolveDailyPunchSlots(
        Collection $logs,
        ?WorkShift $shift = null,
        ?CarbonImmutable $workDate = null,
        bool $isActiveShiftWindow = false,
    ): array {
        $slots = [
            'check_in' => null,
            'break_out' => null,
            'break_in' => null,
            'check_out' => null,
        ];

        if ($logs->isEmpty()) {
            return $slots;
        }

        // 1. Mapeo explícito desde attendanceStatus / statusValue del reloj
        $breakLogs = [];
        foreach ($logs as $log) {
            $punchedAt = CarbonImmutable::instance($log->punched_at);

            if ($log->event_type === AttendanceLog::EVENT_CHECK_IN && $slots['check_in'] === null) {
                $slots['check_in'] = $punchedAt;
            } elseif ($log->event_type === AttendanceLog::EVENT_BREAK_OUT) {
                $breakLogs[] = $punchedAt;
                if ($slots['break_out'] === null) {
                    $slots['break_out'] = $punchedAt;
                }
            } elseif ($log->event_type === AttendanceLog::EVENT_BREAK_IN) {
                $breakLogs[] = $punchedAt;
                if ($slots['break_in'] === null) {
                    $slots['break_in'] = $punchedAt;
                }
            } elseif ($log->event_type === AttendanceLog::EVENT_CHECK_OUT) {
                $slots['check_out'] = $punchedAt;
            }
        }

        // 1b. Normalizar marcas de comida invertidas (ej. breakIn/breakOut del DS-K1T804AEF) o huérfanas
        $breakCount = count($breakLogs);
        if ($breakCount === 1) {
            // Una sola marca de comida siempre corresponde al inicio de comida en curso
            $slots['break_out'] = $breakLogs[0];
            $slots['break_in'] = null;
        } elseif ($breakCount >= 2) {
            // Con dos o más marcas en orden cronológico, la primera es salida y la última regreso
            if ($slots['break_out'] === null || $slots['break_in'] === null || $slots['break_in']->lessThan($slots['break_out'])) {
                $slots['break_out'] = $breakLogs[0];
                $slots['break_in'] = $breakLogs[$breakCount - 1];
            }
        }

        // 2. Respaldo cronológico sobre las checadas sin tecla de estado
        $orderedLogs = $logs->values();
        $totalCount = $orderedLogs->count();
        $unclassifiedLogs = $orderedLogs->filter(
            fn (AttendanceLog $log): bool => $log->event_type === AttendanceLog::EVENT_UNCLASSIFIED
        )->values();

        if ($unclassifiedLogs->isNotEmpty()) {
            $scheduledExit = null;
            if ($shift !== null && $workDate !== null) {
                $exitDate = $shift->crossesMidnight() ? $workDate->addDay() : $workDate;
                $scheduledExit = CarbonImmutable::parse($exitDate->toDateString().' '.$shift->exit_time);
            }

            $chronologicalMap = [
                0 => 'check_in',
                1 => 'break_out',
                2 => 'break_in',
            ];

            foreach ($orderedLogs as $index => $log) {
                if ($log->event_type !== AttendanceLog::EVENT_UNCLASSIFIED) {
                    continue;
                }

                $punchedAt = CarbonImmutable::instance($log->punched_at);

                // Si solo hubo 2 checadas y la 2ª parece salida de jornada (cerca de exit_time o día cerrado), es check_out
                if ($index === 1 && $totalCount === 2 && $slots['check_out'] === null && $slots['break_out'] === null) {
                    $isLikelyExit = ! $isActiveShiftWindow
                        || ($scheduledExit !== null && $punchedAt->greaterThanOrEqualTo($scheduledExit->subMinutes(90)));

                    $targetSlot = $isLikelyExit ? 'check_out' : 'break_out';
                    $slots[$targetSlot] = $punchedAt;
                    continue;
                }

                if (isset($chronologicalMap[$index])) {
                    $targetSlot = $chronologicalMap[$index];
                    if ($slots[$targetSlot] === null) {
                        $slots[$targetSlot] = $punchedAt;
                    }
                } elseif ($index === $totalCount - 1 && $index >= 3) {
                    if ($slots['check_out'] === null) {
                        $slots['check_out'] = $punchedAt;
                    }
                }
            }
        }

        return $slots;
    }

    private function resolveWorkDateForEmployeePunch(Employee $employee, CarbonInterface $punchedAt): CarbonImmutable
    {
        $employee->loadMissing('workShift');

        if ($employee->workShift !== null) {
            return $employee->workShift->resolveWorkDateForPunch($punchedAt);
        }

        return CarbonImmutable::instance($punchedAt)->startOfDay();
    }

    private function determineDailyStatus(
        int $logsCount,
        bool $isActiveShiftWindow,
        ?CarbonImmutable $firstCheckIn,
        ?CarbonImmutable $breakOut,
        ?CarbonImmutable $breakIn,
        ?CarbonImmutable $lastCheckOut,
        int $lateMinutes,
        int $mealMinutes,
        int $allowedMealMinutes,
    ): string {
        if ($logsCount === 0) {
            return DailySummary::STATUS_ABSENT;
        }

        // Salió a comer y aún no regresa ni ha cerrado turno
        if ($breakOut !== null && $breakIn === null && $lastCheckOut === null) {
            if ($isActiveShiftWindow) {
                $elapsedMeal = (int) $breakOut->diffInMinutes(CarbonImmutable::now());

                return $elapsedMeal > $allowedMealMinutes
                    ? DailySummary::STATUS_MEAL_EXCEEDED
                    : DailySummary::STATUS_IN_MEAL;
            }

            return DailySummary::STATUS_INCOMPLETE;
        }

        if ($firstCheckIn === null || (! $isActiveShiftWindow && $lastCheckOut === null)) {
            return DailySummary::STATUS_INCOMPLETE;
        }

        if ($mealMinutes > $allowedMealMinutes) {
            return DailySummary::STATUS_MEAL_EXCEEDED;
        }

        if ($lateMinutes > 0) {
            return DailySummary::STATUS_LATE;
        }

        return DailySummary::STATUS_ON_TIME;
    }
}
