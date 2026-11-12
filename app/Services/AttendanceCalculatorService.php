<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Motor de cálculo laboral y cumplimiento fiscal para checadas biométricas.
 */
class AttendanceCalculatorService
{
    /**
     * Descarga eventos desde el dispositivo ISAPI en un rango y los procesa en bitácora y kardex.
     *
     * @return array<string, int|string>
     */
    public function syncFromDevice(
        HikvisionIsapiService $isapi,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $events = $isapi->fetchAttendanceEvents($start, $end);
        $knownEmployees = Employee::pluck('id', 'hikvision_employee_no');

        $inserted = 0;
        $skipped = 0;
        $unmatched = 0;

        foreach ($events as $event) {
            $employeeNo = (string) ($event['employee_no'] ?? '');

            if ($employeeNo === '' || ! $knownEmployees->has($employeeNo)) {
                $unmatched++;

                continue;
            }

            $log = $this->ingestEvent($event);

            if ($log !== null) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        return [
            'received' => count($events),
            'inserted' => $inserted,
            'skipped' => $skipped,
            'unmatched' => $unmatched,
            'synced_at' => now()->toIso8601String(),
        ];
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
                    'verification_mode' => (string) ($eventData['verification_mode'] ?? 'fingerprint'),
                    'raw_payload' => $eventData['raw_payload'] ?? $eventData,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->recalculateForEmployeeDate($employee, $punchedAt);

        return $log;
    }

    /**
     * Recalcula el DailySummary de un empleado para una fecha laboral específica.
     */
    public function recalculateForEmployeeDate(Employee $employee, CarbonInterface $workDate): DailySummary
    {
        $employee->loadMissing('workShift');
        $shift = $employee->workShift;

        $date = CarbonImmutable::instance($workDate)->startOfDay();
        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();

        /** @var Collection<int, AttendanceLog> $logs */
        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$dayStart, $dayEnd])
            ->orderBy('punched_at')
            ->get();

        $slots = $this->resolveDailyPunchSlots($logs);

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
            workDate: $date,
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

        $existingSummary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        if ($existingSummary !== null) {
            $existingSummary->update($attributes);

            return $existingSummary;
        }

        return DailySummary::create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            ...$attributes,
        ]);
    }

    /**
     * Resuelve las 4 marcas diarias respetando primero el attendanceStatus explícito
     * y aplicando respaldo cronológico (1ª=check_in, 2ª=break_out, 3ª=break_in, 4ª=check_out)
     * cuando existen checadas sin clasificar.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array<string, CarbonImmutable|null>
     */
    private function resolveDailyPunchSlots(Collection $logs): array
    {
        $slots = [
            'check_in' => null,
            'break_out' => null,
            'break_in' => null,
            'check_out' => null,
        ];

        if ($logs->isEmpty()) {
            return $slots;
        }

        // 1. Mapeo explícito desde attendanceStatus del reloj
        foreach ($logs as $log) {
            $punchedAt = CarbonImmutable::instance($log->punched_at);

            if ($log->event_type === AttendanceLog::EVENT_CHECK_IN && $slots['check_in'] === null) {
                $slots['check_in'] = $punchedAt;
            } elseif ($log->event_type === AttendanceLog::EVENT_BREAK_OUT && $slots['break_out'] === null) {
                $slots['break_out'] = $punchedAt;
            } elseif ($log->event_type === AttendanceLog::EVENT_BREAK_IN && $slots['break_in'] === null) {
                $slots['break_in'] = $punchedAt;
            } elseif ($log->event_type === AttendanceLog::EVENT_CHECK_OUT) {
                $slots['check_out'] = $punchedAt;
            }
        }

        // 2. Respaldo cronológico sobre las checadas sin tecla de estado
        $orderedLogs = $logs->values();
        $totalCount = $orderedLogs->count();
        $unclassifiedLogs = $orderedLogs->filter(
            fn (AttendanceLog $log): bool => $log->event_type === AttendanceLog::EVENT_UNCLASSIFIED
        )->values();

        if ($unclassifiedLogs->isNotEmpty()) {
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

    private function determineDailyStatus(
        int $logsCount,
        CarbonImmutable $workDate,
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
            if ($workDate->isToday()) {
                $elapsedMeal = (int) $breakOut->diffInMinutes(CarbonImmutable::now());

                return $elapsedMeal > $allowedMealMinutes
                    ? DailySummary::STATUS_MEAL_EXCEEDED
                    : DailySummary::STATUS_IN_MEAL;
            }

            return DailySummary::STATUS_INCOMPLETE;
        }

        if ($firstCheckIn === null || (! $workDate->isToday() && $lastCheckOut === null)) {
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
