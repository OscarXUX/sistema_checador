<?php

namespace App\Http\Controllers;

use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkShiftController extends Controller
{
    private const AUDITED_FIELDS = [
        'name', 'entry_time', 'exit_time', 'meal_duration_minutes', 'tolerance_minutes', 'max_weekly_hours',
    ];

    public function index(): View
    {
        return view('shifts.index', [
            'shifts' => WorkShift::withCount('employees')->orderBy('entry_time')->get(),
        ]);
    }

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = WorkShift::create($this->validateShift($request));

        $this->audit->record('shift.created', $shift, "Alta del turno {$shift->name}", [], $shift->only(self::AUDITED_FIELDS));

        return redirect()->route('shifts.index')->with('status', 'Turno laboral creado correctamente.');
    }

    public function update(Request $request, WorkShift $shift, AttendanceCalculatorService $calculator): RedirectResponse
    {
        $before = $shift->only(self::AUDITED_FIELDS);
        $shift->update($this->validateShift($request));

        [$old, $new] = $this->audit->diff($before, $shift->only(self::AUDITED_FIELDS));
        if ($new !== []) {
            // Cambiar horarios o tolerancia cambia retardos y horas extra: queda registrado y recalcula resúmenes
            $this->audit->record('shift.updated', $shift, "Cambio en el turno {$shift->name}", $old, $new);

            if (array_intersect(array_keys($new), ['entry_time', 'exit_time', 'meal_duration_minutes', 'tolerance_minutes', 'max_weekly_hours']) !== []) {
                $calculator->recalculateAllForShift($shift);
            }
        }

        return redirect()->route('shifts.index')->with('status', "Turno {$shift->name} actualizado.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateShift(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'entry_time' => ['required', 'date_format:H:i'],
            'exit_time' => ['required', 'date_format:H:i'],
            'meal_duration_minutes' => ['required', 'integer', 'min:15', 'max:180'],
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'max_weekly_hours' => ['required', 'integer', 'min:20', 'max:60'],
        ]);

        $validated['entry_time'] .= ':00';
        $validated['exit_time'] .= ':00';

        return $validated;
    }
}
