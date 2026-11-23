<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\KardexExcelExportService;
use App\Services\ShiftScheduleResolver;
use App\Support\PersonalData;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Exporta el Kardex en Excel (.xlsx) desde el servidor con una pestaña por mes (máximo 12 meses).
     */
    public function exportXlsx(
        Request $request,
        KardexExcelExportService $exporter,
        AuditLogger $audit,
    ): StreamedResponse|Response {
        $startRaw = (string) $request->query('start_date', '');
        $endRaw = (string) $request->query('end_date', '');

        if ($startRaw === '' || $endRaw === '') {
            return $this->unprocessableExport($request, 'Debes indicar fecha de inicio y fecha de fin para exportar.', 'start_date');
        }

        try {
            $start = CarbonImmutable::parse($startRaw)->startOfDay();
            $end = CarbonImmutable::parse($endRaw)->startOfDay();
        } catch (\Throwable) {
            return $this->unprocessableExport($request, 'El formato de fechas para exportar no es válido.', 'start_date');
        }

        if ($start->greaterThan($end)) {
            return $this->unprocessableExport($request, 'La fecha de fin debe ser igual o posterior a la fecha de inicio.', 'end_date');
        }

        $department = trim((string) $request->query('department', ''));
        $employeeId = $request->filled('employee_id') ? (int) $request->query('employee_id') : null;

        try {
            $result = $exporter->buildSpreadsheet(
                $start,
                $end,
                $department !== '' ? $department : null,
                $employeeId
            );
        } catch (InvalidArgumentException $e) {
            return $this->unprocessableExport($request, $e->getMessage(), 'end_date');
        }

        $months = $result['months'];
        $monthsCount = count($months);
        $spreadsheet = $result['spreadsheet'];
        $filename = sprintf('Kardex_Fiscal_%s_a_%s.xlsx', $start->toDateString(), $end->toDateString());

        $audit->record(
            'kardex.exported_xlsx',
            null,
            sprintf('Exportación Excel del kardex (%s a %s, %d hojas)', $start->toDateString(), $end->toDateString(), $monthsCount),
            [],
            [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'months' => $monthsCount,
                'rows' => $result['row_count'],
                'department' => $department !== '' ? $department : null,
                'employee_id' => $employeeId,
            ]
        );

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function unprocessableExport(Request $request, string $message, string $field): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => [$field => [$message]],
            ], 422);
        }

        return response($message, 422);
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
        $query = DailySummary::with(['employee.workShift', 'entryEventType', 'exitEventType', 'mealEventType'])
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

        $summaries = $query->get();

        if ($summaries->isEmpty()) {
            return [];
        }

        $resolver = app(ShiftScheduleResolver::class);
        $employees = $summaries->pluck('employee')->filter()->unique('id')->values();
        $minDate = $summaries->min('work_date');
        $maxDate = $summaries->max('work_date');
        $scheduleContext = $resolver->loadBatchContext($employees, $minDate, $maxDate);

        return $summaries->map(function (DailySummary $summary) use ($resolver, $scheduleContext): array {
            $emp = $summary->employee;
            $resolved = $emp !== null
                ? $resolver->resolveDetailFromContext($emp, CarbonImmutable::instance($summary->work_date)->startOfDay(), $scheduleContext)
                : ['shift' => null];
            $shift = $resolved['shift'] ?? $emp?->workShift;
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
                // Paquete 3: tipos de evento del catálogo e incidencias de la jornada
                'early_exit_minutes' => $summary->early_exit_minutes,
                'corrected_punches' => $summary->corrected_punches,
                'events' => collect([$summary->entryEventType, $summary->mealEventType, $summary->exitEventType])
                    ->filter()
                    ->map(fn ($type): array => ['name' => $type->name, 'color' => $type->color])
                    ->values()
                    ->all(),
                'events_label' => collect([$summary->entryEventType, $summary->mealEventType, $summary->exitEventType])->filter()->pluck('name')->implode(', '),
                'incidents' => $summary->incidentList(),
                'incidents_label' => collect($summary->incidentList())->map(fn (array $i): string => "{$i['label']} ({$i['at']})")->implode('; '),
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
