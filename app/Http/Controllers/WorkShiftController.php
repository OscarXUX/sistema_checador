<?php

namespace App\Http\Controllers;

use App\Models\WorkShift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkShiftController extends Controller
{
    public function index(): View
    {
        return view('shifts.index', [
            'shifts' => WorkShift::withCount('employees')->orderBy('entry_time')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        WorkShift::create($this->validateShift($request));

        return redirect()->route('shifts.index')->with('status', 'Turno laboral creado correctamente.');
    }

    public function update(Request $request, WorkShift $shift): RedirectResponse
    {
        $shift->update($this->validateShift($request));

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
