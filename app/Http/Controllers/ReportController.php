<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Support\PersonalData;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Rango máximo de días que se consulta en el kardex (auditoría OP-04).
     */
    public const MAX_RANGE_DAYS = 93;

    public function kardex(Request $request): View
    {
        $filters = $this->resolveFilters($request);
        $records = $this->queryKardexRows($filters);

        return view('reports.kardex', [
            'filters' => $filters,
            'departments' => Employee::select('department')->distinct()->orderBy('department')->pluck('department'),
            'employees' => Employee::orderBy('full_name')->get(['id', 'hikvision_employee_no', 'full_name', 'department']),
            'kardexRows' => $records,
            'auditLogs' => $this->queryAuditLogs($filters),
        ]);
    }

    public function kardexData(Request $request): JsonResponse
    {
        $filters = $this->resolveFilters($request);

        return response()->json([
            'filters' => $filters,
            'kardex_rows' => $this->queryKardexRows($filters),
            'audit_logs' => $this->queryAuditLogs($filters),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $startRaw = (string) $request->query('start_date', now()->subDays(6)->toDateString());
        $endRaw = (string) $request->query('end_date', now()->toDateString());

        try {
            $start = Carbon::parse($startRaw)->toDateString();
        } catch (\Throwable) {
            $start = now()->subDays(6)->toDateString();
        }

        try {
            $end = Carbon::parse($endRaw)->toDateString();
        } catch (\Throwable) {
            $end = now()->toDateString();
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // Si el rango excede el máximo, se recorta la fecha de inicio y se avisa en pantalla
        $rangeAdjusted = false;
        $earliestStart = Carbon::parse($end)->subDays(self::MAX_RANGE_DAYS - 1)->toDateString();
        if ($start < $earliestStart) {
            $start = $earliestStart;
            $rangeAdjusted = true;
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'range_adjusted' => $rangeAdjusted,
            'max_range_days' => self::MAX_RANGE_DAYS,
            'department' => trim((string) $request->query('department', '')),
            'employee_id' => $request->filled('employee_id') ? (int) $request->query('employee_id') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function queryKardexRows(array $filters): array
    {
        $query = DailySummary::with(['employee.workShift'])
            ->whereBetween('work_date', [$filters['start_date'], $filters['end_date']])
            ->whereHas('employee', function ($q) use ($filters): void {
                if ($filters['department'] !== '') {
                    $q->where('department', $filters['department']);
                }
                if ($filters['employee_id'] !== null) {
                    $q->where('id', $filters['employee_id']);
                }
            })
            ->orderBy('employee_id')
            ->orderBy('work_date');

        return $query->get()->map(function (DailySummary $summary): array {
            $emp = $summary->employee;
            $shift = $emp?->workShift;
            $ordinaryMinutes = max(0, $summary->worked_minutes - $summary->overtime_minutes);

            return [
                'id' => $summary->id,
                'employee_id' => $summary->employee_id,
                'hikvision_employee_no' => $emp?->hikvision_employee_no ?? '-',
                'employee_name' => $emp?->full_name ?? 'N/D',
                'employee_label' => sprintf(
                    '[%s] %s — %s (RFC: %s)',
                    $emp?->hikvision_employee_no ?? '-',
                    $emp?->full_name ?? 'N/D',
                    $emp?->department ?? '-',
                    PersonalData::maskRfc($emp?->rfc) ?? 'S/R'
                ),
                // Enmascarados también en pantalla y exportaciones (auditoría SE-05)
                'rfc' => PersonalData::maskRfc($emp?->rfc) ?? '-',
                'curp' => PersonalData::maskCurp($emp?->curp) ?? '-',
                'nss' => PersonalData::maskNss($emp?->nss) ?? '-',
                'department' => $emp?->department ?? '-',
                'shift_name' => $shift?->name ?? '-',
                'work_date' => $summary->work_date->toDateString(),
                'first_check_in' => $summary->first_check_in?->format('H:i:s') ?? '--:--',
                'break_out' => $summary->break_out?->format('H:i:s') ?? '--:--',
                'break_in' => $summary->break_in?->format('H:i:s') ?? '--:--',
                'last_check_out' => $summary->last_check_out?->format('H:i:s') ?? '--:--',
                'worked_minutes' => $summary->worked_minutes,
                'ordinary_hours' => round($ordinaryMinutes / 60, 2),
                'worked_hours' => round($summary->worked_minutes / 60, 2),
                'meal_minutes' => $summary->meal_minutes,
                'late_minutes' => $summary->late_minutes,
                'overtime_minutes' => $summary->overtime_minutes,
                'overtime_hours' => round($summary->overtime_minutes / 60, 2),
                'status' => $summary->status,
                'status_label' => DailySummary::statusLabel($summary->status),
            ];
        })->values()->all();
    }

    /**
     * Listado ligero de bitácora sin raw_payload para evitar cargar hasta 250 JSONs en memoria (auditoría OP-08).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function queryAuditLogs(array $filters): array
    {
        $startDt = Carbon::parse($filters['start_date'])->startOfDay();
        $endDt = Carbon::parse($filters['end_date'])->endOfDay();

        return AttendanceLog::query()
            ->select(['id', 'employee_id', 'device_ip', 'punched_at', 'event_type', 'verification_mode'])
            ->with('employee:id,hikvision_employee_no,full_name,department')
            ->whereBetween('punched_at', [$startDt, $endDt])
            ->whereHas('employee', function ($q) use ($filters): void {
                if ($filters['department'] !== '') {
                    $q->where('department', $filters['department']);
                }
                if ($filters['employee_id'] !== null) {
                    $q->where('id', $filters['employee_id']);
                }
            })
            ->orderByDesc('punched_at')
            ->limit(250)
            ->get()
            ->map(fn (AttendanceLog $log): array => [
                'id' => $log->id,
                'employee_no' => $log->employee?->hikvision_employee_no ?? '-',
                'employee_name' => $log->employee?->full_name ?? '-',
                'department' => $log->employee?->department ?? '-',
                'punched_at' => $log->punched_at->format('Y-m-d H:i:s'),
                'event_type' => $log->event_type,
                'event_label' => AttendanceLog::eventTypeLabel($log->event_type),
                'verification_mode' => AttendanceLog::verificationModeLabel($log->verification_mode),
                'device_ip' => $log->device_ip,
            ])
            ->values()
            ->all();
    }

    /**
     * Entrega el payload JSON original de una checada solo cuando el usuario abre el modal (auditoría OP-08).
     */
    public function logPayload(AttendanceLog $attendanceLog): JsonResponse
    {
        $attendanceLog->load('employee:id,hikvision_employee_no,full_name');

        return response()->json([
            'id' => $attendanceLog->id,
            'employee_no' => $attendanceLog->employee?->hikvision_employee_no ?? '-',
            'employee_name' => $attendanceLog->employee?->full_name ?? '-',
            'punched_at' => $attendanceLog->punched_at->format('Y-m-d H:i:s'),
            'device_ip' => $attendanceLog->device_ip,
            'raw_payload' => json_encode($attendanceLog->raw_payload ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }
}
