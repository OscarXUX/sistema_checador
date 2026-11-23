<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceLogCorrection;
use App\Models\DailySummary;
use App\Services\AttendanceCalculatorService;
use App\Services\AuditLogger;
use App\Services\PunchClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checadas de un día y su corrección manual (propuesta 3.3). La checada original nunca se modifica:
 * la corrección se guarda aparte con quién, cuándo y por qué, y el día se recalcula.
 */
class PunchCorrectionController extends Controller
{
    public function __construct(
        private readonly AttendanceCalculatorService $calculator,
        private readonly AuditLogger $audit,
    ) {
    }

    public function day(Request $request, DailySummary $dailySummary): JsonResponse
    {
        $employee = $dailySummary->employee()->firstOrFail();
        ['shift' => $shift, 'logs' => $lstChecadas, 'slots' => $slots] = $this->calculator->classifyDay($employee, $dailySummary->work_date);
        $lstChecadas->load('correction.user');

        $dataIncidencias = [];
        foreach ($slots['incidents'] as $incident) {
            $dataIncidencias[$incident['at']][] = PunchClassifier::INCIDENT_LABELS[$incident['code']] ?? $incident['code'];
        }

        return response()->json([
            'employee' => "[{$employee->hikvision_employee_no}] {$employee->full_name}",
            'work_date' => $dailySummary->work_date->toDateString(),
            'shift' => $shift?->name,
            'can_correct' => $request->user()->can('kardex.corregir'),
            'types' => AttendanceLogCorrection::TYPES,
            'punches' => $lstChecadas->map(function (AttendanceLog $log) use ($slots, $dataIncidencias): array {
                $role = $slots['roles'][$log->id] ?? null;
                $at = $log->punched_at->format('Y-m-d H:i:s');

                return [
                    'id' => $log->id,
                    'punched_at' => $at,
                    'time' => $log->punched_at->format('H:i:s'),
                    'device_key' => AttendanceLog::eventTypeLabel((string) $log->event_type),
                    'role' => $role,
                    'role_label' => $role !== null ? (PunchClassifier::ROLE_LABELS[$role] ?? $role) : null,
                    'incidents' => $dataIncidencias[$at] ?? [],
                    'correction' => $log->correction === null ? null : [
                        'type' => $log->correction->corrected_type,
                        'label' => AttendanceLogCorrection::TYPES[$log->correction->corrected_type] ?? $log->correction->corrected_type,
                        'reason' => $log->correction->reason,
                        'user' => $log->correction->user?->name ?? 'N/D',
                        'at' => $log->correction->updated_at?->format('Y-m-d H:i'),
                    ],
                ];
            })->values(),
        ]);
    }

    public function store(Request $request, AttendanceLog $attendanceLog): JsonResponse
    {
        $dataValidado = $request->validate([
            'corrected_type' => ['required', Rule::in(array_keys(AttendanceLogCorrection::TYPES))],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'corrected_type.required' => 'Elige cómo debe contar la checada.',
            'reason.required' => 'Escribe el motivo de la corrección.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
        ]);

        $previous = $attendanceLog->correction;
        $old = $previous === null ? [] : ['tipo' => $previous->corrected_type, 'motivo' => $previous->reason];

        $attendanceLog->correction()->updateOrCreate([], [
            'corrected_type' => $dataValidado['corrected_type'],
            'reason' => trim($dataValidado['reason']),
            'user_id' => $request->user()->id,
        ]);

        $this->audit->record('attendance.corrected', $attendanceLog, $this->describe($attendanceLog, 'Corrección de la checada'), $old, [
            'tipo' => $dataValidado['corrected_type'],
            'motivo' => trim($dataValidado['reason']),
        ]);

        $this->recalculate($attendanceLog);

        return response()->json(['success' => true, 'message' => 'Corrección guardada y día recalculado.']);
    }

    public function destroy(AttendanceLog $attendanceLog): JsonResponse
    {
        $correction = $attendanceLog->correction;
        if ($correction === null) {
            return response()->json(['success' => false, 'message' => 'La checada no tiene corrección.'], 404);
        }

        $correction->delete();
        $this->audit->record('attendance.correction_removed', $attendanceLog, $this->describe($attendanceLog, 'Se quitó la corrección de la checada'), [
            'tipo' => $correction->corrected_type,
            'motivo' => $correction->reason,
        ]);

        $this->recalculate($attendanceLog);

        return response()->json(['success' => true, 'message' => 'Corrección quitada; la checada vuelve a clasificarse sola.']);
    }

    private function describe(AttendanceLog $log, string $text): string
    {
        $log->loadMissing('employee');

        return "{$text} del {$log->punched_at->format('d/m/Y H:i:s')} de #{$log->employee?->hikvision_employee_no}";
    }

    private function recalculate(AttendanceLog $log): void
    {
        $log->unsetRelation('correction');
        $this->calculator->recalculateForPunch($log->employee()->firstOrFail(), $log->punched_at);
    }
}
