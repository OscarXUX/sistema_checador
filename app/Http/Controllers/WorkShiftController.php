<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\ShiftException;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationAssignment;
use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use App\Services\AuditLogger;
use App\Services\ShiftScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkShiftController extends Controller
{
    private const AUDITED_FIELDS = [
        'name', 'entry_time', 'exit_time', 'meal_duration_minutes', 'tolerance_minutes', 'max_weekly_hours',
    ];

    private const VALID_TABS = ['turnos', 'rotaciones', 'asignaciones', 'excepciones'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, ShiftScheduleResolver $resolver): View
    {
        $requestedTab = (string) $request->query('tab', 'turnos');
        $activeTab = in_array($requestedTab, self::VALID_TABS, true) ? $requestedTab : 'turnos';

        $previewStartRaw = (string) $request->query('preview_start', now()->toDateString());
        try {
            $previewStart = CarbonImmutable::parse($previewStartRaw)->startOfDay();
        } catch (\Throwable) {
            $previewStart = CarbonImmutable::today();
        }

        $previewDepartment = trim((string) $request->query('preview_department', ''));

        $lstTurnos = WorkShift::withCount('employees')->orderBy('entry_time')->get();
        $lstRotaciones = ShiftRotation::with(['steps.workShift'])->withCount('assignments')->orderBy('name')->get();
        $lstGrupos = EmployeeGroup::with(['employees:id,hikvision_employee_no,full_name,department'])
            ->withCount('employees')
            ->orderBy('name')
            ->get();
        $lstAsignaciones = ShiftRotationAssignment::with([
            'rotation',
            'employee:id,hikvision_employee_no,full_name,department',
            'group:id,name',
        ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
        $lstExcepciones = ShiftException::with([
            'employee:id,hikvision_employee_no,full_name,department',
            'workShift:id,name,entry_time,exit_time',
        ])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $lstEmpleados = Employee::with(['workShift', 'groups:id'])
            ->orderBy('full_name')
            ->get();

        $lstDepartamentos = $lstEmpleados->pluck('department')
            ->filter(fn ($d): bool => is_string($d) && trim($d) !== '')
            ->unique()
            ->sort()
            ->values();

        $lstEmpleadosVista = $previewDepartment !== ''
            ? $lstEmpleados->where('department', $previewDepartment)->values()
            : $lstEmpleados->where('is_active', true)->values();

        if ($lstEmpleadosVista->isEmpty() && $previewDepartment === '') {
            $lstEmpleadosVista = $lstEmpleados;
        }

        $dataVistaPrevia = $resolver->previewForRange($lstEmpleadosVista->take(50), $previewStart, 14);

        return view('shifts.index', [
            'activeTab' => $activeTab,
            'lstTurnos' => $lstTurnos,
            'lstRotaciones' => $lstRotaciones,
            'lstGrupos' => $lstGrupos,
            'lstAsignaciones' => $lstAsignaciones,
            'lstExcepciones' => $lstExcepciones,
            'lstEmpleados' => $lstEmpleados,
            'lstDepartamentos' => $lstDepartamentos,
            'previewStart' => $previewStart->toDateString(),
            'previewDepartment' => $previewDepartment,
            'dataVistaPrevia' => $dataVistaPrevia,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = WorkShift::create($this->validateShift($request));

        $this->audit->record('shift.created', $shift, "Alta del turno {$shift->name}", [], $shift->only(self::AUDITED_FIELDS));

        return redirect()
            ->route('shifts.index')
            ->with('status', 'Turno laboral creado correctamente.');
    }

    public function update(Request $request, WorkShift $shift, AttendanceCalculatorService $calculator): RedirectResponse
    {
        $dataAntes = $shift->only(self::AUDITED_FIELDS);
        $shift->update($this->validateShift($request));

        [$old, $new] = $this->audit->diff($dataAntes, $shift->only(self::AUDITED_FIELDS));
        if ($new !== []) {
            // Cambiar horarios o tolerancia cambia retardos y horas extra: queda registrado y recalcula resúmenes
            $this->audit->record('shift.updated', $shift, "Cambio en el turno {$shift->name}", $old, $new);

            if (array_intersect(array_keys($new), ['entry_time', 'exit_time', 'meal_duration_minutes', 'tolerance_minutes', 'max_weekly_hours']) !== []) {
                $calculator->recalculateAllForShift($shift);
            }
        }

        return redirect()
            ->route('shifts.index')
            ->with('status', "Turno {$shift->name} actualizado.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateShift(Request $request): array
    {
        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'entry_time' => ['required', 'date_format:H:i'],
            'exit_time' => ['required', 'date_format:H:i'],
            'meal_duration_minutes' => ['required', 'integer', 'min:15', 'max:180'],
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'max_weekly_hours' => ['required', 'integer', 'min:20', 'max:60'],
        ]);

        $dataValidado['entry_time'] .= ':00';
        $dataValidado['exit_time'] .= ':00';

        return $dataValidado;
    }
}
