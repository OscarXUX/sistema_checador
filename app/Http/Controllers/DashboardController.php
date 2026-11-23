<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard', [
            'dashboardData' => $this->buildDashboardPayload(),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->buildDashboardPayload());
    }

    /**
     * Construye los KPIs ejecutivos, datasets para Wijmo FlexChart y monitor en vivo.
     *
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $todayStr = $today->toDateString();

        $activeEmployeesCount = Employee::active()->count();

        // Comparaciones directas en lugar de whereDate() para usar los índices (auditoría OP-03)
        // Incluye turnos nocturnos iniciados ayer cuya ventana laboral siga activa en $now (SE-11)
        $yesterdayStr = $today->subDay()->toDateString();
        $activeWindowDates = DailySummary::with(['employee.workShift'])
            ->whereIn('work_date', [$yesterdayStr, $todayStr])
            ->get()
            ->filter(function (DailySummary $summary) use ($now, $todayStr): bool {
                if ($summary->work_date?->toDateString() === $todayStr) {
                    return true;
                }

                $shift = $summary->employee?->workShift;
                if ($shift === null || ! $shift->crossesMidnight()) {
                    return false;
                }

                [$winStart, $winEnd] = $shift->shiftWindowForDate($summary->work_date);

                return $now->betweenIncluded($winStart, $winEnd);
            })
            ->values();

        // Incidencias de las jornadas en curso (paquete 3: checadas repetidas o fuera de orden)
        $incidentAlerts = $activeWindowDates
            ->flatMap(fn (DailySummary $summary): array => array_map(fn (array $incident): array => [
                'employee_name' => $summary->employee?->full_name ?? 'N/D',
                'employee_no' => $summary->employee?->hikvision_employee_no ?? '-',
                'label' => $incident['label'],
                'at' => $incident['at'],
            ], $summary->incidentList()))
            ->sortByDesc('at')
            ->values();

        $todaySummaries = $activeWindowDates->filter(
            fn (DailySummary $summary): bool => $summary->work_date?->toDateString() === $todayStr
        );

        // Empleados actualmente dentro de planta (entraron, no han salido ni están en comida activa)
        $inPlantCount = $activeWindowDates->filter(function (DailySummary $summary): bool {
            $inActiveMeal = $summary->break_out !== null
                && $summary->break_in === null
                && $summary->last_check_out === null;

            return $summary->first_check_in !== null
                && $summary->last_check_out === null
                && ! $inActiveMeal;
        })->count();

        // Empleados actualmente en hora de comida (break_out marcado y sin break_in/check_out)
        $currentlyInMeal = $activeWindowDates->filter(function (DailySummary $summary): bool {
            return $summary->break_out !== null
                && $summary->break_in === null
                && $summary->last_check_out === null;
        });

        $mealAlerts = [];
        foreach ($activeWindowDates as $summary) {
            $allowed = $summary->employee?->workShift?->meal_duration_minutes ?? 60;
            $isActiveMeal = $summary->break_out !== null && $summary->break_in === null && $summary->last_check_out === null;

            if ($isActiveMeal) {
                $elapsed = (int) CarbonImmutable::instance($summary->break_out)->diffInMinutes($now);
                if ($elapsed > $allowed) {
                    $mealAlerts[] = [
                        'employee_name' => $summary->employee?->full_name ?? 'N/D',
                        'elapsed_minutes' => $elapsed,
                        'allowed_minutes' => $allowed,
                        'active' => true,
                    ];
                }
            } elseif ($summary->status === DailySummary::STATUS_MEAL_EXCEEDED) {
                $mealAlerts[] = [
                    'employee_name' => $summary->employee?->full_name ?? 'N/D',
                    'elapsed_minutes' => $summary->meal_minutes,
                    'allowed_minutes' => $allowed,
                    'active' => false,
                ];
            }
        }

        $lateToday = $todaySummaries->where('late_minutes', '>', 0);
        $lateTodayCount = $lateToday->count();
        $lateTodayMinutes = (int) $lateToday->sum('late_minutes');

        // Horas extra acumuladas de la semana en curso (Lunes a Domingo)
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weekEnd = $now->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        $weeklyOvertimeMinutes = (int) DailySummary::whereBetween('work_date', [$weekStart, $weekEnd])
            ->sum('overtime_minutes');

        // FlexChart 1: Puntualidad semanal (últimos 7 días)
        $chartStart = $today->subDays(6);
        $weeklySummaries = DailySummary::whereBetween('work_date', [$chartStart->toDateString(), $todayStr])
            ->get()
            ->groupBy(fn (DailySummary $s): string => $s->work_date->toDateString());

        $punctualityChart = [];
        for ($i = 0; $i < 7; $i++) {
            $cursor = $chartStart->addDays($i);
            $key = $cursor->toDateString();
            $dayGroup = $weeklySummaries->get($key, collect());

            $onTimeCount = $dayGroup->filter(
                fn (DailySummary $s): bool => $s->first_check_in !== null && $s->late_minutes === 0
            )->count();

            $lateCount = $dayGroup->filter(
                fn (DailySummary $s): bool => $s->late_minutes > 0
            )->count();

            $punctualityChart[] = [
                'date' => $key,
                'day' => mb_convert_case($cursor->locale('es')->isoFormat('ddd DD'), MB_CASE_TITLE, 'UTF-8'),
                'on_time' => $onTimeCount,
                'late' => $lateCount,
            ];
        }

        // FlexChart 2: Horas ordinarias vs horas extra por departamento (semana en curso)
        $deptAggregates = DailySummary::query()
            ->join('employees', 'daily_summaries.employee_id', '=', 'employees.id')
            ->whereBetween('daily_summaries.work_date', [$weekStart, $todayStr])
            ->groupBy('employees.department')
            ->select([
                'employees.department',
                DB::raw('SUM(daily_summaries.worked_minutes) as total_worked_minutes'),
                DB::raw('SUM(daily_summaries.overtime_minutes) as total_overtime_minutes'),
            ])
            ->orderBy('employees.department')
            ->get();

        $departmentChart = $deptAggregates->map(function ($row): array {
            $totalWorked = (int) $row->total_worked_minutes;
            $overtime = (int) $row->total_overtime_minutes;
            $ordinary = max(0, $totalWorked - $overtime);

            return [
                'department' => $row->department,
                'ordinary_hours' => round($ordinary / 60, 2),
                'overtime_hours' => round($overtime / 60, 2),
            ];
        })->values()->all();

        // FlexGrid Monitor en Vivo: últimas checadas del día (fallback a últimas registradas si hoy aún no hay)
        $liveLogsQuery = AttendanceLog::with(['employee.workShift'])
            ->whereBetween('punched_at', [$today, $today->endOfDay()])
            ->orderByDesc('punched_at')
            ->limit(150)
            ->get();

        $liveLogsScope = 'today';
        if ($liveLogsQuery->isEmpty()) {
            $liveLogsQuery = AttendanceLog::with(['employee.workShift'])
                ->orderByDesc('punched_at')
                ->limit(50)
                ->get();
            if ($liveLogsQuery->isNotEmpty()) {
                $liveLogsScope = 'recent';
            }
        }

        $summariesMap = DailySummary::whereIn('employee_id', $liveLogsQuery->pluck('employee_id')->unique()->all())
            ->whereIn('work_date', $liveLogsQuery->map(function (AttendanceLog $log): string {
                $shift = $log->employee?->workShift;

                return $shift !== null
                    ? $shift->resolveWorkDateForPunch($log->punched_at)->toDateString()
                    : CarbonImmutable::instance($log->punched_at)->toDateString();
            })->unique()->all())
            ->get()
            ->keyBy(fn (DailySummary $s): string => $s->employee_id.'|'.$s->work_date->toDateString());

        $liveLogs = $liveLogsQuery->map(function (AttendanceLog $log) use ($summariesMap): array {
            $emp = $log->employee;
            $shift = $emp?->workShift;
            $workDate = $shift !== null
                ? $shift->resolveWorkDateForPunch($log->punched_at)->toDateString()
                : CarbonImmutable::instance($log->punched_at)->toDateString();
            $summary = $emp ? $summariesMap->get($emp->id.'|'.$workDate) : null;
            $status = $summary?->status ?? DailySummary::STATUS_INCOMPLETE;

            return [
                'id' => $log->id,
                'employee_no' => $emp?->hikvision_employee_no ?? '-',
                'full_name' => $emp?->full_name ?? 'Desconocido',
                'department' => $emp?->department ?? '-',
                'shift_name' => $shift?->name ?? '-',
                'punched_at' => $log->punched_at->format('Y-m-d H:i:s'),
                'punched_time' => $log->punched_at->format('H:i:s'),
                'event_type' => $log->event_type,
                'event_label' => AttendanceLog::eventTypeLabel($log->event_type),
                'verification_mode' => $log->verification_mode,
                'verification_label' => AttendanceLog::verificationModeLabel($log->verification_mode),
                'device_ip' => $log->device_ip,
                'daily_status' => $status,
                'daily_status_label' => DailySummary::statusLabel($status),
            ];
        })->values()->all();

        return [
            'kpis' => [
                'active_employees' => $activeEmployeesCount,
                'in_plant' => $inPlantCount,
                'in_meal' => $currentlyInMeal->count(),
                'meal_exceeded_count' => count($mealAlerts),
                'meal_alerts' => $mealAlerts,
                'incidents_today' => $incidentAlerts->count(),
                'incident_alerts' => $incidentAlerts->take(30)->all(),
                'late_today' => $lateTodayCount,
                'late_today_minutes' => $lateTodayMinutes,
                'weekly_overtime_hours' => round($weeklyOvertimeMinutes / 60, 1),
                'weekly_overtime_minutes' => $weeklyOvertimeMinutes,
            ],
            'charts' => [
                'punctuality' => $punctualityChart,
                'departments' => $departmentChart,
            ],
            'live_logs' => $liveLogs,
            'live_logs_scope' => $liveLogsScope,
            'generated_at' => $now->format('d/m/Y H:i:s'),
        ];
    }
}

