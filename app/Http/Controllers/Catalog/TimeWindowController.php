<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Catálogo de ventanas de horario por turno (nota 6 y P6 de Oscar): de qué hora a qué hora una checada
 * es entrada y de qué hora a qué hora es salida. Lo que cae entre las dos es comida.
 */
class TimeWindowController extends Controller
{
    private const FIELDS = ['entry_window_start', 'entry_window_end', 'exit_window_start', 'exit_window_end'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('catalogs.time-windows', [
            'lstTurnos' => WorkShift::orderBy('entry_time')->orderBy('name')->get(),
            'dataOmision' => [WorkShift::DEFAULT_ENTRY_WINDOW, WorkShift::DEFAULT_EXIT_WINDOW],
        ]);
    }

    public function update(Request $request, WorkShift $shift, AttendanceCalculatorService $calculator): RedirectResponse
    {
        $useDefaults = $request->boolean('use_defaults');

        $dataValidado = $request->validate([
            'entry_window_start' => [$useDefaults ? 'nullable' : 'required', 'date_format:H:i'],
            'entry_window_end' => [$useDefaults ? 'nullable' : 'required', 'date_format:H:i'],
            'exit_window_start' => [$useDefaults ? 'nullable' : 'required', 'date_format:H:i'],
            'exit_window_end' => [$useDefaults ? 'nullable' : 'required', 'date_format:H:i'],
            'repeat_threshold_minutes' => ['required', 'integer', 'min:0', 'max:30'],
        ], [
            '*.required' => 'Escribe las cuatro horas o marca "Usar valores por omisión".',
            '*.date_format' => 'Usa el formato HH:MM.',
            'repeat_threshold_minutes.max' => 'Las checadas repetidas se buscan hasta 30 minutos.',
        ]);

        if ($useDefaults) {
            foreach (self::FIELDS as $field) {
                $dataValidado[$field] = null;
            }
        } else {
            $this->validateWindows($shift, $dataValidado);
        }

        $dataAntes = [...$shift->windowTimes(), 'repetida_min' => $shift->repeat_threshold_minutes, 'por_omision' => ! $shift->hasCustomWindows()];
        $shift->fill($dataValidado)->save();
        $dataDespues = [...$shift->windowTimes(), 'repetida_min' => $shift->repeat_threshold_minutes, 'por_omision' => ! $shift->hasCustomWindows()];

        [$old, $new] = $this->audit->diff($dataAntes, $dataDespues);
        if ($new === []) {
            return redirect()->route('catalogs.windows')->with('status', "Sin cambios en {$shift->name}.");
        }

        $this->audit->record('shift.windows_updated', $shift, "Ventanas de horario del turno {$shift->name}", $old, $new);
        $count = $calculator->recalculateAllForShift($shift);

        return redirect()->route('catalogs.windows')->with('status', "Ventanas de {$shift->name} guardadas. Se recalcularon {$count} resúmenes.");
    }

    /**
     * La entrada del turno debe caer en su ventana, la salida en la suya, y no deben encimarse.
     *
     * @param  array<string, mixed>  $dataVentanas
     */
    private function validateWindows(WorkShift $shift, array $dataVentanas): void
    {
        $date = CarbonImmutable::parse('2026-01-05');
        $scheduledEntry = CarbonImmutable::parse($date->toDateString().' '.$shift->entry_time);
        $scheduledExit = CarbonImmutable::parse(($shift->crossesMidnight() ? $date->addDay() : $date)->toDateString().' '.$shift->exit_time);

        $lstVentanaEntrada = WorkShift::anchorWindow([$dataVentanas['entry_window_start'], $dataVentanas['entry_window_end']], $scheduledEntry);
        $lstVentanaSalida = WorkShift::anchorWindow([$dataVentanas['exit_window_start'], $dataVentanas['exit_window_end']], $scheduledExit);

        $dataErrores = [];
        if (! $scheduledEntry->betweenIncluded($lstVentanaEntrada[0], $lstVentanaEntrada[1])) {
            $dataErrores['entry_window_start'] = "La ventana de entrada debe incluir la hora de entrada del turno ({$scheduledEntry->format('H:i')}).";
        }
        if (! $scheduledExit->betweenIncluded($lstVentanaSalida[0], $lstVentanaSalida[1])) {
            $dataErrores['exit_window_start'] = "La ventana de salida debe incluir la hora de salida del turno ({$scheduledExit->format('H:i')}).";
        }
        if ($dataErrores === [] && $lstVentanaEntrada[1]->greaterThanOrEqualTo($lstVentanaSalida[0])) {
            $dataErrores['exit_window_start'] = 'La ventana de salida debe empezar después de que termine la de entrada; entre las dos se cuenta la comida.';
        }

        if ($dataErrores !== []) {
            throw ValidationException::withMessages($dataErrores);
        }
    }
}
