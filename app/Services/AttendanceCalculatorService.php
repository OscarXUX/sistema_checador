<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\ShiftException;
use App\Models\ShiftRotationAssignment;
use App\Models\WorkShift;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de cálculo laboral y cumplimiento fiscal para checadas biométricas.
 */
class AttendanceCalculatorService
{
    /**
     * Filas por sentencia INSERT al guardar checadas descargadas.
     */
    private const INSERT_CHUNK = 500;

    public function __construct(private readonly ?ShiftScheduleResolver $shiftResolver = null) {}

    private function resolver(): ShiftScheduleResolver
    {
        return $this->shiftResolver ?? app(ShiftScheduleResolver::class);
    }

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

        /** @var Collection<string, Employee> $knownEmployees */
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
        $newCandidates = [];

        foreach ($candidates as $key => [$employee, $punchedAt, $event]) {
            if (! isset($existing[$key])) {
                $newCandidates[] = [$employee, $punchedAt, $event];
            }
        }

        $scheduleContext = null;
        if ($newCandidates !== []) {
            $touchedEmployees = collect(array_column($newCandidates, 0))->unique('id')->values();
            $times = array_map(fn (array $c): Carbon => $c[1], $newCandidates);
            $minDay = CarbonImmutable::instance(min($times))->subDay()->startOfDay();
            $maxDay = CarbonImmutable::instance(max($times))->addDay()->startOfDay();
            $scheduleContext = $this->resolver()->loadBatchContext($touchedEmployees, $minDay, $maxDay);
        }

        foreach ($newCandidates as [$employee, $punchedAt, $event]) {
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

            $workDay = $this->resolveWorkDateForEmployeePunch($employee, $punchedAt, $scheduleContext);
            $touchedDays[$employee->id.'|'.$workDay->toDateString()] = [$employee, $workDay];
        }

        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            // insertOrIgnore respeta el índice único si otra sincronización ya insertó la checada
            $inserted += DB::table('attendance_logs')->insertOrIgnore($chunk);
        }

        foreach ($touchedDays as [$employee, $workDate]) {
            $this->recalculateForEmployeeDate($employee, $workDate, $scheduleContext);
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
     *
     * @param  array<string, mixed>|null  $scheduleContext
     */
    public function recalculateForEmployeeDate(
        Employee $employee,
        CarbonInterface $workDate,
        ?array $scheduleContext = null
    ): DailySummary {
        $date = CarbonImmutable::instance($workDate)->startOfDay();
        ['shift' => $shift, 'logs' => $logs, 'slots' => $slots, 'active' => $isActiveShiftWindow] = $this->classifyDay($employee, $date, $scheduleContext);

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

        $earlyExitMinutes = 0;
        if ($lastCheckOut !== null && $shift !== null) {
            $scheduledExit = CarbonImmutable::parse(($shift->crossesMidnight() ? $date->addDay() : $date)->toDateString().' '.$shift->exit_time);
            if ($lastCheckOut->lessThan($scheduledExit)) {
                $earlyExitMinutes = (int) $lastCheckOut->diffInMinutes($scheduledExit);
            }
        }

        $mealExceededMinutes = $breakIn !== null ? max(0, $mealMinutes - ($shift?->meal_duration_minutes ?? 60)) : 0;

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
            'early_exit_minutes' => $earlyExitMinutes,
            'meal_exceeded_minutes' => $mealExceededMinutes,
            'status' => $status,
            'incidents' => $slots['incidents'] === [] ? null : $slots['incidents'],
            'corrected_punches' => $slots['corrected'],
            // Tipos de evento del catálogo (nota 4): solo con turno, porque se miden contra su horario
            ...app(EventTypeClassifier::class)->idsFor(
                $firstCheckIn !== null && $shift !== null,
                $lateMinutes,
                $lastCheckOut !== null && $shift !== null,
                $earlyExitMinutes,
                $breakIn !== null,
                $mealExceededMinutes,
            ),
        ];

        // Comparación directa (sin whereDate) para usar el índice único (OP-03).
        // updateOrCreate dentro de una transacción evita duplicar el resumen si dos procesos coinciden (SE-13).
        return DB::transaction(fn (): DailySummary => DailySummary::updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $date->toDateString()],
            $attributes,
        ));
    }

    /**
     * Turno, checadas y clasificación de una jornada (lo usan el recálculo y la pantalla de corrección).
     *
     * @param  array<string, mixed>|null  $scheduleContext
     * @return array{shift: ?WorkShift, logs: Collection<int, AttendanceLog>, slots: array<string, mixed>, active: bool}
     */
    public function classifyDay(Employee $employee, CarbonInterface $workDate, ?array $scheduleContext = null): array
    {
        $employee->loadMissing('workShift');
        $date = CarbonImmutable::instance($workDate)->startOfDay();
        $shift = $scheduleContext !== null
            ? $this->resolver()->resolveDetailFromContext($employee, $date, $scheduleContext)['shift']
            : $this->resolver()->resolveForEmployeeOnDate($employee, $date);
        [$windowStart, $windowEnd] = $shift !== null
            ? $shift->shiftWindowForDate($date)
            : [$date->startOfDay(), $date->endOfDay()];

        /** @var Collection<int, AttendanceLog> $logs */
        $logs = AttendanceLog::with('correction')
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$windowStart, $windowEnd])
            ->orderBy('punched_at')
            ->get();

        $isActiveShiftWindow = CarbonImmutable::now()->betweenIncluded($windowStart, $windowEnd);

        // Con turno: clasificación por ventanas de horario e incidencias (paquete 3); sin turno, la de siempre
        if ($shift !== null) {
            $slots = app(PunchClassifier::class)->classify($logs, $shift, $date, $isActiveShiftWindow);
        } else {
            // Sin turno, las correcciones se aplican sobre la tecla (en memoria; la checada no se guarda)
            $lstUsables = $logs->reject(fn (AttendanceLog $log): bool => $log->correction?->corrected_type === \App\Models\AttendanceLogCorrection::TYPE_IGNORE)
                ->map(function (AttendanceLog $log): AttendanceLog {
                    if ($log->correction !== null) {
                        $copy = clone $log;
                        $copy->event_type = $log->correction->corrected_type;

                        return $copy;
                    }

                    return $log;
                })->values();
            $slots = $this->resolveDailyPunchSlots($lstUsables, null, $date, $isActiveShiftWindow);
            $slots['incidents'] = [];
            $slots['roles'] = [];
            $slots['corrected'] = $logs->filter(fn (AttendanceLog $log): bool => $log->correction !== null)->count();
        }

        return ['shift' => $shift, 'logs' => $logs, 'slots' => $slots, 'active' => $isActiveShiftWindow];
    }

    /**
     * Recalcula todos los resúmenes diarios vinculados a un turno laboral (directo, en rotaciones o excepciones).
     */
    public function recalculateAllForShift(WorkShift $shift): int
    {
        $directEmployeeIds = Employee::where('work_shift_id', $shift->id)->pluck('id');
        $exceptionEmployeeIds = ShiftException::where('work_shift_id', $shift->id)->pluck('employee_id');

        $rotationAssignments = ShiftRotationAssignment::query()
            ->whereHas('rotation.steps', fn ($q) => $q->where('work_shift_id', $shift->id))
            ->get();

        $rotationEmpIds = $rotationAssignments
            ->where('target_type', ShiftRotationAssignment::TARGET_EMPLOYEE)
            ->pluck('employee_id')
            ->filter();
        $rotationGroupIds = $rotationAssignments
            ->where('target_type', ShiftRotationAssignment::TARGET_GROUP)
            ->pluck('employee_group_id')
            ->filter();
        $rotationDepts = $rotationAssignments
            ->where('target_type', ShiftRotationAssignment::TARGET_DEPARTMENT)
            ->pluck('department')
            ->filter();

        $allEmployeeIds = collect()
            ->merge($directEmployeeIds)
            ->merge($exceptionEmployeeIds)
            ->merge($rotationEmpIds);

        $employees = Employee::with(['workShift', 'groups:id'])
            ->where(function ($q) use ($allEmployeeIds, $rotationGroupIds, $rotationDepts): void {
                if ($allEmployeeIds->isNotEmpty()) {
                    $q->whereIn('id', $allEmployeeIds->unique()->values());
                } else {
                    $q->whereRaw('1 = 0');
                }
                if ($rotationGroupIds->isNotEmpty()) {
                    $q->orWhereHas('groups', fn ($g) => $g->whereIn('employee_groups.id', $rotationGroupIds));
                }
                if ($rotationDepts->isNotEmpty()) {
                    $q->orWhereIn('department', $rotationDepts);
                }
            })
            ->get();

        return $this->recalculateForScope($employees);
    }

    /**
     * Recalcula todos los resúmenes diarios a partir de los registros históricos de bitácora.
     */
    public function recalculateAll(): int
    {
        return $this->recalculateForScope();
    }

    /**
     * Recalcula jornadas para un subconjunto de empleados y/o rango de fechas usando ShiftScheduleResolver.
     *
     * @param  Collection<int, Employee>|null  $employees
     */
    public function recalculateForScope(
        ?Collection $employees = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): int {
        $employees ??= Employee::with(['workShift', 'groups:id'])->get();

        if ($employees->isEmpty()) {
            return 0;
        }

        EloquentCollection::make($employees->all())->loadMissing(['workShift', 'groups:id']);

        $fromDay = $from !== null ? CarbonImmutable::instance($from)->startOfDay() : null;
        $toDay = $to !== null ? CarbonImmutable::instance($to)->startOfDay() : null;

        if ($fromDay !== null && $toDay !== null && $fromDay->greaterThan($toDay)) {
            [$fromDay, $toDay] = [$toDay, $fromDay];
        }

        $byId = $employees->keyBy('id');

        $logsQuery = AttendanceLog::whereIn('employee_id', $byId->keys())
            ->orderBy('punched_at');

        if ($fromDay !== null) {
            $logsQuery->where('punched_at', '>=', $fromDay->subDay()->startOfDay());
        }
        if ($toDay !== null) {
            $logsQuery->where('punched_at', '<=', $toDay->addDay()->endOfDay());
        }

        $logs = $logsQuery->get(['id', 'employee_id', 'punched_at']);

        $summaryQuery = DailySummary::whereIn('employee_id', $byId->keys());
        if ($fromDay !== null) {
            $summaryQuery->where('work_date', '>=', $fromDay->toDateString());
        }
        if ($toDay !== null) {
            $summaryQuery->where('work_date', '<=', $toDay->toDateString());
        }
        $summaries = $summaryQuery->get(['id', 'employee_id', 'work_date']);

        if ($logs->isEmpty() && $summaries->isEmpty()) {
            return 0;
        }

        $dateBounds = [];
        if ($fromDay !== null) {
            $dateBounds[] = $fromDay->subDay();
        }
        if ($toDay !== null) {
            $dateBounds[] = $toDay->addDay();
        }
        foreach ($logs as $log) {
            $dateBounds[] = CarbonImmutable::instance($log->punched_at)->startOfDay();
        }
        foreach ($summaries as $summary) {
            if ($summary->work_date !== null) {
                $dateBounds[] = CarbonImmutable::instance($summary->work_date)->startOfDay();
            }
        }

        $minDate = collect($dateBounds)->min()->subDay();
        $maxDate = collect($dateBounds)->max()->addDay();
        $scheduleContext = $this->resolver()->loadBatchContext($employees, $minDate, $maxDate);

        $targets = [];

        foreach ($logs as $log) {
            $employee = $byId->get($log->employee_id);
            if ($employee === null) {
                continue;
            }

            $workDate = $this->resolveWorkDateForEmployeePunch($employee, $log->punched_at, $scheduleContext);
            if ($fromDay !== null && $workDate->lessThan($fromDay)) {
                continue;
            }
            if ($toDay !== null && $workDate->greaterThan($toDay)) {
                continue;
            }

            $targets[$employee->id.'|'.$workDate->toDateString()] = [$employee, $workDate];
        }

        foreach ($summaries as $summary) {
            $employee = $byId->get($summary->employee_id);
            if ($employee === null || $summary->work_date === null) {
                continue;
            }

            $workDate = CarbonImmutable::instance($summary->work_date)->startOfDay();
            $targets[$employee->id.'|'.$workDate->toDateString()] ??= [$employee, $workDate];
        }

        foreach ($targets as [$employee, $workDate]) {
            $this->recalculateForEmployeeDate($employee, $workDate, $scheduleContext);
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

    /**
     * @param  array<string, mixed>|null  $scheduleContext
     */
    private function resolveWorkDateForEmployeePunch(
        Employee $employee,
        CarbonInterface $punchedAt,
        ?array $scheduleContext = null
    ): CarbonImmutable {
        $instant = CarbonImmutable::instance($punchedAt);
        $baseDay = $instant->startOfDay();

        $resolveShift = function (CarbonImmutable $day) use ($employee, $scheduleContext): ?WorkShift {
            if ($scheduleContext !== null) {
                return $this->resolver()->resolveDetailFromContext($employee, $day, $scheduleContext)['shift'];
            }

            return $this->resolver()->resolveForEmployeeOnDate($employee, $day);
        };

        $todayShift = $resolveShift($baseDay);
        $yesterday = $baseDay->subDay();
        $yesterdayShift = $resolveShift($yesterday);

        // Turno nocturno del día anterior: la salida de madrugada pertenece a la jornada de ayer
        if ($yesterdayShift !== null && $yesterdayShift->crossesMidnight()) {
            [$prevStart, $prevEnd] = $yesterdayShift->shiftWindowForDate($yesterday);

            if ($todayShift !== null && ! $todayShift->crossesMidnight()) {
                $prevExit = CarbonImmutable::parse($baseDay->toDateString().' '.$yesterdayShift->exit_time);
                $todayEntry = CarbonImmutable::parse($baseDay->toDateString().' '.$todayShift->entry_time);
                if ($todayEntry->greaterThan($prevExit)) {
                    $midpoint = $prevExit->addSeconds(intdiv((int) $prevExit->diffInSeconds($todayEntry), 2));
                    if ($midpoint->lessThan($prevEnd)) {
                        $prevEnd = $midpoint;
                    }
                }
            }

            if ($instant->betweenIncluded($prevStart, $prevEnd)) {
                return $yesterday;
            }
        }

        // Turno nocturno del día actual (o turno que cruza hacia mañana)
        if ($todayShift !== null && $todayShift->crossesMidnight()) {
            [$curStart, $curEnd] = $todayShift->shiftWindowForDate($baseDay);
            if ($instant->betweenIncluded($curStart, $curEnd)) {
                return $baseDay;
            }
        }

        // Turno nocturno que inicia mañana cuya ventana temprana comienza hoy por la tarde
        $tomorrow = $baseDay->addDay();
        $tomorrowShift = $resolveShift($tomorrow);
        if ($tomorrowShift !== null && $tomorrowShift->crossesMidnight()) {
            [$nextStart, $nextEnd] = $tomorrowShift->shiftWindowForDate($tomorrow);
            if ($todayShift !== null && ! $todayShift->crossesMidnight()) {
                $todayExit = CarbonImmutable::parse($baseDay->toDateString().' '.$todayShift->exit_time);
                $nextEntry = CarbonImmutable::parse($tomorrow->toDateString().' '.$tomorrowShift->entry_time);
                if ($nextEntry->greaterThan($todayExit)) {
                    $midpoint = $todayExit->addSeconds(intdiv((int) $todayExit->diffInSeconds($nextEntry), 2));
                    if ($midpoint->greaterThan($nextStart)) {
                        $nextStart = $midpoint;
                    }
                }
            }

            if ($instant->betweenIncluded($nextStart, $nextEnd)) {
                return $tomorrow;
            }
        }

        return $baseDay;
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
