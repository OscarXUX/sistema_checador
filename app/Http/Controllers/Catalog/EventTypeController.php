<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEventType;
use App\Services\AuditLogger;
use App\Services\EventTypeClassifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Catálogo de tipos de evento (nota 4 y P7: "esos los creamos nosotros"). Cada cambio vuelve a
 * asignar los tipos a todos los resúmenes diarios sin recalcular checadas.
 */
class EventTypeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventTypeClassifier $classifier,
    ) {
    }

    public function index(): View
    {
        return view('catalogs.event-types', [
            'lstPorTipo' => AttendanceEventType::orderBy('sort_order')->orderBy('id')->get()->groupBy('applies_to'),
            'dataAplicaA' => AttendanceEventType::APPLIES_TO,
            'dataColores' => AttendanceEventType::COLORS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dataValidado = $this->validated($request, null);

        $code = Str::limit(Str::slug($dataValidado['name'], '_'), 30, '');
        $base = $code !== '' ? $code : 'evento';
        $code = $base;
        for ($i = 2; AttendanceEventType::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        $eventType = AttendanceEventType::create([...$dataValidado, 'code' => $code, 'is_system' => false, 'is_active' => true]);
        $this->audit->record('event_type.created', $eventType, "Tipo de evento creado: {$eventType->name}", [], $eventType->only(['name', 'applies_to', 'min_minutes', 'max_minutes']));

        return $this->done("Tipo de evento {$eventType->name} creado.");
    }

    public function update(Request $request, AttendanceEventType $eventType): RedirectResponse
    {
        $request->merge(['applies_to' => $eventType->applies_to, 'is_active' => $request->boolean('is_active')]);
        $dataValidado = $this->validated($request, $eventType);

        $dataAntes = $eventType->only(['name', 'min_minutes', 'max_minutes', 'color', 'sort_order', 'is_active']);
        $eventType->update($dataValidado);
        [$old, $new] = $this->audit->diff($dataAntes, $eventType->only(array_keys($dataAntes)));

        if ($new === []) {
            return redirect()->route('catalogs.event-types')->with('status', 'Sin cambios.');
        }

        $this->audit->record('event_type.updated', $eventType, "Cambio en el tipo de evento {$eventType->name}", $old, $new);

        return $this->done("Tipo de evento {$eventType->name} guardado.");
    }

    public function destroy(AttendanceEventType $eventType): RedirectResponse
    {
        if ($eventType->is_system) {
            return back()->withErrors(['event_type' => "{$eventType->name} viene con el sistema: se puede desactivar, pero no eliminar."]);
        }

        $name = $eventType->name;
        $eventType->delete();
        $this->audit->record('event_type.deleted', null, "Tipo de evento eliminado: {$name}", ['name' => $name]);

        return $this->done("Tipo de evento {$name} eliminado.");
    }

    private function done(string $message): RedirectResponse
    {
        $count = $this->classifier->reclassifyAll();

        return redirect()->route('catalogs.event-types')->with('status', "{$message} Se actualizaron {$count} resúmenes.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AttendanceEventType $current): array
    {
        if ($request->input('max_minutes') === '') {
            $request->merge(['max_minutes' => null]);
        }

        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'applies_to' => ['required', Rule::in(array_keys(AttendanceEventType::APPLIES_TO))],
            'min_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'max_minutes' => ['nullable', 'integer', 'gte:min_minutes', 'max:1440'],
            'color' => ['required', Rule::in(array_keys(AttendanceEventType::COLORS))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Escribe el nombre del evento.',
            'min_minutes.required' => 'Escribe desde cuántos minutos aplica.',
            'max_minutes.gte' => 'El "hasta" debe ser mayor o igual al "desde".',
        ]);
        $dataValidado['sort_order'] ??= $current?->sort_order ?? 100;

        // Dos tipos activos de la misma categoría no pueden cubrir los mismos minutos
        if ($dataValidado['is_active'] ?? true) {
            $min = (int) $dataValidado['min_minutes'];
            $max = $dataValidado['max_minutes'] === null ? PHP_INT_MAX : (int) $dataValidado['max_minutes'];
            $choque = AttendanceEventType::active()
                ->where('applies_to', $dataValidado['applies_to'])
                ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))
                ->get()
                ->first(fn (AttendanceEventType $t): bool => $min <= ($t->max_minutes ?? PHP_INT_MAX) && $t->min_minutes <= $max);

            if ($choque !== null) {
                throw ValidationException::withMessages([
                    'min_minutes' => "Ese rango se cruza con \"{$choque->name}\" ({$choque->rangeLabel()}). Ajusta o desactiva ese primero.",
                ]);
            }
        }

        return $dataValidado;
    }
}
